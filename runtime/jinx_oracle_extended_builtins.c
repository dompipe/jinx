#include "jinx_oracle_extended_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <grp.h>
#include <pwd.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

typedef struct JinxOracleExtStream {
    FILE *fp;
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

typedef struct JinxOracleExtTzScope {
    char *old_tz;
    int had_old;
} JinxOracleExtTzScope;

static char jinx_oracle_ext_default_timezone[128] = JINX_NATIVE_PHP_DEFAULT_TIMEZONE;
static int jinx_oracle_ext_date_error = 0;
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

static int jinx_oracle_ext_parse_datetime_text(
    const char *text,
    const char *timezone,
    int64_t *timestamp
) {
    int y, m, d, hh = 0, mm = 0, ss = 0;
    JinxOracleExtInterval interval;
    time_t now;

    if (text == NULL || timestamp == NULL) return 0;
    if (text[0] == '\0' || strcasecmp(text, "now") == 0) {
        *timestamp = (int64_t)time(NULL);
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
        now = time(NULL);
        return jinx_oracle_ext_apply_interval((int64_t)now, timezone, &interval, 1, timestamp);
    }

    return 0;
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

static JinxValue jinx_oracle_ext_new_stream(FILE *fp) {
    JinxZendObject *object;
    JinxOracleExtStream *stream;

    if (fp == NULL) return jinx_oracle_zero_value();
    stream = (JinxOracleExtStream *)calloc(1u, sizeof(*stream));
    if (stream == NULL) {
        fclose(fp);
        return jinx_oracle_zero_value();
    }
    stream->fp = fp;

    object = jinx_zend_object_new("stream");
    if (object == NULL || !jinx_oracle_ext_object_set_resource(object, "__stream", stream)) {
        fclose(fp);
        free(stream);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
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
        fputs("a,b\\nsecond line\\n", fp);
        rewind(fp);
        return jinx_oracle_ext_new_stream(fp);
    }

    return jinx_oracle_zero_value();
}

/* ---------------- main extended builtin dispatcher ---------------- */

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
        strcmp(name, "chgrp") == 0 ||
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
        if (strcmp(name, "chown") == 0) {
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
            ok = chown(path, uid, (gid_t)-1) == 0;
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
        if (strcmp(name, "chgrp") == 0) {
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
            ok = chown(path, (uid_t)-1, gid) == 0;
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
        strcmp(name, "flock") == 0) {
        JinxZendValue *slot = NULL;
        JinxOracleExtStream *stream;
        if (args == NULL || argc < 1u) return result;
        stream = jinx_oracle_ext_stream_from_value(args[0], &slot);
        if (stream == NULL || stream->fp == NULL) return result;

        if (strcmp(name, "fclose") == 0) {
            ok = fclose(stream->fp) == 0;
            stream->fp = NULL;
            free(stream);
            slot->type = JINX_ZEND_NULL;
            slot->value.ptr = NULL;
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
            ssize_t n = getline(&line, &cap, stream->fp);
            if (handled != NULL) *handled = 1;
            if (n < 0) { free(line); return jinx_oracle_bool_value(0); }
            if (argc >= 2u) {
                int64_t length = jinx_oracle_intish(args[1]);
                if (length < 2) { free(line); return result; }
                if ((int64_t)n >= length) n = length - 1;
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
        char *original;
        char *alias;
        const JinxNativeClassMeta *meta;
        if (args == NULL || argc < 2u || args[0].type != 3u || args[1].type != 3u) return result;
        original = jinx_oracle_ext_dup_string_value(args[0]);
        alias = jinx_oracle_ext_dup_string_value(args[1]);
        if (original == NULL || alias == NULL) { free(original); free(alias); return result; }

        /*
         * The generated metadata contains PHP's internal classes. PHP does not
         * allow class_alias() to alias those as user-defined classes. Until
         * Oracle's user-class registry is connected here, preserve the exact
         * internal/missing-class result instead of inventing an alias.
         */
        meta = jinx_oracle_ext_class_meta(original);
        ok = 0;
        (void)meta;
        free(original);
        free(alias);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok);
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
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        constant_name = jinx_oracle_ext_dup_string_value(args[0]);
        if (constant_name == NULL) return result;
        meta = jinx_oracle_ext_constant_meta(constant_name);
        if (strcmp(name, "defined") == 0) {
            free(constant_name);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(meta != NULL);
        }
        free(constant_name);
        if (handled != NULL) *handled = 1;
        return meta != NULL ? jinx_oracle_ext_constant_value(meta) : jinx_oracle_zero_value();
    }

    return result;
}
