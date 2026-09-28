#include "jinx_oracle_solar_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

/*
 * Solar position/rise-set math follows the public-domain Paul Schlyter
 * algorithm also used by PHP timelib. The call semantics mirror php-src's
 * current date_sunrise/date_sunset/date_sun_info behavior.
 */

#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif

#define B2_SOLAR_RADEG (180.0 / M_PI)
#define B2_SOLAR_DEGRAD (M_PI / 180.0)
#define B2_SOLAR_SIND(x) sin((x) * B2_SOLAR_DEGRAD)
#define B2_SOLAR_COSD(x) cos((x) * B2_SOLAR_DEGRAD)
#define B2_SOLAR_ATAN2D(y,x) (B2_SOLAR_RADEG * atan2((y),(x)))
#define B2_SOLAR_ACOSD(x) (B2_SOLAR_RADEG * acos((x)))

static double solar_revolution(double x) {
    return x - 360.0 * floor(x / 360.0);
}

static double solar_rev180(double x) {
    return x - 360.0 * floor(x / 360.0 + 0.5);
}

static double solar_gmst0(double d) {
    return solar_revolution(
        (180.0 + 356.0470 + 282.9404) +
        (0.9856002585 + 4.70935E-5) * d
    );
}

static void solar_sunpos(double d, double *lon, double *r) {
    double M = solar_revolution(356.0470 + 0.9856002585 * d);
    double w = 282.9404 + 4.70935E-5 * d;
    double e = 0.016709 - 1.151E-9 * d;
    double E = M + e * B2_SOLAR_RADEG * B2_SOLAR_SIND(M) *
        (1.0 + e * B2_SOLAR_COSD(M));
    double x = B2_SOLAR_COSD(E) - e;
    double y = sqrt(1.0 - e * e) * B2_SOLAR_SIND(E);
    double v;

    *r = sqrt(x * x + y * y);
    v = B2_SOLAR_ATAN2D(y, x);
    *lon = v + w;
    if (*lon >= 360.0) *lon -= 360.0;
}

static void solar_sun_ra_dec(
    double d,
    double *ra,
    double *dec,
    double *r
) {
    double lon;
    double obl_ecl;
    double x;
    double y;
    double z;

    solar_sunpos(d, &lon, r);
    x = *r * B2_SOLAR_COSD(lon);
    y = *r * B2_SOLAR_SIND(lon);
    obl_ecl = 23.4393 - 3.563E-7 * d;
    z = y * B2_SOLAR_SIND(obl_ecl);
    y = y * B2_SOLAR_COSD(obl_ecl);
    *ra = B2_SOLAR_ATAN2D(y, x);
    *dec = B2_SOLAR_ATAN2D(z, sqrt(x * x + y * y));
}

static int64_t solar_days_from_civil(int y, unsigned m, unsigned d) {
    y -= m <= 2u;
    {
        const int era = (y >= 0 ? y : y - 399) / 400;
        const unsigned yoe = (unsigned)(y - era * 400);
        const unsigned mp = (unsigned)((int)m + (m > 2u ? -3 : 9));
        const unsigned doy =
            (153u * mp + 2u) / 5u + d - 1u;
        const unsigned doe =
            yoe * 365u + yoe / 4u - yoe / 100u + doy;
        return (int64_t)era * 146097 + (int64_t)doe - 719468;
    }
}

static int64_t solar_utc_midnight(int y, int m, int d) {
    return solar_days_from_civil(y, (unsigned)m, (unsigned)d) * 86400LL;
}

static int solar_tz_enter(char **saved, int *had_saved) {
    const char *old = getenv("TZ");
    *saved = NULL;
    *had_saved = old != NULL;
    if (old != NULL) {
        *saved = strdup(old);
        if (*saved == NULL) return 0;
    }
    if (setenv("TZ", JINX_NATIVE_PHP_DEFAULT_TIMEZONE, 1) != 0) {
        free(*saved);
        *saved = NULL;
        return 0;
    }
    tzset();
    return 1;
}

static void solar_tz_leave(char *saved, int had_saved) {
    if (had_saved) {
        (void)setenv("TZ", saved != NULL ? saved : "", 1);
    } else {
        (void)unsetenv("TZ");
    }
    free(saved);
    tzset();
}

static int solar_local_date(
    int64_t timestamp,
    int *year,
    int *month,
    int *day
) {
    time_t raw = (time_t)timestamp;
    struct tm tmv;
    char *saved;
    int had_saved;
    int ok;

    if (!solar_tz_enter(&saved, &had_saved)) return 0;
    ok = localtime_r(&raw, &tmv) != NULL;
    solar_tz_leave(saved, had_saved);
    if (!ok) return 0;

    *year = tmv.tm_year + 1900;
    *month = tmv.tm_mon + 1;
    *day = tmv.tm_mday;
    return 1;
}

static double solar_current_utc_offset_hours(void) {
    time_t now = time(NULL);
    struct tm local_tm;
    struct tm utc_tm;
    char *saved;
    int had_saved;
    int64_t local_seconds;
    int64_t utc_seconds;

    if (!solar_tz_enter(&saved, &had_saved)) return 0.0;
    if (localtime_r(&now, &local_tm) == NULL) {
        solar_tz_leave(saved, had_saved);
        return 0.0;
    }
    solar_tz_leave(saved, had_saved);

    if (gmtime_r(&now, &utc_tm) == NULL) return 0.0;

    local_seconds =
        solar_utc_midnight(
            local_tm.tm_year + 1900,
            local_tm.tm_mon + 1,
            local_tm.tm_mday
        ) +
        (int64_t)local_tm.tm_hour * 3600 +
        (int64_t)local_tm.tm_min * 60 +
        local_tm.tm_sec;

    utc_seconds =
        solar_utc_midnight(
            utc_tm.tm_year + 1900,
            utc_tm.tm_mon + 1,
            utc_tm.tm_mday
        ) +
        (int64_t)utc_tm.tm_hour * 3600 +
        (int64_t)utc_tm.tm_min * 60 +
        utc_tm.tm_sec;

    return (double)(local_seconds - utc_seconds) / 3600.0;
}

static int solar_rise_set(
    int year,
    int month,
    int day,
    double lon,
    double lat,
    double altitude,
    int upper_limb,
    double *h_rise,
    double *h_set,
    int64_t *ts_rise,
    int64_t *ts_set,
    int64_t *ts_transit
) {
    int64_t utc_midnight = solar_utc_midnight(year, month, day);
    double d =
        ((double)utc_midnight / 86400.0 + 2440587.5 - 2451545.0) +
        2.0 - lon / 360.0;
    double sidtime = solar_revolution(solar_gmst0(d) + 180.0 + lon);
    double sra;
    double sdec;
    double sr;
    double tsouth;
    double sradius;
    double cost;
    double t;

    solar_sun_ra_dec(d, &sra, &sdec, &sr);
    tsouth = 12.0 - solar_rev180(sidtime - sra) / 15.0;
    sradius = 0.2666 / sr;
    if (upper_limb) altitude -= sradius;

    cost =
        (B2_SOLAR_SIND(altitude) -
         B2_SOLAR_SIND(lat) * B2_SOLAR_SIND(sdec)) /
        (B2_SOLAR_COSD(lat) * B2_SOLAR_COSD(sdec));

    *ts_transit = utc_midnight + (int64_t)(tsouth * 3600.0);

    if (cost >= 1.0) {
        *ts_rise = *ts_set = *ts_transit;
        return -1;
    }
    if (cost <= -1.0) {
        *ts_rise = *ts_transit - 12 * 3600;
        *ts_set = *ts_transit + 12 * 3600;
        return 1;
    }

    t = B2_SOLAR_ACOSD(cost) / 15.0;
    *ts_rise = utc_midnight + (int64_t)((tsouth - t) * 3600.0);
    *ts_set = utc_midnight + (int64_t)((tsouth + t) * 3600.0);
    if (h_rise != NULL) *h_rise = tsouth - t;
    if (h_set != NULL) *h_set = tsouth + t;
    return 0;
}

static double solar_cfg_double(const char *key, double fallback) {
    for (size_t i = 0u; i < jinx_native_cfg_metadata_count; i++) {
        if (strcmp(jinx_native_cfg_metadata[i].name, key) == 0) {
            char *end = NULL;
            double value = strtod(jinx_native_cfg_metadata[i].value, &end);
            if (end != jinx_native_cfg_metadata[i].value && *end == '\0') {
                return value;
            }
            break;
        }
    }
    return fallback;
}

static int solar_add_value(
    JinxZendArray *array,
    const char *key,
    int kind,
    int64_t number
) {
    JinxZendValue value = kind == 0
        ? jinx_zend_long(number)
        : jinx_zend_bool(number != 0);
    return jinx_zend_array_add_assoc(array, key, strlen(key), value);
}

static int solar_add_pair(
    JinxZendArray *array,
    int rc,
    const char *begin_key,
    const char *end_key,
    int64_t rise,
    int64_t set
) {
    if (rc == -1) {
        return solar_add_value(array, begin_key, 1, 0) &&
            solar_add_value(array, end_key, 1, 0);
    }
    if (rc == 1) {
        return solar_add_value(array, begin_key, 1, 1) &&
            solar_add_value(array, end_key, 1, 1);
    }
    return solar_add_value(array, begin_key, 0, rise) &&
        solar_add_value(array, end_key, 0, set);
}

static JinxValue solar_info(
    int64_t timestamp,
    double latitude,
    double longitude
) {
    int year;
    int month;
    int day;
    int rc;
    int64_t rise;
    int64_t set;
    int64_t transit;
    JinxZendArray *array;

    if (!isfinite(latitude) || !isfinite(longitude)) {
        return jinx_oracle_zero_value();
    }
    if (!solar_local_date(timestamp, &year, &month, &day)) {
        return jinx_oracle_zero_value();
    }

    array = jinx_zend_array_new_packed(9u);
    if (array == NULL) return jinx_oracle_zero_value();

    rc = solar_rise_set(
        year, month, day, longitude, latitude,
        -35.0 / 60.0, 1, NULL, NULL, &rise, &set, &transit
    );
    if (!solar_add_pair(array, rc, "sunrise", "sunset", rise, set) ||
        !solar_add_value(array, "transit", 0, transit)) {
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
    }

    rc = solar_rise_set(
        year, month, day, longitude, latitude,
        -6.0, 0, NULL, NULL, &rise, &set, &transit
    );
    if (!solar_add_pair(
        array, rc,
        "civil_twilight_begin", "civil_twilight_end",
        rise, set
    )) {
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
    }

    rc = solar_rise_set(
        year, month, day, longitude, latitude,
        -12.0, 0, NULL, NULL, &rise, &set, &transit
    );
    if (!solar_add_pair(
        array, rc,
        "nautical_twilight_begin", "nautical_twilight_end",
        rise, set
    )) {
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
    }

    rc = solar_rise_set(
        year, month, day, longitude, latitude,
        -18.0, 0, NULL, NULL, &rise, &set, &transit
    );
    if (!solar_add_pair(
        array, rc,
        "astronomical_twilight_begin", "astronomical_twilight_end",
        rise, set
    )) {
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_array_value_owned(array);
}

JinxValue jinx_oracle_solar_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "date_sun_info") == 0) {
        if (args == NULL || argc != 3u) return result;
        result = solar_info(
            jinx_oracle_intish(args[0]),
            jinx_oracle_floatish(args[1]),
            jinx_oracle_floatish(args[2])
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "date_sunrise") == 0 ||
        strcmp(name, "date_sunset") == 0) {
        int year;
        int month;
        int day;
        int64_t timestamp;
        int retformat = 1;
        double latitude;
        double longitude;
        double zenith;
        double gmt_offset;
        double h_rise = 0.0;
        double h_set = 0.0;
        int64_t rise;
        int64_t set;
        int64_t transit;
        int rc;
        int calc_sunset = strcmp(name, "date_sunset") == 0;

        if (args == NULL || argc < 1u || argc > 6u) return result;

        timestamp = jinx_oracle_intish(args[0]);
        if (argc >= 2u && args[1].type != 0u) {
            retformat = (int)jinx_oracle_intish(args[1]);
        }
        if (retformat != 0 && retformat != 1 && retformat != 2) {
            return result;
        }

        latitude =
            argc >= 3u && args[2].type != 0u
            ? jinx_oracle_floatish(args[2])
            : solar_cfg_double("date.default_latitude", 31.7667);

        longitude =
            argc >= 4u && args[3].type != 0u
            ? jinx_oracle_floatish(args[3])
            : solar_cfg_double("date.default_longitude", 35.2333);

        zenith =
            argc >= 5u && args[4].type != 0u
            ? jinx_oracle_floatish(args[4])
            : solar_cfg_double(
                calc_sunset ? "date.sunset_zenith" : "date.sunrise_zenith",
                90.833333
            );

        gmt_offset =
            argc >= 6u && args[5].type != 0u
            ? jinx_oracle_floatish(args[5])
            : solar_current_utc_offset_hours();

        if (!isfinite(latitude) || !isfinite(longitude) ||
            !isfinite(zenith) || !isfinite(gmt_offset)) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (!solar_local_date(timestamp, &year, &month, &day)) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        rc = solar_rise_set(
            year, month, day, longitude, latitude,
            90.0 - zenith, 1,
            &h_rise, &h_set,
            &rise, &set, &transit
        );
        if (handled != NULL) *handled = 1;
        if (rc != 0) return jinx_oracle_bool_value(0);

        if (retformat == 0) {
            return jinx_oracle_int_value(calc_sunset ? set : rise);
        }

        {
            double n = (calc_sunset ? h_set : h_rise) + gmt_offset;
            if (n > 24.0 || n < 0.0) {
                n -= floor(n / 24.0) * 24.0;
            }
            if (!(n <= 24.0 && n >= 0.0)) {
                return jinx_oracle_bool_value(0);
            }

            if (retformat == 2) {
                return jinx_oracle_float_value(n);
            }

            {
                char buffer[16];
                char *out;
                int hour = (int)n;
                int minute = (int)(60.0 * (n - (double)hour));
                int length = snprintf(
                    buffer, sizeof(buffer),
                    "%02d:%02d", hour, minute
                );
                if (length < 0 || (size_t)length >= sizeof(buffer)) {
                    return jinx_oracle_zero_value();
                }
                out = jinx_oracle_scratch_string((uint32_t)length);
                memcpy(out, buffer, (size_t)length);
                return jinx_oracle_string_value_len(
                    out, (uint32_t)length
                );
            }
        }
    }

    return result;
}
