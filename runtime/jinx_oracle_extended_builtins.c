#include "jinx_oracle_extended_builtins.h"
#include "jinx_oracle_batch2_builtins.h"
#include "jinx_oracle_resource_registry.h"
#include "jinx_oracle_constant_registry.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <ctype.h>
#include <errno.h>
#include <limits.h>
#include <math.h>
#include <glob.h>
#include <dirent.h>
#include <fcntl.h>
#include <grp.h>
#include <pwd.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/shm.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>
#include <utime.h>

typedef struct JinxOracleExtStream {
    FILE *fp;
    int process_pipe;
} JinxOracleExtStream;

typedef struct JinxOracleExtInterval {
    int years;
    int months;
    int days;
    int hours;
    int minutes;
    int seconds;
    int invert;
    int64_t total_days;
} JinxOracleExtInterval;

typedef struct JinxOracleExtShmop {
    int shmid;
    size_t size;
    int readonly;
    int64_t key;
} JinxOracleExtShmop;

typedef struct JinxOracleExtTzScope {
    char *old_tz;
    int had_old;
} JinxOracleExtTzScope;

static char jinx_oracle_ext_default_timezone[128] = JINX_NATIVE_PHP_DEFAULT_TIMEZONE;
static int jinx_oracle_ext_date_error = 0;

const char *jinx_oracle_extended_default_timezone(void) {
    return jinx_oracle_ext_default_timezone;
}
static char jinx_oracle_ext_date_error_message[160] = "";

#define JINX_ORACLE_EXT_MAX_ALIASES 64u
static char *jinx_oracle_ext_alias_names[JINX_ORACLE_EXT_MAX_ALIASES] = {0};
static char *jinx_oracle_ext_alias_targets[JINX_ORACLE_EXT_MAX_ALIASES] = {0};
static size_t jinx_oracle_ext_alias_count = 0u;

static char *jinx_oracle_ext_strdup(const char *s) {
    size_t len;
    char *out;
    if (s == NULL) return NULL;
    len = strlen(s);
    out = (char *)malloc(len + 1u);
    if (out == NULL) return NULL;
    memcpy(out, s, len + 1u);
    return out;
}

static int jinx_oracle_ext_name_in_list(
    const char *name,
    const char *const *items,
    size_t count
) {
    if (name == NULL || items == NULL) return 0;
    for (size_t i = 0u; i < count; i++) {
        if (items[i] != NULL && strcasecmp(items[i], name) == 0) return 1;
    }
    return 0;
}

static char *jinx_oracle_ext_dup_string_value(JinxValue value) {
    uint32_t len;
    char *out;
    if (value.type != 3u || value.as.ptr == NULL) return NULL;
    len = jinx_oracle_string_len(value);
    out = (char *)malloc((size_t)len + 1u);
    if (out == NULL) return NULL;
    if (len != 0u) memcpy(out, jinx_oracle_string_bytes(value), len);
    out[len] = '\0';
    return out;
}

static JinxValue jinx_oracle_ext_copy_string(const char *bytes, size_t len) {
    char *out;
    if (len > UINT32_MAX) return jinx_oracle_zero_value();
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u && bytes != NULL) memcpy(out, bytes, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

static int jinx_oracle_ext_object_set_long(JinxZendObject *object, const char *key, int64_t value) {
    return object != NULL && object->properties != NULL &&
        jinx_zend_array_add_assoc(object->properties, key, strlen(key), jinx_zend_long(value));
}

static int jinx_oracle_ext_object_set_string(JinxZendObject *object, const char *key, const char *value) {
    JinxZendString *string;
    int ok;
    if (object == NULL || object->properties == NULL || value == NULL) return 0;
    string = jinx_zend_string_new(value, strlen(value));
    if (string == NULL) return 0;
    ok = jinx_zend_array_add_assoc(
        object->properties,
        key,
        strlen(key),
        jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static int jinx_oracle_ext_object_set_value(
    JinxZendObject *object,
    const char *key,
    JinxValue value
) {
    JinxZendValue zend_value;
    JinxZendString *owned_string = NULL;
    int ok;

    if (object == NULL || object->properties == NULL || key == NULL) return 0;
    if (!jinx_oracle_jinx_value_to_zend(value, &zend_value, &owned_string)) {
        return 0;
    }
    ok = jinx_zend_array_add_assoc(
        object->properties,
        key,
        strlen(key),
        zend_value
    );
    jinx_zend_string_release(owned_string);
    return ok;
}

static int jinx_oracle_ext_object_set_resource(JinxZendObject *object, const char *key, void *ptr) {
    JinxZendValue value = jinx_zend_null();
    if (object == NULL || object->properties == NULL) return 0;
    value.type = JINX_ZEND_RESOURCE;
    value.value.ptr = ptr;
    return jinx_zend_array_add_assoc(object->properties, key, strlen(key), value);
}

static JinxZendValue *jinx_oracle_ext_object_prop(JinxZendObject *object, const char *key) {
    if (object == NULL || object->properties == NULL) return NULL;
    return jinx_zend_array_find(object->properties, key, strlen(key));
}

static int64_t jinx_oracle_ext_object_long(JinxZendObject *object, const char *key, int64_t fallback) {
    JinxZendValue *value = jinx_oracle_ext_object_prop(object, key);
    return value != NULL && value->type == JINX_ZEND_LONG ? value->value.lval : fallback;
}

static const char *jinx_oracle_ext_object_string(JinxZendObject *object, const char *key, const char *fallback) {
    JinxZendValue *value = jinx_oracle_ext_object_prop(object, key);
    return value != NULL && value->type == JINX_ZEND_STRING && value->value.str != NULL
        ? value->value.str->bytes
        : fallback;
}

static int jinx_oracle_ext_array_append_string(JinxZendArray *array, const char *bytes, size_t len) {
    JinxZendString *string;
    int ok;
    string = jinx_zend_string_new(bytes != NULL ? bytes : "", len);
    if (string == NULL) return 0;
    ok = jinx_zend_array_append(array, jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}

static int jinx_oracle_ext_array_add_assoc_string(
    JinxZendArray *array,
    const char *key,
    const char *value
) {
    JinxZendString *string;
    int ok;
    if (array == NULL || key == NULL || value == NULL) return 0;
    string = jinx_zend_string_new(value, strlen(value));
    if (string == NULL) return 0;
    ok = jinx_zend_array_add_assoc(array, key, strlen(key), jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}

static int jinx_oracle_ext_array_add_assoc_array(
    JinxZendArray *array,
    const char *key,
    JinxZendArray *child
) {
    int ok;
    if (array == NULL || key == NULL || child == NULL) return 0;
    ok = jinx_zend_array_add_assoc(array, key, strlen(key), jinx_zend_array_value(child));
    return ok;
}

/* ---------------- callable dispatch ---------------- */

static int jinx_oracle_ext_call_named(
    JinxValue callback,
    JinxValue *args,
    size_t argc,
    JinxValue *result
) {
    char *name;
    int ok = 0;

    if (callback.type != 3u || result == NULL || argc > 64u) return 0;
    name = jinx_oracle_ext_dup_string_value(callback);
    if (name == NULL) return 0;

    *result = jinx_call_builtin_through_oracle_checked(name, args, argc, &ok);
    free(name);
    return ok;
}

static int jinx_oracle_ext_call_user_func(
    JinxValue *args,
    size_t argc,
    JinxValue *result
) {
    if (args == NULL || argc < 1u) return 0;
    return jinx_oracle_ext_call_named(args[0], args + 1u, argc - 1u, result);
}

static int jinx_oracle_ext_call_user_func_array(
    JinxValue *args,
    size_t argc,
    JinxValue *result
) {
    JinxZendArray *array;
    JinxValue call_args[64];
    size_t live;

    if (args == NULL || argc < 2u || !jinx_oracle_value_is_zend_array(args[1])) return 0;
    array = jinx_oracle_zend_array_ptr(args[1]);
    live = jinx_zend_array_live_count(array);
    if (live > 64u) return 0;

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == NULL || !jinx_oracle_zend_to_jinx_borrowed(bucket->value, &call_args[i])) {
            return 0;
        }
    }

    return jinx_oracle_ext_call_named(args[0], call_args, live, result);
}

/* ---------------- date/time carrier ---------------- */

static void jinx_oracle_ext_set_date_error(const char *message) {
    jinx_oracle_ext_date_error = message != NULL && message[0] != '\0';
    if (message == NULL) message = "";
    snprintf(
        jinx_oracle_ext_date_error_message,
        sizeof(jinx_oracle_ext_date_error_message),
        "%s",
        message
    );
}

static int jinx_oracle_ext_tz_enter(const char *timezone, JinxOracleExtTzScope *scope) {
    const char *old;
    if (scope == NULL || timezone == NULL || timezone[0] == '\0') return 0;
    old = getenv("TZ");
    scope->had_old = old != NULL;
    scope->old_tz = old != NULL ? jinx_oracle_ext_strdup(old) : NULL;
    if (setenv("TZ", timezone, 1) != 0) {
        free(scope->old_tz);
        scope->old_tz = NULL;
        return 0;
    }
    tzset();
    return 1;
}

static void jinx_oracle_ext_tz_leave(JinxOracleExtTzScope *scope) {
    if (scope == NULL) return;
    if (scope->had_old && scope->old_tz != NULL) {
        (void)setenv("TZ", scope->old_tz, 1);
    } else {
        (void)unsetenv("TZ");
    }
    tzset();
    free(scope->old_tz);
    scope->old_tz = NULL;
}

static int jinx_oracle_ext_parts_from_timestamp(
    int64_t timestamp,
    const char *timezone,
    struct tm *out
) {
    JinxOracleExtTzScope scope = {0};
    time_t raw = (time_t)timestamp;
    int ok;

    if (out == NULL || !jinx_oracle_ext_tz_enter(timezone, &scope)) return 0;
    ok = localtime_r(&raw, out) != NULL;
    jinx_oracle_ext_tz_leave(&scope);
    return ok;
}

static int jinx_oracle_ext_timestamp_from_parts(
    int year,
    int month,
    int day,
    int hour,
    int minute,
    int second,
    const char *timezone,
    int64_t *timestamp
) {
    JinxOracleExtTzScope scope = {0};
    struct tm tmv;
    time_t raw;

    if (timestamp == NULL || !jinx_oracle_ext_tz_enter(timezone, &scope)) return 0;
    memset(&tmv, 0, sizeof(tmv));
    tmv.tm_year = year - 1900;
    tmv.tm_mon = month - 1;
    tmv.tm_mday = day;
    tmv.tm_hour = hour;
    tmv.tm_min = minute;
    tmv.tm_sec = second;
    tmv.tm_isdst = -1;
    errno = 0;
    raw = mktime(&tmv);
    jinx_oracle_ext_tz_leave(&scope);
    if (raw == (time_t)-1 && errno != 0) return 0;
    *timestamp = (int64_t)raw;
    return 1;
}

static int jinx_oracle_ext_parse_uint(const char **p, int min_digits, int max_digits, int *out) {
    const char *s = *p;
    int value = 0;
    int count = 0;
    while (*s >= '0' && *s <= '9' && count < max_digits) {
        value = value * 10 + (*s - '0');
        s++;
        count++;
    }
    if (count < min_digits) return 0;
    *p = s;
    *out = value;
    return 1;
}

static int jinx_oracle_ext_parse_datetime_format(
    const char *format,
    const char *text,
    const char *timezone,
    int64_t *timestamp,
    struct tm *parsed_tm
) {
    const char *f = format;
    const char *p = text;
    struct tm base;
    time_t now = time(NULL);
    int reset = 0;
    int direct_timestamp = 0;
    int64_t direct_value = 0;
    JinxOracleExtTzScope scope = {0};

    if (format == NULL || text == NULL || timestamp == NULL) return 0;

    if (*f == '!') {
        reset = 1;
        f++;
    }

    memset(&base, 0, sizeof(base));
    if (reset) {
        base.tm_year = 1970 - 1900;
        base.tm_mon = 0;
        base.tm_mday = 1;
    } else {
        if (!jinx_oracle_ext_tz_enter(timezone, &scope)) return 0;
        if (localtime_r(&now, &base) == NULL) {
            jinx_oracle_ext_tz_leave(&scope);
            return 0;
        }
        jinx_oracle_ext_tz_leave(&scope);
    }

    while (*f != '\0') {
        int v = 0;
        if (*f == '\\' && f[1] != '\0') {
            f++;
            if (*p != *f) return 0;
            p++;
            f++;
            continue;
        }

        switch (*f) {
            case 'Y':
                if (!jinx_oracle_ext_parse_uint(&p, 4, 4, &v)) return 0;
                base.tm_year = v - 1900;
                break;
            case 'y':
                if (!jinx_oracle_ext_parse_uint(&p, 2, 2, &v)) return 0;
                base.tm_year = (v >= 70 ? 1900 + v : 2000 + v) - 1900;
                break;
            case 'm':
            case 'd':
            case 'H':
            case 'i':
            case 's':
                if (!jinx_oracle_ext_parse_uint(&p, 2, 2, &v)) return 0;
                if (*f == 'm') base.tm_mon = v - 1;
                if (*f == 'd') base.tm_mday = v;
                if (*f == 'H') base.tm_hour = v;
                if (*f == 'i') base.tm_min = v;
                if (*f == 's') base.tm_sec = v;
                break;
            case 'n':
            case 'j':
            case 'G':
                if (!jinx_oracle_ext_parse_uint(&p, 1, 2, &v)) return 0;
                if (*f == 'n') base.tm_mon = v - 1;
                if (*f == 'j') base.tm_mday = v;
                if (*f == 'G') base.tm_hour = v;
                break;
            case 'U': {
                char *end = NULL;
                long long raw = strtoll(p, &end, 10);
                if (end == p) return 0;
                direct_timestamp = 1;
                direct_value = (int64_t)raw;
                p = end;
                break;
            }
            default:
                if (*p != *f) return 0;
                p++;
                break;
        }
        f++;
    }

    if (*p != '\0') return 0;

    if (direct_timestamp) {
        *timestamp = direct_value;
        if (parsed_tm != NULL) {
            if (!jinx_oracle_ext_parts_from_timestamp(direct_value, timezone, parsed_tm)) {
                memset(parsed_tm, 0, sizeof(*parsed_tm));
            }
        }
        return 1;
    }

    if (!jinx_oracle_ext_timestamp_from_parts(
        base.tm_year + 1900,
        base.tm_mon + 1,
        base.tm_mday,
        base.tm_hour,
        base.tm_min,
        base.tm_sec,
        timezone,
        timestamp
    )) {
        return 0;
    }

    if (parsed_tm != NULL) *parsed_tm = base;
    return 1;
}

static int jinx_oracle_ext_parse_interval_text(
    const char *text,
    JinxOracleExtInterval *out
) {
    const char *p = text;
    int sign = 1;
    int any = 0;

    if (text == NULL || out == NULL) return 0;
    memset(out, 0, sizeof(*out));
    out->total_days = -1;

    if (*p == 'P') {
        int in_time = 0;
        p++;
        while (*p != '\0') {
            if (*p == 'T') {
                in_time = 1;
                p++;
                continue;
            }
            char *end = NULL;
            long v = strtol(p, &end, 10);
            if (end == p || *end == '\0') return 0;
            char unit = *end++;
            if (!in_time && unit == 'Y') out->years = (int)v;
            else if (!in_time && unit == 'M') out->months = (int)v;
            else if (!in_time && unit == 'D') out->days = (int)v;
            else if (in_time && unit == 'H') out->hours = (int)v;
            else if (in_time && unit == 'M') out->minutes = (int)v;
            else if (in_time && unit == 'S') out->seconds = (int)v;
            else return 0;
            any = 1;
            p = end;
        }
        return any;
    }

    while (*p != '\0') {
        long value;
        char *end;
        char unit[24];
        size_t u = 0u;

        while (*p == ' ' || *p == '\t' || *p == ',') p++;
        if (*p == '\0') break;

        if (*p == '+') { sign = 1; p++; }
        else if (*p == '-') { sign = -1; p++; }

        value = strtol(p, &end, 10);
        if (end == p) return 0;
        p = end;
        while (*p == ' ' || *p == '\t') p++;
        while ((*p >= 'A' && *p <= 'Z') || (*p >= 'a' && *p <= 'z')) {
            if (u + 1u < sizeof(unit)) unit[u++] = (char)tolower((unsigned char)*p);
            p++;
        }
        unit[u] = '\0';
        if (u == 0u) return 0;

        if (strncmp(unit, "year", 4) == 0) out->years += sign * (int)value;
        else if (strncmp(unit, "month", 5) == 0) out->months += sign * (int)value;
        else if (strncmp(unit, "week", 4) == 0) out->days += sign * (int)value * 7;
        else if (strncmp(unit, "day", 3) == 0) out->days += sign * (int)value;
        else if (strncmp(unit, "hour", 4) == 0) out->hours += sign * (int)value;
        else if (strncmp(unit, "min", 3) == 0) out->minutes += sign * (int)value;
        else if (strncmp(unit, "sec", 3) == 0) out->seconds += sign * (int)value;
        else return 0;
        any = 1;
        sign = 1;
    }

    return any;
}

static JinxValue jinx_oracle_ext_new_datetime(
    const char *class_name,
    int64_t timestamp,
    const char *timezone
) {
    JinxZendObject *object = jinx_zend_object_new(class_name);
    if (object == NULL) return jinx_oracle_zero_value();
    if (!jinx_oracle_ext_object_set_long(object, "timestamp", timestamp) ||
        !jinx_oracle_ext_object_set_string(object, "timezone", timezone)) {
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxValue jinx_oracle_ext_new_timezone(const char *timezone) {
    JinxZendObject *object = jinx_zend_object_new("DateTimeZone");
    if (object == NULL) return jinx_oracle_zero_value();
    if (!jinx_oracle_ext_object_set_string(object, "timezone", timezone)) {
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxValue jinx_oracle_ext_new_shmop(
    int shmid,
    size_t size,
    int readonly,
    int64_t key
) {
    JinxZendObject *object = jinx_zend_object_new("Shmop");
    if (object == NULL) return jinx_oracle_zero_value();
    if (!jinx_oracle_ext_object_set_long(object, "shmid", (int64_t)shmid) ||
        !jinx_oracle_ext_object_set_long(object, "size", (int64_t)size) ||
        !jinx_oracle_ext_object_set_long(object, "readonly", readonly ? 1 : 0) ||
        !jinx_oracle_ext_object_set_long(object, "key", key)) {
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static int jinx_oracle_ext_shmop_parts(
    JinxValue value,
    JinxOracleExtShmop *parts
) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    int64_t shmid;
    int64_t size;

    if (parts == NULL || object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "Shmop") != 0) {
        return 0;
    }

    shmid = jinx_oracle_ext_object_long(object, "shmid", -1);
    size = jinx_oracle_ext_object_long(object, "size", -1);
    if (shmid < 0 || size < 0) return 0;

    parts->shmid = (int)shmid;
    parts->size = (size_t)size;
    parts->readonly = jinx_oracle_ext_object_long(object, "readonly", 0) != 0;
    parts->key = jinx_oracle_ext_object_long(object, "key", 0);
    return 1;
}

static JinxValue jinx_oracle_ext_new_interval(const JinxOracleExtInterval *parts) {
    JinxZendObject *object = jinx_zend_object_new("DateInterval");
    if (object == NULL || parts == NULL) {
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }

    if (!jinx_oracle_ext_object_set_long(object, "y", parts->years) ||
        !jinx_oracle_ext_object_set_long(object, "m", parts->months) ||
        !jinx_oracle_ext_object_set_long(object, "d", parts->days) ||
        !jinx_oracle_ext_object_set_long(object, "h", parts->hours) ||
        !jinx_oracle_ext_object_set_long(object, "i", parts->minutes) ||
        !jinx_oracle_ext_object_set_long(object, "s", parts->seconds) ||
        !jinx_oracle_ext_object_set_long(object, "invert", parts->invert) ||
        !jinx_oracle_ext_object_set_long(object, "days", parts->total_days)) {
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_object_value_owned(object);
}

static int jinx_oracle_ext_interval_from_value(
    JinxValue value,
    JinxOracleExtInterval *parts
) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "DateInterval") != 0 || parts == NULL) {
        return 0;
    }

    memset(parts, 0, sizeof(*parts));
    parts->years = (int)jinx_oracle_ext_object_long(object, "y", 0);
    parts->months = (int)jinx_oracle_ext_object_long(object, "m", 0);
    parts->days = (int)jinx_oracle_ext_object_long(object, "d", 0);
    parts->hours = (int)jinx_oracle_ext_object_long(object, "h", 0);
    parts->minutes = (int)jinx_oracle_ext_object_long(object, "i", 0);
    parts->seconds = (int)jinx_oracle_ext_object_long(object, "s", 0);
    parts->invert = (int)jinx_oracle_ext_object_long(object, "invert", 0);
    parts->total_days = jinx_oracle_ext_object_long(object, "days", -1);
    return 1;
}

static int jinx_oracle_ext_datetime_parts(
    JinxValue value,
    JinxZendObject **object,
    int64_t *timestamp,
    const char **timezone
) {
    JinxZendObject *obj = jinx_oracle_zend_object_ptr(value);
    if (obj == NULL || obj->class_name == NULL ||
        (strcmp(obj->class_name, "DateTime") != 0 &&
         strcmp(obj->class_name, "DateTimeImmutable") != 0)) {
        return 0;
    }
    if (object != NULL) *object = obj;
    if (timestamp != NULL) *timestamp = jinx_oracle_ext_object_long(obj, "timestamp", 0);
    if (timezone != NULL) *timezone = jinx_oracle_ext_object_string(
        obj, "timezone", jinx_oracle_ext_default_timezone
    );
    return 1;
}

static int jinx_oracle_ext_timezone_from_value(JinxValue value, const char **timezone) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "DateTimeZone") != 0) {
        return 0;
    }
    if (timezone != NULL) {
        *timezone = jinx_oracle_ext_object_string(
            object, "timezone", jinx_oracle_ext_default_timezone
        );
    }
    return 1;
}

static int jinx_oracle_ext_apply_interval(
    int64_t input,
    const char *timezone,
    const JinxOracleExtInterval *parts,
    int direction,
    int64_t *output
) {
    struct tm tmv;
    JinxOracleExtTzScope scope = {0};
    time_t raw;
    int mul;

    if (parts == NULL || output == NULL ||
        !jinx_oracle_ext_parts_from_timestamp(input, timezone, &tmv)) {
        return 0;
    }

    mul = direction * (parts->invert ? -1 : 1);
    tmv.tm_year += mul * parts->years;
    tmv.tm_mon += mul * parts->months;
    tmv.tm_mday += mul * parts->days;
    tmv.tm_hour += mul * parts->hours;
    tmv.tm_min += mul * parts->minutes;
    tmv.tm_sec += mul * parts->seconds;
    tmv.tm_isdst = -1;

    if (!jinx_oracle_ext_tz_enter(timezone, &scope)) return 0;
    errno = 0;
    raw = mktime(&tmv);
    jinx_oracle_ext_tz_leave(&scope);
    if (raw == (time_t)-1 && errno != 0) return 0;
    *output = (int64_t)raw;
    return 1;
}

static int jinx_oracle_ext_parse_datetime_text_at(
    const char *text,
    const char *timezone,
    int64_t base_timestamp,
    int64_t *timestamp
) {
    int y, m, d, hh = 0, mm = 0, ss = 0;
    JinxOracleExtInterval interval;

    if (text == NULL || timestamp == NULL) return 0;
    if (text[0] == '\0' || strcasecmp(text, "now") == 0) {
        *timestamp = base_timestamp;
        return 1;
    }
    if (text[0] == '@') {
        char *end = NULL;
        long long raw = strtoll(text + 1, &end, 10);
        if (end == text + 1 || *end != '\0') return 0;
        *timestamp = (int64_t)raw;
        return 1;
    }

    if (sscanf(text, "%d-%d-%dT%d:%d:%d", &y, &m, &d, &hh, &mm, &ss) == 6 ||
        sscanf(text, "%d-%d-%d %d:%d:%d", &y, &m, &d, &hh, &mm, &ss) == 6 ||
        sscanf(text, "%d-%d-%d %d:%d", &y, &m, &d, &hh, &mm) == 5 ||
        sscanf(text, "%d-%d-%d", &y, &m, &d) == 3) {
        return jinx_oracle_ext_timestamp_from_parts(y, m, d, hh, mm, ss, timezone, timestamp);
    }

    if (jinx_oracle_ext_parse_interval_text(text, &interval)) {
        return jinx_oracle_ext_apply_interval(
            base_timestamp, timezone, &interval, 1, timestamp
        );
    }

    return 0;
}

static int jinx_oracle_ext_parse_datetime_text(
    const char *text,
    const char *timezone,
    int64_t *timestamp
) {
    return jinx_oracle_ext_parse_datetime_text_at(
        text, timezone, (int64_t)time(NULL), timestamp
    );
}

static int jinx_oracle_ext_timezone_valid(const char *timezone) {
    char path[512];
    if (timezone == NULL || timezone[0] == '\0') return 0;
    if (strcmp(timezone, "UTC") == 0 || strcmp(timezone, "GMT") == 0) return 1;
    if (strstr(timezone, "..") != NULL) return 0;
    snprintf(path, sizeof(path), "/usr/share/zoneinfo/%s", timezone);
    return access(path, R_OK) == 0;
}

static int jinx_oracle_ext_timezone_offset_seconds(int64_t timestamp, const char *timezone) {
    struct tm tmv;
    JinxOracleExtTzScope scope = {0};
    time_t raw = (time_t)timestamp;
    char buf[16];
    int sign = 1;
    int hh = 0;
    int mm = 0;

    if (!jinx_oracle_ext_tz_enter(timezone, &scope)) return 0;
    if (localtime_r(&raw, &tmv) == NULL) {
        jinx_oracle_ext_tz_leave(&scope);
        return 0;
    }
    if (strftime(buf, sizeof(buf), "%z", &tmv) == 0u) {
        jinx_oracle_ext_tz_leave(&scope);
        return 0;
    }
    jinx_oracle_ext_tz_leave(&scope);

    if (buf[0] == '-') sign = -1;
    if (sscanf(buf + 1, "%2d%2d", &hh, &mm) != 2) return 0;
    return sign * (hh * 3600 + mm * 60);
}


static void jinx_oracle_ext_append_text(char *out, size_t cap, size_t *pos, const char *text) {
    size_t n;
    if (out == NULL || pos == NULL || text == NULL || *pos >= cap) return;
    n = strlen(text);
    if (n > cap - 1u - *pos) n = cap - 1u - *pos;
    if (n != 0u) memcpy(out + *pos, text, n);
    *pos += n;
    out[*pos] = '\0';
}

static void jinx_oracle_ext_append_num(char *out, size_t cap, size_t *pos, const char *fmt, int value) {
    char tmp[64];
    snprintf(tmp, sizeof(tmp), fmt, value);
    jinx_oracle_ext_append_text(out, cap, pos, tmp);
}

static JinxValue jinx_oracle_ext_date_format_value(
    int64_t timestamp,
    const char *timezone,
    JinxValue format_value
) {
    struct tm tmv;
    const unsigned char *fmt;
    uint32_t len;
    char out[2048];
    size_t pos = 0u;
    char tmp[256];

    if (!jinx_oracle_ext_parts_from_timestamp(timestamp, timezone, &tmv) ||
        format_value.type != 3u) {
        return jinx_oracle_zero_value();
    }

    fmt = jinx_oracle_string_bytes(format_value);
    len = jinx_oracle_string_len(format_value);
    out[0] = '\0';

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = fmt[i];
        if (ch == '\\' && i + 1u < len) {
            tmp[0] = (char)fmt[++i];
            tmp[1] = '\0';
            jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
            continue;
        }

        switch (ch) {
            case 'Y': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%04d", tmv.tm_year + 1900); break;
            case 'y': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", (tmv.tm_year + 1900) % 100); break;
            case 'm': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", tmv.tm_mon + 1); break;
            case 'n': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", tmv.tm_mon + 1); break;
            case 'd': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", tmv.tm_mday); break;
            case 'j': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", tmv.tm_mday); break;
            case 'H': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", tmv.tm_hour); break;
            case 'G': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", tmv.tm_hour); break;
            case 'h': {
                int h = tmv.tm_hour % 12; if (h == 0) h = 12;
                jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", h); break;
            }
            case 'g': {
                int h = tmv.tm_hour % 12; if (h == 0) h = 12;
                jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", h); break;
            }
            case 'i': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", tmv.tm_min); break;
            case 's': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%02d", tmv.tm_sec); break;
            case 'U': snprintf(tmp, sizeof(tmp), "%lld", (long long)timestamp); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
            case 'w': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", tmv.tm_wday); break;
            case 'N': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", tmv.tm_wday == 0 ? 7 : tmv.tm_wday); break;
            case 'z': jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", tmv.tm_yday); break;
            case 'L': {
                int y = tmv.tm_year + 1900;
                int leap = (y % 4 == 0 && (y % 100 != 0 || y % 400 == 0));
                jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", leap); break;
            }
            case 't': {
                static const int mdays[] = {31,28,31,30,31,30,31,31,30,31,30,31};
                int y = tmv.tm_year + 1900;
                int days = mdays[tmv.tm_mon];
                if (tmv.tm_mon == 1 && y % 4 == 0 && (y % 100 != 0 || y % 400 == 0)) days = 29;
                jinx_oracle_ext_append_num(out, sizeof(out), &pos, "%d", days); break;
            }
            case 'a': jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmv.tm_hour < 12 ? "am" : "pm"); break;
            case 'A': jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmv.tm_hour < 12 ? "AM" : "PM"); break;
            case 'D': strftime(tmp, sizeof(tmp), "%a", &tmv); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
            case 'l': strftime(tmp, sizeof(tmp), "%A", &tmv); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
            case 'M': strftime(tmp, sizeof(tmp), "%b", &tmv); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
            case 'F': strftime(tmp, sizeof(tmp), "%B", &tmv); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
            case 'T': {
                JinxOracleExtTzScope scope = {0};
                if (jinx_oracle_ext_tz_enter(timezone, &scope)) {
                    strftime(tmp, sizeof(tmp), "%Z", &tmv);
                    jinx_oracle_ext_tz_leave(&scope);
                    jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                }
                break;
            }
            case 'e': jinx_oracle_ext_append_text(out, sizeof(out), &pos, timezone); break;
            case 'O':
            case 'P': {
                int offset = jinx_oracle_ext_timezone_offset_seconds(timestamp, timezone);
                int sign = offset < 0 ? -1 : 1;
                int total = offset < 0 ? -offset : offset;
                int hh = total / 3600;
                int mm = (total / 60) % 60;
                snprintf(tmp, sizeof(tmp), ch == 'P' ? "%c%02d:%02d" : "%c%02d%02d", sign < 0 ? '-' : '+', hh, mm);
                jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                break;
            }
            case 'c': {
                int offset = jinx_oracle_ext_timezone_offset_seconds(timestamp, timezone);
                int total = offset < 0 ? -offset : offset;
                snprintf(tmp, sizeof(tmp), "%04d-%02d-%02dT%02d:%02d:%02d%c%02d:%02d",
                    tmv.tm_year + 1900, tmv.tm_mon + 1, tmv.tm_mday,
                    tmv.tm_hour, tmv.tm_min, tmv.tm_sec,
                    offset < 0 ? '-' : '+', total / 3600, (total / 60) % 60);
                jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                break;
            }
            case 'r': {
                int offset = jinx_oracle_ext_timezone_offset_seconds(timestamp, timezone);
                int total = offset < 0 ? -offset : offset;
                char day[8], mon[8];
                strftime(day, sizeof(day), "%a", &tmv);
                strftime(mon, sizeof(mon), "%b", &tmv);
                snprintf(tmp, sizeof(tmp), "%s, %02d %s %04d %02d:%02d:%02d %c%02d%02d",
                    day, tmv.tm_mday, mon, tmv.tm_year + 1900,
                    tmv.tm_hour, tmv.tm_min, tmv.tm_sec,
                    offset < 0 ? '-' : '+', total / 3600, (total / 60) % 60);
                jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                break;
            }
            default:
                tmp[0] = (char)ch;
                tmp[1] = '\0';
                jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                break;
        }
    }

    return jinx_oracle_ext_copy_string(out, pos);
}

static JinxZendArray *jinx_oracle_ext_date_parse_array(
    const char *format,
    const char *text,
    int use_format
) {
    int64_t timestamp = 0;
    struct tm tmv;
    int ok;
    JinxZendArray *result;
    JinxZendArray *warnings;
    JinxZendArray *errors;

    memset(&tmv, 0, sizeof(tmv));
    ok = use_format
        ? jinx_oracle_ext_parse_datetime_format(
            format, text, jinx_oracle_ext_default_timezone, &timestamp, &tmv
        )
        : jinx_oracle_ext_parse_datetime_text(
            text, jinx_oracle_ext_default_timezone, &timestamp
        );

    if (ok && !use_format) {
        (void)jinx_oracle_ext_parts_from_timestamp(
            timestamp, jinx_oracle_ext_default_timezone, &tmv
        );
    }

    jinx_oracle_ext_set_date_error(ok ? "" : "Failed to parse time string");

    result = jinx_zend_array_new_packed(12u);
    warnings = jinx_zend_array_new_packed(1u);
    errors = jinx_zend_array_new_packed(1u);
    if (result == NULL || warnings == NULL || errors == NULL) {
        jinx_zend_array_release(result);
        jinx_zend_array_release(warnings);
        jinx_zend_array_release(errors);
        return NULL;
    }

    jinx_zend_array_add_assoc(result, "year", 4u, ok ? jinx_zend_long(tmv.tm_year + 1900) : jinx_zend_bool(0));
    jinx_zend_array_add_assoc(result, "month", 5u, ok ? jinx_zend_long(tmv.tm_mon + 1) : jinx_zend_bool(0));
    jinx_zend_array_add_assoc(result, "day", 3u, ok ? jinx_zend_long(tmv.tm_mday) : jinx_zend_bool(0));
    jinx_zend_array_add_assoc(result, "hour", 4u, ok ? jinx_zend_long(tmv.tm_hour) : jinx_zend_bool(0));
    jinx_zend_array_add_assoc(result, "minute", 6u, ok ? jinx_zend_long(tmv.tm_min) : jinx_zend_bool(0));
    jinx_zend_array_add_assoc(result, "second", 6u, ok ? jinx_zend_long(tmv.tm_sec) : jinx_zend_bool(0));
    jinx_zend_array_add_assoc(result, "fraction", 8u, jinx_zend_double(0.0));
    jinx_zend_array_add_assoc(result, "warning_count", 13u, jinx_zend_long(0));
    jinx_oracle_ext_array_add_assoc_array(result, "warnings", warnings);
    jinx_zend_array_add_assoc(result, "error_count", 11u, jinx_zend_long(ok ? 0 : 1));
    if (!ok) {
        jinx_oracle_ext_array_add_assoc_string(errors, "0", jinx_oracle_ext_date_error_message);
    }
    jinx_oracle_ext_array_add_assoc_array(result, "errors", errors);
    jinx_zend_array_add_assoc(result, "is_localtime", 12u, jinx_zend_bool(0));

    jinx_zend_array_release(warnings);
    jinx_zend_array_release(errors);
    return result;
}

/* ---------------- filesystem/stream carrier ---------------- */

static JinxZendArray *jinx_oracle_ext_stat_array(const struct stat *st) {
    static const char *const names[13] = {
        "dev","ino","mode","nlink","uid","gid","rdev","size",
        "atime","mtime","ctime","blksize","blocks"
    };
    int64_t values[13];
    JinxZendArray *array;

    if (st == NULL) return NULL;
    values[0]=(int64_t)st->st_dev;
    values[1]=(int64_t)st->st_ino;
    values[2]=(int64_t)st->st_mode;
    values[3]=(int64_t)st->st_nlink;
    values[4]=(int64_t)st->st_uid;
    values[5]=(int64_t)st->st_gid;
    values[6]=(int64_t)st->st_rdev;
    values[7]=(int64_t)st->st_size;
    values[8]=(int64_t)st->st_atime;
    values[9]=(int64_t)st->st_mtime;
    values[10]=(int64_t)st->st_ctime;
#ifdef st_blksize
    values[11]=(int64_t)st->st_blksize;
    values[12]=(int64_t)st->st_blocks;
#else
    values[11]=0;
    values[12]=0;
#endif
    array=jinx_zend_array_new_packed(26u);
    if(array==NULL) return NULL;
    for(size_t i=0u;i<13u;i++){
        if(!jinx_zend_array_add_index(array,i,jinx_zend_long(values[i])) ||
           !jinx_zend_array_add_assoc(array,names[i],strlen(names[i]),jinx_zend_long(values[i]))){
            jinx_zend_array_release(array);
            return NULL;
        }
    }
    return array;
}

static JinxZendArray *jinx_oracle_ext_string_list_from_dir(const char *path, int order) {
    struct dirent **entries = NULL;
    int count;
    JinxZendArray *array;

    if(path==NULL) return NULL;
    count=scandir(path,&entries,NULL,alphasort);
    if(count<0) return NULL;

    array=jinx_zend_array_new_packed(count==0?1u:(size_t)count);
    if(array==NULL){
        for(int i=0;i<count;i++) free(entries[i]);
        free(entries);
        return NULL;
    }

    if(order==1){
        for(int i=count-1;i>=0;i--){
            if(!jinx_oracle_ext_array_append_string(array,entries[i]->d_name,strlen(entries[i]->d_name))){
                for(int j=0;j<count;j++) free(entries[j]);
                free(entries);
                jinx_zend_array_release(array);
                return NULL;
            }
        }
    } else {
        for(int i=0;i<count;i++){
            if(!jinx_oracle_ext_array_append_string(array,entries[i]->d_name,strlen(entries[i]->d_name))){
                for(int j=0;j<count;j++) free(entries[j]);
                free(entries);
                jinx_zend_array_release(array);
                return NULL;
            }
        }
    }

    for(int i=0;i<count;i++) free(entries[i]);
    free(entries);
    return array;
}

static JinxOracleExtStream *jinx_oracle_ext_stream_from_value(
    JinxValue value,
    JinxZendValue **slot
) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *property;

    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "stream") != 0) {
        return NULL;
    }

    property = jinx_oracle_ext_object_prop(object, "__stream");
    if (property == NULL || property->type != JINX_ZEND_RESOURCE || property->value.ptr == NULL) {
        return NULL;
    }

    if (slot != NULL) *slot = property;
    return (JinxOracleExtStream *)property->value.ptr;
}

static JinxValue jinx_oracle_ext_new_stream_kind(FILE *fp, int process_pipe) {
    JinxZendObject *object;
    JinxOracleExtStream *stream;

    if (fp == NULL) return jinx_oracle_zero_value();
    stream = (JinxOracleExtStream *)calloc(1u, sizeof(*stream));
    if (stream == NULL) {
        if (process_pipe) (void)pclose(fp);
        else fclose(fp);
        return jinx_oracle_zero_value();
    }
    stream->fp = fp;
    stream->process_pipe = process_pipe;

    object = jinx_zend_object_new("stream");
    if (object == NULL || !jinx_oracle_ext_object_set_resource(object, "__stream", stream)) {
        if (process_pipe) (void)pclose(fp);
        else fclose(fp);
        free(stream);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    if (jinx_oracle_resource_register(stream, "stream") == 0) {
        if (process_pipe) (void)pclose(fp);
        else fclose(fp);
        free(stream);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxValue jinx_oracle_ext_new_stream(FILE *fp) {
    return jinx_oracle_ext_new_stream_kind(fp, 0);
}

static JinxValue jinx_oracle_ext_new_process_stream(FILE *fp) {
    return jinx_oracle_ext_new_stream_kind(fp, 1);
}

static JinxZendArray *jinx_oracle_ext_parse_csv_line(
    const char *line,
    size_t len,
    char delimiter,
    char enclosure,
    char escape
) {
    JinxZendArray *result = jinx_zend_array_new_packed(8u);
    size_t i = 0u;
    if (result == NULL) return NULL;

    while (i <= len) {
        char *field = (char *)malloc(len - i + 1u);
        size_t pos = 0u;
        int quoted = 0;

        if (field == NULL) {
            jinx_zend_array_release(result);
            return NULL;
        }

        if (i < len && line[i] == enclosure) {
            quoted = 1;
            i++;
        }

        while (i < len) {
            char ch = line[i];
            if (quoted) {
                if (ch == enclosure) {
                    if (i + 1u < len && line[i + 1u] == enclosure) {
                        field[pos++] = enclosure;
                        i += 2u;
                        continue;
                    }
                    quoted = 0;
                    i++;
                    if (i < len && line[i] == delimiter) i++;
                    break;
                }
                if (escape != '\0' && ch == escape && i + 1u < len) {
                    field[pos++] = line[i + 1u];
                    i += 2u;
                    continue;
                }
                field[pos++] = ch;
                i++;
            } else {
                if (ch == delimiter) {
                    i++;
                    break;
                }
                field[pos++] = ch;
                i++;
            }
        }

        field[pos] = '\0';
        if (!jinx_oracle_ext_array_append_string(result, field, pos)) {
            free(field);
            jinx_zend_array_release(result);
            return NULL;
        }
        free(field);

        if (i >= len) {
            if (len != 0u && line[len - 1u] == delimiter) {
                if (!jinx_oracle_ext_array_append_string(result, "", 0u)) {
                    jinx_zend_array_release(result);
                    return NULL;
                }
            }
            break;
        }
    }

    return result;
}

static JinxValue jinx_oracle_ext_file_get_contents(
    JinxValue *args,
    size_t argc,
    int *ok
) {
    char *path = NULL;
    FILE *fp = NULL;
    long size;
    int64_t offset = argc >= 4u ? jinx_oracle_intish(args[3]) : 0;
    int64_t requested = argc >= 5u && args[4].type != 0u ? jinx_oracle_intish(args[4]) : -1;
    size_t take;
    char *out;
    size_t read_count;

    *ok = 0;
    if (argc < 1u || args[0].type != 3u) return jinx_oracle_zero_value();
    path = jinx_oracle_ext_dup_string_value(args[0]);
    if (path == NULL) return jinx_oracle_zero_value();
    fp = fopen(path, "rb");
    free(path);
    if (fp == NULL) {
        *ok = 1;
        return jinx_oracle_bool_value(0);
    }

    if (fseek(fp, 0, SEEK_END) != 0 || (size = ftell(fp)) < 0) {
        fclose(fp);
        *ok = 1;
        return jinx_oracle_bool_value(0);
    }

    if (offset < 0) offset = size + offset;
    if (offset < 0 || offset > size || fseek(fp, (long)offset, SEEK_SET) != 0) {
        fclose(fp);
        *ok = 1;
        return jinx_oracle_bool_value(0);
    }

    take = (size_t)(size - (long)offset);
    if (requested >= 0 && (uint64_t)requested < (uint64_t)take) take = (size_t)requested;
    if (take > UINT32_MAX) {
        fclose(fp);
        return jinx_oracle_zero_value();
    }

    out = jinx_oracle_scratch_string((uint32_t)take);
    read_count = take == 0u ? 0u : fread(out, 1u, take, fp);
    {
        int read_error = ferror(fp);
        fclose(fp);
        if (read_count != take && read_error) {
            *ok = 1;
            return jinx_oracle_bool_value(0);
        }
    }

    *ok = 1;
    return jinx_oracle_string_value_len(out, (uint32_t)read_count);
}

/* ---------------- generated class/constant metadata ---------------- */

static const char *jinx_oracle_ext_alias_target(const char *name) {
    for (size_t i = 0u; i < jinx_oracle_ext_alias_count; i++) {
        if (strcasecmp(jinx_oracle_ext_alias_names[i], name) == 0) {
            return jinx_oracle_ext_alias_targets[i];
        }
    }
    return name;
}

static const JinxNativeClassMeta *jinx_oracle_ext_class_meta(const char *name) {
    const char *resolved;
    if (name == NULL) return NULL;
    while (*name == '\\') name++;
    resolved = jinx_oracle_ext_alias_target(name);
    for (size_t i = 0u; i < jinx_native_class_metadata_count; i++) {
        if (strcasecmp(jinx_native_class_metadata[i].name, resolved) == 0) {
            return &jinx_native_class_metadata[i];
        }
    }
    return NULL;
}

static int jinx_oracle_ext_class_has_method(
    const JinxNativeClassMeta *meta,
    const char *method_name
) {
    if (meta == NULL || method_name == NULL) return 0;
    for (size_t i = 0u; i < meta->method_count; i++) {
        if (meta->methods[i] != NULL &&
            strcasecmp(meta->methods[i], method_name) == 0) {
            return 1;
        }
    }
    return 0;
}

static int jinx_oracle_ext_class_has_property(
    const JinxNativeClassMeta *meta,
    const char *property_name
) {
    if (meta == NULL || property_name == NULL) return 0;

    for (size_t i = 0u; i < meta->property_count; i++) {
        if (meta->properties[i] != NULL &&
            strcmp(meta->properties[i], property_name) == 0) {
            return 1;
        }
    }

    for (size_t i = 0u; i < meta->parent_count; i++) {
        const JinxNativeClassMeta *parent =
            jinx_oracle_ext_class_meta(meta->parents[i]);
        if (jinx_oracle_ext_class_has_property(parent, property_name)) {
            return 1;
        }
    }

    return 0;
}

static int jinx_oracle_ext_class_implements_name(
    const JinxNativeClassMeta *meta,
    const char *interface_name
) {
    if (meta == NULL || interface_name == NULL) return 0;
    for (size_t i = 0u; i < meta->implements_count; i++) {
        if (meta->implements[i] != NULL &&
            strcasecmp(meta->implements[i], interface_name) == 0) {
            return 1;
        }
    }
    return 0;
}

static int jinx_oracle_ext_class_is_a_name(
    const char *instance_name,
    const char *target_name,
    int only_subclass,
    int *known
) {
    const JinxNativeClassMeta *meta;
    if (known != NULL) *known = 0;
    if (instance_name == NULL || target_name == NULL) return 0;
    while (*instance_name == '\\') instance_name++;
    while (*target_name == '\\') target_name++;

    if (strcasecmp(instance_name, target_name) == 0) {
        if (known != NULL) *known = 1;
        return only_subclass ? 0 : 1;
    }

    meta = jinx_oracle_ext_class_meta(instance_name);
    if (meta == NULL) return 0;

    for (size_t i = 0u; i < meta->parent_count; i++) {
        if (meta->parents[i] != NULL &&
            strcasecmp(meta->parents[i], target_name) == 0) {
            if (known != NULL) *known = 1;
            return 1;
        }
    }
    for (size_t i = 0u; i < meta->implements_count; i++) {
        if (meta->implements[i] != NULL &&
            strcasecmp(meta->implements[i], target_name) == 0) {
            if (known != NULL) *known = 1;
            return 1;
        }
    }

    if (known != NULL) *known = 1;
    return 0;
}

static const JinxNativeConstantMeta *jinx_oracle_ext_constant_meta(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        if (strcmp(jinx_native_constant_metadata[i].name, name) == 0) {
            return &jinx_native_constant_metadata[i];
        }
    }
    return NULL;
}

static JinxValue jinx_oracle_ext_constant_value(const JinxNativeConstantMeta *meta) {
    if (meta == NULL) return jinx_oracle_zero_value();
    if (meta->type == 1u) return jinx_oracle_int_value((int64_t)meta->i64);
    if (meta->type == 2u) return jinx_oracle_bool_value(meta->i64 != 0);
    if (meta->type == 3u) return jinx_oracle_string_value(meta->str != NULL ? meta->str : "");
    if (meta->type == 5u) return jinx_oracle_float_value(meta->f64);
    return jinx_oracle_zero_value();
}

static JinxValue jinx_oracle_ext_class_list(
    const char *class_name,
    int which,
    int *ok
) {
    const JinxNativeClassMeta *meta = jinx_oracle_ext_class_meta(class_name);
    const char *const *items = NULL;
    size_t count = 0u;
    JinxZendArray *array;

    if (meta == NULL) {
        *ok = 1;
        return jinx_oracle_bool_value(0);
    }

    if (which == 0) { items = meta->implements; count = meta->implements_count; }
    else if (which == 1) { items = meta->parents; count = meta->parent_count; }
    else { items = meta->uses; count = meta->uses_count; }

    array = jinx_zend_array_new_packed(count == 0u ? 1u : count);
    if (array == NULL) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < count; i++) {
        JinxZendString *string = jinx_zend_string_new(items[i], strlen(items[i]));
        int added;
        if (string == NULL) {
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
        added = jinx_zend_array_add_assoc(
            array, items[i], strlen(items[i]), jinx_zend_string_value(string)
        );
        jinx_zend_string_release(string);
        if (!added) {
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
    }

    *ok = 1;
    return jinx_oracle_zend_array_value_owned(array);
}

/* ---------------- public fixture factory ---------------- */

JinxValue jinx_oracle_extended_fixture(const char *spec) {
    if (spec == NULL) return jinx_oracle_zero_value();

    if (strncmp(spec, "obj:", 4u) == 0) {
        JinxZendObject *object = jinx_zend_object_new(spec + 4u);
        return object != NULL
            ? jinx_oracle_zend_object_value_owned(object)
            : jinx_oracle_zero_value();
    }

    if (strncmp(spec, "ex:", 3u) == 0) {
        const char *class_name = spec + 3u;
        int known = 0;
        JinxZendObject *object;

        if (!jinx_oracle_ext_class_is_a_name(
            class_name, "Throwable", 0, &known
        ) || !known) {
            return jinx_oracle_zero_value();
        }

        object = jinx_zend_object_new(class_name);
        if (object == NULL ||
            !jinx_oracle_ext_object_set_string(
                object, "message", "jinx-message"
            ) ||
            !jinx_oracle_ext_object_set_long(object, "code", 73) ||
            !jinx_oracle_ext_object_set_string(
                object, "file", "jinx-fixture.php"
            ) ||
            !jinx_oracle_ext_object_set_long(object, "line", 123) ||
            !jinx_oracle_ext_object_set_string(
                object, "trace_string", "#0 {main}"
            )) {
            jinx_zend_object_release(object);
            return jinx_oracle_zero_value();
        }

        return jinx_oracle_zend_object_value_owned(object);
    }

    if (strncmp(spec, "dt:", 3u) == 0) {
        int64_t timestamp;
        if (!jinx_oracle_ext_parse_datetime_text(
            spec + 3u, jinx_oracle_ext_default_timezone, &timestamp
        )) return jinx_oracle_zero_value();
        return jinx_oracle_ext_new_datetime(
            "DateTime", timestamp, jinx_oracle_ext_default_timezone
        );
    }

    if (strncmp(spec, "dti:", 4u) == 0) {
        int64_t timestamp;
        if (!jinx_oracle_ext_parse_datetime_text(
            spec + 4u, jinx_oracle_ext_default_timezone, &timestamp
        )) return jinx_oracle_zero_value();
        return jinx_oracle_ext_new_datetime(
            "DateTimeImmutable", timestamp, jinx_oracle_ext_default_timezone
        );
    }

    if (strncmp(spec, "tz:", 3u) == 0) {
        return jinx_oracle_ext_new_timezone(spec + 3u);
    }

    if (strncmp(spec, "di:", 3u) == 0) {
        JinxOracleExtInterval interval;
        if (!jinx_oracle_ext_parse_interval_text(spec + 3u, &interval)) {
            return jinx_oracle_zero_value();
        }
        return jinx_oracle_ext_new_interval(&interval);
    }

    if (strcmp(spec, "fp:tmp") == 0) {
        FILE *fp = tmpfile();
        if (fp == NULL) return jinx_oracle_zero_value();
        fputs("a,b\nsecond line\n", fp);
        rewind(fp);
        return jinx_oracle_ext_new_stream(fp);
    }

    if (strcmp(spec, "pp:tmp") == 0) {
        /*
         * Deterministic pclose fixture: do not leave unread child output in
         * the pipe, because an immediate close can race the writer into
         * SIGPIPE and make the exit status platform/scheduler dependent.
         */
        FILE *fp = popen("true", "r");
        return fp != NULL
            ? jinx_oracle_ext_new_process_stream(fp)
            : jinx_oracle_zero_value();
    }

    {
        JinxValue batch2 = jinx_oracle_batch2_fixture(spec);
        if (batch2.type != 0u) return batch2;
    }

    return jinx_oracle_zero_value();
}

/* ---------------- main extended builtin dispatcher ---------------- */

JinxValue jinx_oracle_extended_builtin_with_context(
    JinxOracleAsmContext *ctx,
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    if (handled != NULL) *handled = 0;
    return jinx_oracle_batch2_builtin_with_context(
        ctx, name, args, argc, handled
    );
}

JinxValue jinx_oracle_extended_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    int ok = 0;

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    /*
     * Date/time constructors and static factories. Method wrappers present the
     * native receiver as args[0]; user-supplied arguments begin at args[1].
     */
    if (strcmp(name, "DateTime::__construct") == 0 ||
        strcmp(name, "DateTimeImmutable::__construct") == 0) {
        JinxZendObject *object;
        const char *timezone = jinx_oracle_ext_default_timezone;
        char *text = NULL;
        int64_t timestamp;

        if (args == NULL || argc < 1u || argc > 3u) return result;
        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL) return result;

        if (argc >= 2u && args[1].type != 0u) {
            if (args[1].type != 3u) return result;
            text = jinx_oracle_ext_dup_string_value(args[1]);
        } else {
            text = jinx_oracle_ext_strdup("now");
        }
        if (text == NULL) return result;

        if (argc >= 3u && args[2].type != 0u &&
            !jinx_oracle_ext_timezone_from_value(args[2], &timezone)) {
            free(text);
            return result;
        }

        ok = jinx_oracle_ext_parse_datetime_text(text, timezone, &timestamp);
        free(text);
        if (!ok ||
            !jinx_oracle_ext_object_set_long(object, "timestamp", timestamp) ||
            !jinx_oracle_ext_object_set_string(object, "timezone", timezone)) {
            return result;
        }

        jinx_oracle_ext_set_date_error("");
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "DateTimeZone::__construct") == 0) {
        JinxZendObject *object;
        char *timezone;

        if (args == NULL || argc != 2u || args[1].type != 3u) return result;
        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL) return result;
        timezone = jinx_oracle_ext_dup_string_value(args[1]);
        if (timezone == NULL) return result;
        ok = jinx_oracle_ext_timezone_valid(timezone) &&
            jinx_oracle_ext_object_set_string(object, "timezone", timezone);
        free(timezone);
        if (!ok) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "DateInterval::__construct") == 0) {
        JinxZendObject *object;
        JinxOracleExtInterval interval;
        char *spec;

        if (args == NULL || argc != 2u || args[1].type != 3u) return result;
        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL) return result;
        spec = jinx_oracle_ext_dup_string_value(args[1]);
        if (spec == NULL) return result;
        ok = jinx_oracle_ext_parse_interval_text(spec, &interval);
        free(spec);
        if (!ok ||
            !jinx_oracle_ext_object_set_long(object, "y", interval.years) ||
            !jinx_oracle_ext_object_set_long(object, "m", interval.months) ||
            !jinx_oracle_ext_object_set_long(object, "d", interval.days) ||
            !jinx_oracle_ext_object_set_long(object, "h", interval.hours) ||
            !jinx_oracle_ext_object_set_long(object, "i", interval.minutes) ||
            !jinx_oracle_ext_object_set_long(object, "s", interval.seconds) ||
            !jinx_oracle_ext_object_set_long(object, "invert", interval.invert) ||
            !jinx_oracle_ext_object_set_long(object, "days", interval.total_days)) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "DateTime::createFromFormat") == 0 ||
        strcmp(name, "DateTimeImmutable::createFromFormat") == 0) {
        const char *alias =
            strcmp(name, "DateTimeImmutable::createFromFormat") == 0
                ? "date_create_immutable_from_format"
                : "date_create_from_format";
        if (args == NULL || argc < 3u) return result;
        return jinx_oracle_extended_builtin(
            alias, args + 1u, argc - 1u, handled
        );
    }

    if (strcmp(name, "DateTime::getLastErrors") == 0 ||
        strcmp(name, "DateTimeImmutable::getLastErrors") == 0) {
        if (args == NULL || argc != 1u ||
            !jinx_oracle_value_is_zend_object(args[0])) {
            return result;
        }
        return jinx_oracle_extended_builtin(
            "date_get_last_errors", NULL, 0u, handled
        );
    }

    if (strcmp(name, "DateInterval::createFromDateString") == 0) {
        if (args == NULL || argc != 2u) return result;
        return jinx_oracle_extended_builtin(
            "date_interval_create_from_date_string",
            args + 1u,
            1u,
            handled
        );
    }

    if (strcmp(name, "DateTime::createFromInterface") == 0 ||
        strcmp(name, "DateTimeImmutable::createFromInterface") == 0 ||
        strcmp(name, "DateTime::createFromImmutable") == 0) {
        int64_t timestamp;
        const char *timezone;
        const char *target_class =
            strcmp(name, "DateTimeImmutable::createFromInterface") == 0
                ? "DateTimeImmutable"
                : "DateTime";
        if (args == NULL || argc != 2u ||
            !jinx_oracle_ext_datetime_parts(
                args[1], NULL, &timestamp, &timezone
            )) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_ext_new_datetime(
            target_class, timestamp, timezone
        );
    }

    if (strcmp(name, "DateTime::format") == 0 ||
        strcmp(name, "DateTimeImmutable::format") == 0) {
        int64_t timestamp;
        const char *timezone;
        if (args == NULL || argc != 2u || args[1].type != 3u) return result;
        if (!jinx_oracle_ext_datetime_parts(
            args[0], NULL, &timestamp, &timezone
        )) return result;
        result = jinx_oracle_ext_date_format_value(timestamp, timezone, args[1]);
        if (result.type == 0u) return result;
        if (handled != NULL) *handled = 1;
        return result;
    }

    {
        const char *procedural_alias = NULL;
        if (strcmp(name, "DateTime::getTimestamp") == 0 ||
            strcmp(name, "DateTimeImmutable::getTimestamp") == 0) {
            procedural_alias = "date_timestamp_get";
        } else if (strcmp(name, "DateTime::getOffset") == 0 ||
                   strcmp(name, "DateTimeImmutable::getOffset") == 0) {
            procedural_alias = "date_offset_get";
        } else if (strcmp(name, "DateTimeZone::getName") == 0) {
            procedural_alias = "timezone_name_get";
        } else if (strcmp(name, "DateTimeZone::getOffset") == 0) {
            procedural_alias = "timezone_offset_get";
        } else if (strcmp(name, "DateInterval::format") == 0) {
            procedural_alias = "date_interval_format";
        }

        if (procedural_alias != NULL) {
            return jinx_oracle_extended_builtin(
                procedural_alias, args, argc, handled
            );
        }
    }

    {
        const char *datetime_alias = NULL;
        int immutable = 0;
        int mutating = 0;

        if (strcmp(name, "DateTime::getTimezone") == 0) {
            datetime_alias = "date_timezone_get";
        } else if (strcmp(name, "DateTimeImmutable::getTimezone") == 0) {
            datetime_alias = "date_timezone_get";
            immutable = 1;
        } else if (strcmp(name, "DateTime::diff") == 0) {
            datetime_alias = "date_diff";
        } else if (strcmp(name, "DateTimeImmutable::diff") == 0) {
            datetime_alias = "date_diff";
            immutable = 1;
        } else if (strcmp(name, "DateTime::add") == 0) {
            datetime_alias = "date_add";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::add") == 0) {
            datetime_alias = "date_add";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::sub") == 0) {
            datetime_alias = "date_sub";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::sub") == 0) {
            datetime_alias = "date_sub";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::modify") == 0) {
            datetime_alias = "date_modify";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::modify") == 0) {
            datetime_alias = "date_modify";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::setDate") == 0) {
            datetime_alias = "date_date_set";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::setDate") == 0) {
            datetime_alias = "date_date_set";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::setISODate") == 0) {
            datetime_alias = "date_isodate_set";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::setISODate") == 0) {
            datetime_alias = "date_isodate_set";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::setTime") == 0) {
            datetime_alias = "date_time_set";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::setTime") == 0) {
            datetime_alias = "date_time_set";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::setTimestamp") == 0) {
            datetime_alias = "date_timestamp_set";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::setTimestamp") == 0) {
            datetime_alias = "date_timestamp_set";
            immutable = 1;
            mutating = 1;
        } else if (strcmp(name, "DateTime::setTimezone") == 0) {
            datetime_alias = "date_timezone_set";
            mutating = 1;
        } else if (strcmp(name, "DateTimeImmutable::setTimezone") == 0) {
            datetime_alias = "date_timezone_set";
            immutable = 1;
            mutating = 1;
        }

        if (strcmp(name, "DateTime::getMicrosecond") == 0 ||
            strcmp(name, "DateTimeImmutable::getMicrosecond") == 0) {
            JinxZendObject *object =
                args != NULL && argc == 1u
                    ? jinx_oracle_zend_object_ptr(args[0])
                    : NULL;
            if (object == NULL) return result;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(
                jinx_oracle_ext_object_long(object, "microsecond", 0)
            );
        }

        if (datetime_alias != NULL) {
            if (args == NULL || argc < 1u) return result;

            if (immutable && mutating) {
                JinxZendObject *source = NULL;
                int64_t timestamp = 0;
                const char *timezone = NULL;
                JinxValue clone;
                JinxValue call_args[8];
                JinxValue alias_result;
                int alias_handled = 0;

                if (argc > 8u ||
                    !jinx_oracle_ext_datetime_parts(
                        args[0], &source, &timestamp, &timezone
                    )) {
                    return result;
                }
                clone = jinx_oracle_ext_new_datetime(
                    "DateTimeImmutable", timestamp, timezone
                );
                if (!jinx_oracle_value_is_zend_object(clone)) return result;

                call_args[0] = clone;
                for (size_t i = 1u; i < argc; i++) call_args[i] = args[i];
                alias_result = jinx_oracle_extended_builtin(
                    datetime_alias,
                    call_args,
                    argc,
                    &alias_handled
                );
                jinx_oracle_zend_container_value_release(clone);
                if (!alias_handled) {
                    jinx_oracle_zend_container_value_release(alias_result);
                    return result;
                }
                if (handled != NULL) *handled = 1;
                return alias_result;
            }

            return jinx_oracle_extended_builtin(
                datetime_alias, args, argc, handled
            );
        }
    }

    {
        const char *separator = strstr(name, "::");
        JinxZendObject *object =
            args != NULL && argc >= 1u
                ? jinx_oracle_zend_object_ptr(args[0])
                : NULL;

        if (separator != NULL && object != NULL &&
            object->class_name != NULL) {
            size_t class_len = (size_t)(separator - name);
            char class_name[256];
            int known = 0;

            if (class_len < sizeof(class_name)) {
                memcpy(class_name, name, class_len);
                class_name[class_len] = '\0';

                {
                    int route_known = 0;
                    if (jinx_oracle_ext_class_is_a_name(
                            object->class_name, class_name, 0, &route_known
                        ) && route_known &&
                        jinx_oracle_ext_class_is_a_name(
                            object->class_name, "Throwable", 0, &known
                        ) && known) {
                    const char *method = separator + 2u;

                    if (strcasecmp(method, "__wakeup") == 0 &&
                        argc == 1u) {
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_zero_value();
                    }

                    if (strcasecmp(method, "__construct") == 0) {
                        const char *message = "";
                        int64_t code = 0;
                        int is_error_exception =
                            strcasecmp(object->class_name, "ErrorException") == 0;

                        if ((!is_error_exception && (argc < 1u || argc > 4u)) ||
                            (is_error_exception && (argc < 1u || argc > 7u))) {
                            return result;
                        }

                        if (argc >= 2u) {
                            if (args[1].type != 3u) return result;
                            message = (const char *)
                                jinx_oracle_string_bytes(args[1]);
                        }
                        if (argc >= 3u) {
                            code = jinx_oracle_intish(args[2]);
                        }

                        if (!jinx_oracle_ext_object_set_string(
                                object, "message", message
                            ) ||
                            !jinx_oracle_ext_object_set_long(
                                object, "code", code
                            )) {
                            return result;
                        }

                        if (is_error_exception) {
                            if (argc >= 4u &&
                                !jinx_oracle_ext_object_set_long(
                                    object, "severity",
                                    jinx_oracle_intish(args[3])
                                )) {
                                return result;
                            }
                            if (argc >= 5u) {
                                char *file;
                                if (args[4].type != 3u) return result;
                                file = jinx_oracle_ext_dup_string_value(args[4]);
                                if (file == NULL ||
                                    !jinx_oracle_ext_object_set_string(
                                        object, "file", file
                                    )) {
                                    free(file);
                                    return result;
                                }
                                free(file);
                            }
                            if (argc >= 6u &&
                                !jinx_oracle_ext_object_set_long(
                                    object, "line",
                                    jinx_oracle_intish(args[5])
                                )) {
                                return result;
                            }
                            if (argc >= 7u &&
                                !jinx_oracle_ext_object_set_value(
                                    object, "previous", args[6]
                                )) {
                                return result;
                            }
                        } else if (argc >= 4u &&
                                   !jinx_oracle_ext_object_set_value(
                                       object, "previous", args[3]
                                   )) {
                            return result;
                        }

                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_zero_value();
                    }

                    if (strcasecmp(method, "getSeverity") == 0 &&
                        argc == 1u &&
                        strcasecmp(object->class_name, "ErrorException") == 0) {
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_int_value(
                            jinx_oracle_ext_object_long(
                                object, "severity", 1
                            )
                        );
                    }

                    if (strcasecmp(method, "getMessage") == 0 &&
                        argc == 1u) {
                        const char *message =
                            jinx_oracle_ext_object_string(
                                object, "message", ""
                            );
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_ext_copy_string(
                            message, strlen(message)
                        );
                    }

                    if (strcasecmp(method, "getCode") == 0 &&
                        argc == 1u) {
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_int_value(
                            jinx_oracle_ext_object_long(
                                object, "code", 0
                            )
                        );
                    }

                    if (strcasecmp(method, "getPrevious") == 0 &&
                        argc == 1u) {
                        JinxZendValue *previous =
                            jinx_oracle_ext_object_prop(object, "previous");
                        if (handled != NULL) *handled = 1;
                        if (previous == NULL ||
                            previous->type == JINX_ZEND_NULL) {
                            return jinx_oracle_zero_value();
                        }
                        if (previous->type == JINX_ZEND_OBJECT &&
                            previous->value.object != NULL) {
                            return jinx_oracle_zend_object_value_borrowed(
                                previous->value.object
                            );
                        }
                        return jinx_oracle_zero_value();
                    }

                    if (strcasecmp(method, "getTrace") == 0 &&
                        argc == 1u) {
                        JinxZendArray *trace =
                            jinx_zend_array_new_packed(1u);
                        if (trace == NULL) return result;
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_zend_array_value_owned(trace);
                    }

                    if (strcasecmp(method, "getTraceAsString") == 0 &&
                        argc == 1u) {
                        const char *trace_string =
                            jinx_oracle_ext_object_string(
                                object, "trace_string", ""
                            );
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_ext_copy_string(
                            trace_string, strlen(trace_string)
                        );
                    }

                    if (strcasecmp(method, "getFile") == 0 &&
                        argc == 1u) {
                        const char *file =
                            jinx_oracle_ext_object_string(
                                object, "file", ""
                            );
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_ext_copy_string(
                            file, strlen(file)
                        );
                    }

                    if (strcasecmp(method, "getLine") == 0 &&
                        argc == 1u) {
                        if (handled != NULL) *handled = 1;
                        return jinx_oracle_int_value(
                            jinx_oracle_ext_object_long(
                                object, "line", 0
                            )
                        );
                    }

                    if (strcasecmp(method, "__toString") == 0 &&
                        argc == 1u) {
                        const char *message =
                            jinx_oracle_ext_object_string(
                                object, "message", ""
                            );
                        const char *file =
                            jinx_oracle_ext_object_string(
                                object, "file", ""
                            );
                        const char *trace_string =
                            jinx_oracle_ext_object_string(
                                object, "trace_string", ""
                            );
                        long long line = (long long)
                            jinx_oracle_ext_object_long(
                                object, "line", 0
                            );
                        int needed = snprintf(
                            NULL, 0,
                            "%s: %s in %s:%lld\nStack trace:\n%s",
                            object->class_name,
                            message,
                            file,
                            line,
                            trace_string
                        );
                        char *text;

                        if (needed < 0) return result;
                        text = (char *)malloc((size_t)needed + 1u);
                        if (text == NULL) return result;
                        snprintf(
                            text, (size_t)needed + 1u,
                            "%s: %s in %s:%lld\nStack trace:\n%s",
                            object->class_name,
                            message,
                            file,
                            line,
                            trace_string
                        );
                        result = jinx_oracle_ext_copy_string(
                            text, (size_t)needed
                        );
                        free(text);
                        if (result.type != 0u &&
                            handled != NULL) {
                            *handled = 1;
                        }
                        return result;
                    }
                    }
                }
            }
        }
    }

    if (strcmp(name, "is_callable") == 0) {
        char *callable;
        int answer;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc != 1u) return result;
        callable = jinx_oracle_ext_dup_string_value(args[0]);
        if (callable == NULL) return result;
        if (strstr(callable, "::") != NULL) {
            free(callable);
            return result;
        }
        answer = jinx_oracle_ext_name_in_list(
            callable,
            jinx_native_internal_function_names,
            jinx_native_internal_function_names_count
        );
        free(callable);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(answer);
    }

    if (strcmp(name, "tmpfile") == 0) {
        FILE *fp;
        if (argc != 0u) return result;
        fp = tmpfile();
        if (handled != NULL) *handled = 1;
        return fp != NULL
            ? jinx_oracle_ext_new_stream(fp)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "tempnam") == 0) {
        char *directory;
        char *prefix;
        char pattern[PATH_MAX];
        size_t directory_len;
        int fd;
        int written;
        if (args == NULL || argc != 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        directory = jinx_oracle_ext_dup_string_value(args[0]);
        prefix = jinx_oracle_ext_dup_string_value(args[1]);
        if (directory == NULL || prefix == NULL) {
            free(directory);
            free(prefix);
            return result;
        }
        if (strchr(prefix, '/') != NULL || strchr(prefix, '\\') != NULL) {
            free(directory);
            free(prefix);
            return result;
        }
        {
            struct stat st;
            if (stat(directory, &st) != 0 || !S_ISDIR(st.st_mode)) {
                free(directory);
                free(prefix);
                return result;
            }
        }
        directory_len = strlen(directory);
        written = snprintf(
            pattern,
            sizeof(pattern),
            "%s%s%sXXXXXX",
            directory,
            directory_len != 0u && directory[directory_len - 1u] == '/' ? "" : "/",
            prefix
        );
        free(directory);
        free(prefix);
        if (written < 0 || (size_t)written >= sizeof(pattern)) return result;
        fd = mkstemp(pattern);
        if (handled != NULL) *handled = 1;
        if (fd < 0) return jinx_oracle_bool_value(0);
        close(fd);
        return jinx_oracle_ext_copy_string(pattern, strlen(pattern));
    }

    if (strcmp(name, "call_user_func") == 0) {
        if (jinx_oracle_ext_call_user_func(args, argc, &result)) {
            if (handled != NULL) *handled = 1;
        }
        return result;
    }

    if (strcmp(name, "call_user_func_array") == 0) {
        if (jinx_oracle_ext_call_user_func_array(args, argc, &result)) {
            if (handled != NULL) *handled = 1;
        }
        return result;
    }

    /* Date/time constructors and global timezone. */
    if (strcmp(name, "date") == 0 || strcmp(name, "gmdate") == 0) {
        int64_t timestamp;
        const char *timezone;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        timestamp = argc >= 2u && args[1].type != 0u
            ? jinx_oracle_intish(args[1])
            : (int64_t)time(NULL);
        timezone = strcmp(name, "gmdate") == 0
            ? "UTC"
            : jinx_oracle_ext_default_timezone;
        result = jinx_oracle_ext_date_format_value(timestamp, timezone, args[0]);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "strtotime") == 0) {
        char *text;
        int64_t base_timestamp;
        int64_t timestamp;

        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        text = jinx_oracle_ext_dup_string_value(args[0]);
        if (text == NULL) return result;

        base_timestamp = argc >= 2u
            ? jinx_oracle_intish(args[1])
            : (int64_t)time(NULL);

        if (!jinx_oracle_ext_parse_datetime_text_at(
                text,
                jinx_oracle_ext_default_timezone,
                base_timestamp,
                &timestamp)) {
            free(text);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        free(text);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(timestamp);
    }

    if (strcmp(name, "mktime") == 0) {
        struct tm now_parts;
        time_t now = time(NULL);
        int64_t timestamp;
        int year;
        if (args == NULL || argc < 1u) return result;
        if (!jinx_oracle_ext_parts_from_timestamp(
                (int64_t)now,
                jinx_oracle_ext_default_timezone,
                &now_parts
            )) return result;

        now_parts.tm_hour = (int)jinx_oracle_intish(args[0]);
        if (argc >= 2u && args[1].type != 0u) now_parts.tm_min = (int)jinx_oracle_intish(args[1]);
        if (argc >= 3u && args[2].type != 0u) now_parts.tm_sec = (int)jinx_oracle_intish(args[2]);
        if (argc >= 4u && args[3].type != 0u) now_parts.tm_mon = (int)jinx_oracle_intish(args[3]) - 1;
        if (argc >= 5u && args[4].type != 0u) now_parts.tm_mday = (int)jinx_oracle_intish(args[4]);
        if (argc >= 6u && args[5].type != 0u) {
            year = (int)jinx_oracle_intish(args[5]);
            if (year >= 0 && year < 70) year += 2000;
            else if (year >= 70 && year <= 100) year += 1900;
            now_parts.tm_year = year - 1900;
        }
        now_parts.tm_isdst = -1;
        if (!jinx_oracle_ext_timestamp_from_parts(
                now_parts.tm_year + 1900,
                now_parts.tm_mon + 1,
                now_parts.tm_mday,
                now_parts.tm_hour,
                now_parts.tm_min,
                now_parts.tm_sec,
                jinx_oracle_ext_default_timezone,
                &timestamp
            )) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(timestamp);
    }

    if (strcmp(name, "localtime") == 0) {
        int64_t timestamp = args != NULL && argc >= 1u && args[0].type != 0u
            ? jinx_oracle_intish(args[0])
            : (int64_t)time(NULL);
        int associative = args != NULL && argc >= 2u
            ? jinx_oracle_boolish(args[1])
            : 0;
        struct tm tmv;
        JinxZendArray *array;
        if (!jinx_oracle_ext_parts_from_timestamp(
                timestamp, jinx_oracle_ext_default_timezone, &tmv
            )) return result;
        array = jinx_zend_array_new_packed(9u);
        if (array == NULL) return result;
        if (associative) {
            if (!jinx_zend_array_add_assoc(array, "tm_sec", 6u, jinx_zend_long(tmv.tm_sec)) ||
                !jinx_zend_array_add_assoc(array, "tm_min", 6u, jinx_zend_long(tmv.tm_min)) ||
                !jinx_zend_array_add_assoc(array, "tm_hour", 7u, jinx_zend_long(tmv.tm_hour)) ||
                !jinx_zend_array_add_assoc(array, "tm_mday", 7u, jinx_zend_long(tmv.tm_mday)) ||
                !jinx_zend_array_add_assoc(array, "tm_mon", 6u, jinx_zend_long(tmv.tm_mon)) ||
                !jinx_zend_array_add_assoc(array, "tm_year", 7u, jinx_zend_long(tmv.tm_year)) ||
                !jinx_zend_array_add_assoc(array, "tm_wday", 7u, jinx_zend_long(tmv.tm_wday)) ||
                !jinx_zend_array_add_assoc(array, "tm_yday", 7u, jinx_zend_long(tmv.tm_yday)) ||
                !jinx_zend_array_add_assoc(array, "tm_isdst", 8u, jinx_zend_long(tmv.tm_isdst > 0 ? 1 : 0))) {
                jinx_zend_array_release(array);
                return result;
            }
        } else {
            int64_t values[9] = {
                tmv.tm_sec, tmv.tm_min, tmv.tm_hour, tmv.tm_mday,
                tmv.tm_mon, tmv.tm_year, tmv.tm_wday, tmv.tm_yday,
                tmv.tm_isdst > 0 ? 1 : 0
            };
            for (size_t i = 0u; i < 9u; i++) {
                if (!jinx_zend_array_append(array, jinx_zend_long(values[i]))) {
                    jinx_zend_array_release(array);
                    return result;
                }
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "idate") == 0) {
        int64_t timestamp;
        struct tm tmv;
        const unsigned char *format;
        int64_t value = -1;
        char buf[32];
        int offset;
        int year;
        int days;
        if (args == NULL || argc < 1u || args[0].type != 3u ||
            jinx_oracle_string_len(args[0]) != 1u) {
            if (args != NULL && argc >= 1u && args[0].type == 3u) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            return result;
        }
        timestamp = argc >= 2u && args[1].type != 0u
            ? jinx_oracle_intish(args[1])
            : (int64_t)time(NULL);
        if (!jinx_oracle_ext_parts_from_timestamp(
                timestamp, jinx_oracle_ext_default_timezone, &tmv
            )) return result;
        format = jinx_oracle_string_bytes(args[0]);
        year = tmv.tm_year + 1900;
        switch ((char)format[0]) {
            case 'd': case 'j': value = tmv.tm_mday; break;
            case 'N': value = tmv.tm_wday == 0 ? 7 : tmv.tm_wday; break;
            case 'w': value = tmv.tm_wday; break;
            case 'z': value = tmv.tm_yday; break;
            case 'W':
                if (strftime(buf, sizeof(buf), "%V", &tmv) == 0u) value = -1;
                else value = strtoll(buf, NULL, 10);
                break;
            case 'm': case 'n': value = tmv.tm_mon + 1; break;
            case 't': {
                static const int mdays[] = {31,28,31,30,31,30,31,31,30,31,30,31};
                days = mdays[tmv.tm_mon];
                if (tmv.tm_mon == 1 &&
                    year % 4 == 0 && (year % 100 != 0 || year % 400 == 0)) days = 29;
                value = days;
                break;
            }
            case 'L': value = year % 4 == 0 && (year % 100 != 0 || year % 400 == 0); break;
            case 'y': value = year % 100; break;
            case 'Y': value = year; break;
            case 'o':
                if (strftime(buf, sizeof(buf), "%G", &tmv) == 0u) value = -1;
                else value = strtoll(buf, NULL, 10);
                break;
            case 'B': {
                int64_t beat = (((timestamp % 86400LL) + 3600LL) * 10LL);
                if (beat < 0) beat += 864000LL;
                value = (beat / 864LL) % 1000LL;
                break;
            }
            case 'g': case 'h': value = tmv.tm_hour % 12 ? tmv.tm_hour % 12 : 12; break;
            case 'H': case 'G': value = tmv.tm_hour; break;
            case 'i': value = tmv.tm_min; break;
            case 's': value = tmv.tm_sec; break;
            case 'I': value = tmv.tm_isdst > 0 ? 1 : 0; break;
            case 'Z':
                offset = jinx_oracle_ext_timezone_offset_seconds(
                    timestamp, jinx_oracle_ext_default_timezone
                );
                value = offset;
                break;
            case 'U': value = timestamp; break;
            default: value = -1; break;
        }
        if (handled != NULL) *handled = 1;
        return value == -1
            ? jinx_oracle_bool_value(0)
            : jinx_oracle_int_value(value);
    }

    if (strcmp(name, "date_default_timezone_get") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value(jinx_oracle_ext_default_timezone);
    }

    if (strcmp(name, "date_default_timezone_set") == 0) {
        char *timezone;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        timezone = jinx_oracle_ext_dup_string_value(args[0]);
        if (timezone == NULL) return result;
        ok = jinx_oracle_ext_timezone_valid(timezone);
        if (ok) snprintf(
            jinx_oracle_ext_default_timezone,
            sizeof(jinx_oracle_ext_default_timezone),
            "%s",
            timezone
        );
        free(timezone);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok);
    }

    if (strcmp(name, "date_create") == 0 ||
        strcmp(name, "date_create_immutable") == 0) {
        char *text = NULL;
        const char *timezone = jinx_oracle_ext_default_timezone;
        int64_t timestamp;
        if (argc >= 1u && args[0].type != 0u) {
            if (args[0].type != 3u) return result;
            text = jinx_oracle_ext_dup_string_value(args[0]);
        } else {
            text = jinx_oracle_ext_strdup("now");
        }
        if (argc >= 2u && args[1].type != 0u &&
            !jinx_oracle_ext_timezone_from_value(args[1], &timezone)) {
            free(text);
            return result;
        }
        ok = text != NULL && jinx_oracle_ext_parse_datetime_text(text, timezone, &timestamp);
        jinx_oracle_ext_set_date_error(ok ? "" : "Failed to parse time string");
        free(text);
        if (handled != NULL) *handled = 1;
        if (!ok) return jinx_oracle_bool_value(0);
        return jinx_oracle_ext_new_datetime(
            strcmp(name, "date_create_immutable") == 0 ? "DateTimeImmutable" : "DateTime",
            timestamp,
            timezone
        );
    }

    if (strcmp(name, "date_create_from_format") == 0 ||
        strcmp(name, "date_create_immutable_from_format") == 0) {
        char *format;
        char *text;
        const char *timezone = jinx_oracle_ext_default_timezone;
        int64_t timestamp;
        if (args == NULL || argc < 2u || args[0].type != 3u || args[1].type != 3u) return result;
        format = jinx_oracle_ext_dup_string_value(args[0]);
        text = jinx_oracle_ext_dup_string_value(args[1]);
        if (format == NULL || text == NULL) {
            free(format); free(text); return result;
        }
        if (argc >= 3u && args[2].type != 0u &&
            !jinx_oracle_ext_timezone_from_value(args[2], &timezone)) {
            free(format); free(text); return result;
        }
        ok = jinx_oracle_ext_parse_datetime_format(format, text, timezone, &timestamp, NULL);
        jinx_oracle_ext_set_date_error(ok ? "" : "Failed to parse time string");
        free(format); free(text);
        if (handled != NULL) *handled = 1;
        if (!ok) return jinx_oracle_bool_value(0);
        return jinx_oracle_ext_new_datetime(
            strcmp(name, "date_create_immutable_from_format") == 0 ? "DateTimeImmutable" : "DateTime",
            timestamp,
            timezone
        );
    }

    if (strcmp(name, "date_get_last_errors") == 0) {
        if (handled != NULL) *handled = 1;
        if (!jinx_oracle_ext_date_error) return jinx_oracle_bool_value(0);
        {
            JinxZendArray *array = jinx_zend_array_new_packed(4u);
            JinxZendArray *warnings = jinx_zend_array_new_packed(1u);
            JinxZendArray *errors = jinx_zend_array_new_packed(1u);
            if (array == NULL || warnings == NULL || errors == NULL) {
                jinx_zend_array_release(array);
                jinx_zend_array_release(warnings);
                jinx_zend_array_release(errors);
                return result;
            }
            jinx_zend_array_add_assoc(array, "warning_count", 13u, jinx_zend_long(0));
            jinx_oracle_ext_array_add_assoc_array(array, "warnings", warnings);
            jinx_zend_array_add_assoc(array, "error_count", 11u, jinx_zend_long(1));
            jinx_oracle_ext_array_add_assoc_string(errors, "0", jinx_oracle_ext_date_error_message);
            jinx_oracle_ext_array_add_assoc_array(array, "errors", errors);
            jinx_zend_array_release(warnings);
            jinx_zend_array_release(errors);
            return jinx_oracle_zend_array_value_owned(array);
        }
    }

    if (strcmp(name, "date_parse") == 0 ||
        strcmp(name, "date_parse_from_format") == 0) {
        char *format = NULL;
        char *text = NULL;
        JinxZendArray *array;
        int use_format = strcmp(name, "date_parse_from_format") == 0;
        if (args == NULL || argc < (use_format ? 2u : 1u)) return result;
        if (use_format) {
            if (args[0].type != 3u || args[1].type != 3u) return result;
            format = jinx_oracle_ext_dup_string_value(args[0]);
            text = jinx_oracle_ext_dup_string_value(args[1]);
        } else {
            if (args[0].type != 3u) return result;
            text = jinx_oracle_ext_dup_string_value(args[0]);
        }
        if (text == NULL || (use_format && format == NULL)) {
            free(format); free(text); return result;
        }
        array = jinx_oracle_ext_date_parse_array(format, text, use_format);
        free(format); free(text);
        if (array == NULL) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "date_interval_create_from_date_string") == 0) {
        char *text;
        JinxOracleExtInterval interval;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        text = jinx_oracle_ext_dup_string_value(args[0]);
        if (text == NULL) return result;
        ok = jinx_oracle_ext_parse_interval_text(text, &interval);
        free(text);
        if (handled != NULL) *handled = 1;
        if (!ok) return jinx_oracle_bool_value(0);
        return jinx_oracle_ext_new_interval(&interval);
    }

    if (strcmp(name, "date_interval_format") == 0) {
        JinxOracleExtInterval interval;
        const unsigned char *fmt;
        uint32_t len;
        char out[1024];
        size_t pos = 0u;
        char tmp[128];
        if (args == NULL || argc < 2u ||
            !jinx_oracle_ext_interval_from_value(args[0], &interval) ||
            args[1].type != 3u) {
            return result;
        }
        fmt = jinx_oracle_string_bytes(args[1]);
        len = jinx_oracle_string_len(args[1]);
        out[0] = '\0';
        for (uint32_t i = 0u; i < len; i++) {
            if (fmt[i] != '%' || i + 1u >= len) {
                tmp[0] = (char)fmt[i]; tmp[1] = '\0';
                jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                continue;
            }
            switch (fmt[++i]) {
                case '%': jinx_oracle_ext_append_text(out, sizeof(out), &pos, "%"); break;
                case 'y': snprintf(tmp, sizeof(tmp), "%d", abs(interval.years)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'Y': snprintf(tmp, sizeof(tmp), "%02d", abs(interval.years)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'm': snprintf(tmp, sizeof(tmp), "%d", abs(interval.months)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'M': snprintf(tmp, sizeof(tmp), "%02d", abs(interval.months)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'd': snprintf(tmp, sizeof(tmp), "%d", abs(interval.days)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'D': snprintf(tmp, sizeof(tmp), "%02d", abs(interval.days)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'h': snprintf(tmp, sizeof(tmp), "%d", abs(interval.hours)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'H': snprintf(tmp, sizeof(tmp), "%02d", abs(interval.hours)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'i': snprintf(tmp, sizeof(tmp), "%d", abs(interval.minutes)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'I': snprintf(tmp, sizeof(tmp), "%02d", abs(interval.minutes)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 's': snprintf(tmp, sizeof(tmp), "%d", abs(interval.seconds)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'S': snprintf(tmp, sizeof(tmp), "%02d", abs(interval.seconds)); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); break;
                case 'a':
                    if (interval.total_days < 0) jinx_oracle_ext_append_text(out, sizeof(out), &pos, "(unknown)");
                    else { snprintf(tmp, sizeof(tmp), "%lld", (long long)interval.total_days); jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp); }
                    break;
                case 'r': jinx_oracle_ext_append_text(out, sizeof(out), &pos, interval.invert ? "-" : ""); break;
                case 'R': jinx_oracle_ext_append_text(out, sizeof(out), &pos, interval.invert ? "-" : "+"); break;
                default:
                    jinx_oracle_ext_append_text(out, sizeof(out), &pos, "%");
                    tmp[0] = (char)fmt[i]; tmp[1] = '\0';
                    jinx_oracle_ext_append_text(out, sizeof(out), &pos, tmp);
                    break;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_ext_copy_string(out, pos);
    }

    /* DateTime object operations. */
    if (strcmp(name, "date_format") == 0 ||
        strcmp(name, "date_timestamp_get") == 0 ||
        strcmp(name, "date_timestamp_set") == 0 ||
        strcmp(name, "date_date_set") == 0 ||
        strcmp(name, "date_time_set") == 0 ||
        strcmp(name, "date_isodate_set") == 0 ||
        strcmp(name, "date_modify") == 0 ||
        strcmp(name, "date_add") == 0 ||
        strcmp(name, "date_sub") == 0 ||
        strcmp(name, "date_offset_get") == 0 ||
        strcmp(name, "date_timezone_get") == 0 ||
        strcmp(name, "date_timezone_set") == 0) {
        JinxZendObject *object = NULL;
        int64_t timestamp = 0;
        const char *timezone = NULL;

        if (args == NULL || argc < 1u ||
            !jinx_oracle_ext_datetime_parts(args[0], &object, &timestamp, &timezone)) {
            return result;
        }

        if (strcmp(name, "date_format") == 0) {
            if (argc < 2u || args[1].type != 3u) return result;
            result = jinx_oracle_ext_date_format_value(timestamp, timezone, args[1]);
            if (result.type != 0u && handled != NULL) *handled = 1;
            return result;
        }

        if (strcmp(name, "date_timestamp_get") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(timestamp);
        }

        if (strcmp(name, "date_offset_get") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(
                jinx_oracle_ext_timezone_offset_seconds(timestamp, timezone)
            );
        }

        if (strcmp(name, "date_timezone_get") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_ext_new_timezone(timezone);
        }

        if (strcmp(name, "date_timezone_set") == 0) {
            const char *new_timezone;
            if (argc < 2u || !jinx_oracle_ext_timezone_from_value(args[1], &new_timezone)) {
                return result;
            }
            if (!jinx_oracle_ext_object_set_string(object, "timezone", new_timezone)) return result;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_object_value_retained(object);
        }

        if (strcmp(name, "date_timestamp_set") == 0) {
            if (argc < 2u) return result;
            timestamp = jinx_oracle_intish(args[1]);
        } else if (strcmp(name, "date_date_set") == 0) {
            struct tm tmv;
            if (argc < 4u || !jinx_oracle_ext_parts_from_timestamp(timestamp, timezone, &tmv)) return result;
            if (!jinx_oracle_ext_timestamp_from_parts(
                (int)jinx_oracle_intish(args[1]),
                (int)jinx_oracle_intish(args[2]),
                (int)jinx_oracle_intish(args[3]),
                tmv.tm_hour, tmv.tm_min, tmv.tm_sec,
                timezone, &timestamp
            )) return result;
        } else if (strcmp(name, "date_time_set") == 0) {
            struct tm tmv;
            if (argc < 3u || !jinx_oracle_ext_parts_from_timestamp(timestamp, timezone, &tmv)) return result;
            if (!jinx_oracle_ext_timestamp_from_parts(
                tmv.tm_year + 1900, tmv.tm_mon + 1, tmv.tm_mday,
                (int)jinx_oracle_intish(args[1]),
                (int)jinx_oracle_intish(args[2]),
                argc >= 4u ? (int)jinx_oracle_intish(args[3]) : 0,
                timezone, &timestamp
            )) return result;
        } else if (strcmp(name, "date_isodate_set") == 0) {
            int iso_year;
            int iso_week;
            int iso_day;
            struct tm jan4;
            struct tm original;
            JinxOracleExtTzScope scope = {0};
            time_t raw;
            int monday_offset;
            if (argc < 3u ||
                !jinx_oracle_ext_parts_from_timestamp(timestamp, timezone, &original)) return result;
            iso_year = (int)jinx_oracle_intish(args[1]);
            iso_week = (int)jinx_oracle_intish(args[2]);
            iso_day = argc >= 4u ? (int)jinx_oracle_intish(args[3]) : 1;
            memset(&jan4, 0, sizeof(jan4));
            jan4.tm_year = iso_year - 1900;
            jan4.tm_mon = 0;
            jan4.tm_mday = 4;
            jan4.tm_hour = original.tm_hour;
            jan4.tm_min = original.tm_min;
            jan4.tm_sec = original.tm_sec;
            jan4.tm_isdst = -1;
            if (!jinx_oracle_ext_tz_enter(timezone, &scope)) return result;
            raw = mktime(&jan4);
            if (raw == (time_t)-1 || localtime_r(&raw, &jan4) == NULL) {
                jinx_oracle_ext_tz_leave(&scope);
                return result;
            }
            monday_offset = (jan4.tm_wday + 6) % 7;
            jan4.tm_mday -= monday_offset;
            jan4.tm_mday += (iso_week - 1) * 7 + (iso_day - 1);
            jan4.tm_isdst = -1;
            raw = mktime(&jan4);
            jinx_oracle_ext_tz_leave(&scope);
            if (raw == (time_t)-1) return result;
            timestamp = (int64_t)raw;
        } else if (strcmp(name, "date_modify") == 0) {
            char *text;
            int64_t changed;
            JinxOracleExtInterval interval;
            if (argc < 2u || args[1].type != 3u) return result;
            text = jinx_oracle_ext_dup_string_value(args[1]);
            if (text == NULL) return result;
            if (jinx_oracle_ext_parse_interval_text(text, &interval)) {
                ok = jinx_oracle_ext_apply_interval(timestamp, timezone, &interval, 1, &changed);
            } else {
                ok = jinx_oracle_ext_parse_datetime_text(text, timezone, &changed);
            }
            free(text);
            if (!ok) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            timestamp = changed;
        } else if (strcmp(name, "date_add") == 0 || strcmp(name, "date_sub") == 0) {
            JinxOracleExtInterval interval;
            int64_t changed;
            if (argc < 2u || !jinx_oracle_ext_interval_from_value(args[1], &interval)) return result;
            if (!jinx_oracle_ext_apply_interval(
                timestamp, timezone, &interval,
                strcmp(name, "date_sub") == 0 ? -1 : 1,
                &changed
            )) return result;
            timestamp = changed;
        }

        if (!jinx_oracle_ext_object_set_long(object, "timestamp", timestamp)) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_object_value_retained(object);
    }

    if (strcmp(name, "timezone_open") == 0) {
        char *timezone;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        timezone = jinx_oracle_ext_dup_string_value(args[0]);
        if (timezone == NULL) return result;
        if (!jinx_oracle_ext_timezone_valid(timezone)) {
            free(timezone);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        result = jinx_oracle_ext_new_timezone(timezone);
        free(timezone);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "timezone_name_get") == 0) {
        const char *timezone = NULL;
        if (args == NULL || argc != 1u ||
            !jinx_oracle_ext_timezone_from_value(args[0], &timezone)) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_ext_copy_string(timezone, strlen(timezone));
    }

    if (strcmp(name, "timezone_offset_get") == 0) {
        const char *timezone = NULL;
        int64_t timestamp = 0;
        if (args == NULL || argc != 2u ||
            !jinx_oracle_ext_timezone_from_value(args[0], &timezone) ||
            !jinx_oracle_ext_datetime_parts(args[1], NULL, &timestamp, NULL)) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(
            jinx_oracle_ext_timezone_offset_seconds(timestamp, timezone)
        );
    }

    if (strcmp(name, "date_diff") == 0) {
        int64_t left, right;
        const char *tz1 = NULL;
        const char *tz2 = NULL;
        JinxOracleExtInterval interval;
        int64_t delta;
        int absolute = argc >= 3u ? jinx_oracle_boolish(args[2]) : 0;
        if (args == NULL || argc < 2u ||
            !jinx_oracle_ext_datetime_parts(args[0], NULL, &left, &tz1) ||
            !jinx_oracle_ext_datetime_parts(args[1], NULL, &right, &tz2)) {
            return result;
        }
        (void)tz1; (void)tz2;
        delta = right - left;
        memset(&interval, 0, sizeof(interval));
        interval.invert = delta < 0 && !absolute;
        if (delta < 0) delta = -delta;
        interval.total_days = delta / 86400;
        interval.days = (int)(delta / 86400);
        delta %= 86400;
        interval.hours = (int)(delta / 3600);
        delta %= 3600;
        interval.minutes = (int)(delta / 60);
        interval.seconds = (int)(delta % 60);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_ext_new_interval(&interval);
    }

    /* Filesystem scalar/stat operations. */
    if (strcmp(name, "clearstatcache") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value(); /* PHP void/null */
    }

    if (strcmp(name, "chdir") == 0 ||
        strcmp(name, "chmod") == 0 ||
        strcmp(name, "chown") == 0 ||
        strcmp(name, "lchown") == 0 ||
        strcmp(name, "chgrp") == 0 ||
        strcmp(name, "lchgrp") == 0 ||
        strcmp(name, "file_exists") == 0 ||
        strcmp(name, "fileatime") == 0 ||
        strcmp(name, "filectime") == 0 ||
        strcmp(name, "filegroup") == 0 ||
        strcmp(name, "fileinode") == 0 ||
        strcmp(name, "filemtime") == 0 ||
        strcmp(name, "fileowner") == 0 ||
        strcmp(name, "fileperms") == 0 ||
        strcmp(name, "filesize") == 0 ||
        strcmp(name, "filetype") == 0) {
        char *path;
        struct stat st;
        int stat_ok;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL) return result;

        if (strcmp(name, "chdir") == 0) {
            ok = chdir(path) == 0;
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
        if (strcmp(name, "chmod") == 0) {
            if (argc < 2u) { free(path); return result; }
            ok = chmod(path, (mode_t)jinx_oracle_intish(args[1])) == 0;
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
        if (strcmp(name, "chown") == 0 || strcmp(name, "lchown") == 0) {
            uid_t uid;
            if (argc < 2u) { free(path); return result; }
            if (args[1].type == 1u) {
                uid = (uid_t)args[1].as.i64;
            } else if (args[1].type == 3u) {
                char *user = jinx_oracle_ext_dup_string_value(args[1]);
                struct passwd *pw = user != NULL ? getpwnam(user) : NULL;
                free(user);
                if (pw == NULL) { free(path); if (handled != NULL) *handled = 1; return jinx_oracle_bool_value(0); }
                uid = pw->pw_uid;
            } else { free(path); return result; }
            ok = (strcmp(name, "lchown") == 0
                ? lchown(path, uid, (gid_t)-1)
                : chown(path, uid, (gid_t)-1)) == 0;
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
        if (strcmp(name, "chgrp") == 0 || strcmp(name, "lchgrp") == 0) {
            gid_t gid;
            if (argc < 2u) { free(path); return result; }
            if (args[1].type == 1u) {
                gid = (gid_t)args[1].as.i64;
            } else if (args[1].type == 3u) {
                char *group = jinx_oracle_ext_dup_string_value(args[1]);
                struct group *gr = group != NULL ? getgrnam(group) : NULL;
                free(group);
                if (gr == NULL) { free(path); if (handled != NULL) *handled = 1; return jinx_oracle_bool_value(0); }
                gid = gr->gr_gid;
            } else { free(path); return result; }
            ok = (strcmp(name, "lchgrp") == 0
                ? lchown(path, (uid_t)-1, gid)
                : chown(path, (uid_t)-1, gid)) == 0;
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }

        stat_ok = stat(path, &st) == 0;
        free(path);
        if (handled != NULL) *handled = 1;
        if (strcmp(name, "file_exists") == 0) return jinx_oracle_bool_value(stat_ok);
        if (!stat_ok) return jinx_oracle_bool_value(0);
        if (strcmp(name, "fileatime") == 0) return jinx_oracle_int_value((int64_t)st.st_atime);
        if (strcmp(name, "filectime") == 0) return jinx_oracle_int_value((int64_t)st.st_ctime);
        if (strcmp(name, "filegroup") == 0) return jinx_oracle_int_value((int64_t)st.st_gid);
        if (strcmp(name, "fileinode") == 0) return jinx_oracle_int_value((int64_t)st.st_ino);
        if (strcmp(name, "filemtime") == 0) return jinx_oracle_int_value((int64_t)st.st_mtime);
        if (strcmp(name, "fileowner") == 0) return jinx_oracle_int_value((int64_t)st.st_uid);
        if (strcmp(name, "fileperms") == 0) return jinx_oracle_int_value((int64_t)st.st_mode);
        if (strcmp(name, "filesize") == 0) return jinx_oracle_int_value((int64_t)st.st_size);
        if (strcmp(name, "filetype") == 0) {
            const char *type = S_ISREG(st.st_mode) ? "file" :
                (S_ISDIR(st.st_mode) ? "dir" :
                (S_ISLNK(st.st_mode) ? "link" :
                (S_ISFIFO(st.st_mode) ? "fifo" :
                (S_ISCHR(st.st_mode) ? "char" :
                (S_ISBLK(st.st_mode) ? "block" :
                (S_ISSOCK(st.st_mode) ? "socket" : "unknown"))))));
            return jinx_oracle_string_value(type);
        }
    }

    if (strcmp(name, "copy") == 0) {
        char *src = NULL;
        char *dst = NULL;
        FILE *in = NULL;
        FILE *out = NULL;
        char buffer[65536];
        size_t n;
        if (args == NULL || argc < 2u || args[0].type != 3u || args[1].type != 3u) return result;
        src = jinx_oracle_ext_dup_string_value(args[0]);
        dst = jinx_oracle_ext_dup_string_value(args[1]);
        if (src == NULL || dst == NULL) { free(src); free(dst); return result; }
        in = fopen(src, "rb");
        if (in != NULL) out = fopen(dst, "wb");
        ok = in != NULL && out != NULL;
        while (ok && (n = fread(buffer, 1u, sizeof(buffer), in)) != 0u) {
            if (fwrite(buffer, 1u, n, out) != n) ok = 0;
        }
        if (in != NULL && ferror(in)) ok = 0;
        if (in != NULL) fclose(in);
        if (out != NULL && fclose(out) != 0) ok = 0;
        free(src); free(dst);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok);
    }

    if (strcmp(name, "file_get_contents") == 0) {
        result = jinx_oracle_ext_file_get_contents(args, argc, &ok);
        if (ok && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "file_put_contents") == 0) {
        char *path;
        FILE *fp;
        const unsigned char *bytes;
        uint32_t len;
        int64_t flags = argc >= 3u ? jinx_oracle_intish(args[2]) : 0;
        size_t written;
        if (args == NULL || argc < 2u || args[0].type != 3u || args[1].type != 3u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL) return result;
        fp = fopen(path, (flags & 8) ? "ab" : "wb"); /* FILE_APPEND = 8 */
        free(path);
        if (fp == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (flags & 2) (void)flock(fileno(fp), LOCK_EX); /* LOCK_EX = 2 */
        bytes = jinx_oracle_string_bytes(args[1]);
        len = jinx_oracle_string_len(args[1]);
        written = len == 0u ? 0u : fwrite(bytes, 1u, len, fp);
        if (flags & 2) (void)flock(fileno(fp), LOCK_UN);
        ok = written == len && fclose(fp) == 0;
        if (handled != NULL) *handled = 1;
        return ok ? jinx_oracle_int_value((int64_t)written) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "readfile") == 0) {
        char *path;
        FILE *fp;
        unsigned char buffer[8192];
        size_t total = 0u;
        size_t n;
        int write_failed = 0;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 2u && args[1].type != 0u && jinx_oracle_boolish(args[1])) return result;
        if (argc >= 3u && args[2].type != 0u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL) return result;
        fp = fopen(path, "rb");
        free(path);
        if (fp == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        while ((n = fread(buffer, 1u, sizeof(buffer), fp)) != 0u) {
            if (fwrite(buffer, 1u, n, stdout) != n) {
                write_failed = 1;
                break;
            }
            total += n;
        }
        ok = !write_failed && !ferror(fp);
        fclose(fp);
        fflush(stdout);
        if (handled != NULL) *handled = 1;
        return ok
            ? jinx_oracle_int_value((int64_t)total)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "file") == 0) {
        char *path;
        FILE *fp;
        char *line = NULL;
        size_t cap = 0u;
        ssize_t len;
        int flags = argc >= 2u ? (int)jinx_oracle_intish(args[1]) : 0;
        JinxZendArray *array;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL) return result;
        fp = fopen(path, "rb");
        free(path);
        if (fp == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(16u);
        if (array == NULL) { fclose(fp); return result; }
        while ((len = getline(&line, &cap, fp)) >= 0) {
            size_t n = (size_t)len;
            if (flags & 2) { /* FILE_IGNORE_NEW_LINES */
                while (n != 0u && (line[n - 1u] == '\n' || line[n - 1u] == '\r')) n--;
            }
            if ((flags & 4) && n == 0u) continue; /* FILE_SKIP_EMPTY_LINES */
            if (!jinx_oracle_ext_array_append_string(array, line, n)) {
                free(line); fclose(fp); jinx_zend_array_release(array); return result;
            }
        }
        free(line);
        fclose(fp);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "shmop_open") == 0) {
        key_t key;
        char *mode;
        int permissions;
        int64_t requested_size;
        int flags = 0;
        size_t shm_size;
        int shmid;
        struct shmid_ds info;
        int readonly = 0;

        if (args == NULL || argc != 4u ||
            args[1].type != 3u) return result;

        key = (key_t)jinx_oracle_intish(args[0]);
        mode = jinx_oracle_ext_dup_string_value(args[1]);
        if (mode == NULL) return result;
        permissions = (int)(jinx_oracle_intish(args[2]) & 0777);
        requested_size = jinx_oracle_intish(args[3]);

        if (strcmp(mode, "a") == 0) {
            readonly = 1;
            shm_size = 1u;
        } else if (strcmp(mode, "w") == 0) {
            shm_size = 1u;
        } else if (strcmp(mode, "c") == 0) {
            if (requested_size <= 0) {
                free(mode);
                return result;
            }
            shm_size = (size_t)requested_size;
            flags = IPC_CREAT | permissions;
        } else if (strcmp(mode, "n") == 0) {
            if (requested_size <= 0) {
                free(mode);
                return result;
            }
            shm_size = (size_t)requested_size;
            flags = IPC_CREAT | IPC_EXCL | permissions;
        } else {
            free(mode);
            return result;
        }
        free(mode);

        shmid = shmget(key, shm_size, flags);
        if (handled != NULL) *handled = 1;
        if (shmid < 0 || shmctl(shmid, IPC_STAT, &info) != 0) {
            return jinx_oracle_bool_value(0);
        }
        return jinx_oracle_ext_new_shmop(
            shmid,
            (size_t)info.shm_segsz,
            readonly,
            (int64_t)key
        );
    }

    if (strcmp(name, "shmop_size") == 0) {
        JinxOracleExtShmop shmop;
        if (args == NULL || argc != 1u ||
            !jinx_oracle_ext_shmop_parts(args[0], &shmop)) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)shmop.size);
    }

    if (strcmp(name, "shmop_read") == 0) {
        JinxOracleExtShmop shmop;
        int64_t offset;
        int64_t length;
        void *memory;
        JinxValue value;

        if (args == NULL || argc != 3u ||
            !jinx_oracle_ext_shmop_parts(args[0], &shmop)) return result;
        offset = jinx_oracle_intish(args[1]);
        length = jinx_oracle_intish(args[2]);
        if (offset < 0 || length < 0 ||
            (uint64_t)offset > (uint64_t)shmop.size ||
            (uint64_t)length > (uint64_t)(shmop.size - (size_t)offset)) {
            return result;
        }

        memory = shmat(shmop.shmid, NULL, SHM_RDONLY);
        if (handled != NULL) *handled = 1;
        if (memory == (void *)-1) return jinx_oracle_bool_value(0);
        value = jinx_oracle_ext_copy_string(
            (const char *)memory + (size_t)offset,
            (size_t)length
        );
        (void)shmdt(memory);
        return value;
    }

    if (strcmp(name, "shmop_write") == 0) {
        JinxOracleExtShmop shmop;
        int64_t offset;
        uint32_t input_len;
        size_t writable;
        void *memory;

        if (args == NULL || argc != 3u ||
            args[1].type != 3u ||
            !jinx_oracle_ext_shmop_parts(args[0], &shmop)) return result;
        if (shmop.readonly) return result;
        offset = jinx_oracle_intish(args[2]);
        if (offset < 0 || (uint64_t)offset > (uint64_t)shmop.size) return result;

        input_len = jinx_oracle_string_len(args[1]);
        writable = shmop.size - (size_t)offset;
        if (writable > (size_t)input_len) writable = (size_t)input_len;

        memory = shmat(shmop.shmid, NULL, 0);
        if (handled != NULL) *handled = 1;
        if (memory == (void *)-1) return jinx_oracle_bool_value(0);
        if (writable != 0u) {
            memcpy(
                (char *)memory + (size_t)offset,
                jinx_oracle_string_bytes(args[1]),
                writable
            );
        }
        (void)shmdt(memory);
        return jinx_oracle_int_value((int64_t)writable);
    }

    if (strcmp(name, "shmop_delete") == 0) {
        JinxOracleExtShmop shmop;
        int deleted;
        if (args == NULL || argc != 1u ||
            !jinx_oracle_ext_shmop_parts(args[0], &shmop)) return result;
        deleted = shmctl(shmop.shmid, IPC_RMID, NULL) == 0;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(deleted);
    }

    if (strcmp(name, "shmop_close") == 0) {
        JinxOracleExtShmop shmop;
        if (args == NULL || argc != 1u ||
            !jinx_oracle_ext_shmop_parts(args[0], &shmop)) return result;
        (void)shmop;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "popen") == 0) {
        char *command;
        char *mode;
        const char *posix_mode = NULL;
        FILE *fp;
        if (args == NULL || argc != 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        command = jinx_oracle_ext_dup_string_value(args[0]);
        mode = jinx_oracle_ext_dup_string_value(args[1]);
        if (command == NULL || mode == NULL) {
            free(command);
            free(mode);
            return result;
        }
        if (strcmp(mode, "r") == 0 || strcmp(mode, "rb") == 0) posix_mode = "r";
        else if (strcmp(mode, "w") == 0 || strcmp(mode, "wb") == 0) posix_mode = "w";
        else {
            free(command);
            free(mode);
            return result;
        }
        fp = popen(command, posix_mode);
        free(command);
        free(mode);
        if (handled != NULL) *handled = 1;
        return fp != NULL
            ? jinx_oracle_ext_new_process_stream(fp)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "pclose") == 0) {
        JinxZendValue *slot = NULL;
        JinxOracleExtStream *stream;
        JinxZendObject *resource_object;
        int status;
        if (args == NULL || argc != 1u) return result;
        stream = jinx_oracle_ext_stream_from_value(args[0], &slot);
        if (stream == NULL || stream->fp == NULL || !stream->process_pipe) return result;
        resource_object = jinx_oracle_zend_object_ptr(args[0]);
        status = pclose(stream->fp);
        jinx_oracle_resource_unregister(stream);
        stream->fp = NULL;
        free(stream);
        slot->type = JINX_ZEND_NULL;
        slot->value.ptr = NULL;
        if (resource_object != NULL) {
            char *closed_name = jinx_oracle_ext_strdup("closed-resource");
            if (closed_name != NULL) {
                free((void *)resource_object->class_name);
                resource_object->class_name = closed_name;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)status);
    }

    if (strcmp(name, "fopen") == 0) {
        char *path;
        char *mode;
        FILE *fp;
        if (args == NULL || argc < 2u || args[0].type != 3u || args[1].type != 3u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        mode = jinx_oracle_ext_dup_string_value(args[1]);
        if (path == NULL || mode == NULL) { free(path); free(mode); return result; }
        fp = fopen(path, mode);
        free(path); free(mode);
        if (handled != NULL) *handled = 1;
        return fp != NULL ? jinx_oracle_ext_new_stream(fp) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "fclose") == 0 ||
        strcmp(name, "feof") == 0 ||
        strcmp(name, "fflush") == 0 ||
        strcmp(name, "fgetc") == 0 ||
        strcmp(name, "fgets") == 0 ||
        strcmp(name, "fgetcsv") == 0 ||
        strcmp(name, "flock") == 0 ||
        strcmp(name, "fread") == 0 ||
        strcmp(name, "fwrite") == 0 ||
        strcmp(name, "fseek") == 0 ||
        strcmp(name, "rewind") == 0 ||
        strcmp(name, "ftell") == 0 ||
        strcmp(name, "fstat") == 0 ||
        strcmp(name, "fsync") == 0 ||
        strcmp(name, "fdatasync") == 0) {
        JinxZendValue *slot = NULL;
        JinxOracleExtStream *stream;
        if (args == NULL || argc < 1u) return result;
        stream = jinx_oracle_ext_stream_from_value(args[0], &slot);
        if (stream == NULL || stream->fp == NULL) return result;

        if (strcmp(name, "fclose") == 0) {
            JinxZendObject *resource_object = jinx_oracle_zend_object_ptr(args[0]);
            if (stream->process_pipe) {
                ok = pclose(stream->fp) != -1;
            } else {
                ok = fclose(stream->fp) == 0;
            }
            jinx_oracle_resource_unregister(stream);
            stream->fp = NULL;
            free(stream);
            slot->type = JINX_ZEND_NULL;
            slot->value.ptr = NULL;
            if (ok && resource_object != NULL) {
                char *closed_name = jinx_oracle_ext_strdup("closed-resource");
                if (closed_name != NULL) {
                    free((void *)resource_object->class_name);
                    resource_object->class_name = closed_name;
                }
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
        if (strcmp(name, "feof") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(feof(stream->fp));
        }
        if (strcmp(name, "fflush") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(fflush(stream->fp) == 0);
        }
        if (strcmp(name, "fgetc") == 0) {
            int ch = fgetc(stream->fp);
            if (handled != NULL) *handled = 1;
            if (ch == EOF) return jinx_oracle_bool_value(0);
            {
                char *out = jinx_oracle_scratch_string(1u);
                out[0] = (char)ch;
                return jinx_oracle_string_value_len(out, 1u);
            }
        }
        if (strcmp(name, "fgets") == 0) {
            char *line = NULL;
            size_t cap = 0u;
            ssize_t n;
            int64_t length = 0;

            if (argc >= 2u && args[1].type != 0u) {
                length = jinx_oracle_intish(args[1]);
                if (length < 2) {
                    return result;
                }
            }

            n = getline(&line, &cap, stream->fp);
            if (handled != NULL) *handled = 1;
            if (n < 0) { free(line); return jinx_oracle_bool_value(0); }
            if (length >= 2 && (int64_t)n >= length) {
                n = length - 1;
            }
            result = jinx_oracle_ext_copy_string(line, (size_t)n);
            free(line);
            return result;
        }
        if (strcmp(name, "fgetcsv") == 0) {
            char *line = NULL;
            size_t cap = 0u;
            ssize_t n = getline(&line, &cap, stream->fp);
            char delimiter = ',';
            char enclosure = '"';
            char escape = '\\';
            JinxZendArray *array;
            if (handled != NULL) *handled = 1;
            if (n < 0) { free(line); return jinx_oracle_bool_value(0); }
            if (argc >= 3u && args[2].type == 3u && args[2].flags == 1u) delimiter = ((char *)args[2].as.ptr)[0];
            if (argc >= 4u && args[3].type == 3u && args[3].flags == 1u) enclosure = ((char *)args[3].as.ptr)[0];
            if (argc >= 5u && args[4].type == 3u && args[4].flags <= 1u) escape = args[4].flags == 0u ? '\0' : ((char *)args[4].as.ptr)[0];
            while (n > 0 && (line[n - 1] == '\n' || line[n - 1] == '\r')) n--;
            array = jinx_oracle_ext_parse_csv_line(line, (size_t)n, delimiter, enclosure, escape);
            free(line);
            return array != NULL ? jinx_oracle_zend_array_value_owned(array) : result;
        }
        if (strcmp(name, "fread") == 0) {
            int64_t length;
            char *out;
            size_t got;
            if (argc < 2u) return result;
            length=jinx_oracle_intish(args[1]);
            if(length<=0 || length>UINT32_MAX) return result;
            out=jinx_oracle_scratch_string((uint32_t)length);
            got=fread(out,1u,(size_t)length,stream->fp);
            if(got==0u && ferror(stream->fp)){
                if(handled!=NULL)*handled=1;
                return jinx_oracle_bool_value(0);
            }
            if(handled!=NULL)*handled=1;
            return jinx_oracle_string_value_len(out,(uint32_t)got);
        }
        if (strcmp(name, "fwrite") == 0) {
            const unsigned char *bytes;
            uint32_t len;
            size_t requested;
            size_t written;
            if(argc<2u || args[1].type!=3u) return result;
            bytes=jinx_oracle_string_bytes(args[1]);
            len=jinx_oracle_string_len(args[1]);
            requested=len;
            if(argc>=3u && args[2].type!=0u){
                int64_t limit=jinx_oracle_intish(args[2]);
                if(limit<0) return result;
                if((uint64_t)limit<(uint64_t)requested) requested=(size_t)limit;
            }
            written=requested==0u?0u:fwrite(bytes,1u,requested,stream->fp);
            if(handled!=NULL)*handled=1;
            return written==0u && requested!=0u && ferror(stream->fp)
                ? jinx_oracle_bool_value(0)
                : jinx_oracle_int_value((int64_t)written);
        }
        if (strcmp(name, "fseek") == 0) {
            int64_t offset;
            int whence=SEEK_SET;
            if(argc<2u) return result;
            offset=jinx_oracle_intish(args[1]);
            if(argc>=3u) whence=(int)jinx_oracle_intish(args[2]);
            if(handled!=NULL)*handled=1;
            return jinx_oracle_int_value(fseek(stream->fp,(long)offset,whence)==0?0:-1);
        }
        if (strcmp(name, "rewind") == 0) {
            int rc;
            if (argc != 1u) return result;
            clearerr(stream->fp);
            rc = fseek(stream->fp, 0L, SEEK_SET);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(rc == 0);
        }
        if (strcmp(name, "ftell") == 0) {
            long pos=ftell(stream->fp);
            if(handled!=NULL)*handled=1;
            return pos<0?jinx_oracle_bool_value(0):jinx_oracle_int_value((int64_t)pos);
        }
        if (strcmp(name, "fstat") == 0) {
            struct stat st;
            JinxZendArray *array;
            if(handled!=NULL)*handled=1;
            if(fstat(fileno(stream->fp),&st)!=0) return jinx_oracle_bool_value(0);
            array=jinx_oracle_ext_stat_array(&st);
            return array!=NULL?jinx_oracle_zend_array_value_owned(array):result;
        }
        if (strcmp(name, "fsync") == 0 || strcmp(name, "fdatasync") == 0) {
            int rc;
            if(strcmp(name,"fdatasync")==0){
#ifdef __linux__
                rc=fdatasync(fileno(stream->fp));
#else
                rc=fsync(fileno(stream->fp));
#endif
            } else {
                rc=fsync(fileno(stream->fp));
            }
            if(handled!=NULL)*handled=1;
            return jinx_oracle_bool_value(rc==0);
        }

        if (strcmp(name, "flock") == 0) {
            int operation;
            int native_op = 0;
            if (argc < 2u) return result;
            operation = (int)jinx_oracle_intish(args[1]);
            if ((operation & 3) == 1) native_op = LOCK_SH;
            else if ((operation & 3) == 2) native_op = LOCK_EX;
            else if ((operation & 3) == 3) native_op = LOCK_UN;
            else return result;
            if (operation & 4) native_op |= LOCK_NB;
            ok = flock(fileno(stream->fp), native_op) == 0;
            /* The generated wrapper passes the optional would_block argument by
             * reference. The central dispatcher owns write-back to the original
             * call frame; this backend only reports the flock result. */
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
    }

    if (strcmp(name, "umask") == 0) {
        mode_t previous = umask(077);
        if (argc >= 1u && args != NULL && args[0].type != 0u) {
            umask((mode_t)jinx_oracle_intish(args[0]));
        } else {
            umask(previous);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)previous);
    }

    if (strcmp(name, "rename") == 0) {
        char *from;
        char *to;
        int rc;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        if (argc >= 3u && args[2].type != 0u) return result;
        from = jinx_oracle_ext_dup_string_value(args[0]);
        to = jinx_oracle_ext_dup_string_value(args[1]);
        if (from == NULL || to == NULL) {
            free(from);
            free(to);
            return result;
        }
        rc = rename(from, to);
        free(from);
        free(to);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }

    if (strcmp(name, "rmdir") == 0) {
        char *path;
        int rc;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 2u && args[1].type != 0u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL) return result;
        rc = rmdir(path);
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }

    if (strcmp(name, "touch") == 0) {
        char *path;
        struct utimbuf times;
        struct utimbuf *times_ptr = NULL;
        int rc;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 3u && args[1].type == 0u && args[2].type != 0u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL || path[0] == '\0') {
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (access(path, F_OK) != 0) {
            FILE *created = fopen(path, "wb");
            if (created == NULL) {
                free(path);
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            fclose(created);
        }
        if (argc >= 2u && args[1].type != 0u) {
            time_t modified = (time_t)jinx_oracle_intish(args[1]);
            times.modtime = modified;
            times.actime = argc >= 3u && args[2].type != 0u
                ? (time_t)jinx_oracle_intish(args[2])
                : modified;
            times_ptr = &times;
        }
        rc = utime(path, times_ptr);
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }

    if (strcmp(name, "readlink") == 0 || strcmp(name, "linkinfo") == 0) {
        char *path;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = jinx_oracle_ext_dup_string_value(args[0]);
        if (path == NULL) return result;

        if (strcmp(name, "readlink") == 0) {
            char target[PATH_MAX];
            ssize_t length = readlink(path, target, sizeof(target) - 1u);
            free(path);
            if (handled != NULL) *handled = 1;
            if (length < 0) return jinx_oracle_bool_value(0);
            return jinx_oracle_ext_copy_string(target, (size_t)length);
        }

        {
            struct stat st;
            ok = lstat(path, &st) == 0;
            free(path);
            if (handled != NULL) *handled = 1;
            return ok
                ? jinx_oracle_int_value((int64_t)st.st_dev)
                : jinx_oracle_int_value(-1);
        }
    }

    if (strcmp(name, "link") == 0 || strcmp(name, "symlink") == 0) {
        char *target;
        char *link_path;
        int rc;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        target = jinx_oracle_ext_dup_string_value(args[0]);
        link_path = jinx_oracle_ext_dup_string_value(args[1]);
        if (target == NULL || link_path == NULL) {
            free(target);
            free(link_path);
            return result;
        }
        rc = strcmp(name, "symlink") == 0
            ? symlink(target, link_path)
            : link(target, link_path);
        free(target);
        free(link_path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }

    if (strcmp(name, "is_dir") == 0 || strcmp(name, "is_file") == 0 ||
        strcmp(name, "is_link") == 0 || strcmp(name, "is_readable") == 0 ||
        strcmp(name, "is_writable") == 0 || strcmp(name, "is_writeable") == 0 ||
        strcmp(name, "is_executable") == 0 ||
        strcmp(name, "lstat") == 0 || strcmp(name, "stat") == 0 ||
        strcmp(name, "mkdir") == 0 || strcmp(name, "unlink") == 0 ||
        strcmp(name, "realpath") == 0 || strcmp(name, "scandir") == 0 ||
        strcmp(name, "glob") == 0) {
        char *path;
        if(args==NULL || argc<1u || args[0].type!=3u) return result;
        path=jinx_oracle_ext_dup_string_value(args[0]);
        if(path==NULL) return result;

        if(strcmp(name,"mkdir")==0){
            mode_t mode=argc>=2u?(mode_t)jinx_oracle_intish(args[1]):0777;
            ok=mkdir(path,mode)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            return jinx_oracle_bool_value(ok);
        }

        if(strcmp(name,"unlink")==0){
            ok=unlink(path)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            return jinx_oracle_bool_value(ok);
        }

        if(strcmp(name,"realpath")==0){
            char resolved[PATH_MAX];
            char *rp=realpath(path,resolved);
            JinxValue out;
            free(path);
            if(handled!=NULL)*handled=1;
            if(rp==NULL) return jinx_oracle_bool_value(0);
            out=jinx_oracle_ext_copy_string(resolved,strlen(resolved));
            return out;
        }

        if(strcmp(name,"scandir")==0){
            int order=argc>=2u?(int)jinx_oracle_intish(args[1]):0;
            JinxZendArray *array=jinx_oracle_ext_string_list_from_dir(path,order);
            free(path);
            if(handled!=NULL)*handled=1;
            return array!=NULL?jinx_oracle_zend_array_value_owned(array):jinx_oracle_bool_value(0);
        }

        if(strcmp(name,"glob")==0){
            glob_t g;
            JinxZendArray *array;
            int flags=argc>=2u?(int)jinx_oracle_intish(args[1]):0;
            int rc=glob(path,flags,NULL,&g);
            free(path);
            if(handled!=NULL)*handled=1;
            if(rc==GLOB_NOMATCH){
                globfree(&g);
                return jinx_oracle_zend_array_value_owned(jinx_zend_array_new_packed(1u));
            }
            if(rc!=0){
                globfree(&g);
                return jinx_oracle_bool_value(0);
            }
            array=jinx_zend_array_new_packed(g.gl_pathc==0u?1u:g.gl_pathc);
            if(array==NULL){ globfree(&g); return result; }
            for(size_t i=0u;i<g.gl_pathc;i++){
                if(!jinx_oracle_ext_array_append_string(array,g.gl_pathv[i],strlen(g.gl_pathv[i]))){
                    jinx_zend_array_release(array); globfree(&g); return result;
                }
            }
            globfree(&g);
            return jinx_oracle_zend_array_value_owned(array);
        }

        if(strcmp(name,"is_readable")==0 ||
           strcmp(name,"is_writable")==0 ||
           strcmp(name,"is_writeable")==0 ||
           strcmp(name,"is_executable")==0){
            int mode = strcmp(name,"is_readable")==0 ? R_OK :
                (strcmp(name,"is_executable")==0 ? X_OK : W_OK);
            ok=access(path,mode)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            return jinx_oracle_bool_value(ok);
        }

        if(strcmp(name,"is_link")==0){
            struct stat st;
            ok=lstat(path,&st)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            return jinx_oracle_bool_value(ok && S_ISLNK(st.st_mode));
        }

        if(strcmp(name,"lstat")==0){
            struct stat st;
            JinxZendArray *array;
            ok=lstat(path,&st)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            if(!ok) return jinx_oracle_bool_value(0);
            array=jinx_oracle_ext_stat_array(&st);
            return array!=NULL?jinx_oracle_zend_array_value_owned(array):result;
        }

        if(strcmp(name,"stat")==0){
            struct stat st;
            JinxZendArray *array;
            ok=stat(path,&st)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            if(!ok) return jinx_oracle_bool_value(0);
            array=jinx_oracle_ext_stat_array(&st);
            return array!=NULL?jinx_oracle_zend_array_value_owned(array):result;
        }

        {
            struct stat st;
            ok=stat(path,&st)==0;
            free(path);
            if(handled!=NULL)*handled=1;
            if(!ok) return jinx_oracle_bool_value(0);
            return jinx_oracle_bool_value(
                strcmp(name,"is_dir")==0?S_ISDIR(st.st_mode):S_ISREG(st.st_mode)
            );
        }
    }

    if (strcmp(name, "spl_object_id") == 0 ||
        strcmp(name, "spl_object_hash") == 0) {
        JinxZendObject *object;
        uintptr_t raw_id;

        if (args == NULL || argc < 1u ||
            args[0].type != JINX_ORACLE_VALUE_ZEND_OBJECT) {
            return result;
        }

        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL) return result;
        raw_id = (uintptr_t)object;

        if (handled != NULL) *handled = 1;

        if (strcmp(name, "spl_object_id") == 0) {
            return jinx_oracle_int_value((int64_t)raw_id);
        }

        {
            char hash[33];
            snprintf(
                hash,
                sizeof(hash),
                "%016llx%016llx",
                (unsigned long long)raw_id,
                0ULL
            );
            return jinx_oracle_ext_copy_string(hash, 32u);
        }
    }

    if (strcmp(name, "property_exists") == 0) {
        const JinxNativeClassMeta *meta = NULL;
        JinxZendObject *object = NULL;
        char *class_name = NULL;
        char *property_name;
        int answer = 0;

        if (args == NULL || argc < 2u || args[1].type != 3u) return result;

        if (args[0].type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
            object = jinx_oracle_zend_object_ptr(args[0]);
            if (object == NULL || object->class_name == NULL) return result;
            meta = jinx_oracle_ext_class_meta(object->class_name);
        } else if (args[0].type == 3u) {
            class_name = jinx_oracle_ext_dup_string_value(args[0]);
            if (class_name == NULL) return result;
            meta = jinx_oracle_ext_class_meta(class_name);
        } else {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        property_name = jinx_oracle_ext_dup_string_value(args[1]);
        if (property_name == NULL) {
            free(class_name);
            return result;
        }

        if (object != NULL &&
            jinx_oracle_ext_object_prop(object, property_name) != NULL) {
            answer = 1;
        } else if (meta != NULL) {
            answer = jinx_oracle_ext_class_has_property(meta, property_name);
        }

        free(property_name);
        free(class_name);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(answer);
    }

    if (strcmp(name, "method_exists") == 0) {
        const JinxNativeClassMeta *meta = NULL;
        char *class_name = NULL;
        char *method_name;
        int answer;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        if (args[0].type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
            JinxZendObject *object = jinx_oracle_zend_object_ptr(args[0]);
            if (object == NULL || object->class_name == NULL) return result;
            meta = jinx_oracle_ext_class_meta(object->class_name);
            if (meta == NULL) return result;
        } else if (args[0].type == 3u) {
            class_name = jinx_oracle_ext_dup_string_value(args[0]);
            if (class_name == NULL) return result;
            meta = jinx_oracle_ext_class_meta(class_name);
            if (meta == NULL) {
                free(class_name);
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
        } else {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        method_name = jinx_oracle_ext_dup_string_value(args[1]);
        if (method_name == NULL) {
            free(class_name);
            return result;
        }
        answer = jinx_oracle_ext_class_has_method(meta, method_name);
        free(method_name);
        free(class_name);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(answer);
    }

    if (strcmp(name, "is_a") == 0 || strcmp(name, "is_subclass_of") == 0) {
        const char *instance_name = NULL;
        char *instance_string = NULL;
        char *target_name;
        int only_subclass = strcmp(name, "is_subclass_of") == 0;
        int allow_string = only_subclass;
        int known = 0;
        int answer;

        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        if (argc >= 3u) allow_string = jinx_oracle_boolish(args[2]);

        if (args[0].type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
            JinxZendObject *object = jinx_oracle_zend_object_ptr(args[0]);
            if (object == NULL || object->class_name == NULL) return result;
            instance_name = object->class_name;
        } else if (allow_string && args[0].type == 3u) {
            instance_string = jinx_oracle_ext_dup_string_value(args[0]);
            if (instance_string == NULL) return result;
            instance_name = instance_string;
            if (jinx_oracle_ext_class_meta(instance_name) == NULL) {
                free(instance_string);
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
        } else {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        target_name = jinx_oracle_ext_dup_string_value(args[1]);
        if (target_name == NULL) {
            free(instance_string);
            return result;
        }
        answer = jinx_oracle_ext_class_is_a_name(
            instance_name, target_name, only_subclass, &known
        );
        free(target_name);
        free(instance_string);
        if (!known) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(answer);
    }

    /* Object-sensitive variable predicates use generated PHP class metadata. */
    if (strcmp(name, "is_countable") == 0 || strcmp(name, "is_iterable") == 0) {
        JinxZendObject *object;
        const JinxNativeClassMeta *meta;
        int answer;

        if (args == NULL || argc < 1u ||
            args[0].type != JINX_ORACLE_VALUE_ZEND_OBJECT) {
            return result;
        }
        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL || object->class_name == NULL) return result;
        meta = jinx_oracle_ext_class_meta(object->class_name);
        if (meta == NULL) {
            /* Unknown user classes require the Oracle user-class registry. */
            return result;
        }

        answer = strcmp(name, "is_countable") == 0
            ? jinx_oracle_ext_class_implements_name(meta, "Countable")
            : jinx_oracle_ext_class_implements_name(meta, "Traversable");
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(answer);
    }

    /* Core class and constant introspection. */
    if (strcmp(name, "class_exists") == 0) {
        char *class_name;
        const JinxNativeClassMeta *meta;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        class_name = jinx_oracle_ext_dup_string_value(args[0]);
        if (class_name == NULL) return result;
        meta = jinx_oracle_ext_class_meta(class_name);
        free(class_name);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(meta != NULL);
    }

    if (strcmp(name, "class_alias") == 0) {
        /*
         * class_alias() requires Oracle's user-class registry. The generated
         * metadata table only describes internal/runtime classes, so an
         * unconditional false result would be a fabricated implementation.
         */
        return result;
    }

    if (strcmp(name, "class_implements") == 0 ||
        strcmp(name, "class_parents") == 0 ||
        strcmp(name, "class_uses") == 0) {
        char *class_name;
        int which = strcmp(name, "class_implements") == 0 ? 0 :
            (strcmp(name, "class_parents") == 0 ? 1 : 2);
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        class_name = jinx_oracle_ext_dup_string_value(args[0]);
        if (class_name == NULL) return result;
        result = jinx_oracle_ext_class_list(class_name, which, &ok);
        free(class_name);
        if (ok && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "defined") == 0 || strcmp(name, "constant") == 0) {
        char *constant_name;
        const JinxNativeConstantMeta *meta;
        JinxValue runtime_value;
        int runtime_defined;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        constant_name = jinx_oracle_ext_dup_string_value(args[0]);
        if (constant_name == NULL) return result;
        meta = jinx_oracle_ext_constant_meta(constant_name);
        runtime_defined = jinx_oracle_constant_registry_defined(constant_name);
        if (strcmp(name, "defined") == 0) {
            free(constant_name);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(meta != NULL || runtime_defined);
        }
        if (meta != NULL) {
            free(constant_name);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_ext_constant_value(meta);
        }
        if (runtime_defined &&
            jinx_oracle_constant_registry_get(
                constant_name, &runtime_value
            )) {
            free(constant_name);
            if (handled != NULL) *handled = 1;
            return runtime_value;
        }
        free(constant_name);
        /* PHP 8+ constant() throws Error for an undefined constant. */
        return result;
    }

    {
        int batch2_handled = 0;
        JinxValue batch2_result = jinx_oracle_batch2_builtin(
            name, args, argc, &batch2_handled
        );
        if (batch2_handled) {
            if (handled != NULL) *handled = 1;
            return batch2_result;
        }
    }

    return result;
}
