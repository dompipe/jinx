#ifndef JINX_ORACLE_ASM_RUNTIME_H
#define JINX_ORACLE_ASM_RUNTIME_H

#include <stdint.h>
#include <stddef.h>
#include <ctype.h>
#include <math.h>
#include <locale.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/*
 * Low-level native Oracle/PASM runtime carrier for generated wrappers.
 * Exact handlers live here only when they can be implemented from the PHP
 * manual using the current JinxValue model. Complex PHP families remain
 * explicit PHP fallbacks or sandbox-blocked until native storage/security
 * models exist.
 */

typedef struct JinxOracleAsmContext JinxOracleAsmContext;
typedef struct JinxValue JinxValue;

struct JinxValue {
    uint32_t type;
    uint32_t flags;
    union {
        int64_t i64;
        double f64;
        void *ptr;
    } as;
};

enum JinxOracleRegister {
    JINX_ORA_ACC = 0,
    JINX_ORA_RET = 1,
    JINX_ORA_R0 = 16,
    JINX_ORA_R1 = 17,
    JINX_ORA_R2 = 18,
    JINX_ORA_R3 = 19,
    JINX_ORA_R4 = 20,
    JINX_ORA_R5 = 21,
    JINX_ORA_R6 = 22,
    JINX_ORA_R7 = 23,
    JINX_ORA_R8 = 24,
    JINX_ORA_R9 = 25,
    JINX_ORA_R10 = 26,
    JINX_ORA_R11 = 27,
    JINX_ORA_R12 = 28,
    JINX_ORA_R13 = 29,
    JINX_ORA_R14 = 30,
    JINX_ORA_R15 = 31,
    JINX_ORA_R16 = 32,
    JINX_ORA_R17 = 33,
    JINX_ORA_R18 = 34,
    JINX_ORA_R19 = 35,
    JINX_ORA_R20 = 36,
    JINX_ORA_R21 = 37,
    JINX_ORA_R22 = 38,
    JINX_ORA_R23 = 39,
    JINX_ORA_R24 = 40,
    JINX_ORA_R25 = 41,
    JINX_ORA_R26 = 42,
    JINX_ORA_R27 = 43,
    JINX_ORA_R28 = 44,
    JINX_ORA_R29 = 45,
    JINX_ORA_R30 = 46,
    JINX_ORA_R31 = 47,
};

struct JinxOracleAsmContext {
    JinxValue registers[64];
    JinxValue call_args[64];
    uint8_t register_valid[64];
    uint8_t register_arg_index[64];
    uint8_t call_arg_kinds[64];
    uint8_t call_arg_source_index[64];
    JinxValue *argv;
    uint32_t argc;
    uint32_t call_argc;
    const char *fault;
};

static inline JinxValue jinx_oracle_zero_value(void) {
    JinxValue v;
    v.type = 0;
    v.flags = 0;
    v.as.i64 = 0;
    return v;
}

static inline JinxValue jinx_oracle_int_value(int64_t value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 1u;
    v.as.i64 = value;
    return v;
}

static inline JinxValue jinx_oracle_bool_value(int value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 2u;
    v.as.i64 = value ? 1 : 0;
    return v;
}

static inline JinxValue jinx_oracle_string_value(const char *value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 3u;
    v.as.ptr = (void *)value;
    v.flags = value == NULL ? 0u : (uint32_t)strlen(value);
    return v;
}

static inline JinxValue jinx_oracle_string_value_len(const char *value, uint32_t len) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 3u;
    v.as.ptr = (void *)value;
    v.flags = value == NULL ? 0u : len;
    return v;
}

static inline JinxValue jinx_oracle_array_count_value(uint32_t count) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 4u;
    v.flags = count;
    v.as.i64 = (int64_t)count;
    return v;
}

static inline JinxValue jinx_oracle_float_value(double value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 5u;
    v.as.f64 = value;
    return v;
}

static inline const unsigned char *jinx_oracle_string_bytes(JinxValue value) {
    return value.type == 3u && value.as.ptr != NULL ? (const unsigned char *)value.as.ptr : (const unsigned char *)"";
}

static inline uint32_t jinx_oracle_string_len(JinxValue value) {
    return value.type == 3u ? value.flags : 0u;
}

static inline int64_t jinx_oracle_intish(JinxValue value);
static inline double jinx_oracle_floatish(JinxValue value);

static inline char *jinx_oracle_scratch_string(uint32_t len) {
    enum { JINX_ORACLE_SCRATCH_SLOTS = 8 };
    static char *buffers[JINX_ORACLE_SCRATCH_SLOTS] = {0};
    static size_t capacities[JINX_ORACLE_SCRATCH_SLOTS] = {0};
    static uint32_t slot = 0u;
    size_t needed = (size_t)len + 1u;
    char *buffer;

    slot = (slot + 1u) % JINX_ORACLE_SCRATCH_SLOTS;

    if (capacities[slot] < needed) {
        char *grown = (char *)realloc(buffers[slot], needed);
        if (grown == NULL) {
            fputs("JINX Oracle ASM scratch allocation failed\n", stderr);
            abort();
        }
        buffers[slot] = grown;
        capacities[slot] = needed;
    }

    buffer = buffers[slot];
    buffer[len] = '\0';
    return buffer;
}

static inline unsigned char jinx_oracle_ascii_lower_byte(unsigned char c) {
    return c >= (unsigned char)'A' && c <= (unsigned char)'Z'
        ? (unsigned char)(c + ((unsigned char)'a' - (unsigned char)'A'))
        : c;
}

static inline unsigned char jinx_oracle_ascii_upper_byte(unsigned char c) {
    return c >= (unsigned char)'a' && c <= (unsigned char)'z'
        ? (unsigned char)(c - ((unsigned char)'a' - (unsigned char)'A'))
        : c;
}

static inline JinxValue jinx_oracle_string_transform_case(JinxValue value, int upper) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    for (uint32_t i = 0; i < len; i++) {
        out[i] = (char)(upper
            ? jinx_oracle_ascii_upper_byte(bytes[i])
            : jinx_oracle_ascii_lower_byte(bytes[i]));
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_string_transform_first(JinxValue value, int upper) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    if (len != 0u) {
        memcpy(out, bytes, len);
        out[0] = (char)(upper
            ? jinx_oracle_ascii_upper_byte((unsigned char)out[0])
            : jinx_oracle_ascii_lower_byte((unsigned char)out[0]));
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_string_reverse(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    for (uint32_t i = 0; i < len; i++) {
        out[i] = (char)bytes[len - 1u - i];
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline int jinx_oracle_default_trim_byte(unsigned char c) {
    return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\0' || c == '\v';
}

static inline JinxValue jinx_oracle_string_trim_default(JinxValue value, int left, int right) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t start = 0u;
    uint32_t end = len;
    char *out;

    if (left) {
        while (start < end && jinx_oracle_default_trim_byte(bytes[start])) {
            start++;
        }
    }

    if (right) {
        while (end > start && jinx_oracle_default_trim_byte(bytes[end - 1u])) {
            end--;
        }
    }

    out = jinx_oracle_scratch_string(end - start);
    if (end > start) {
        memcpy(out, bytes + start, end - start);
    }

    return jinx_oracle_string_value_len(out, end - start);
}

static inline JinxValue jinx_oracle_chr_value(JinxValue value) {
    char *out = jinx_oracle_scratch_string(1u);
    out[0] = (char)((unsigned int)jinx_oracle_intish(value) & 0xffu);
    return jinx_oracle_string_value_len(out, 1u);
}

static inline JinxValue jinx_oracle_ord_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    return jinx_oracle_int_value(len == 0u ? 0 : (int64_t)bytes[0]);
}

static inline JinxValue jinx_oracle_substr_value(JinxValue value, JinxValue offset_value, JinxValue length_value, uint32_t argc) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t offset = jinx_oracle_intish(offset_value);
    int64_t requested = argc >= 3u ? jinx_oracle_intish(length_value) : (int64_t)len;
    int64_t start = offset < 0 ? (int64_t)len + offset : offset;
    int64_t available;
    uint32_t out_len;
    char *out;

    if (start < 0) {
        start = 0;
    }

    if (start > (int64_t)len) {
        start = (int64_t)len;
    }

    available = (int64_t)len - start;
    if (requested < 0) {
        requested = available + requested;
    }

    if (requested < 0) {
        requested = 0;
    }

    if (requested > available) {
        requested = available;
    }

    out_len = (uint32_t)requested;
    out = jinx_oracle_scratch_string(out_len);
    if (out_len != 0u) {
        memcpy(out, bytes + start, out_len);
    }

    return jinx_oracle_string_value_len(out, out_len);
}

static inline int jinx_oracle_string_match_at(
    const unsigned char *haystack,
    const unsigned char *needle,
    uint32_t needle_len,
    uint32_t offset,
    int fold_case
) {
    for (uint32_t i = 0u; i < needle_len; i++) {
        unsigned char a = haystack[offset + i];
        unsigned char b = needle[i];

        if (fold_case) {
            a = jinx_oracle_ascii_lower_byte(a);
            b = jinx_oracle_ascii_lower_byte(b);
        }

        if (a != b) {
            return 0;
        }
    }

    return 1;
}

static inline JinxValue jinx_oracle_strpos_value(
    JinxValue haystack_value,
    JinxValue needle_value,
    JinxValue offset_value,
    uint32_t argc,
    int fold_case
) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    int64_t raw_offset = argc >= 3u ? jinx_oracle_intish(offset_value) : 0;
    uint32_t offset;

    if (raw_offset < 0) {
        raw_offset = (int64_t)haystack_len + raw_offset;
    }

    if (raw_offset < 0 || raw_offset > (int64_t)haystack_len) {
        return jinx_oracle_bool_value(0);
    }

    offset = (uint32_t)raw_offset;

    if (needle_len == 0u) {
        return jinx_oracle_int_value((int64_t)offset);
    }

    if (needle_len > haystack_len || offset > haystack_len - needle_len) {
        return jinx_oracle_bool_value(0);
    }

    for (uint32_t i = offset; i <= haystack_len - needle_len; i++) {
        if (jinx_oracle_string_match_at(haystack, needle, needle_len, i, fold_case)) {
            return jinx_oracle_int_value((int64_t)i);
        }
    }

    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_strrpos_value(
    JinxValue haystack_value,
    JinxValue needle_value,
    JinxValue offset_value,
    uint32_t argc,
    int fold_case
) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    int64_t raw_offset = argc >= 3u ? jinx_oracle_intish(offset_value) : 0;
    int64_t min_start = 0;
    int64_t max_start;

    if (raw_offset > (int64_t)haystack_len || raw_offset < -(int64_t)haystack_len) {
        return jinx_oracle_bool_value(0);
    }

    if (raw_offset >= 0) {
        min_start = raw_offset;
        max_start = (int64_t)haystack_len;
    } else {
        max_start = (int64_t)haystack_len + raw_offset;
    }

    if (needle_len == 0u) {
        if (max_start < min_start) {
            return jinx_oracle_bool_value(0);
        }
        return jinx_oracle_int_value(max_start);
    }

    if (needle_len > haystack_len) {
        return jinx_oracle_bool_value(0);
    }

    {
        int64_t last_valid_start = (int64_t)(haystack_len - needle_len);
        if (max_start > last_valid_start) {
            max_start = last_valid_start;
        }
    }

    if (max_start < min_start) {
        return jinx_oracle_bool_value(0);
    }

    for (int64_t i = max_start; i >= min_start; i--) {
        if (jinx_oracle_string_match_at(haystack, needle, needle_len, (uint32_t)i, fold_case)) {
            return jinx_oracle_int_value(i);
        }
    }

    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_string_slice_copy(
    const unsigned char *bytes,
    uint32_t start,
    uint32_t len
) {
    char *out = jinx_oracle_scratch_string(len);
    if (len != 0u) {
        memcpy(out, bytes + start, len);
    }
    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_strstr_value(
    JinxValue haystack_value,
    JinxValue needle_value,
    JinxValue before_value,
    uint32_t argc,
    int fold_case
) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    uint32_t position = 0u;
    int found = 0;
    int before = argc >= 3u && jinx_oracle_intish(before_value) != 0;

    if (needle_len == 0u) {
        found = 1;
    } else if (needle_len <= haystack_len) {
        for (uint32_t i = 0u; i <= haystack_len - needle_len; i++) {
            if (jinx_oracle_string_match_at(haystack, needle, needle_len, i, fold_case)) {
                position = i;
                found = 1;
                break;
            }
        }
    }

    if (!found) {
        return jinx_oracle_bool_value(0);
    }

    if (before) {
        return jinx_oracle_string_slice_copy(haystack, 0u, position);
    }

    return jinx_oracle_string_slice_copy(haystack, position, haystack_len - position);
}

static inline JinxValue jinx_oracle_stripslashes_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];

        if (c == (unsigned char)'\\') {
            i++;
            if (i >= len) {
                break;
            }
            c = bytes[i] == (unsigned char)'0' ? 0u : bytes[i];
        }

        out[pos++] = (char)c;
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_is_quotemeta_byte(unsigned char c) {
    return c == (unsigned char)'.' || c == (unsigned char)'\\' ||
        c == (unsigned char)'+' || c == (unsigned char)'*' ||
        c == (unsigned char)'?' || c == (unsigned char)'[' ||
        c == (unsigned char)'^' || c == (unsigned char)']' ||
        c == (unsigned char)'(' || c == (unsigned char)'$' ||
        c == (unsigned char)')';
}

static inline JinxValue jinx_oracle_quotemeta_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t needed = len;

    if (len == 0u) {
        return jinx_oracle_string_value_len("", 0u);
    }

    for (uint32_t i = 0u; i < len; i++) {
        if (jinx_oracle_is_quotemeta_byte(bytes[i])) {
            needed++;
        }
    }

    if (needed > UINT32_MAX) {
        return jinx_oracle_bool_value(0);
    }

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        if (jinx_oracle_is_quotemeta_byte(bytes[i])) {
            out[pos++] = '\\';
        }
        out[pos++] = (char)bytes[i];
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_strpbrk_value(JinxValue value, JinxValue characters_value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    const unsigned char *characters = jinx_oracle_string_bytes(characters_value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t characters_len = jinx_oracle_string_len(characters_value);

    for (uint32_t i = 0u; i < len; i++) {
        for (uint32_t j = 0u; j < characters_len; j++) {
            if (bytes[i] == characters[j]) {
                return jinx_oracle_string_slice_copy(bytes, i, len - i);
            }
        }
    }

    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_chunk_split_value(
    JinxValue value,
    JinxValue length_value,
    JinxValue separator_value,
    uint32_t argc
) {
    static const unsigned char default_separator[] = "\r\n";
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t raw_length = argc >= 2u ? jinx_oracle_intish(length_value) : 76;
    const unsigned char *separator = argc >= 3u
        ? jinx_oracle_string_bytes(separator_value)
        : default_separator;
    uint32_t separator_len = argc >= 3u
        ? jinx_oracle_string_len(separator_value)
        : 2u;

    if (len == 0u) {
        return jinx_oracle_string_value_len("", 0u);
    }

    if (raw_length <= 0) {
        return jinx_oracle_bool_value(0);
    }

    uint64_t chunk_length = (uint64_t)raw_length;
    uint64_t chunks = ((uint64_t)len + chunk_length - 1u) / chunk_length;
    uint64_t needed = (uint64_t)len + chunks * (uint64_t)separator_len;

    if (needed > UINT32_MAX) {
        return jinx_oracle_bool_value(0);
    }

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t source = 0u;
    uint32_t pos = 0u;

    while (source < len) {
        uint32_t remaining = len - source;
        uint32_t take = remaining < (uint64_t)raw_length ? remaining : (uint32_t)raw_length;

        memcpy(out + pos, bytes + source, take);
        pos += take;
        source += take;

        if (separator_len != 0u) {
            memcpy(out + pos, separator, separator_len);
            pos += separator_len;
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_byte_in_set(
    unsigned char value,
    const unsigned char *set,
    uint32_t set_len
) {
    for (uint32_t i = 0u; i < set_len; i++) {
        if (value == set[i]) {
            return 1;
        }
    }
    return 0;
}

static inline JinxValue jinx_oracle_strrchr_value(
    JinxValue haystack_value,
    JinxValue needle_value,
    JinxValue before_value,
    uint32_t argc
) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    unsigned char target = needle_len == 0u ? 0u : needle[0];
    int before = argc >= 3u && jinx_oracle_intish(before_value) != 0;

    for (uint32_t i = haystack_len; i > 0u; i--) {
        uint32_t position = i - 1u;
        if (haystack[position] == target) {
            if (before) {
                return jinx_oracle_string_slice_copy(haystack, 0u, position);
            }
            return jinx_oracle_string_slice_copy(haystack, position, haystack_len - position);
        }
    }

    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_span_value(
    JinxValue value,
    JinxValue characters_value,
    JinxValue offset_value,
    JinxValue length_value,
    uint32_t argc,
    int require_member
) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    const unsigned char *characters = jinx_oracle_string_bytes(characters_value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t characters_len = jinx_oracle_string_len(characters_value);
    int64_t offset = argc >= 3u ? jinx_oracle_intish(offset_value) : 0;
    int64_t start = offset < 0 ? (int64_t)len + offset : offset;
    int64_t available;
    int64_t requested;
    uint32_t count = 0u;

    if (start < 0) {
        start = 0;
    }
    if (start > (int64_t)len) {
        start = (int64_t)len;
    }

    available = (int64_t)len - start;
    requested = (argc >= 4u && length_value.type != 0u)
        ? jinx_oracle_intish(length_value)
        : available;

    if (requested < 0) {
        requested = available + requested;
    }
    if (requested < 0) {
        requested = 0;
    }
    if (requested > available) {
        requested = available;
    }

    for (int64_t i = 0; i < requested; i++) {
        int member = jinx_oracle_byte_in_set(
            bytes[(uint32_t)(start + i)],
            characters,
            characters_len
        );

        if ((require_member && !member) || (!require_member && member)) {
            break;
        }
        count++;
    }

    return jinx_oracle_int_value((int64_t)count);
}

static inline JinxValue jinx_oracle_ucwords_value(
    JinxValue value,
    JinxValue separators_value,
    uint32_t argc
) {
    static const unsigned char default_separators[] = " \t\r\n\f\v";
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    const unsigned char *separators = argc >= 2u
        ? jinx_oracle_string_bytes(separators_value)
        : default_separators;
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t separators_len = argc >= 2u
        ? jinx_oracle_string_len(separators_value)
        : 6u;
    char *out = jinx_oracle_scratch_string(len);
    int word_start = 1;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];
        out[i] = (char)(word_start ? jinx_oracle_ascii_upper_byte(c) : c);
        word_start = jinx_oracle_byte_in_set(c, separators, separators_len);
    }

    return jinx_oracle_string_value_len(out, len);
}


static inline int jinx_oracle_hex_nibble(unsigned char c);

static inline int jinx_oracle_ascii_alnum(unsigned char c) {
    return (c >= (unsigned char)'A' && c <= (unsigned char)'Z') ||
        (c >= (unsigned char)'a' && c <= (unsigned char)'z') ||
        (c >= (unsigned char)'0' && c <= (unsigned char)'9');
}

static inline JinxValue jinx_oracle_base64_encode_value(JinxValue value) {
    static const char alphabet[] =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t needed = ((uint64_t)len + 2u) / 3u * 4u;

    if (needed > UINT32_MAX) {
        return jinx_oracle_bool_value(0);
    }

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i += 3u) {
        uint32_t remain = len - i;
        uint32_t triple = (uint32_t)bytes[i] << 16u;

        if (remain > 1u) {
            triple |= (uint32_t)bytes[i + 1u] << 8u;
        }
        if (remain > 2u) {
            triple |= (uint32_t)bytes[i + 2u];
        }

        out[pos++] = alphabet[(triple >> 18u) & 0x3fu];
        out[pos++] = alphabet[(triple >> 12u) & 0x3fu];
        out[pos++] = remain > 1u ? alphabet[(triple >> 6u) & 0x3fu] : '=';
        out[pos++] = remain > 2u ? alphabet[triple & 0x3fu] : '=';
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_base64_digit(unsigned char c) {
    if (c >= (unsigned char)'A' && c <= (unsigned char)'Z') return (int)(c - (unsigned char)'A');
    if (c >= (unsigned char)'a' && c <= (unsigned char)'z') return 26 + (int)(c - (unsigned char)'a');
    if (c >= (unsigned char)'0' && c <= (unsigned char)'9') return 52 + (int)(c - (unsigned char)'0');
    if (c == (unsigned char)'+') return 62;
    if (c == (unsigned char)'/') return 63;
    return -1;
}

static inline JinxValue jinx_oracle_base64_decode_value(JinxValue value, JinxValue strict_value, uint32_t argc) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int strict = argc >= 2u && jinx_oracle_boolish(strict_value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;
    int quartet[4];
    uint32_t q = 0u;
    int saw_padding = 0;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];

        if (c == ' ' || c == '\t' || c == '\r' || c == '\n') {
            continue;
        }

        if (c == '=') {
            saw_padding = 1;
            quartet[q++] = -2;
        } else {
            int digit = jinx_oracle_base64_digit(c);
            if (digit < 0) {
                if (strict) {
                    return jinx_oracle_bool_value(0);
                }
                continue;
            }
            if (saw_padding) {
                return jinx_oracle_bool_value(0);
            }
            quartet[q++] = digit;
        }

        if (q == 4u) {
            if (quartet[0] < 0 || quartet[1] < 0 ||
                (quartet[2] == -2 && quartet[3] != -2)) {
                return jinx_oracle_bool_value(0);
            }

            uint32_t triple =
                ((uint32_t)quartet[0] << 18u) |
                ((uint32_t)quartet[1] << 12u) |
                ((uint32_t)(quartet[2] < 0 ? 0 : quartet[2]) << 6u) |
                (uint32_t)(quartet[3] < 0 ? 0 : quartet[3]);

            out[pos++] = (char)((triple >> 16u) & 0xffu);
            if (quartet[2] != -2) out[pos++] = (char)((triple >> 8u) & 0xffu);
            if (quartet[3] != -2) out[pos++] = (char)(triple & 0xffu);
            q = 0u;
        }
    }

    if (q != 0u) {
        if (strict || q == 1u) {
            return jinx_oracle_bool_value(0);
        }

        while (q < 4u) {
            quartet[q++] = -2;
        }

        if (quartet[0] < 0 || quartet[1] < 0) {
            return jinx_oracle_bool_value(0);
        }

        uint32_t triple =
            ((uint32_t)quartet[0] << 18u) |
            ((uint32_t)quartet[1] << 12u) |
            ((uint32_t)(quartet[2] < 0 ? 0 : quartet[2]) << 6u);

        out[pos++] = (char)((triple >> 16u) & 0xffu);
        if (quartet[2] != -2) out[pos++] = (char)((triple >> 8u) & 0xffu);
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_urlencode_value(JinxValue value, int raw) {
    static const char hex[] = "0123456789ABCDEF";
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t needed = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];
        int safe = jinx_oracle_ascii_alnum(c) || c == '-' || c == '_' || c == '.' || (raw && c == '~');
        needed += safe || (!raw && c == ' ') ? 1u : 3u;
    }

    if (needed > UINT32_MAX) {
        return jinx_oracle_bool_value(0);
    }

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];
        int safe = jinx_oracle_ascii_alnum(c) || c == '-' || c == '_' || c == '.' || (raw && c == '~');

        if (safe) {
            out[pos++] = (char)c;
        } else if (!raw && c == ' ') {
            out[pos++] = '+';
        } else {
            out[pos++] = '%';
            out[pos++] = hex[(c >> 4u) & 0x0fu];
            out[pos++] = hex[c & 0x0fu];
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_urldecode_value(JinxValue value, int raw) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];

        if (!raw && c == '+') {
            out[pos++] = ' ';
            continue;
        }

        if (c == '%' && i + 2u < len) {
            int hi = jinx_oracle_hex_nibble(bytes[i + 1u]);
            int lo = jinx_oracle_hex_nibble(bytes[i + 2u]);
            if (hi >= 0 && lo >= 0) {
                out[pos++] = (char)((hi << 4) | lo);
                i += 2u;
                continue;
            }
        }

        out[pos++] = (char)c;
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_path_sep(unsigned char c) {
#ifdef _WIN32
    return c == '/' || c == '\\';
#else
    return c == '/';
#endif
}

static inline JinxValue jinx_oracle_basename_value(JinxValue path_value, JinxValue suffix_value, uint32_t argc) {
    const unsigned char *path = jinx_oracle_string_bytes(path_value);
    uint32_t len = jinx_oracle_string_len(path_value);
    uint32_t end = len;
    uint32_t start;
    uint32_t out_len;

    while (end > 0u && jinx_oracle_path_sep(path[end - 1u])) end--;
    start = end;
    while (start > 0u && !jinx_oracle_path_sep(path[start - 1u])) start--;

    out_len = end - start;

    if (argc >= 2u && suffix_value.type == 3u) {
        const unsigned char *suffix = jinx_oracle_string_bytes(suffix_value);
        uint32_t suffix_len = jinx_oracle_string_len(suffix_value);
        if (suffix_len > 0u && suffix_len <= out_len &&
            memcmp(path + end - suffix_len, suffix, suffix_len) == 0) {
            out_len -= suffix_len;
        }
    }

    return jinx_oracle_string_slice_copy(path, start, out_len);
}

static inline JinxValue jinx_oracle_dirname_value(JinxValue path_value, JinxValue levels_value, uint32_t argc) {
    const unsigned char *path = jinx_oracle_string_bytes(path_value);
    uint32_t len = jinx_oracle_string_len(path_value);
    int64_t levels = argc >= 2u ? jinx_oracle_intish(levels_value) : 1;
    uint32_t end = len;

    if (levels < 1) {
        return jinx_oracle_bool_value(0);
    }

    while (levels-- > 0) {
        while (end > 1u && jinx_oracle_path_sep(path[end - 1u])) end--;
        while (end > 0u && !jinx_oracle_path_sep(path[end - 1u])) end--;
        while (end > 1u && jinx_oracle_path_sep(path[end - 1u])) end--;

        if (end == 0u) {
            return jinx_oracle_string_value(".");
        }
    }

    return jinx_oracle_string_slice_copy(path, 0u, end);
}

static inline int jinx_oracle_base_digit(unsigned char c) {
    if (c >= '0' && c <= '9') return (int)(c - '0');
    if (c >= 'a' && c <= 'z') return 10 + (int)(c - 'a');
    if (c >= 'A' && c <= 'Z') return 10 + (int)(c - 'A');
    return -1;
}

static inline uint64_t jinx_oracle_parse_base_uint(JinxValue value, int base) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t n = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        int digit = jinx_oracle_base_digit(bytes[i]);
        if (digit < 0 || digit >= base) continue;
        n = n * (uint64_t)base + (uint64_t)digit;
    }

    return n;
}

static inline JinxValue jinx_oracle_uint_to_base(uint64_t n, int base) {
    static const char digits[] = "0123456789abcdefghijklmnopqrstuvwxyz";
    char tmp[80];
    uint32_t pos = 0u;

    if (base < 2 || base > 36) {
        return jinx_oracle_bool_value(0);
    }

    do {
        tmp[pos++] = digits[n % (uint64_t)base];
        n /= (uint64_t)base;
    } while (n != 0u && pos < sizeof(tmp));

    char *out = jinx_oracle_scratch_string(pos);
    for (uint32_t i = 0u; i < pos; i++) {
        out[i] = tmp[pos - 1u - i];
    }
    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_base_convert_value(JinxValue value, JinxValue from_value, JinxValue to_value) {
    int from = (int)jinx_oracle_intish(from_value);
    int to = (int)jinx_oracle_intish(to_value);

    if (from < 2 || from > 36 || to < 2 || to > 36) {
        return jinx_oracle_bool_value(0);
    }

    return jinx_oracle_uint_to_base(jinx_oracle_parse_base_uint(value, from), to);
}


typedef struct JinxOracleFormatBuffer {
    char *data;
    size_t len;
    size_t cap;
} JinxOracleFormatBuffer;

static inline int jinx_oracle_format_reserve(JinxOracleFormatBuffer *buffer, size_t extra) {
    size_t needed = buffer->len + extra + 1u;
    if (needed <= buffer->cap) return 1;

    size_t cap = buffer->cap == 0u ? 128u : buffer->cap;
    while (cap < needed) {
        if (cap > SIZE_MAX / 2u) return 0;
        cap *= 2u;
    }

    char *grown = (char *)realloc(buffer->data, cap);
    if (grown == NULL) return 0;
    buffer->data = grown;
    buffer->cap = cap;
    return 1;
}

static inline int jinx_oracle_format_append(JinxOracleFormatBuffer *buffer, const char *data, size_t len) {
    if (!jinx_oracle_format_reserve(buffer, len)) return 0;
    if (len != 0u) memcpy(buffer->data + buffer->len, data, len);
    buffer->len += len;
    buffer->data[buffer->len] = '\0';
    return 1;
}

static inline int jinx_oracle_format_append_char(JinxOracleFormatBuffer *buffer, char ch) {
    return jinx_oracle_format_append(buffer, &ch, 1u);
}

static inline int jinx_oracle_format_append_padded(
    JinxOracleFormatBuffer *buffer,
    const char *data,
    size_t len,
    size_t width,
    char padding,
    int left
) {
    size_t pad = width > len ? width - len : 0u;

    if (!left && padding == '0' && len > 0u && (data[0] == '-' || data[0] == '+')) {
        if (!jinx_oracle_format_append_char(buffer, data[0])) return 0;
        for (size_t i = 0u; i < pad; i++) if (!jinx_oracle_format_append_char(buffer, '0')) return 0;
        return jinx_oracle_format_append(buffer, data + 1u, len - 1u);
    }

    if (!left) {
        for (size_t i = 0u; i < pad; i++) if (!jinx_oracle_format_append_char(buffer, padding)) return 0;
    }
    if (!jinx_oracle_format_append(buffer, data, len)) return 0;
    if (left) {
        for (size_t i = 0u; i < pad; i++) if (!jinx_oracle_format_append_char(buffer, padding)) return 0;
    }
    return 1;
}

static inline JinxValue jinx_oracle_format_string_value(JinxValue value) {
    if (value.type == 3u) return value;
    if (value.type == 6u || value.type == 4u) return jinx_oracle_string_value("Array");
    return jinx_oracle_strval_value(value);
}

static inline int jinx_oracle_format_read_number(
    const unsigned char *format,
    uint32_t len,
    uint32_t *position,
    int *out
) {
    uint64_t value = 0u;
    uint32_t start = *position;

    while (*position < len && format[*position] >= '0' && format[*position] <= '9') {
        value = value * 10u + (uint64_t)(format[*position] - '0');
        if (value > INT32_MAX) return 0;
        (*position)++;
    }

    if (*position == start) return 0;
    *out = (int)value;
    return 1;
}

static inline int jinx_oracle_format_argument_index(
    const unsigned char *format,
    uint32_t len,
    uint32_t *position
) {
    uint32_t save = *position;
    int number = 0;

    if (!jinx_oracle_format_read_number(format, len, position, &number) ||
        *position >= len || format[*position] != '$') {
        *position = save;
        return -1;
    }

    (*position)++;
    return number > 0 ? number - 1 : -2;
}

static inline JinxValue jinx_oracle_sprintf_values(
    JinxValue format_value,
    const JinxValue *values,
    uint32_t value_count,
    int *ok
) {
    const unsigned char *format = jinx_oracle_string_bytes(format_value);
    uint32_t format_len = jinx_oracle_string_len(format_value);
    JinxOracleFormatBuffer out = {0};
    uint32_t pos = 0u;
    uint32_t next_arg = 0u;

    *ok = 0;

    while (pos < format_len) {
        if (format[pos] != '%') {
            uint32_t start = pos;
            while (pos < format_len && format[pos] != '%') pos++;
            if (!jinx_oracle_format_append(&out, (const char *)format + start, pos - start)) goto fail;
            continue;
        }

        pos++;
        if (pos >= format_len) goto fail;

        if (format[pos] == '%') {
            if (!jinx_oracle_format_append_char(&out, '%')) goto fail;
            pos++;
            continue;
        }

        int arg_index = jinx_oracle_format_argument_index(format, format_len, &pos);
        if (arg_index == -2) goto fail;

        char padding = ' ';
        int left = 0;
        int always_sign = 0;

        for (;;) {
            if (pos >= format_len) goto fail;
            if (format[pos] == ' ' || format[pos] == '0') {
                padding = (char)format[pos++];
            } else if (format[pos] == '-') {
                left = 1;
                pos++;
            } else if (format[pos] == '+') {
                always_sign = 1;
                pos++;
            } else if (format[pos] == '\'') {
                pos++;
                if (pos >= format_len) goto fail;
                padding = (char)format[pos++];
            } else {
                break;
            }
        }

        int width = 0;
        if (pos < format_len && format[pos] == '*') {
            pos++;
            int width_index = jinx_oracle_format_argument_index(format, format_len, &pos);
            if (width_index == -2) goto fail;
            if (width_index < 0) width_index = (int)next_arg++;
            if ((uint32_t)width_index >= value_count || values[width_index].type != 1u) goto fail;
            int64_t raw_width = values[width_index].as.i64;
            if (raw_width < 0 || raw_width > INT32_MAX) goto fail;
            width = (int)raw_width;
        } else if (pos < format_len && format[pos] >= '0' && format[pos] <= '9') {
            if (!jinx_oracle_format_read_number(format, format_len, &pos, &width)) goto fail;
        }

        int precision = 0;
        int has_precision = 0;
        if (pos < format_len && format[pos] == '.') {
            pos++;
            has_precision = 1;

            if (pos < format_len && format[pos] == '*') {
                pos++;
                int precision_index = jinx_oracle_format_argument_index(format, format_len, &pos);
                if (precision_index == -2) goto fail;
                if (precision_index < 0) precision_index = (int)next_arg++;
                if ((uint32_t)precision_index >= value_count || values[precision_index].type != 1u) goto fail;
                int64_t raw_precision = values[precision_index].as.i64;
                if (raw_precision < -1 || raw_precision > INT32_MAX) goto fail;
                precision = (int)raw_precision;
            } else if (pos < format_len && format[pos] >= '0' && format[pos] <= '9') {
                if (!jinx_oracle_format_read_number(format, format_len, &pos, &precision)) goto fail;
            } else {
                precision = 0;
            }
        }

        if (pos < format_len && format[pos] == 'l') pos++;
        if (pos >= format_len) goto fail;

        char spec = (char)format[pos++];

        if (arg_index < 0) arg_index = (int)next_arg++;
        if ((uint32_t)arg_index >= value_count) goto fail;
        JinxValue value = values[arg_index];

        if (spec == '%') {
            if (!jinx_oracle_format_append_char(&out, '%')) goto fail;
            continue;
        }

        if (spec == 'c') {
            if (!jinx_oracle_format_append_char(&out, (char)jinx_oracle_intish(value))) goto fail;
            continue;
        }

        if (spec == 's') {
            JinxValue string_value = jinx_oracle_format_string_value(value);
            const char *bytes = (const char *)jinx_oracle_string_bytes(string_value);
            size_t string_len = jinx_oracle_string_len(string_value);
            if (has_precision && precision >= 0 && (size_t)precision < string_len) string_len = (size_t)precision;
            if (!jinx_oracle_format_append_padded(&out, bytes, string_len, (size_t)width, padding, left)) goto fail;
            continue;
        }

        if (spec == 'd' || spec == 'u') {
            char piece[96];
            int n;

            if (spec == 'd') {
                int64_t number = jinx_oracle_intish(value);
                n = always_sign && number >= 0
                    ? snprintf(piece, sizeof(piece), "+%lld", (long long)number)
                    : snprintf(piece, sizeof(piece), "%lld", (long long)number);
            } else {
                uint64_t number = (uint64_t)jinx_oracle_intish(value);
                n = snprintf(piece, sizeof(piece), "%llu", (unsigned long long)number);
            }

            if (n < 0 || !jinx_oracle_format_append_padded(&out, piece, (size_t)n, (size_t)width, padding, left)) goto fail;
            continue;
        }

        if (spec == 'b' || spec == 'o' || spec == 'x' || spec == 'X') {
            int base = spec == 'b' ? 2 : (spec == 'o' ? 8 : 16);
            JinxValue converted = jinx_oracle_uint_to_base((uint64_t)jinx_oracle_intish(value), base);
            const char *bytes = (const char *)jinx_oracle_string_bytes(converted);
            size_t converted_len = jinx_oracle_string_len(converted);

            char *upper = NULL;
            if (spec == 'X' && converted_len != 0u) {
                upper = (char *)malloc(converted_len);
                if (upper == NULL) goto fail;
                for (size_t i = 0u; i < converted_len; i++) upper[i] = (char)jinx_oracle_ascii_upper_byte((unsigned char)bytes[i]);
                bytes = upper;
            }

            int appended = jinx_oracle_format_append_padded(&out, bytes, converted_len, (size_t)width, padding, left);
            free(upper);
            if (!appended) goto fail;
            continue;
        }

        if (spec == 'e' || spec == 'E' || spec == 'f' || spec == 'F' ||
            spec == 'g' || spec == 'G' || spec == 'h' || spec == 'H') {
            double number = jinx_oracle_floatish(value);

            if (has_precision && precision == -1 &&
                spec != 'g' && spec != 'G' && spec != 'h' && spec != 'H') {
                goto fail;
            }

            int effective_precision = has_precision ? precision : 6;
            if (effective_precision == -1) effective_precision = 17;
            if (effective_precision == 0 && (spec == 'g' || spec == 'G' || spec == 'h' || spec == 'H')) {
                effective_precision = 1;
            }
            if (effective_precision > 53) effective_precision = 53;

            char special[8];
            const char *piece_view = NULL;
            size_t piece_len = 0u;
            char *piece = NULL;

            if (isnan(number)) {
                piece_view = "NaN";
                piece_len = 3u;
            } else if (isinf(number)) {
                if (number < 0.0) {
                    piece_view = "-INF";
                    piece_len = 4u;
                } else {
                    piece_view = "INF";
                    piece_len = 3u;
                }
            } else {
                char c_spec = spec == 'h' ? 'g' : (spec == 'H' ? 'G' : spec);
                char conversion[16];
                snprintf(conversion, sizeof(conversion), "%%%s.%d%c", always_sign ? "+" : "", effective_precision, c_spec);

                int needed = snprintf(NULL, 0, conversion, number);
                if (needed < 0) goto fail;
                piece = (char *)malloc((size_t)needed + 1u);
                if (piece == NULL) goto fail;
                snprintf(piece, (size_t)needed + 1u, conversion, number);

                if (spec == 'e' || spec == 'E' || spec == 'F' || spec == 'h' || spec == 'H') {
                    struct lconv *locale = localeconv();
                    const char *decimal = locale != NULL ? locale->decimal_point : ".";
                    if (decimal != NULL && decimal[0] != '\0' && strcmp(decimal, ".") != 0) {
                        char *found = strstr(piece, decimal);
                        if (found != NULL && strlen(decimal) == 1u) {
                            *found = '.';
                        }
                    }
                }

                piece_view = piece;
                piece_len = (size_t)needed;
            }

            int appended = jinx_oracle_format_append_padded(
                &out, piece_view, piece_len, (size_t)width, padding, left
            );
            free(piece);
            (void)special;
            if (!appended) goto fail;
            continue;
        }

        goto fail;
    }

    if (out.data == NULL) {
        out.data = (char *)calloc(1u, 1u);
        if (out.data == NULL) goto fail;
    }

    if (out.len > UINT32_MAX) goto fail;
    {
        char *scratch = jinx_oracle_scratch_string((uint32_t)out.len);
        if (out.len != 0u) memcpy(scratch, out.data, out.len);
        free(out.data);
        *ok = 1;
        return jinx_oracle_string_value_len(scratch, (uint32_t)out.len);
    }

fail:
    free(out.data);
    return jinx_oracle_zero_value();
}



static inline int jinx_oracle_hebrev_is_hebrew(unsigned char ch) {
    return ch >= 224u && ch <= 250u;
}

static inline int jinx_oracle_hebrev_is_blank(unsigned char ch) {
    return ch == (unsigned char)' ' || ch == (unsigned char)'\t';
}

static inline int jinx_oracle_hebrev_is_newline(unsigned char ch) {
    return ch == (unsigned char)'\n' || ch == (unsigned char)'\r';
}

static inline char jinx_oracle_hebrev_mirror_punctuation(char ch) {
    switch (ch) {
        case '(': return ')';
        case ')': return '(';
        case '[': return ']';
        case ']': return '[';
        case '{': return '}';
        case '}': return '{';
        case '<': return '>';
        case '>': return '<';
        case '\\': return '/';
        case '/': return '\\';
        default: return ch;
    }
}

static inline JinxValue jinx_oracle_hebrev_value(
    JinxValue value,
    JinxValue max_chars_value,
    uint32_t argc
) {
    const unsigned char *input = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t max_chars = argc >= 2u ? jinx_oracle_intish(max_chars_value) : 0;

    if (len == 0u) {
        return jinx_oracle_string_value_len("", 0u);
    }

    char *source = (char *)malloc((size_t)len + 1u);
    char *hebrew = (char *)malloc((size_t)len + 1u);
    char *broken = (char *)malloc((size_t)len + 1u);
    if (source == NULL || hebrew == NULL || broken == NULL) {
        free(source);
        free(hebrew);
        free(broken);
        return jinx_oracle_zero_value();
    }

    memcpy(source, input, len);
    source[len] = '\0';

    const char *tmp = source;
    size_t block_start = 0u;
    size_t block_end = 0u;
    size_t block_type;
    size_t i;

    char *target = hebrew + len;
    *target = '\0';
    target--;

    block_type = jinx_oracle_hebrev_is_hebrew((unsigned char)*tmp) ? 2u : 1u;

    do {
        if (block_type == 2u) {
            while ((jinx_oracle_hebrev_is_hebrew((unsigned char)tmp[1]) ||
                    jinx_oracle_hebrev_is_blank((unsigned char)tmp[1]) ||
                    ispunct((unsigned char)tmp[1]) ||
                    tmp[1] == '\n') &&
                   block_end < (size_t)len - 1u) {
                tmp++;
                block_end++;
            }

            for (i = block_start + 1u; i <= block_end + 1u; i++) {
                *target = jinx_oracle_hebrev_mirror_punctuation(source[i - 1u]);
                target--;
            }
            block_type = 1u;
        } else {
            while (!jinx_oracle_hebrev_is_hebrew((unsigned char)tmp[1]) &&
                   tmp[1] != '\n' &&
                   block_end < (size_t)len - 1u) {
                tmp++;
                block_end++;
            }

            while ((jinx_oracle_hebrev_is_blank((unsigned char)*tmp) ||
                    ispunct((unsigned char)*tmp)) &&
                   *tmp != '/' && *tmp != '-' &&
                   block_end > block_start) {
                tmp--;
                block_end--;
            }

            for (i = block_end + 1u; i >= block_start + 1u; i--) {
                *target = source[i - 1u];
                target--;
            }
            block_type = 2u;
        }

        block_start = block_end + 1u;
    } while (block_end < (size_t)len - 1u);

    size_t begin = (size_t)len - 1u;
    size_t end = begin;
    char *write = broken;

    while (1) {
        int64_t char_count = 0;

        while ((!max_chars || (max_chars > 0 && char_count < max_chars)) && begin > 0u) {
            char_count++;
            begin--;

            if (jinx_oracle_hebrev_is_newline((unsigned char)hebrew[begin])) {
                while (begin > 0u &&
                       jinx_oracle_hebrev_is_newline((unsigned char)hebrew[begin - 1u])) {
                    begin--;
                    char_count++;
                }
                break;
            }
        }

        if (max_chars >= 0 && char_count == max_chars) {
            size_t new_char_count = (size_t)char_count;
            size_t new_begin = begin;

            while (new_char_count > 0u) {
                if (jinx_oracle_hebrev_is_blank((unsigned char)hebrew[new_begin]) ||
                    jinx_oracle_hebrev_is_newline((unsigned char)hebrew[new_begin])) {
                    break;
                }
                new_begin++;
                new_char_count--;
            }

            if (new_char_count > 0u) {
                begin = new_begin;
            }
        }

        size_t original_begin = begin;

        if (jinx_oracle_hebrev_is_blank((unsigned char)hebrew[begin])) {
            hebrew[begin] = '\n';
        }

        while (begin <= end &&
               jinx_oracle_hebrev_is_newline((unsigned char)hebrew[begin])) {
            begin++;
        }

        for (i = begin; i <= end; i++) {
            *write++ = hebrew[i];
        }

        for (i = original_begin;
             i <= end && jinx_oracle_hebrev_is_newline((unsigned char)hebrew[i]);
             i++) {
            *write++ = hebrew[i];
        }

        begin = original_begin;
        if (begin == 0u) {
            *write = '\0';
            break;
        }

        begin--;
        end = begin;
    }

    uint32_t out_len = (uint32_t)(write - broken);
    char *out = jinx_oracle_scratch_string(out_len);
    if (out_len != 0u) memcpy(out, broken, out_len);

    free(source);
    free(hebrew);
    free(broken);
    return jinx_oracle_string_value_len(out, out_len);
}

static inline JinxValue jinx_oracle_wordwrap_value(
    JinxValue text_value,
    JinxValue width_value,
    JinxValue break_value,
    JinxValue cut_value,
    uint32_t argc,
    int *ok
) {
    const unsigned char *text = jinx_oracle_string_bytes(text_value);
    uint32_t text_len = jinx_oracle_string_len(text_value);
    int64_t width = argc >= 2u ? jinx_oracle_intish(width_value) : 75;
    const unsigned char *break_bytes = argc >= 3u
        ? jinx_oracle_string_bytes(break_value)
        : (const unsigned char *)"\n";
    uint32_t break_len = argc >= 3u ? jinx_oracle_string_len(break_value) : 1u;
    int cut = argc >= 4u && jinx_oracle_boolish(cut_value);
    int64_t laststart = 0;
    int64_t lastspace = 0;

    *ok = 0;

    if (text_len == 0u) {
        *ok = 1;
        return jinx_oracle_string_value_len("", 0u);
    }

    if (break_len == 0u || (width == 0 && cut)) {
        return jinx_oracle_zero_value();
    }

    if (break_len == 1u && !cut) {
        char *out = jinx_oracle_scratch_string(text_len);
        memcpy(out, text, text_len);

        laststart = lastspace = 0;
        for (int64_t current = 0; current < (int64_t)text_len; current++) {
            if ((unsigned char)out[current] == break_bytes[0]) {
                laststart = lastspace = current + 1;
            } else if ((unsigned char)out[current] == (unsigned char)' ') {
                if (current - laststart >= width) {
                    out[current] = (char)break_bytes[0];
                    laststart = current + 1;
                }
                lastspace = current;
            } else if (current - laststart >= width && laststart != lastspace) {
                out[lastspace] = (char)break_bytes[0];
                laststart = lastspace + 1;
            }
        }

        *ok = 1;
        return jinx_oracle_string_value_len(out, text_len);
    }

    JinxOracleFormatBuffer out = {0};
    int64_t current;

    laststart = lastspace = 0;
    for (current = 0; current < (int64_t)text_len; current++) {
        if ((unsigned char)text[current] == break_bytes[0] &&
            current + (int64_t)break_len < (int64_t)text_len &&
            memcmp(text + current, break_bytes, break_len) == 0) {
            size_t copy_len = (size_t)(current - laststart) + break_len;
            if (!jinx_oracle_format_append(&out, (const char *)text + laststart, copy_len)) goto fail;
            current += (int64_t)break_len - 1;
            laststart = lastspace = current + 1;
        } else if ((unsigned char)text[current] == (unsigned char)' ') {
            if (current - laststart >= width) {
                if (!jinx_oracle_format_append(
                    &out,
                    (const char *)text + laststart,
                    (size_t)(current - laststart)
                )) goto fail;
                if (!jinx_oracle_format_append(&out, (const char *)break_bytes, break_len)) goto fail;
                laststart = current + 1;
            }
            lastspace = current;
        } else if (current - laststart >= width && cut && laststart >= lastspace) {
            if (!jinx_oracle_format_append(
                &out,
                (const char *)text + laststart,
                (size_t)(current - laststart)
            )) goto fail;
            if (!jinx_oracle_format_append(&out, (const char *)break_bytes, break_len)) goto fail;
            laststart = lastspace = current;
        } else if (current - laststart >= width && laststart < lastspace) {
            if (!jinx_oracle_format_append(
                &out,
                (const char *)text + laststart,
                (size_t)(lastspace - laststart)
            )) goto fail;
            if (!jinx_oracle_format_append(&out, (const char *)break_bytes, break_len)) goto fail;
            laststart = lastspace = lastspace + 1;
        }
    }

    if (laststart != current) {
        if (!jinx_oracle_format_append(
            &out,
            (const char *)text + laststart,
            (size_t)(current - laststart)
        )) goto fail;
    }

    if (out.len > UINT32_MAX) goto fail;
    {
        char *scratch = jinx_oracle_scratch_string((uint32_t)out.len);
        if (out.len != 0u) memcpy(scratch, out.data, out.len);
        uint32_t result_len = (uint32_t)out.len;
        free(out.data);
        *ok = 1;
        return jinx_oracle_string_value_len(scratch, result_len);
    }

fail:
    free(out.data);
    return jinx_oracle_zero_value();
}


static inline unsigned char jinx_oracle_uu_enc(unsigned int value) {
    value &= 077u;
    return value == 0u ? (unsigned char)'\`' : (unsigned char)(value + (unsigned int)' ');
}

static inline unsigned int jinx_oracle_uu_dec(unsigned char value) {
    return ((unsigned int)value - (unsigned int)' ') & 077u;
}

static inline JinxValue jinx_oracle_uuencode_value(JinxValue value) {
    const unsigned char *src = jinx_oracle_string_bytes(value);
    uint32_t src_len = jinx_oracle_string_len(value);
    uint64_t lines = ((uint64_t)src_len + 44u) / 45u;
    uint64_t needed = lines * 62u + 2u;

    if (needed > UINT32_MAX) return jinx_oracle_bool_value(0);
    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;
    uint32_t offset = 0u;

    while (offset < src_len) {
        uint32_t line_len = src_len - offset;
        if (line_len > 45u) line_len = 45u;

        out[pos++] = (char)jinx_oracle_uu_enc(line_len);

        for (uint32_t i = 0u; i < line_len; i += 3u) {
            unsigned int a = src[offset + i];
            unsigned int b = i + 1u < line_len ? src[offset + i + 1u] : 0u;
            unsigned int d = i + 2u < line_len ? src[offset + i + 2u] : 0u;

            out[pos++] = (char)jinx_oracle_uu_enc(a >> 2u);
            out[pos++] = (char)jinx_oracle_uu_enc(((a << 4u) & 060u) | ((b >> 4u) & 017u));
            out[pos++] = (char)jinx_oracle_uu_enc(((b << 2u) & 074u) | ((d >> 6u) & 03u));
            out[pos++] = (char)jinx_oracle_uu_enc(d & 077u);
        }

        out[pos++] = '\n';
        offset += line_len;
    }

    out[pos++] = (char)jinx_oracle_uu_enc(0u);
    out[pos++] = '\n';

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_uudecode_value(JinxValue value) {
    const unsigned char *src = jinx_oracle_string_bytes(value);
    uint32_t src_len = jinx_oracle_string_len(value);
    char *out;
    uint32_t pos = 0u;
    uint32_t offset = 0u;

    if (src_len == 0u) return jinx_oracle_bool_value(0);

    out = jinx_oracle_scratch_string(src_len);

    while (offset < src_len) {
        uint32_t line_len = jinx_oracle_uu_dec(src[offset++]);
        if (line_len == 0u) {
            return jinx_oracle_string_value_len(out, pos);
        }
        if (line_len > 45u) return jinx_oracle_bool_value(0);

        uint32_t encoded_len = ((line_len + 2u) / 3u) * 4u;
        if (encoded_len > src_len - offset) return jinx_oracle_bool_value(0);

        uint32_t written = 0u;
        for (uint32_t i = 0u; i < encoded_len; i += 4u) {
            unsigned int a = jinx_oracle_uu_dec(src[offset + i]);
            unsigned int b = jinx_oracle_uu_dec(src[offset + i + 1u]);
            unsigned int d = jinx_oracle_uu_dec(src[offset + i + 2u]);
            unsigned int e = jinx_oracle_uu_dec(src[offset + i + 3u]);

            unsigned char one = (unsigned char)((a << 2u) | (b >> 4u));
            unsigned char two = (unsigned char)((b << 4u) | (d >> 2u));
            unsigned char three = (unsigned char)((d << 6u) | e);

            if (written < line_len) { out[pos++] = (char)one; written++; }
            if (written < line_len) { out[pos++] = (char)two; written++; }
            if (written < line_len) { out[pos++] = (char)three; written++; }
        }

        offset += encoded_len;
        if (offset < src_len && src[offset] == '\r') offset++;
        if (offset < src_len && src[offset] == '\n') offset++;

        if (line_len < 45u) {
            return jinx_oracle_string_value_len(out, pos);
        }
    }

    return jinx_oracle_bool_value(0);
}


static inline void jinx_oracle_similar_str(
    const unsigned char *txt1,
    uint32_t len1,
    const unsigned char *txt2,
    uint32_t len2,
    uint32_t *pos1,
    uint32_t *pos2,
    uint32_t *max,
    uint32_t *count
) {
    *max = 0u;
    *count = 0u;

    for (uint32_t p = 0u; p < len1; p++) {
        for (uint32_t q = 0u; q < len2; q++) {
            uint32_t l = 0u;
            while (p + l < len1 && q + l < len2 && txt1[p + l] == txt2[q + l]) l++;

            if (l > *max) {
                *max = l;
                (*count)++;
                *pos1 = p;
                *pos2 = q;
            }
        }
    }
}

static inline uint64_t jinx_oracle_similar_char(
    const unsigned char *txt1,
    uint32_t len1,
    const unsigned char *txt2,
    uint32_t len2
) {
    uint64_t sum;
    uint32_t pos1 = 0u;
    uint32_t pos2 = 0u;
    uint32_t max = 0u;
    uint32_t count = 0u;

    jinx_oracle_similar_str(txt1, len1, txt2, len2, &pos1, &pos2, &max, &count);
    sum = max;

    if (sum != 0u) {
        if (pos1 != 0u && pos2 != 0u && count > 1u) {
            sum += jinx_oracle_similar_char(txt1, pos1, txt2, pos2);
        }

        if (pos1 + max < len1 && pos2 + max < len2) {
            sum += jinx_oracle_similar_char(
                txt1 + pos1 + max,
                len1 - pos1 - max,
                txt2 + pos2 + max,
                len2 - pos2 - max
            );
        }
    }

    return sum;
}

static inline JinxValue jinx_oracle_similar_text_value(
    JinxOracleAsmContext *ctx,
    JinxValue a,
    JinxValue b,
    uint32_t argc
) {
    uint32_t a_len = jinx_oracle_string_len(a);
    uint32_t b_len = jinx_oracle_string_len(b);
    uint64_t similar = jinx_oracle_similar_char(
        jinx_oracle_string_bytes(a),
        a_len,
        jinx_oracle_string_bytes(b),
        b_len
    );

    if (argc >= 3u) {
        double percent = ((uint64_t)a_len + (uint64_t)b_len) == 0u
            ? 0.0
            : ((double)similar * 200.0) / (double)((uint64_t)a_len + (uint64_t)b_len);

        if (!jinx_oracle_write_ref_arg(ctx, 2u, jinx_oracle_float_value(percent))) {
            ctx->fault = "similar_text percent argument is not writable by reference";
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_int_value((int64_t)similar);
}

static inline int jinx_oracle_metaphone_code(unsigned char c) {
    static const unsigned char codes[26] = {
        1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2,
        2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0
    };

    return c >= (unsigned char)'A' && c <= (unsigned char)'Z'
        ? codes[c - (unsigned char)'A']
        : 0;
}

static inline int jinx_oracle_metaphone_is_vowel(unsigned char c) {
    return (jinx_oracle_metaphone_code(c) & 1) != 0;
}

static inline int jinx_oracle_metaphone_affects_h(unsigned char c) {
    return (jinx_oracle_metaphone_code(c) & 4) != 0;
}

static inline int jinx_oracle_metaphone_makes_soft(unsigned char c) {
    return (jinx_oracle_metaphone_code(c) & 8) != 0;
}

static inline int jinx_oracle_metaphone_no_gh_to_f(unsigned char c) {
    return (jinx_oracle_metaphone_code(c) & 16) != 0;
}

static inline unsigned char jinx_oracle_metaphone_at(
    const unsigned char *word,
    uint32_t len,
    int64_t index
) {
    if (index < 0 || (uint64_t)index >= (uint64_t)len) return 0u;
    return (unsigned char)toupper((int)word[index]);
}

static inline unsigned char jinx_oracle_metaphone_lookahead(
    const unsigned char *word,
    uint32_t len,
    uint32_t start,
    uint32_t distance
) {
    uint32_t pos = start;
    uint32_t moved = 0u;

    while (pos < len && word[pos] != 0u && moved < distance) {
        pos++;
        moved++;
    }

    return pos < len ? (unsigned char)toupper((int)word[pos]) : 0u;
}

static inline void jinx_oracle_metaphone_emit(
    char *out,
    uint32_t capacity,
    uint32_t *pos,
    unsigned char ch
) {
    if (*pos < capacity) out[(*pos)++] = (char)ch;
}

static inline JinxValue jinx_oracle_metaphone_value(JinxValue value, JinxValue max_value, uint32_t argc) {
    const unsigned char *word = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t max_phonemes = argc >= 2u ? jinx_oracle_intish(max_value) : 0;
    uint64_t capacity64 = (uint64_t)len * 2u + 2u;
    uint32_t capacity;
    uint32_t w = 0u;
    uint32_t p = 0u;

    if (max_phonemes < 0) return jinx_oracle_zero_value();
    if (capacity64 > UINT32_MAX) return jinx_oracle_zero_value();
    capacity = (uint32_t)capacity64;

    char *out = jinx_oracle_scratch_string(capacity);

    while (w < len && !isalpha((int)word[w])) {
        if (word[w] == 0u) return jinx_oracle_string_value_len(out, 0u);
        w++;
    }
    if (w >= len) return jinx_oracle_string_value_len(out, 0u);

    unsigned char curr = (unsigned char)toupper((int)word[w]);
    unsigned char next = jinx_oracle_metaphone_at(word, len, (int64_t)w + 1);

    switch (curr) {
        case 'A':
            if (next == (unsigned char)'E') {
                jinx_oracle_metaphone_emit(out, capacity, &p, 'E');
                w += 2u;
            } else {
                jinx_oracle_metaphone_emit(out, capacity, &p, 'A');
                w++;
            }
            break;
        case 'G':
        case 'K':
        case 'P':
            if (next == (unsigned char)'N') {
                jinx_oracle_metaphone_emit(out, capacity, &p, 'N');
                w += 2u;
            }
            break;
        case 'W':
            if (next == (unsigned char)'R') {
                jinx_oracle_metaphone_emit(out, capacity, &p, 'R');
                w += 2u;
            } else if (next == (unsigned char)'H' || jinx_oracle_metaphone_is_vowel(next)) {
                jinx_oracle_metaphone_emit(out, capacity, &p, 'W');
                w += 2u;
            }
            break;
        case 'X':
            jinx_oracle_metaphone_emit(out, capacity, &p, 'S');
            w++;
            break;
        case 'E':
        case 'I':
        case 'O':
        case 'U':
            jinx_oracle_metaphone_emit(out, capacity, &p, curr);
            w++;
            break;
        default:
            break;
    }

    while (w < len && word[w] != 0u &&
           (max_phonemes == 0 || p < (uint64_t)max_phonemes)) {
        unsigned int skip = 0u;
        unsigned char raw = word[w];

        if (!isalpha((int)raw)) {
            w++;
            continue;
        }

        curr = (unsigned char)toupper((int)raw);
        unsigned char prev = jinx_oracle_metaphone_at(word, len, (int64_t)w - 1);
        next = jinx_oracle_metaphone_at(word, len, (int64_t)w + 1);
        unsigned char after_next = next != 0u
            ? jinx_oracle_metaphone_at(word, len, (int64_t)w + 2)
            : 0u;

        if (curr == prev && curr != (unsigned char)'C') {
            w++;
            continue;
        }

        switch (curr) {
            case 'B':
                if (prev != (unsigned char)'M') jinx_oracle_metaphone_emit(out, capacity, &p, 'B');
                break;

            case 'C':
                if (jinx_oracle_metaphone_makes_soft(next)) {
                    if (next == (unsigned char)'I' && after_next == (unsigned char)'A') {
                        jinx_oracle_metaphone_emit(out, capacity, &p, 'X');
                    } else if (prev != (unsigned char)'S') {
                        jinx_oracle_metaphone_emit(out, capacity, &p, 'S');
                    }
                } else if (next == (unsigned char)'H') {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'X');
                    skip++;
                } else {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'K');
                }
                break;

            case 'D':
                if (next == (unsigned char)'G' && jinx_oracle_metaphone_makes_soft(after_next)) {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'J');
                    skip++;
                } else {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'T');
                }
                break;

            case 'G':
                if (next == (unsigned char)'H') {
                    unsigned char back3 = jinx_oracle_metaphone_at(word, len, (int64_t)w - 3);
                    unsigned char back4 = jinx_oracle_metaphone_at(word, len, (int64_t)w - 4);
                    if (!jinx_oracle_metaphone_no_gh_to_f(back3) && back4 != (unsigned char)'H') {
                        jinx_oracle_metaphone_emit(out, capacity, &p, 'F');
                        skip++;
                    }
                } else if (next == (unsigned char)'N') {
                    if (!(after_next == 0u ||
                         (after_next == (unsigned char)'E' &&
                          jinx_oracle_metaphone_lookahead(word, len, w, 3u) == (unsigned char)'D'))) {
                        jinx_oracle_metaphone_emit(out, capacity, &p, 'K');
                    }
                } else if (jinx_oracle_metaphone_makes_soft(next) && prev != (unsigned char)'G') {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'J');
                } else {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'K');
                }
                break;

            case 'H':
                if (jinx_oracle_metaphone_is_vowel(next) && !jinx_oracle_metaphone_affects_h(prev)) {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'H');
                }
                break;

            case 'K':
                if (prev != (unsigned char)'C') jinx_oracle_metaphone_emit(out, capacity, &p, 'K');
                break;

            case 'P':
                jinx_oracle_metaphone_emit(out, capacity, &p, next == (unsigned char)'H' ? 'F' : 'P');
                break;

            case 'Q':
                jinx_oracle_metaphone_emit(out, capacity, &p, 'K');
                break;

            case 'S':
                if (next == (unsigned char)'I' &&
                    (after_next == (unsigned char)'O' || after_next == (unsigned char)'A')) {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'X');
                } else if (next == (unsigned char)'H') {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'X');
                    skip++;
                } else {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'S');
                }
                break;

            case 'T':
                if (next == (unsigned char)'I' &&
                    (after_next == (unsigned char)'O' || after_next == (unsigned char)'A')) {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'X');
                } else if (next == (unsigned char)'H') {
                    jinx_oracle_metaphone_emit(out, capacity, &p, '0');
                    skip++;
                } else if (!(next == (unsigned char)'C' && after_next == (unsigned char)'H')) {
                    jinx_oracle_metaphone_emit(out, capacity, &p, 'T');
                }
                break;

            case 'V':
                jinx_oracle_metaphone_emit(out, capacity, &p, 'F');
                break;

            case 'W':
                if (jinx_oracle_metaphone_is_vowel(next)) jinx_oracle_metaphone_emit(out, capacity, &p, 'W');
                break;

            case 'X':
                jinx_oracle_metaphone_emit(out, capacity, &p, 'K');
                jinx_oracle_metaphone_emit(out, capacity, &p, 'S');
                break;

            case 'Y':
                if (jinx_oracle_metaphone_is_vowel(next)) jinx_oracle_metaphone_emit(out, capacity, &p, 'Y');
                break;

            case 'Z':
                jinx_oracle_metaphone_emit(out, capacity, &p, 'S');
                break;

            case 'F':
            case 'J':
            case 'L':
            case 'M':
            case 'N':
            case 'R':
                jinx_oracle_metaphone_emit(out, capacity, &p, curr);
                break;

            default:
                break;
        }

        w += 1u + skip;
    }

    return jinx_oracle_string_value_len(out, p);
}

static inline JinxValue jinx_oracle_soundex_value(JinxValue value) {
    static const unsigned char soundex_table[26] = {
        0, '1', '2', '3', 0, '1', '2', 0, 0, '2', '2', '4', '5',
        '5', 0, '1', '2', '6', '2', '3', 0, '1', 0, '2', 0, '2'
    };
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char out[4] = {'0', '0', '0', '0'};
    uint32_t pos = 0u;
    int last = -1;

    for (uint32_t i = 0u; i < len && pos < 4u; i++) {
        int code = toupper(bytes[i]);
        if (code < 'A' || code > 'Z') continue;

        if (pos == 0u) {
            out[pos++] = (char)code;
            last = soundex_table[code - 'A'];
        } else {
            int mapped = soundex_table[code - 'A'];
            if (mapped != last) {
                if (mapped != 0) out[pos++] = (char)mapped;
                last = mapped;
            }
        }
    }

    char *scratch = jinx_oracle_scratch_string(4u);
    memcpy(scratch, out, 4u);
    return jinx_oracle_string_value_len(scratch, 4u);
}

static inline int jinx_oracle_is_hex_digit(unsigned char c) {
    return (c >= '0' && c <= '9') ||
        (c >= 'A' && c <= 'F') ||
        (c >= 'a' && c <= 'f');
}

static inline JinxValue jinx_oracle_quoted_printable_decode_value(JinxValue value) {
    const unsigned char *src = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t i = 0u;
    uint32_t pos = 0u;

    while (i < len && src[i] != '\0') {
        if (src[i] == '=') {
            if (i + 2u < len && src[i + 1u] != '\0' && src[i + 2u] != '\0' &&
                jinx_oracle_is_hex_digit(src[i + 1u]) &&
                jinx_oracle_is_hex_digit(src[i + 2u])) {
                int hi = jinx_oracle_hex_nibble(src[i + 1u]);
                int lo = jinx_oracle_hex_nibble(src[i + 2u]);
                out[pos++] = (char)((hi << 4) | lo);
                i += 3u;
                continue;
            }

            uint32_t k = 1u;
            while (i + k < len && (src[i + k] == ' ' || src[i + k] == '\t')) k++;

            if (i + k >= len || src[i + k] == '\0') {
                i += k;
                continue;
            }
            if (src[i + k] == '\r' && i + k + 1u < len && src[i + k + 1u] == '\n') {
                i += k + 2u;
                continue;
            }
            if (src[i + k] == '\r' || src[i + k] == '\n') {
                i += k + 1u;
                continue;
            }

            out[pos++] = '=';
            i++;
            continue;
        }

        out[pos++] = (char)src[i++];
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_quoted_printable_encode_value(JinxValue value) {
    static const char hex[] = "0123456789ABCDEF";
    const unsigned char *src = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t needed = (uint64_t)len * 4u + 8u;

    if (needed > UINT32_MAX) return jinx_oracle_bool_value(0);
    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;
    uint32_t lp = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = src[i];

        if (ch == '\r' && i + 1u < len && src[i + 1u] == '\n') {
            out[pos++] = '\r';
            out[pos++] = '\n';
            i++;
            lp = 0u;
            continue;
        }

        int encode = iscntrl(ch) || ch == 0x7fu || (ch & 0x80u) != 0u || ch == '=' ||
            (ch == ' ' && i + 1u < len && src[i + 1u] == '\r');

        if (encode) {
            lp += 3u;
            int wrap =
                ((lp > 75u) && ch <= 0x7fu) ||
                ((ch > 0x7fu && ch <= 0xdfu) && (lp + 3u > 75u)) ||
                ((ch > 0xdfu && ch <= 0xefu) && (lp + 6u > 75u)) ||
                ((ch > 0xefu && ch <= 0xf4u) && (lp + 9u > 75u));
            if (wrap) {
                out[pos++] = '=';
                out[pos++] = '\r';
                out[pos++] = '\n';
                lp = 3u;
            }
            out[pos++] = '=';
            out[pos++] = hex[ch >> 4u];
            out[pos++] = hex[ch & 0x0fu];
        } else {
            lp++;
            if (lp > 75u) {
                out[pos++] = '=';
                out[pos++] = '\r';
                out[pos++] = '\n';
                lp = 1u;
            }
            out[pos++] = (char)ch;
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline uint32_t jinx_oracle_crc32_bytes(const unsigned char *bytes, uint32_t len) {
    uint32_t crc = 0xffffffffu;

    for (uint32_t i = 0u; i < len; i++) {
        crc ^= (uint32_t)bytes[i];
        for (uint32_t bit = 0u; bit < 8u; bit++) {
            uint32_t mask = (uint32_t)-(int32_t)(crc & 1u);
            crc = (crc >> 1u) ^ (0xedb88320u & mask);
        }
    }

    return crc ^ 0xffffffffu;
}

static inline int jinx_oracle_is_leap_year(int64_t year) {
    return (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
}

static inline JinxValue jinx_oracle_checkdate_value(JinxValue month_value, JinxValue day_value, JinxValue year_value) {
    int64_t month = jinx_oracle_intish(month_value);
    int64_t day = jinx_oracle_intish(day_value);
    int64_t year = jinx_oracle_intish(year_value);
    static const unsigned char days[] = {31,28,31,30,31,30,31,31,30,31,30,31};

    if (year < 1 || year > 32767 || month < 1 || month > 12 || day < 1) {
        return jinx_oracle_bool_value(0);
    }

    int64_t max_day = days[month - 1];
    if (month == 2 && jinx_oracle_is_leap_year(year)) max_day = 29;
    return jinx_oracle_bool_value(day <= max_day);
}

static inline JinxValue jinx_oracle_nl2br_value(JinxValue value, JinxValue xhtml_value, uint32_t argc) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int xhtml = argc < 2u || jinx_oracle_boolish(xhtml_value);
    const char *tag = xhtml ? "<br />" : "<br>";
    uint32_t tag_len = xhtml ? 6u : 4u;
    uint64_t breaks = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        if (bytes[i] == '\r' || bytes[i] == '\n') {
            breaks++;
            if (bytes[i] == '\r' && i + 1u < len && bytes[i + 1u] == '\n') i++;
        }
    }

    uint64_t needed = (uint64_t)len + breaks * tag_len;
    if (needed > UINT32_MAX) return jinx_oracle_bool_value(0);

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        if (bytes[i] == '\r' || bytes[i] == '\n') {
            memcpy(out + pos, tag, tag_len);
            pos += tag_len;
            out[pos++] = (char)bytes[i];
            if (bytes[i] == '\r' && i + 1u < len && bytes[i + 1u] == '\n') {
                out[pos++] = '\n';
                i++;
            }
        } else {
            out[pos++] = (char)bytes[i];
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_number_format_value(
    JinxValue number_value,
    JinxValue decimals_value,
    JinxValue decimal_sep_value,
    JinxValue thousands_sep_value,
    uint32_t argc
) {
    double number = jinx_oracle_floatish(number_value);
    int64_t decimals_raw = argc >= 2u ? jinx_oracle_intish(decimals_value) : 0;
    const unsigned char *decimal_sep = argc >= 3u && decimal_sep_value.type != 0u
        ? jinx_oracle_string_bytes(decimal_sep_value)
        : (const unsigned char *)".";
    uint32_t decimal_sep_len = argc >= 3u && decimal_sep_value.type != 0u
        ? jinx_oracle_string_len(decimal_sep_value)
        : 1u;
    const unsigned char *thousands_sep = argc >= 4u && thousands_sep_value.type != 0u
        ? jinx_oracle_string_bytes(thousands_sep_value)
        : (const unsigned char *)",";
    uint32_t thousands_sep_len = argc >= 4u && thousands_sep_value.type != 0u
        ? jinx_oracle_string_len(thousands_sep_value)
        : 1u;
    int decimals;
    double rounded = number;

    if (decimals_raw > INT_MAX || decimals_raw < INT_MIN) {
        return jinx_oracle_zero_value();
    }

    decimals = decimals_raw > 0 ? (int)decimals_raw : 0;

    if (decimals_raw < 0) {
        int64_t places = -decimals_raw;
        if (places > 308) {
            rounded = 0.0;
        } else {
            double scale = pow(10.0, (double)places);
            rounded = round(number / scale) * scale;
        }
    } else if (decimals_raw > 0 && decimals_raw <= 308) {
        double scale = pow(10.0, (double)decimals_raw);
        if (isfinite(scale) && isfinite(number * scale)) {
            rounded = round(number * scale) / scale;
        }
    } else {
        rounded = round(number);
    }

    if (rounded == 0.0) {
        rounded = 0.0;
    }

    int plain_len = snprintf(NULL, 0, "%.*f", decimals, rounded);
    if (plain_len < 0) return jinx_oracle_zero_value();

    char *plain = (char *)malloc((size_t)plain_len + 1u);
    if (plain == NULL) return jinx_oracle_zero_value();
    snprintf(plain, (size_t)plain_len + 1u, "%.*f", decimals, rounded);

    char *dot = decimals > 0 ? strchr(plain, '.') : NULL;
    uint32_t integer_start = plain[0] == '-' ? 1u : 0u;
    uint32_t integer_end = dot == NULL ? (uint32_t)plain_len : (uint32_t)(dot - plain);
    uint32_t integer_digits = integer_end - integer_start;
    uint32_t groups = integer_digits > 0u ? (integer_digits - 1u) / 3u : 0u;
    uint64_t needed = (uint64_t)plain_len + (uint64_t)groups * thousands_sep_len;

    if (dot != NULL) {
        needed += decimal_sep_len;
        needed -= 1u;
    }

    if (needed > UINT32_MAX) {
        free(plain);
        return jinx_oracle_zero_value();
    }

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    if (integer_start != 0u) out[pos++] = '-';

    for (uint32_t i = integer_start; i < integer_end; i++) {
        uint32_t remaining = integer_end - i;
        out[pos++] = plain[i];
        if (remaining > 1u && (remaining - 1u) % 3u == 0u && thousands_sep_len != 0u) {
            memcpy(out + pos, thousands_sep, thousands_sep_len);
            pos += thousands_sep_len;
        }
    }

    if (dot != NULL) {
        if (decimal_sep_len != 0u) {
            memcpy(out + pos, decimal_sep, decimal_sep_len);
            pos += decimal_sep_len;
        }
        memcpy(out + pos, dot + 1, (size_t)plain_len - (size_t)(dot - plain) - 1u);
        pos += (uint32_t)((size_t)plain_len - (size_t)(dot - plain) - 1u);
    }

    free(plain);
    return jinx_oracle_string_value_len(out, pos);
}


static inline int jinx_oracle_ascii_alnum_string(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);

    if (len == 0u) return 0;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (!((ch >= (unsigned char)'0' && ch <= (unsigned char)'9') ||
              (ch >= (unsigned char)'A' && ch <= (unsigned char)'Z') ||
              (ch >= (unsigned char)'a' && ch <= (unsigned char)'z'))) {
            return 0;
        }
    }
    return 1;
}

static inline JinxValue jinx_oracle_str_increment_decrement_value(
    JinxValue value,
    int decrement,
    int *ok
) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    *ok = 0;

    if (!jinx_oracle_ascii_alnum_string(value)) {
        return jinx_oracle_zero_value();
    }
    if (decrement && bytes[0] == (unsigned char)'0') {
        return jinx_oracle_zero_value();
    }

    char *out = jinx_oracle_scratch_string(len + 1u);
    memcpy(out, bytes, len);

    int carry = 0;
    uint32_t position = len - 1u;
    do {
        unsigned char ch = (unsigned char)out[position];

        if (!decrement) {
            if (ch != (unsigned char)'z' && ch != (unsigned char)'Z' && ch != (unsigned char)'9') {
                out[position] = (char)(ch + 1u);
                carry = 0;
            } else {
                carry = 1;
                out[position] = ch == (unsigned char)'9' ? '0' : (char)(ch - 25u);
            }
        } else {
            if (ch != (unsigned char)'a' && ch != (unsigned char)'A' && ch != (unsigned char)'0') {
                out[position] = (char)(ch - 1u);
                carry = 0;
            } else {
                carry = 1;
                out[position] = ch == (unsigned char)'0' ? '9' : (char)(ch + 25u);
            }
        }

        if (!carry || position == 0u) break;
        position--;
    } while (1);

    if (!decrement && carry) {
        char *expanded = jinx_oracle_scratch_string(len + 1u);
        memcpy(expanded + 1u, out, len);
        expanded[0] = out[0] == '0' ? '1' : out[0];
        *ok = 1;
        return jinx_oracle_string_value_len(expanded, len + 1u);
    }

    if (decrement && (carry || (out[0] == '0' && len > 1u))) {
        if (len == 1u) {
            return jinx_oracle_zero_value();
        }

        char *contracted = jinx_oracle_scratch_string(len - 1u);
        memcpy(contracted, out + 1u, len - 1u);
        *ok = 1;
        return jinx_oracle_string_value_len(contracted, len - 1u);
    }

    *ok = 1;
    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_bin2hex_value(JinxValue value) {
    static const char hex[] = "0123456789abcdef";
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t out_len;

    if (len > UINT32_MAX / 2u) {
        return jinx_oracle_bool_value(0);
    }

    out_len = len * 2u;
    char *out = jinx_oracle_scratch_string(out_len);

    for (uint32_t i = 0u; i < len; i++) {
        out[i * 2u] = hex[(bytes[i] >> 4u) & 0x0fu];
        out[i * 2u + 1u] = hex[bytes[i] & 0x0fu];
    }

    return jinx_oracle_string_value_len(out, out_len);
}

static inline int jinx_oracle_hex_nibble(unsigned char c) {
    if (c >= (unsigned char)'0' && c <= (unsigned char)'9') {
        return (int)(c - (unsigned char)'0');
    }
    if (c >= (unsigned char)'a' && c <= (unsigned char)'f') {
        return 10 + (int)(c - (unsigned char)'a');
    }
    if (c >= (unsigned char)'A' && c <= (unsigned char)'F') {
        return 10 + (int)(c - (unsigned char)'A');
    }
    return -1;
}


static inline void jinx_oracle_charlist_set(unsigned char selected[256], JinxValue charlist_value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(charlist_value);
    uint32_t len = jinx_oracle_string_len(charlist_value);
    memset(selected, 0, 256u);

    for (uint32_t i = 0u; i < len; i++) {
        if (i + 3u < len && bytes[i + 1u] == '.' && bytes[i + 2u] == '.' && bytes[i] <= bytes[i + 3u]) {
            for (unsigned int ch = bytes[i]; ch <= bytes[i + 3u]; ch++) selected[ch] = 1u;
            i += 3u;
        } else {
            selected[bytes[i]] = 1u;
        }
    }
}

static inline JinxValue jinx_oracle_addcslashes_value(JinxValue value, JinxValue charlist_value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    unsigned char selected[256];
    uint64_t needed = 0u;
    jinx_oracle_charlist_set(selected, charlist_value);

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (!selected[ch]) needed += 1u;
        else if (ch == 7u || ch == 8u || ch == 9u || ch == 10u || ch == 11u || ch == 12u || ch == 13u) needed += 2u;
        else if (ch >= 32u && ch <= 126u) needed += 2u;
        else needed += 4u;
    }

    if (needed > UINT32_MAX) return jinx_oracle_bool_value(0);
    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (!selected[ch]) {
            out[pos++] = (char)ch;
            continue;
        }

        out[pos++] = '\\';
        if (ch == 7u) out[pos++] = 'a';
        else if (ch == 8u) out[pos++] = 'b';
        else if (ch == 9u) out[pos++] = 't';
        else if (ch == 10u) out[pos++] = 'n';
        else if (ch == 11u) out[pos++] = 'v';
        else if (ch == 12u) out[pos++] = 'f';
        else if (ch == 13u) out[pos++] = 'r';
        else if (ch >= 32u && ch <= 126u) out[pos++] = (char)ch;
        else {
            out[pos++] = (char)('0' + ((ch >> 6u) & 7u));
            out[pos++] = (char)('0' + ((ch >> 3u) & 7u));
            out[pos++] = (char)('0' + (ch & 7u));
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_stripcslashes_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (ch != '\\' || i + 1u >= len) {
            out[pos++] = (char)ch;
            continue;
        }

        ch = bytes[++i];
        if (ch == 'n') out[pos++] = '\n';
        else if (ch == 'r') out[pos++] = '\r';
        else if (ch == 't') out[pos++] = '\t';
        else if (ch == 'v') out[pos++] = '\v';
        else if (ch == 'b') out[pos++] = '\b';
        else if (ch == 'f') out[pos++] = '\f';
        else if (ch == 'a') out[pos++] = '\a';
        else if (ch == 'x' && i + 1u < len && isxdigit((int)bytes[i + 1u])) {
            int hi = jinx_oracle_hex_nibble(bytes[++i]);
            int lo = 0;
            if (i + 1u < len && isxdigit((int)bytes[i + 1u])) lo = jinx_oracle_hex_nibble(bytes[++i]);
            else { lo = hi; hi = 0; }
            out[pos++] = (char)((hi << 4) | lo);
        } else if (ch >= '0' && ch <= '7') {
            unsigned int oct = (unsigned int)(ch - '0');
            uint32_t digits = 1u;
            while (digits < 3u && i + 1u < len && bytes[i + 1u] >= '0' && bytes[i + 1u] <= '7') {
                oct = (oct << 3u) | (unsigned int)(bytes[++i] - '0');
                digits++;
            }
            out[pos++] = (char)(oct & 0xffu);
        } else {
            out[pos++] = (char)ch;
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_str_pad_value(
    JinxValue value,
    JinxValue length_value,
    JinxValue pad_value,
    JinxValue type_value,
    uint32_t argc
) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t target_raw = jinx_oracle_intish(length_value);
    const unsigned char *pad = argc >= 3u ? jinx_oracle_string_bytes(pad_value) : (const unsigned char *)" ";
    uint32_t pad_len = argc >= 3u ? jinx_oracle_string_len(pad_value) : 1u;
    int type = argc >= 4u ? (int)jinx_oracle_intish(type_value) : 1;

    if (target_raw <= (int64_t)len) return value;
    if (pad_len == 0u || target_raw > UINT32_MAX) return jinx_oracle_bool_value(0);

    uint32_t target = (uint32_t)target_raw;
    uint32_t needed = target - len;
    uint32_t left = type == 0 ? needed : (type == 2 ? needed / 2u : 0u);
    uint32_t right = needed - left;
    char *out = jinx_oracle_scratch_string(target);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < left; i++) out[pos++] = (char)pad[i % pad_len];
    if (len != 0u) { memcpy(out + pos, bytes, len); pos += len; }
    for (uint32_t i = 0u; i < right; i++) out[pos++] = (char)pad[i % pad_len];

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_str_replace_scalar(
    JinxValue search_value,
    JinxValue replace_value,
    JinxValue subject_value,
    int fold_case
) {
    const unsigned char *search = jinx_oracle_string_bytes(search_value);
    const unsigned char *replace = jinx_oracle_string_bytes(replace_value);
    const unsigned char *subject = jinx_oracle_string_bytes(subject_value);
    uint32_t search_len = jinx_oracle_string_len(search_value);
    uint32_t replace_len = jinx_oracle_string_len(replace_value);
    uint32_t subject_len = jinx_oracle_string_len(subject_value);

    if (search_len == 0u || search_len > subject_len) return subject_value;

    uint32_t matches = 0u;
    for (uint32_t i = 0u; i + search_len <= subject_len;) {
        if (jinx_oracle_string_match_at(subject, search, search_len, i, fold_case)) {
            matches++;
            i += search_len;
        } else i++;
    }

    int64_t delta = (int64_t)replace_len - (int64_t)search_len;
    int64_t needed64 = (int64_t)subject_len + delta * (int64_t)matches;
    if (needed64 < 0 || (uint64_t)needed64 > UINT32_MAX) return jinx_oracle_bool_value(0);

    char *out = jinx_oracle_scratch_string((uint32_t)needed64);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < subject_len;) {
        if (i + search_len <= subject_len &&
            jinx_oracle_string_match_at(subject, search, search_len, i, fold_case)) {
            if (replace_len != 0u) { memcpy(out + pos, replace, replace_len); pos += replace_len; }
            i += search_len;
        } else {
            out[pos++] = (char)subject[i++];
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_strtr_three_value(JinxValue value, JinxValue from_value, JinxValue to_value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    const unsigned char *from = jinx_oracle_string_bytes(from_value);
    const unsigned char *to = jinx_oracle_string_bytes(to_value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t from_len = jinx_oracle_string_len(from_value);
    uint32_t to_len = jinx_oracle_string_len(to_value);
    uint32_t map_len = from_len < to_len ? from_len : to_len;
    char *out = jinx_oracle_scratch_string(len);

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        out[i] = (char)ch;
        for (uint32_t j = 0u; j < map_len; j++) {
            if (ch == from[j]) {
                out[i] = (char)to[j];
                break;
            }
        }
    }
    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_levenshtein_value(JinxValue left_value, JinxValue right_value) {
    const unsigned char *left = jinx_oracle_string_bytes(left_value);
    const unsigned char *right = jinx_oracle_string_bytes(right_value);
    uint32_t left_len = jinx_oracle_string_len(left_value);
    uint32_t right_len = jinx_oracle_string_len(right_value);

    uint32_t *prev = (uint32_t *)malloc(((size_t)right_len + 1u) * sizeof(uint32_t));
    uint32_t *curr = (uint32_t *)malloc(((size_t)right_len + 1u) * sizeof(uint32_t));
    if (prev == NULL || curr == NULL) {
        free(prev); free(curr);
        return jinx_oracle_bool_value(0);
    }

    for (uint32_t j = 0u; j <= right_len; j++) prev[j] = j;
    for (uint32_t i = 1u; i <= left_len; i++) {
        curr[0] = i;
        for (uint32_t j = 1u; j <= right_len; j++) {
            uint32_t del = prev[j] + 1u;
            uint32_t ins = curr[j - 1u] + 1u;
            uint32_t sub = prev[j - 1u] + (left[i - 1u] == right[j - 1u] ? 0u : 1u);
            uint32_t best = del < ins ? del : ins;
            curr[j] = best < sub ? best : sub;
        }
        uint32_t *tmp = prev; prev = curr; curr = tmp;
    }

    uint32_t result = prev[right_len];
    free(prev); free(curr);
    return jinx_oracle_int_value((int64_t)result);
}

static inline int jinx_oracle_entity_at(const unsigned char *bytes, uint32_t len, uint32_t i) {
    if (bytes[i] != '&') return 0;
    uint32_t limit = i + 16u < len ? i + 16u : len;
    for (uint32_t j = i + 1u; j < limit; j++) {
        if (bytes[j] == ';') return j > i + 1u;
        if (!(jinx_oracle_ascii_alnum(bytes[j]) || bytes[j] == '#')) return 0;
    }
    return 0;
}

static inline JinxValue jinx_oracle_htmlspecialchars_value(
    JinxValue value,
    JinxValue flags_value,
    JinxValue double_encode_value,
    uint32_t argc
) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int flags = argc >= 2u ? (int)jinx_oracle_intish(flags_value) : 11;
    int quote_style = flags & 3;
    int double_encode = argc < 4u || jinx_oracle_boolish(double_encode_value);
    uint64_t needed = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (ch == '&' && !double_encode && jinx_oracle_entity_at(bytes, len, i)) needed += 1u;
        else if (ch == '&') needed += 5u;
        else if (ch == '<' || ch == '>') needed += 4u;
        else if (ch == '"' && (quote_style == 2 || quote_style == 3)) needed += 6u;
        else if (ch == '\'' && quote_style == 3) needed += 6u;
        else needed += 1u;
    }

    if (needed > UINT32_MAX) return jinx_oracle_bool_value(0);
    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        const char *entity = NULL;
        uint32_t entity_len = 0u;

        if (ch == '&' && !double_encode && jinx_oracle_entity_at(bytes, len, i)) {
            out[pos++] = '&';
            continue;
        }
        if (ch == '&') { entity = "&amp;"; entity_len = 5u; }
        else if (ch == '<') { entity = "&lt;"; entity_len = 4u; }
        else if (ch == '>') { entity = "&gt;"; entity_len = 4u; }
        else if (ch == '"' && (quote_style == 2 || quote_style == 3)) { entity = "&quot;"; entity_len = 6u; }
        else if (ch == '\'' && quote_style == 3) { entity = "&#039;"; entity_len = 6u; }

        if (entity != NULL) {
            memcpy(out + pos, entity, entity_len);
            pos += entity_len;
        } else out[pos++] = (char)ch;
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_match_literal(
    const unsigned char *bytes,
    uint32_t len,
    uint32_t pos,
    const char *literal
) {
    uint32_t literal_len = (uint32_t)strlen(literal);
    return pos + literal_len <= len && memcmp(bytes + pos, literal, literal_len) == 0;
}

static inline JinxValue jinx_oracle_htmlspecialchars_decode_value(JinxValue value, JinxValue flags_value, uint32_t argc) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int flags = argc >= 2u ? (int)jinx_oracle_intish(flags_value) : 11;
    int quote_style = flags & 3;
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len;) {
        if (jinx_oracle_match_literal(bytes, len, i, "&amp;")) { out[pos++]='&'; i+=5u; }
        else if (jinx_oracle_match_literal(bytes, len, i, "&lt;")) { out[pos++]='<'; i+=4u; }
        else if (jinx_oracle_match_literal(bytes, len, i, "&gt;")) { out[pos++]='>'; i+=4u; }
        else if ((quote_style == 2 || quote_style == 3) && jinx_oracle_match_literal(bytes, len, i, "&quot;")) { out[pos++]='"'; i+=6u; }
        else if (quote_style == 3 && jinx_oracle_match_literal(bytes, len, i, "&#039;")) { out[pos++]='\''; i+=6u; }
        else { out[pos++]=(char)bytes[i++]; }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_hex2bin_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t out_len;
    char *out;

    if ((len & 1u) != 0u) {
        return jinx_oracle_bool_value(0);
    }

    out_len = len / 2u;
    out = jinx_oracle_scratch_string(out_len);

    for (uint32_t i = 0u; i < out_len; i++) {
        int hi = jinx_oracle_hex_nibble(bytes[i * 2u]);
        int lo = jinx_oracle_hex_nibble(bytes[i * 2u + 1u]);
        if (hi < 0 || lo < 0) {
            return jinx_oracle_bool_value(0);
        }
        out[i] = (char)((hi << 4) | lo);
    }

    return jinx_oracle_string_value_len(out, out_len);
}

static inline JinxValue jinx_oracle_str_rot13_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];

        if (c >= (unsigned char)'a' && c <= (unsigned char)'z') {
            c = (unsigned char)('a' + ((c - (unsigned char)'a' + 13u) % 26u));
        } else if (c >= (unsigned char)'A' && c <= (unsigned char)'Z') {
            c = (unsigned char)('A' + ((c - (unsigned char)'A' + 13u) % 26u));
        }

        out[i] = (char)c;
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_addslashes_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t needed = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char c = bytes[i];
        needed += (c == (unsigned char)'\'' || c == (unsigned char)'"' || c == (unsigned char)'\\' || c == 0u) ? 2u : 1u;
    }

    if (needed > UINT32_MAX) {
        return jinx_oracle_bool_value(0);
    }

    uint32_t out_len = (uint32_t)needed;
    char *out = jinx_oracle_scratch_string(out_len);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len && pos < out_len; i++) {
        unsigned char c = bytes[i];
        int escaped = c == (unsigned char)'\'' || c == (unsigned char)'"' || c == (unsigned char)'\\' || c == 0u;

        if (escaped && pos < out_len) {
            out[pos++] = '\\';
        }

        if (pos < out_len) {
            out[pos++] = c == 0u ? '0' : (char)c;
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_str_repeat_value(JinxValue value, JinxValue count_value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t count = jinx_oracle_intish(count_value);
    uint32_t out_len;
    char *out;

    if (count == 0 || len == 0u) {
        return jinx_oracle_string_value_len("", 0u);
    }

    if ((uint64_t)len * (uint64_t)count > UINT32_MAX) {
        return jinx_oracle_string_value_len("", 0u);
    }

    out_len = len * (uint32_t)count;
    out = jinx_oracle_scratch_string(out_len);

    for (int64_t i = 0; i < count; i++) {
        memcpy(out + ((uint32_t)i * len), bytes, len);
    }

    return jinx_oracle_string_value_len(out, out_len);
}

static inline int jinx_oracle_string_compare_value(JinxValue left_value, JinxValue right_value, JinxValue length_value, uint32_t argc, int fold_case) {
    const unsigned char *left = jinx_oracle_string_bytes(left_value);
    const unsigned char *right = jinx_oracle_string_bytes(right_value);
    uint32_t left_len = jinx_oracle_string_len(left_value);
    uint32_t right_len = jinx_oracle_string_len(right_value);
    uint32_t limit = left_len > right_len ? right_len : left_len;

    if (argc >= 3u) {
        int64_t requested = jinx_oracle_intish(length_value);
        if (requested < 0) {
            requested = 0;
        }
        if ((uint64_t)requested < (uint64_t)limit) {
            limit = (uint32_t)requested;
        }
    }

    for (uint32_t i = 0; i < limit; i++) {
        unsigned char a = left[i];
        unsigned char b = right[i];

        if (fold_case) {
            a = jinx_oracle_ascii_lower_byte(a);
            b = jinx_oracle_ascii_lower_byte(b);
        }

        if (a != b) {
            return (int)a - (int)b;
        }
    }

    if (argc >= 3u) {
        return 0;
    }

    if (left_len == right_len) {
        return 0;
    }

    return left_len < right_len ? -1 : 1;
}

static inline JinxValue jinx_oracle_strcoll_value(JinxValue left_value, JinxValue right_value) {
    uint32_t left_len = jinx_oracle_string_len(left_value);
    uint32_t right_len = jinx_oracle_string_len(right_value);
    const unsigned char *left_bytes = jinx_oracle_string_bytes(left_value);
    const unsigned char *right_bytes = jinx_oracle_string_bytes(right_value);
    char *left = (char *)malloc((size_t)left_len + 1u);
    char *right = (char *)malloc((size_t)right_len + 1u);

    if (left == NULL || right == NULL) {
        free(left);
        free(right);
        return jinx_oracle_zero_value();
    }

    if (left_len != 0u) memcpy(left, left_bytes, left_len);
    if (right_len != 0u) memcpy(right, right_bytes, right_len);
    left[left_len] = '\0';
    right[right_len] = '\0';

    int result = strcoll(left, right);
    free(left);
    free(right);
    return jinx_oracle_int_value((int64_t)result);
}

static inline JinxValue jinx_oracle_substr_replace_scalar_value(
    JinxValue string_value,
    JinxValue replacement_value,
    JinxValue start_value,
    JinxValue length_value,
    uint32_t argc
) {
    const unsigned char *string = jinx_oracle_string_bytes(string_value);
    const unsigned char *replacement = jinx_oracle_string_bytes(replacement_value);
    uint32_t string_len = jinx_oracle_string_len(string_value);
    uint32_t replacement_len = jinx_oracle_string_len(replacement_value);
    int64_t start = jinx_oracle_intish(start_value);
    int64_t length = argc >= 4u && length_value.type != 0u
        ? jinx_oracle_intish(length_value)
        : (int64_t)string_len;

    if (start < 0) {
        start = (int64_t)string_len + start;
        if (start < 0) start = 0;
    } else if (start > (int64_t)string_len) {
        start = (int64_t)string_len;
    }

    if (length < 0) {
        length = ((int64_t)string_len - start) + length;
        if (length < 0) length = 0;
    }

    if (length > (int64_t)string_len) length = (int64_t)string_len;
    if (start + length > (int64_t)string_len) length = (int64_t)string_len - start;

    uint64_t needed = (uint64_t)string_len - (uint64_t)length + (uint64_t)replacement_len;
    if (needed > UINT32_MAX) return jinx_oracle_zero_value();

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    if (start > 0) {
        memcpy(out, string, (size_t)start);
        pos = (uint32_t)start;
    }
    if (replacement_len != 0u) {
        memcpy(out + pos, replacement, replacement_len);
        pos += replacement_len;
    }

    uint32_t tail_start = (uint32_t)(start + length);
    uint32_t tail_len = string_len - tail_start;
    if (tail_len != 0u) {
        memcpy(out + pos, string + tail_start, tail_len);
        pos += tail_len;
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_binary_strncmp_bytes(
    const unsigned char *left,
    uint32_t left_len,
    const unsigned char *right,
    uint32_t right_len,
    uint32_t length,
    int case_insensitive_locale
) {
    uint32_t limit = left_len < right_len ? left_len : right_len;
    if (length < limit) limit = length;

    for (uint32_t i = 0u; i < limit; i++) {
        int a = left[i];
        int b = right[i];

        if (case_insensitive_locale) {
            a = tolower(a);
            b = tolower(b);
        }

        if (a != b) return a - b;
    }

    uint32_t compared_left = left_len < length ? left_len : length;
    uint32_t compared_right = right_len < length ? right_len : length;
    if (compared_left == compared_right) return 0;
    return compared_left < compared_right ? -1 : 1;
}

static inline JinxValue jinx_oracle_substr_compare_value(
    JinxValue haystack_value,
    JinxValue needle_value,
    JinxValue offset_value,
    JinxValue length_value,
    JinxValue case_value,
    uint32_t argc
) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    int64_t offset = jinx_oracle_intish(offset_value);
    uint32_t start;
    uint32_t compare_len;
    int case_insensitive = argc >= 5u && jinx_oracle_boolish(case_value);

    if (offset < 0) {
        int64_t normalized = (int64_t)haystack_len + offset;
        start = normalized < 0 ? 0u : (uint32_t)normalized;
    } else {
        start = (uint32_t)offset;
    }

    if (argc >= 4u && length_value.type != 0u) {
        int64_t requested = jinx_oracle_intish(length_value);
        if (requested == 0) return jinx_oracle_int_value(0);
        compare_len = (uint32_t)requested;
    } else {
        uint32_t remaining = haystack_len - start;
        compare_len = remaining > needle_len ? remaining : needle_len;
    }

    return jinx_oracle_int_value((int64_t)jinx_oracle_binary_strncmp_bytes(
        haystack + start,
        haystack_len - start,
        needle,
        needle_len,
        compare_len,
        case_insensitive
    ));
}

static inline JinxValue jinx_oracle_utf8_encode_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint64_t needed = 0u;

    for (uint32_t i = 0u; i < len; i++) needed += bytes[i] < 0x80u ? 1u : 2u;
    if (needed > UINT32_MAX) return jinx_oracle_zero_value();

    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (ch < 0x80u) {
            out[pos++] = (char)ch;
        } else {
            out[pos++] = (char)(0xc0u | (ch >> 6u));
            out[pos++] = (char)(0x80u | (ch & 0x3fu));
        }
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_utf8_next(
    const unsigned char *bytes,
    uint32_t len,
    uint32_t *position,
    uint32_t *codepoint
) {
    uint32_t i = *position;
    unsigned char first;

    if (i >= len) return 0;
    first = bytes[i++];

    if (first < 0x80u) {
        *codepoint = first;
        *position = i;
        return 1;
    }

    uint32_t cp;
    uint32_t needed;
    uint32_t min_cp;

    if (first >= 0xc2u && first <= 0xdfu) {
        cp = first & 0x1fu;
        needed = 1u;
        min_cp = 0x80u;
    } else if (first >= 0xe0u && first <= 0xefu) {
        cp = first & 0x0fu;
        needed = 2u;
        min_cp = 0x800u;
    } else if (first >= 0xf0u && first <= 0xf4u) {
        cp = first & 0x07u;
        needed = 3u;
        min_cp = 0x10000u;
    } else {
        *position = i;
        *codepoint = (uint32_t)'?';
        return 0;
    }

    if (i + needed > len) {
        *position = i;
        *codepoint = (uint32_t)'?';
        return 0;
    }

    for (uint32_t j = 0u; j < needed; j++) {
        unsigned char cont = bytes[i + j];
        if ((cont & 0xc0u) != 0x80u) {
            *position = i;
            *codepoint = (uint32_t)'?';
            return 0;
        }
        cp = (cp << 6u) | (cont & 0x3fu);
    }

    if (cp < min_cp || cp > 0x10ffffu || (cp >= 0xd800u && cp <= 0xdfffu)) {
        *position = i;
        *codepoint = (uint32_t)'?';
        return 0;
    }

    i += needed;
    *position = i;
    *codepoint = cp;
    return 1;
}

static inline JinxValue jinx_oracle_utf8_decode_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;
    uint32_t i = 0u;

    while (i < len) {
        uint32_t cp = (uint32_t)'?';
        uint32_t before = i;
        int valid = jinx_oracle_utf8_next(bytes, len, &i, &cp);

        if (!valid) {
            if (i <= before) i = before + 1u;
            out[pos++] = '?';
            continue;
        }

        out[pos++] = cp <= 0xffu ? (char)cp : '?';
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline int jinx_oracle_nat_compare_right(
    const unsigned char **a,
    const unsigned char *a_end,
    const unsigned char **b,
    const unsigned char *b_end
) {
    int bias = 0;

    for (;;) {
        int a_digit = *a < a_end && isdigit((int)**a);
        int b_digit = *b < b_end && isdigit((int)**b);

        if (!a_digit && !b_digit) return bias;
        if (!a_digit) return -1;
        if (!b_digit) return 1;

        if (**a < **b && bias == 0) bias = -1;
        else if (**a > **b && bias == 0) bias = 1;

        (*a)++;
        (*b)++;
    }
}

static inline int jinx_oracle_nat_compare_left(
    const unsigned char **a,
    const unsigned char *a_end,
    const unsigned char **b,
    const unsigned char *b_end
) {
    for (;;) {
        int a_digit = *a < a_end && isdigit((int)**a);
        int b_digit = *b < b_end && isdigit((int)**b);

        if (!a_digit && !b_digit) return 0;
        if (!a_digit) return -1;
        if (!b_digit) return 1;
        if (**a < **b) return -1;
        if (**a > **b) return 1;

        (*a)++;
        (*b)++;
    }
}

static inline int jinx_oracle_strnatcmp_bytes(
    const unsigned char *a,
    uint32_t a_len,
    const unsigned char *b,
    uint32_t b_len,
    int case_insensitive
) {
    const unsigned char *ap;
    const unsigned char *bp;
    const unsigned char *a_end = a + a_len;
    const unsigned char *b_end = b + b_len;
    unsigned char ca;
    unsigned char cb;

    if (a_len == 0u || b_len == 0u) {
        return a_len == b_len ? 0 : (a_len > b_len ? 1 : -1);
    }

    ap = a;
    bp = b;
    ca = *ap;
    cb = *bp;

    while (ca == (unsigned char)'0' && ap + 1 < a_end && isdigit((int)ap[1])) {
        ca = *++ap;
    }
    while (cb == (unsigned char)'0' && bp + 1 < b_end && isdigit((int)bp[1])) {
        cb = *++bp;
    }

    for (;;) {
        while (ap < a_end && isspace((int)ca)) {
            ap++;
            if (ap < a_end) ca = *ap;
        }
        while (bp < b_end && isspace((int)cb)) {
            bp++;
            if (bp < b_end) cb = *bp;
        }

        if (ap >= a_end || bp >= b_end) {
            if (ap >= a_end && bp >= b_end) return 0;
            return ap >= a_end ? -1 : 1;
        }

        if (isdigit((int)ca) && isdigit((int)cb)) {
            int fractional = ca == (unsigned char)'0' || cb == (unsigned char)'0';
            int result = fractional
                ? jinx_oracle_nat_compare_left(&ap, a_end, &bp, b_end)
                : jinx_oracle_nat_compare_right(&ap, a_end, &bp, b_end);

            if (result != 0) return result;
            if (ap == a_end && bp == b_end) return 0;
            if (ap == a_end) return -1;
            if (bp == b_end) return 1;

            ca = *ap;
            cb = *bp;
        }

        if (case_insensitive) {
            ca = (unsigned char)toupper((int)ca);
            cb = (unsigned char)toupper((int)cb);
        }

        if (ca < cb) return -1;
        if (ca > cb) return 1;

        ap++;
        bp++;
        if (ap >= a_end && bp >= b_end) return 0;
        if (ap >= a_end) return -1;
        if (bp >= b_end) return 1;

        ca = *ap;
        cb = *bp;
    }
}

static inline JinxValue jinx_oracle_substr_count_value(JinxValue haystack_value, JinxValue needle_value, JinxValue offset_value, JinxValue length_value, uint32_t argc) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    int64_t raw_offset = offset_value.type == 1u ? jinx_oracle_intish(offset_value) : 0;
    uint32_t start;
    uint32_t end;
    uint32_t count = 0u;

    (void)argc;

    if (needle_len == 0u) {
        return jinx_oracle_int_value(0);
    }

    if (raw_offset < 0) {
        raw_offset = (int64_t)haystack_len + raw_offset;
    }

    if (raw_offset < 0 || raw_offset > (int64_t)haystack_len) {
        return jinx_oracle_int_value(0);
    }

    start = (uint32_t)raw_offset;
    end = haystack_len;

    if (argc >= 4u && length_value.type != 0u) {
        int64_t requested = jinx_oracle_intish(length_value);
        int64_t remaining = (int64_t)haystack_len - (int64_t)start;

        if (requested < 0) {
            requested += remaining;
        }

        if (requested < 0) {
            return jinx_oracle_int_value(0);
        }

        end = start + (uint32_t)requested;
    }

    if (needle_len > end - start) {
        return jinx_oracle_int_value(0);
    }

    for (uint32_t i = start; i <= end - needle_len;) {
        if (memcmp(haystack + i, needle, needle_len) == 0) {
            count++;
            i += needle_len;
        } else {
            i++;
        }
    }

    return jinx_oracle_int_value((int64_t)count);
}

static inline int jinx_oracle_boolish(JinxValue value) {
    if (value.type == 0u) {
        return 0;
    }

    if (value.type == 1u || value.type == 2u) {
        return value.as.i64 != 0;
    }

    if (value.type == 4u) {
        return value.flags != 0u;
    }

    if (value.type == 5u) {
        return value.as.f64 != 0.0;
    }

    if (value.type == 3u) {
        const unsigned char *bytes = jinx_oracle_string_bytes(value);
        return value.flags != 0u &&
            !(value.flags == 1u && bytes[0] == (unsigned char)'0');
    }

    return 1;
}

static inline JinxValue jinx_oracle_strval_value(JinxValue value) {
    char *out;

    if (value.type == 3u) {
        return value;
    }

    out = jinx_oracle_scratch_string(64u);

    if (value.type == 0u) {
        out[0] = '\0';
        return jinx_oracle_string_value_len(out, 0u);
    }

    if (value.type == 2u) {
        if (value.as.i64 != 0) {
            memcpy(out, "1", 1u);
            return jinx_oracle_string_value_len(out, 1u);
        }

        out[0] = '\0';
        return jinx_oracle_string_value_len(out, 0u);
    }

    if (value.type == 5u) {
        int len = snprintf(out, 64u, "%g", value.as.f64);
        return jinx_oracle_string_value_len(out, len < 0 ? 0u : (uint32_t)len);
    }

    if (value.type == 4u) {
        memcpy(out, "Array", 5u);
        return jinx_oracle_string_value_len(out, 5u);
    }

    {
        int len = snprintf(out, 64u, "%lld", (long long)jinx_oracle_intish(value));
        return jinx_oracle_string_value_len(out, len < 0 ? 0u : (uint32_t)len);
    }
}

static inline int jinx_oracle_ascii_numeric_space(unsigned char c) {
    return c == (unsigned char)' ' || c == (unsigned char)'\t' ||
        c == (unsigned char)'\n' || c == (unsigned char)'\r' ||
        c == (unsigned char)'\f' || c == (unsigned char)'\v';
}

static inline int jinx_oracle_decimal_prefix(
    const unsigned char *bytes,
    uint32_t len,
    uint32_t *start_out,
    uint32_t *end_out,
    int *floating_out
) {
    uint32_t i = 0u;
    uint32_t mantissa_digits = 0u;
    int floating = 0;

    while (i < len && jinx_oracle_ascii_numeric_space(bytes[i])) i++;

    uint32_t start = i;
    if (i < len && (bytes[i] == (unsigned char)'+' || bytes[i] == (unsigned char)'-')) i++;

    while (i < len && bytes[i] >= (unsigned char)'0' && bytes[i] <= (unsigned char)'9') {
        mantissa_digits++;
        i++;
    }

    if (i < len && bytes[i] == (unsigned char)'.') {
        floating = 1;
        i++;
        while (i < len && bytes[i] >= (unsigned char)'0' && bytes[i] <= (unsigned char)'9') {
            mantissa_digits++;
            i++;
        }
    }

    if (mantissa_digits == 0u) return 0;

    if (i < len && (bytes[i] == (unsigned char)'e' || bytes[i] == (unsigned char)'E')) {
        uint32_t exponent_start = i;
        uint32_t j = i + 1u;
        uint32_t exponent_digits = 0u;

        if (j < len && (bytes[j] == (unsigned char)'+' || bytes[j] == (unsigned char)'-')) j++;
        while (j < len && bytes[j] >= (unsigned char)'0' && bytes[j] <= (unsigned char)'9') {
            exponent_digits++;
            j++;
        }

        if (exponent_digits != 0u) {
            floating = 1;
            i = j;
        } else {
            i = exponent_start;
        }
    }

    if (start_out != NULL) *start_out = start;
    if (end_out != NULL) *end_out = i;
    if (floating_out != NULL) *floating_out = floating;
    return 1;
}

static inline double jinx_oracle_decimal_prefix_double(
    const unsigned char *bytes,
    uint32_t start,
    uint32_t end
) {
    uint32_t len = end > start ? end - start : 0u;
    char *buffer = (char *)malloc((size_t)len + 1u);
    double result;

    if (buffer == NULL) return 0.0;
    if (len != 0u) memcpy(buffer, bytes + start, len);
    buffer[len] = '\0';
    result = strtod(buffer, NULL);
    free(buffer);
    return result;
}

static inline int jinx_oracle_string_is_numeric(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t start = 0u;
    uint32_t end = 0u;
    int floating = 0;

    if (!jinx_oracle_decimal_prefix(bytes, len, &start, &end, &floating)) return 0;
    (void)start;
    (void)floating;

    while (end < len && jinx_oracle_ascii_numeric_space(bytes[end])) end++;
    return end == len;
}

static inline int jinx_oracle_base_digit_value(unsigned char c) {
    if (c >= (unsigned char)'0' && c <= (unsigned char)'9') return (int)(c - (unsigned char)'0');
    if (c >= (unsigned char)'a' && c <= (unsigned char)'z') return 10 + (int)(c - (unsigned char)'a');
    if (c >= (unsigned char)'A' && c <= (unsigned char)'Z') return 10 + (int)(c - (unsigned char)'A');
    return -1;
}

static inline int64_t jinx_oracle_parse_int_base(JinxValue value, int base) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t i = 0u;
    int negative = 0;
    uint64_t number = 0u;
    uint64_t limit;
    int saw_digit = 0;

    while (i < len && jinx_oracle_ascii_numeric_space(bytes[i])) i++;
    if (i < len && (bytes[i] == (unsigned char)'+' || bytes[i] == (unsigned char)'-')) {
        negative = bytes[i] == (unsigned char)'-';
        i++;
    }

    if (base == 0) {
        if (i + 1u < len && bytes[i] == (unsigned char)'0' &&
            (bytes[i + 1u] == (unsigned char)'x' || bytes[i + 1u] == (unsigned char)'X')) {
            base = 16;
            i += 2u;
        } else if (i + 1u < len && bytes[i] == (unsigned char)'0' &&
            (bytes[i + 1u] == (unsigned char)'b' || bytes[i + 1u] == (unsigned char)'B')) {
            base = 2;
            i += 2u;
        } else if (i < len && bytes[i] == (unsigned char)'0') {
            base = 8;
        } else {
            base = 10;
        }
    } else if (base == 2 && i + 1u < len && bytes[i] == (unsigned char)'0' &&
        (bytes[i + 1u] == (unsigned char)'b' || bytes[i + 1u] == (unsigned char)'B')) {
        i += 2u;
    } else if (base == 16 && i + 1u < len && bytes[i] == (unsigned char)'0' &&
        (bytes[i + 1u] == (unsigned char)'x' || bytes[i + 1u] == (unsigned char)'X')) {
        i += 2u;
    }

    if (base < 2 || base > 36) return 0;

    limit = negative ? ((uint64_t)INT64_MAX + 1u) : (uint64_t)INT64_MAX;

    while (i < len) {
        int digit = jinx_oracle_base_digit_value(bytes[i]);
        if (digit < 0 || digit >= base) break;
        saw_digit = 1;

        if (number > (limit - (uint64_t)digit) / (uint64_t)base) {
            return negative ? INT64_MIN : INT64_MAX;
        }

        number = number * (uint64_t)base + (uint64_t)digit;
        i++;
    }

    if (!saw_digit) return 0;
    if (negative) {
        if (number == (uint64_t)INT64_MAX + 1u) return INT64_MIN;
        return -(int64_t)number;
    }
    return (int64_t)number;
}

static inline int jinx_oracle_mem_contains(const unsigned char *haystack, uint32_t haystack_len, const unsigned char *needle, uint32_t needle_len) {
    if (needle_len == 0u) {
        return 1;
    }
    if (needle_len > haystack_len) {
        return 0;
    }
    for (uint32_t i = 0; i <= haystack_len - needle_len; i++) {
        if (memcmp(haystack + i, needle, needle_len) == 0) {
            return 1;
        }
    }
    return 0;
}

static inline int jinx_oracle_name_is(const char *actual, const char *expected) {
    return actual != NULL && strcmp(actual, expected) == 0;
}

static inline int jinx_oracle_name_has(const char *name, const char *needle) {
    return name != NULL && needle != NULL && strstr(name, needle) != NULL;
}

static inline int jinx_oracle_name_starts(const char *name, const char *prefix) {
    return name != NULL && prefix != NULL && strncmp(name, prefix, strlen(prefix)) == 0;
}

static inline int jinx_oracle_name_ends(const char *name, const char *suffix) {
    size_t name_len;
    size_t suffix_len;

    if (name == NULL || suffix == NULL) {
        return 0;
    }

    name_len = strlen(name);
    suffix_len = strlen(suffix);

    return suffix_len <= name_len && strcmp(name + name_len - suffix_len, suffix) == 0;
}

static inline int jinx_oracle_name_in2(const char *name, const char *a, const char *b) {
    return jinx_oracle_name_is(name, a) || jinx_oracle_name_is(name, b);
}

static inline int jinx_oracle_name_in3(const char *name, const char *a, const char *b, const char *c) {
    return jinx_oracle_name_in2(name, a, b) || jinx_oracle_name_is(name, c);
}

static inline int jinx_oracle_name_in4(const char *name, const char *a, const char *b, const char *c, const char *d) {
    return jinx_oracle_name_in3(name, a, b, c) || jinx_oracle_name_is(name, d);
}

static inline int jinx_oracle_name_in8(
    const char *name,
    const char *a,
    const char *b,
    const char *c,
    const char *d,
    const char *e,
    const char *f,
    const char *g,
    const char *h
) {
    return jinx_oracle_name_in4(name, a, b, c, d) ||
        jinx_oracle_name_in4(name, e, f, g, h);
}

static inline int64_t jinx_oracle_intish(JinxValue value) {
    if (value.type == 1u) return value.as.i64;
    if (value.type == 2u) return value.as.i64 != 0 ? 1 : 0;
    if (value.type == 4u) return value.flags != 0u ? 1 : 0;

    if (value.type == 5u) {
        if (value.as.f64 >= (double)INT64_MAX) return INT64_MAX;
        if (value.as.f64 <= (double)INT64_MIN) return INT64_MIN;
        return (int64_t)value.as.f64;
    }

    if (value.type == 3u) {
        const unsigned char *bytes = jinx_oracle_string_bytes(value);
        uint32_t start = 0u;
        uint32_t end = 0u;
        int floating = 0;

        if (!jinx_oracle_decimal_prefix(bytes, value.flags, &start, &end, &floating)) return 0;

        if (!floating) {
            return jinx_oracle_parse_int_base(value, 10);
        }

        double parsed = jinx_oracle_decimal_prefix_double(bytes, start, end);
        if (parsed >= (double)INT64_MAX) return INT64_MAX;
        if (parsed <= (double)INT64_MIN) return INT64_MIN;
        return (int64_t)parsed;
    }

    return 0;
}

static inline double jinx_oracle_floatish(JinxValue value) {
    if (value.type == 5u) return value.as.f64;
    if (value.type == 4u) return value.flags != 0u ? 1.0 : 0.0;

    if (value.type == 3u) {
        const unsigned char *bytes = jinx_oracle_string_bytes(value);
        uint32_t start = 0u;
        uint32_t end = 0u;
        int floating = 0;

        if (!jinx_oracle_decimal_prefix(bytes, value.flags, &start, &end, &floating)) return 0.0;
        (void)floating;
        return jinx_oracle_decimal_prefix_double(bytes, start, end);
    }

    return (double)jinx_oracle_intish(value);
}

static inline double jinx_oracle_pi(void) {
    return acos(-1.0);
}

static inline void jinx_oracle_return(JinxOracleAsmContext *ctx, JinxValue value) {
    if (ctx != NULL) {
        ctx->fault = NULL;
        ctx->registers[JINX_ORA_RET] = value;
    }
}

static inline int jinx_oracle_ctype_all(JinxValue value, int (*predicate)(int)) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);

    if (len == 0u) {
        return 0;
    }
    for (uint32_t i = 0; i < len; i++) {
        if (!predicate((int)bytes[i])) {
            return 0;
        }
    }
    return 1;
}

static inline JinxValue jinx_oracle_asm_load_arg(JinxOracleAsmContext *ctx, uint32_t reg, uint32_t arg_index) {
    JinxValue value = jinx_oracle_zero_value();

    if (ctx != NULL && arg_index < ctx->argc && ctx->argv != NULL) {
        value = ctx->argv[arg_index];
        if (reg < 64u) {
            ctx->register_valid[reg] = 1u;
            ctx->register_arg_index[reg] = arg_index < 255u ? (uint8_t)arg_index : 255u;
        }
    } else if (ctx != NULL && reg < 64u) {
        ctx->register_valid[reg] = 0u;
        ctx->register_arg_index[reg] = 255u;
    }

    if (ctx != NULL && reg < 64u) {
        ctx->registers[reg] = value;
    }

    return value;
}

static inline void jinx_oracle_asm_frame_push(
    JinxOracleAsmContext *ctx,
    JinxValue value,
    uint8_t kind,
    uint8_t source_index
) {
    if (ctx == NULL) {
        return;
    }

    if (ctx->call_argc >= 64u) {
        ctx->fault = "Oracle ASM call frame overflow";
        return;
    }

    ctx->call_args[ctx->call_argc] = value;
    ctx->call_arg_kinds[ctx->call_argc] = kind;
    ctx->call_arg_source_index[ctx->call_argc] = source_index;
    ctx->call_argc++;
}

static inline void jinx_oracle_asm_push_arg(JinxOracleAsmContext *ctx, uint32_t reg) {
    if (ctx != NULL && reg < 64u && ctx->register_valid[reg]) {
        jinx_oracle_asm_frame_push(
            ctx,
            ctx->registers[reg],
            0u,
            ctx->register_arg_index[reg]
        );
    }
}

static inline void jinx_oracle_asm_push_arg_ref(JinxOracleAsmContext *ctx, uint32_t reg) {
    if (ctx != NULL && reg < 64u && ctx->register_valid[reg]) {
        jinx_oracle_asm_frame_push(
            ctx,
            ctx->registers[reg],
            1u,
            ctx->register_arg_index[reg]
        );
    }
}

static inline void jinx_oracle_asm_push_arg_variadic(JinxOracleAsmContext *ctx, uint32_t reg) {
    uint32_t start;

    if (ctx == NULL || reg >= 64u || !ctx->register_valid[reg]) {
        return;
    }

    start = (uint32_t)ctx->register_arg_index[reg];
    if (ctx->argv == NULL || start >= ctx->argc) {
        return;
    }

    for (uint32_t i = start; i < ctx->argc; i++) {
        jinx_oracle_asm_frame_push(
            ctx,
            ctx->argv[i],
            2u,
            i < 255u ? (uint8_t)i : 255u
        );
        if (ctx->fault != NULL) {
            return;
        }
    }
}

static inline JinxValue jinx_oracle_call_arg(JinxOracleAsmContext *ctx, uint32_t index) {
    if (ctx != NULL && index < ctx->call_argc) {
        return ctx->call_args[index];
    }
    return jinx_oracle_zero_value();
}

static inline int jinx_oracle_write_ref_arg(
    JinxOracleAsmContext *ctx,
    uint32_t call_index,
    JinxValue value
) {
    uint8_t source_index;

    if (ctx == NULL || call_index >= ctx->call_argc || ctx->argv == NULL) {
        return 0;
    }

    if (ctx->call_arg_kinds[call_index] != 1u) {
        return 0;
    }

    source_index = ctx->call_arg_source_index[call_index];
    if (source_index == 255u || source_index >= ctx->argc) {
        return 0;
    }

    ctx->call_args[call_index] = value;
    ctx->argv[source_index] = value;
    return 1;
}


static inline int jinx_oracle_ascii_tag_char(unsigned char c) {
    return (c >= (unsigned char)'A' && c <= (unsigned char)'Z') ||
        (c >= (unsigned char)'a' && c <= (unsigned char)'z') ||
        (c >= (unsigned char)'0' && c <= (unsigned char)'9');
}

static inline int jinx_oracle_strip_tags_allowed_name(
    JinxValue allowed_value,
    const unsigned char *name,
    uint32_t name_len
) {
    if (allowed_value.type == 0u || name_len == 0u) return 0;
    if (allowed_value.type != 3u) return -1;

    const unsigned char *allowed = jinx_oracle_string_bytes(allowed_value);
    uint32_t allowed_len = jinx_oracle_string_len(allowed_value);

    for (uint32_t i = 0u; i < allowed_len;) {
        if (allowed[i] != (unsigned char)'<') {
            i++;
            continue;
        }

        uint32_t p = i + 1u;
        if (p < allowed_len && allowed[p] == (unsigned char)'/') p++;
        while (p < allowed_len && jinx_oracle_ascii_numeric_space(allowed[p])) p++;

        uint32_t start = p;
        while (p < allowed_len && jinx_oracle_ascii_tag_char(allowed[p])) p++;
        uint32_t candidate_len = p - start;

        if (candidate_len == name_len) {
            int match = 1;
            for (uint32_t j = 0u; j < name_len; j++) {
                if (jinx_oracle_ascii_lower_byte(allowed[start + j]) !=
                    jinx_oracle_ascii_lower_byte(name[j])) {
                    match = 0;
                    break;
                }
            }
            if (match) return 1;
        }

        while (p < allowed_len && allowed[p] != (unsigned char)'>') p++;
        i = p < allowed_len ? p + 1u : allowed_len;
    }

    return 0;
}

static inline JinxValue jinx_oracle_strip_tags_value(
    JinxValue input_value,
    JinxValue allowed_value,
    uint32_t argc,
    int *ok
) {
    const unsigned char *input = jinx_oracle_string_bytes(input_value);
    uint32_t len = jinx_oracle_string_len(input_value);
    char *out = jinx_oracle_scratch_string(len);
    uint32_t pos = 0u;

    *ok = 0;

    if (argc >= 2u && allowed_value.type != 0u && allowed_value.type != 3u) {
        return jinx_oracle_zero_value();
    }

    for (uint32_t i = 0u; i < len;) {
        if (input[i] == 0u) {
            i++;
            continue;
        }

        if (input[i] != (unsigned char)'<') {
            out[pos++] = (char)input[i++];
            continue;
        }

        if (i + 3u < len &&
            input[i + 1u] == (unsigned char)'!' &&
            input[i + 2u] == (unsigned char)'-' &&
            input[i + 3u] == (unsigned char)'-') {
            uint32_t p = i + 4u;
            int closed = 0;
            while (p + 2u < len) {
                if (input[p] == (unsigned char)'-' &&
                    input[p + 1u] == (unsigned char)'-' &&
                    input[p + 2u] == (unsigned char)'>') {
                    i = p + 3u;
                    closed = 1;
                    break;
                }
                p++;
            }
            if (!closed) i = len;
            continue;
        }

        if (i + 1u < len && input[i + 1u] == (unsigned char)'?') {
            uint32_t p = i + 2u;
            int closed = 0;
            while (p + 1u < len) {
                if (input[p] == (unsigned char)'?' && input[p + 1u] == (unsigned char)'>') {
                    i = p + 2u;
                    closed = 1;
                    break;
                }
                p++;
            }
            if (!closed) i = len;
            continue;
        }

        uint32_t end = i + 1u;
        unsigned char quote = 0u;
        int closed = 0;

        while (end < len) {
            unsigned char ch = input[end];

            if (quote != 0u) {
                if (ch == quote) quote = 0u;
            } else if (ch == (unsigned char)'\'' || ch == (unsigned char)'"') {
                quote = ch;
            } else if (ch == (unsigned char)'>') {
                closed = 1;
                break;
            }
            end++;
        }

        if (!closed) {
            i = len;
            continue;
        }

        uint32_t p = i + 1u;
        if (p < end && input[p] == (unsigned char)'/') p++;
        while (p < end && jinx_oracle_ascii_numeric_space(input[p])) p++;

        uint32_t name_start = p;
        while (p < end && jinx_oracle_ascii_tag_char(input[p])) p++;
        uint32_t name_len = p - name_start;
        int allowed = name_len <= 1023u
            ? jinx_oracle_strip_tags_allowed_name(
                argc >= 2u ? allowed_value : jinx_oracle_zero_value(),
                input + name_start,
                name_len
            )
            : 0;

        if (allowed < 0) return jinx_oracle_zero_value();

        if (allowed > 0) {
            for (uint32_t j = i; j <= end; j++) {
                if (input[j] != 0u) out[pos++] = (char)input[j];
            }
        }

        i = end + 1u;
    }

    *ok = 1;
    return jinx_oracle_string_value_len(out, pos);
}


static inline const char *jinx_oracle_image_type_mime(int64_t image_type) {
    switch (image_type) {
        case 1: return "image/gif";
        case 2: return "image/jpeg";
        case 3: return "image/png";
        case 4:
        case 13: return "application/x-shockwave-flash";
        case 5: return "image/psd";
        case 6: return "image/bmp";
        case 7:
        case 8: return "image/tiff";
        case 9:
        case 11:
        case 12: return "application/octet-stream";
        case 10: return "image/jp2";
        case 14: return "image/iff";
        case 15: return "image/vnd.wap.wbmp";
        case 16: return "image/xbm";
        case 17: return "image/vnd.microsoft.icon";
        case 18: return "image/webp";
        case 19: return "image/avif";
        default: return "application/octet-stream";
    }
}

static inline const char *jinx_oracle_image_type_extension(int64_t image_type) {
    switch (image_type) {
        case 1: return ".gif";
        case 2: return ".jpeg";
        case 3: return ".png";
        case 4:
        case 13: return ".swf";
        case 5: return ".psd";
        case 6:
        case 15: return ".bmp";
        case 7:
        case 8: return ".tiff";
        case 9: return ".jpc";
        case 10: return ".jp2";
        case 11: return ".jpx";
        case 12: return ".jb2";
        case 14: return ".iff";
        case 16: return ".xbm";
        case 17: return ".ico";
        case 18: return ".webp";
        case 19: return ".avif";
        default: return NULL;
    }
}


static inline char *jinx_oracle_canonicalize_version(JinxValue value) {
    const unsigned char *src = jinx_oracle_string_bytes(value);
    uint32_t full_len = jinx_oracle_string_len(value);
    uint32_t len = 0u;

    while (len < full_len && src[len] != 0u) len++;

    char *buf = (char *)malloc((size_t)len * 2u + 1u);
    if (buf == NULL) return NULL;

    if (len == 0u) {
        buf[0] = '\0';
        return buf;
    }

    const unsigned char *p = src;
    char *q = buf;
    unsigned char lp = *p++;
    *q++ = (char)lp;

    for (uint32_t i = 1u; i < len; i++, p++) {
        unsigned char ch = *p;
        unsigned char lq = (unsigned char)q[-1];
        int prev_digit = isdigit((int)lp) && lp != (unsigned char)'.';
        int curr_digit = isdigit((int)ch) && ch != (unsigned char)'.';
        int prev_nondigit = !isdigit((int)lp) && lp != (unsigned char)'.';
        int curr_nondigit = !isdigit((int)ch) && ch != (unsigned char)'.';

        if (ch == '-' || ch == '_' || ch == '+') {
            if (lq != '.') *q++ = '.';
        } else if ((prev_nondigit && curr_digit) || (prev_digit && curr_nondigit)) {
            if (lq != '.') *q++ = '.';
            *q++ = (char)ch;
        } else if (!isalnum((int)ch)) {
            if (lq != '.') *q++ = '.';
        } else {
            *q++ = (char)ch;
        }
        lp = ch;
    }

    if (q > buf && q[-1] == '.') {
        q[-1] = '\0';
    } else {
        *q = '\0';
    }

    return buf;
}

static inline int jinx_oracle_compare_special_version_forms(const char *a, const char *b) {
    static const struct {
        const char *name;
        size_t len;
        int order;
    } forms[] = {
        {"dev", 3u, 0},
        {"alpha", 5u, 1},
        {"a", 1u, 1},
        {"beta", 4u, 2},
        {"b", 1u, 2},
        {"RC", 2u, 3},
        {"rc", 2u, 3},
        {"#", 1u, 4},
        {"pl", 2u, 5},
        {"p", 1u, 5},
        {NULL, 0u, 0}
    };

    int found_a = -1;
    int found_b = -1;

    for (size_t i = 0u; forms[i].name != NULL; i++) {
        if (strncmp(a, forms[i].name, forms[i].len) == 0) {
            found_a = forms[i].order;
            break;
        }
    }
    for (size_t i = 0u; forms[i].name != NULL; i++) {
        if (strncmp(b, forms[i].name, forms[i].len) == 0) {
            found_b = forms[i].order;
            break;
        }
    }

    return found_a == found_b ? 0 : (found_a > found_b ? 1 : -1);
}

static inline char *jinx_oracle_version_strdup(const char *src) {
    size_t len = strlen(src);
    char *copy = (char *)malloc(len + 1u);
    if (copy == NULL) return NULL;
    memcpy(copy, src, len + 1u);
    return copy;
}

static inline int jinx_oracle_version_compare_cstr(const char *orig_a, const char *orig_b) {
    if (*orig_a == '\0' || *orig_b == '\0') {
        if (*orig_a == '\0' && *orig_b == '\0') return 0;
        return *orig_a != '\0' ? 1 : -1;
    }

    char *a = orig_a[0] == '#' ? jinx_oracle_version_strdup(orig_a) : NULL;
    char *b = orig_b[0] == '#' ? jinx_oracle_version_strdup(orig_b) : NULL;

    if (a == NULL && orig_a[0] == '#') return 0;
    if (b == NULL && orig_b[0] == '#') {
        free(a);
        return 0;
    }

    if (orig_a[0] != '#') {
        size_t len = strlen(orig_a);
        JinxValue temp = jinx_oracle_string_value_len(orig_a, (uint32_t)len);
        a = jinx_oracle_canonicalize_version(temp);
    }
    if (orig_b[0] != '#') {
        size_t len = strlen(orig_b);
        JinxValue temp = jinx_oracle_string_value_len(orig_b, (uint32_t)len);
        b = jinx_oracle_canonicalize_version(temp);
    }

    if (a == NULL || b == NULL) {
        free(a);
        free(b);
        return 0;
    }

    char *p1 = a;
    char *p2 = b;
    char *n1 = a;
    char *n2 = b;
    int compare = 0;

    while (*p1 && *p2 && n1 != NULL && n2 != NULL) {
        n1 = strchr(p1, '.');
        if (n1 != NULL) *n1 = '\0';
        n2 = strchr(p2, '.');
        if (n2 != NULL) *n2 = '\0';

        if (isdigit((unsigned char)*p1) && isdigit((unsigned char)*p2)) {
            long l1 = strtol(p1, NULL, 10);
            long l2 = strtol(p2, NULL, 10);
            compare = l1 == l2 ? 0 : (l1 > l2 ? 1 : -1);
        } else if (!isdigit((unsigned char)*p1) && !isdigit((unsigned char)*p2)) {
            compare = jinx_oracle_compare_special_version_forms(p1, p2);
        } else if (isdigit((unsigned char)*p1)) {
            compare = jinx_oracle_compare_special_version_forms("#N#", p2);
        } else {
            compare = jinx_oracle_compare_special_version_forms(p1, "#N#");
        }

        if (compare != 0) break;
        if (n1 != NULL) p1 = n1 + 1;
        if (n2 != NULL) p2 = n2 + 1;
    }

    if (compare == 0) {
        if (n1 != NULL) {
            compare = isdigit((unsigned char)*p1)
                ? 1
                : jinx_oracle_version_compare_cstr(p1, "#N#");
        } else if (n2 != NULL) {
            compare = isdigit((unsigned char)*p2)
                ? -1
                : jinx_oracle_version_compare_cstr("#N#", p2);
        }
    }

    free(a);
    free(b);
    return compare;
}

static inline JinxValue jinx_oracle_version_compare_value(
    JinxValue a_value,
    JinxValue b_value,
    JinxValue op_value,
    uint32_t argc,
    int *ok
) {
    uint32_t a_len = jinx_oracle_string_len(a_value);
    uint32_t b_len = jinx_oracle_string_len(b_value);
    char *a = (char *)malloc((size_t)a_len + 1u);
    char *b = (char *)malloc((size_t)b_len + 1u);

    *ok = 0;
    if (a == NULL || b == NULL) {
        free(a);
        free(b);
        return jinx_oracle_zero_value();
    }

    if (a_len != 0u) memcpy(a, jinx_oracle_string_bytes(a_value), a_len);
    if (b_len != 0u) memcpy(b, jinx_oracle_string_bytes(b_value), b_len);
    a[a_len] = '\0';
    b[b_len] = '\0';

    int compare = jinx_oracle_version_compare_cstr(a, b);
    free(a);
    free(b);

    if (argc < 3u || op_value.type == 0u) {
        *ok = 1;
        return jinx_oracle_int_value(compare);
    }

    if (op_value.type != 3u) return jinx_oracle_zero_value();

    const char *op = (const char *)jinx_oracle_string_bytes(op_value);
    uint32_t op_len = jinx_oracle_string_len(op_value);

#define JINX_OP_IS(lit) (op_len == sizeof(lit) - 1u && memcmp(op, lit, sizeof(lit) - 1u) == 0)
    int result;
    if (JINX_OP_IS("<") || JINX_OP_IS("lt")) {
        result = compare == -1;
    } else if (JINX_OP_IS("<=") || JINX_OP_IS("le")) {
        result = compare != 1;
    } else if (JINX_OP_IS(">") || JINX_OP_IS("gt")) {
        result = compare == 1;
    } else if (JINX_OP_IS(">=") || JINX_OP_IS("ge")) {
        result = compare != -1;
    } else if (JINX_OP_IS("==") || JINX_OP_IS("=") || JINX_OP_IS("eq")) {
        result = compare == 0;
    } else if (JINX_OP_IS("!=") || JINX_OP_IS("<>") || JINX_OP_IS("ne")) {
        result = compare != 0;
    } else {
#undef JINX_OP_IS
        return jinx_oracle_zero_value();
    }
#undef JINX_OP_IS

    *ok = 1;
    return jinx_oracle_bool_value(result);
}


static inline int jinx_oracle_int_pow_checked(
    int64_t base,
    int64_t exponent,
    int64_t *out
) {
    if (base < 0 || exponent < 0 || out == 0) return 0;

    uint64_t result = 1u;
    uint64_t factor = (uint64_t)base;
    uint64_t power = (uint64_t)exponent;

    while (power != 0u) {
        if ((power & 1u) != 0u) {
            if (factor != 0u && result > (uint64_t)INT64_MAX / factor) return 0;
            result *= factor;
        }
        power >>= 1u;
        if (power != 0u) {
            if (factor != 0u && factor > (uint64_t)INT64_MAX / factor) return 0;
            factor *= factor;
        }
    }

    *out = (int64_t)result;
    return 1;
}

static inline JinxValue jinx_oracle_pow_value(
    JinxValue base_value,
    JinxValue exponent_value,
    int always_float
) {
    if (!always_float &&
        base_value.type == 1u &&
        exponent_value.type == 1u &&
        base_value.as.i64 >= 0 &&
        exponent_value.as.i64 >= 0) {
        int64_t exact;
        if (jinx_oracle_int_pow_checked(
            base_value.as.i64,
            exponent_value.as.i64,
            &exact
        )) {
            return jinx_oracle_int_value(exact);
        }
    }

    return jinx_oracle_float_value(pow(
        jinx_oracle_floatish(base_value),
        jinx_oracle_floatish(exponent_value)
    ));
}


static inline int jinx_oracle_numeric_scalar(JinxValue value) {
    return value.type == 1u || value.type == 5u;
}

static inline JinxValue jinx_oracle_numeric_extreme(
    JinxOracleAsmContext *ctx,
    int want_max
) {
    if (ctx == NULL || ctx->call_argc < 2u) {
        if (ctx != NULL) ctx->fault = "min/max scalar form requires at least two values";
        return jinx_oracle_zero_value();
    }

    JinxValue best = jinx_oracle_call_arg(ctx, 0u);
    if (!jinx_oracle_numeric_scalar(best)) {
        ctx->fault = "min/max mixed-type comparison is not native yet";
        return jinx_oracle_zero_value();
    }
    double best_value = jinx_oracle_floatish(best);

    for (uint32_t i = 1u; i < ctx->call_argc; i++) {
        JinxValue candidate = jinx_oracle_call_arg(ctx, i);
        if (!jinx_oracle_numeric_scalar(candidate)) {
            ctx->fault = "min/max mixed-type comparison is not native yet";
            return jinx_oracle_zero_value();
        }

        double candidate_value = jinx_oracle_floatish(candidate);
        if ((want_max && candidate_value > best_value) ||
            (!want_max && candidate_value < best_value)) {
            best = candidate;
            best_value = candidate_value;
        }
    }

    return best;
}


#define JINX_PHP_ROUND_HALF_UP 1
#define JINX_PHP_ROUND_HALF_DOWN 2
#define JINX_PHP_ROUND_HALF_EVEN 3
#define JINX_PHP_ROUND_HALF_ODD 4
#define JINX_PHP_ROUND_CEILING 5
#define JINX_PHP_ROUND_FLOOR 6
#define JINX_PHP_ROUND_TOWARD_ZERO 7
#define JINX_PHP_ROUND_AWAY_FROM_ZERO 8

static inline double jinx_oracle_intpow10(int power) {
    static const double powers[] = {
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
        1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22
    };
    if (power >= 0 && power <= 22) return powers[power];
    return pow(10.0, (double)power);
}

static inline double jinx_oracle_round_basic_edge(double integral, double exponent, int places) {
    return places > 0
        ? fabs((integral + copysign(0.5, integral)) / exponent)
        : fabs((integral + copysign(0.5, integral)) * exponent);
}

static inline double jinx_oracle_round_zero_edge(double integral, double exponent, int places) {
    return places > 0
        ? fabs(integral / exponent)
        : fabs(integral * exponent);
}

static inline double jinx_oracle_round_helper(
    double integral,
    double value,
    double exponent,
    int places,
    int mode
) {
    double value_abs = fabs(value);
    double edge_case;

    switch (mode) {
        case JINX_PHP_ROUND_HALF_UP:
            edge_case = jinx_oracle_round_basic_edge(integral, exponent, places);
            return value_abs >= edge_case
                ? integral + copysign(1.0, integral)
                : integral;

        case JINX_PHP_ROUND_HALF_DOWN:
            edge_case = jinx_oracle_round_basic_edge(integral, exponent, places);
            return value_abs > edge_case
                ? integral + copysign(1.0, integral)
                : integral;

        case JINX_PHP_ROUND_CEILING:
            edge_case = jinx_oracle_round_zero_edge(integral, exponent, places);
            return value > 0.0 && value_abs > edge_case ? integral + 1.0 : integral;

        case JINX_PHP_ROUND_FLOOR:
            edge_case = jinx_oracle_round_zero_edge(integral, exponent, places);
            return value < 0.0 && value_abs > edge_case ? integral - 1.0 : integral;

        case JINX_PHP_ROUND_TOWARD_ZERO:
            return integral;

        case JINX_PHP_ROUND_AWAY_FROM_ZERO:
            edge_case = jinx_oracle_round_zero_edge(integral, exponent, places);
            return value_abs > edge_case
                ? integral + copysign(1.0, integral)
                : integral;

        case JINX_PHP_ROUND_HALF_EVEN:
            edge_case = jinx_oracle_round_basic_edge(integral, exponent, places);
            if (value_abs > edge_case) {
                return integral + copysign(1.0, integral);
            }
            if (value_abs == edge_case && fmod(integral, 2.0) != 0.0) {
                return integral + copysign(1.0, integral);
            }
            return integral;

        case JINX_PHP_ROUND_HALF_ODD:
            edge_case = jinx_oracle_round_basic_edge(integral, exponent, places);
            if (value_abs > edge_case) {
                return integral + copysign(1.0, integral);
            }
            if (value_abs == edge_case && fmod(integral, 2.0) == 0.0) {
                return integral + copysign(1.0, integral);
            }
            return integral;

        default:
            return value;
    }
}

static inline double jinx_oracle_php_round(double value, int places, int mode) {
    if (!isfinite(value) || value == 0.0) return value;

    if (places < INT_MIN + 1) places = INT_MIN + 1;
    int power = places < 0 ? -places : places;
    double exponent = jinx_oracle_intpow10(power);
    double tmp_value;
    double tmp_value2;

    if (value >= 0.0) {
        tmp_value = floor(places > 0 ? value * exponent : value / exponent);
        tmp_value2 = tmp_value + 1.0;
    } else {
        tmp_value = ceil(places > 0 ? value * exponent : value / exponent);
        tmp_value2 = tmp_value - 1.0;
    }

    if ((places > 0 ? tmp_value2 / exponent : tmp_value2 * exponent) == value) {
        tmp_value = tmp_value2;
    }

    if (fabs(tmp_value) >= 1e16) return value;

    tmp_value = jinx_oracle_round_helper(tmp_value, value, exponent, places, mode);

    if (power < 23) {
        return places > 0 ? tmp_value / exponent : tmp_value * exponent;
    }

    char buf[40];
    snprintf(buf, 39u, "%15fe%d", tmp_value, -places);
    buf[39] = '\0';
    double parsed = strtod(buf, NULL);
    return !isfinite(parsed) || isnan(parsed) ? value : parsed;
}

static inline JinxValue jinx_oracle_round_value(
    JinxValue value,
    JinxValue precision_value,
    JinxValue mode_value,
    uint32_t argc,
    int *ok
) {
    int64_t raw_precision = argc >= 2u ? jinx_oracle_intish(precision_value) : 0;
    int places = raw_precision > INT_MAX
        ? INT_MAX
        : (raw_precision < INT_MIN ? INT_MIN : (int)raw_precision);
    int mode = argc >= 3u ? (int)jinx_oracle_intish(mode_value) : JINX_PHP_ROUND_HALF_UP;

    *ok = 0;
    if (mode < JINX_PHP_ROUND_HALF_UP || mode > JINX_PHP_ROUND_AWAY_FROM_ZERO) {
        return jinx_oracle_zero_value();
    }

    *ok = 1;
    return jinx_oracle_float_value(
        jinx_oracle_php_round(jinx_oracle_floatish(value), places, mode)
    );
}

static inline JinxValue jinx_oracle_asm_call_builtin(
    JinxOracleAsmContext *ctx,
    const char *name,
    uint32_t argc
) {
    JinxValue ret = jinx_oracle_zero_value();
    JinxValue arg0;
    JinxValue arg1;

    if (ctx == NULL || name == NULL) {
        return ret;
    }

    argc = ctx->call_argc;
    arg0 = jinx_oracle_call_arg(ctx, 0u);
    arg1 = jinx_oracle_call_arg(ctx, 1u);

    if (jinx_oracle_name_is(name, "strip_tags")) {
        int strip_ok = 0;
        ret = jinx_oracle_strip_tags_value(arg0, arg1, argc, &strip_ok);
        if (!strip_ok) {
            ctx->fault = "strip_tags array allowed-tags overload is not native yet";
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "image_type_to_mime_type")) {
        ret = jinx_oracle_string_value(
            jinx_oracle_image_type_mime(jinx_oracle_intish(arg0))
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "image_type_to_extension")) {
        const char *extension = jinx_oracle_image_type_extension(jinx_oracle_intish(arg0));
        if (extension == NULL) {
            ret = jinx_oracle_bool_value(0);
        } else {
            int include_dot = argc < 2u || jinx_oracle_boolish(arg1);
            ret = jinx_oracle_string_value(include_dot ? extension : extension + 1);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "round")) {
        int round_ok = 0;
        ret = jinx_oracle_round_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc,
            &round_ok
        );
        if (!round_ok) {
            ctx->fault = "round mode must be a PHP 8.4 integer rounding mode";
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "version_compare")) {
        int compare_ok = 0;
        ret = jinx_oracle_version_compare_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc,
            &compare_ok
        );
        if (!compare_ok) {
            ctx->fault = "version_compare operator or allocation error";
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "min", "max")) {
        ret = jinx_oracle_numeric_extreme(ctx, jinx_oracle_name_is(name, "max"));
        if (ctx->fault != NULL) return jinx_oracle_zero_value();
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "pow", "fpow")) {
        ret = jinx_oracle_pow_value(arg0, arg1, jinx_oracle_name_is(name, "fpow"));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "chr")) {
        ret = jinx_oracle_chr_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "ord")) {
        ret = jinx_oracle_ord_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "substr")) {
        ret = jinx_oracle_substr_value(arg0, arg1, jinx_oracle_call_arg(ctx, 2u), argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "strpos", "stripos")) {
        if (argc >= 3u) {
            int64_t offset = jinx_oracle_intish(jinx_oracle_call_arg(ctx, 2u));
            int64_t len = (int64_t)jinx_oracle_string_len(arg0);
            if (offset > len || offset < -len) {
                ctx->fault = "string search offset must be contained in haystack";
                return jinx_oracle_zero_value();
            }
        }
        ret = jinx_oracle_strpos_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc,
            jinx_oracle_name_is(name, "stripos")
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "strrpos", "strripos")) {
        if (argc >= 3u) {
            int64_t offset = jinx_oracle_intish(jinx_oracle_call_arg(ctx, 2u));
            int64_t len = (int64_t)jinx_oracle_string_len(arg0);
            if (offset > len || offset < -len) {
                ctx->fault = "reverse string search offset must be contained in haystack";
                return jinx_oracle_zero_value();
            }
        }
        ret = jinx_oracle_strrpos_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc,
            jinx_oracle_name_is(name, "strripos")
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "strstr", "strchr", "stristr")) {
        ret = jinx_oracle_strstr_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc,
            jinx_oracle_name_is(name, "stristr")
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "strrchr")) {
        ret = jinx_oracle_strrchr_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "strspn", "strcspn")) {
        ret = jinx_oracle_span_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            jinx_oracle_call_arg(ctx, 3u),
            argc,
            jinx_oracle_name_is(name, "strspn")
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "ucwords")) {
        ret = jinx_oracle_ucwords_value(arg0, arg1, argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "str_repeat")) {
        if (jinx_oracle_intish(arg1) < 0) {
            ctx->fault = "str_repeat times must be greater than or equal to 0";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_str_repeat_value(arg0, arg1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "str_increment", "str_decrement")) {
        int conversion_ok = 0;
        ret = jinx_oracle_str_increment_decrement_value(
            arg0,
            jinx_oracle_name_is(name, "str_decrement"),
            &conversion_ok
        );
        if (!conversion_ok) {
            ctx->fault = jinx_oracle_name_is(name, "str_decrement")
                ? "str_decrement input is invalid or out of range"
                : "str_increment input must be non-empty ASCII alphanumeric";
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "bin2hex")) {
        ret = jinx_oracle_bin2hex_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "hex2bin")) {
        ret = jinx_oracle_hex2bin_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "str_rot13")) {
        ret = jinx_oracle_str_rot13_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "addslashes")) {
        ret = jinx_oracle_addslashes_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "stripslashes")) {
        ret = jinx_oracle_stripslashes_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "quotemeta")) {
        ret = jinx_oracle_quotemeta_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "strpbrk")) {
        if (jinx_oracle_string_len(arg1) == 0u) {
            ctx->fault = "strpbrk character list must be non-empty";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_strpbrk_value(arg0, arg1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "chunk_split")) {
        if (argc >= 2u && jinx_oracle_intish(arg1) <= 0) {
            ctx->fault = "chunk_split length must be greater than 0";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_chunk_split_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            argc
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "addcslashes")) {
        ret = jinx_oracle_addcslashes_value(arg0, arg1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "stripcslashes")) {
        ret = jinx_oracle_stripcslashes_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "str_pad")) {
        int64_t target = jinx_oracle_intish(arg1);
        if (target > (int64_t)jinx_oracle_string_len(arg0)) {
            JinxValue pad = jinx_oracle_call_arg(ctx, 2u);
            int64_t type = argc >= 4u ? jinx_oracle_intish(jinx_oracle_call_arg(ctx, 3u)) : 1;

            if (argc >= 3u && jinx_oracle_string_len(pad) == 0u) {
                ctx->fault = "str_pad pad string must not be empty";
                return jinx_oracle_zero_value();
            }
            if (type < 0 || type > 2) {
                ctx->fault = "str_pad type must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH";
                return jinx_oracle_zero_value();
            }
        }

        ret = jinx_oracle_str_pad_value(
            arg0, arg1, jinx_oracle_call_arg(ctx, 2u), jinx_oracle_call_arg(ctx, 3u), argc
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "str_replace", "str_ireplace")) {
        ret = jinx_oracle_str_replace_scalar(
            arg0, arg1, jinx_oracle_call_arg(ctx, 2u), jinx_oracle_name_is(name, "str_ireplace")
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "strtr") && argc >= 3u) {
        ret = jinx_oracle_strtr_three_value(arg0, arg1, jinx_oracle_call_arg(ctx, 2u));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "levenshtein")) {
        ret = jinx_oracle_levenshtein_value(arg0, arg1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "htmlspecialchars")) {
        ret = jinx_oracle_htmlspecialchars_value(
            arg0, arg1, jinx_oracle_call_arg(ctx, 3u), argc
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "htmlspecialchars_decode")) {
        ret = jinx_oracle_htmlspecialchars_decode_value(arg0, arg1, argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "base64_encode")) {
        ret = jinx_oracle_base64_encode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "base64_decode")) {
        ret = jinx_oracle_base64_decode_value(arg0, arg1, argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "urlencode", "rawurlencode")) {
        ret = jinx_oracle_urlencode_value(arg0, jinx_oracle_name_is(name, "rawurlencode"));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "urldecode", "rawurldecode")) {
        ret = jinx_oracle_urldecode_value(arg0, jinx_oracle_name_is(name, "rawurldecode"));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "basename")) {
        ret = jinx_oracle_basename_value(arg0, arg1, argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "dirname")) {
        ret = jinx_oracle_dirname_value(arg0, arg1, argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "base_convert")) {
        ret = jinx_oracle_base_convert_value(arg0, arg1, jinx_oracle_call_arg(ctx, 2u));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "bindec", "hexdec", "octdec")) {
        int base = jinx_oracle_name_is(name, "bindec") ? 2 : (jinx_oracle_name_is(name, "hexdec") ? 16 : 8);
        ret = jinx_oracle_int_value((int64_t)jinx_oracle_parse_base_uint(arg0, base));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "decbin", "dechex", "decoct")) {
        int base = jinx_oracle_name_is(name, "decbin") ? 2 : (jinx_oracle_name_is(name, "dechex") ? 16 : 8);
        ret = jinx_oracle_uint_to_base((uint64_t)jinx_oracle_intish(arg0), base);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "metaphone")) {
        if (argc >= 2u && jinx_oracle_intish(arg1) < 0) {
            ctx->fault = "metaphone max phonemes must be greater than or equal to 0";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_metaphone_value(arg0, arg1, argc);
        if (ret.type == 0u) {
            ctx->fault = "metaphone native conversion failed";
            return ret;
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "similar_text")) {
        ret = jinx_oracle_similar_text_value(ctx, arg0, arg1, argc);
        if (ctx->fault != NULL) {
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "soundex")) {
        ret = jinx_oracle_soundex_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "quoted_printable_decode")) {
        ret = jinx_oracle_quoted_printable_decode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "quoted_printable_encode")) {
        ret = jinx_oracle_quoted_printable_encode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "crc32")) {
        ret = jinx_oracle_int_value((int64_t)jinx_oracle_crc32_bytes(
            jinx_oracle_string_bytes(arg0),
            jinx_oracle_string_len(arg0)
        ));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "checkdate")) {
        ret = jinx_oracle_checkdate_value(arg0, arg1, jinx_oracle_call_arg(ctx, 2u));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "nl2br")) {
        ret = jinx_oracle_nl2br_value(arg0, arg1, argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "number_format")) {
        if (argc >= 2u) {
            int64_t decimals = jinx_oracle_intish(arg1);
            if (decimals > INT_MAX || decimals < INT_MIN) {
                ctx->fault = "number_format decimals must fit a C int";
                return jinx_oracle_zero_value();
            }
        }
        ret = jinx_oracle_number_format_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            jinx_oracle_call_arg(ctx, 3u),
            argc
        );
        if (ret.type == 0u) {
            ctx->fault = "number_format native formatting failed";
            return ret;
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "printf")) {
        int format_ok = 0;
        ret = jinx_oracle_sprintf_values(
            arg0,
            ctx->call_argc > 1u ? &ctx->call_args[1] : NULL,
            ctx->call_argc > 1u ? ctx->call_argc - 1u : 0u,
            &format_ok
        );
        if (!format_ok || ret.type != 3u) {
            ctx->fault = "printf format/argument error";
            return jinx_oracle_zero_value();
        }
        if (ret.flags != 0u && fwrite(ret.as.ptr, 1u, ret.flags, stdout) != ret.flags) {
            ctx->fault = "printf output write failed";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_int_value((int64_t)ret.flags);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "sprintf")) {
        int format_ok = 0;
        ret = jinx_oracle_sprintf_values(
            arg0,
            ctx->call_argc > 1u ? &ctx->call_args[1] : NULL,
            ctx->call_argc > 1u ? ctx->call_argc - 1u : 0u,
            &format_ok
        );
        if (!format_ok) {
            ctx->fault = "sprintf format/argument error";
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "hebrev")) {
        ret = jinx_oracle_hebrev_value(arg0, arg1, argc);
        if (ret.type == 0u) {
            ctx->fault = "hebrev native allocation failed";
            return ret;
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "wordwrap")) {
        int wrap_ok = 0;
        ret = jinx_oracle_wordwrap_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            jinx_oracle_call_arg(ctx, 3u),
            argc,
            &wrap_ok
        );
        if (!wrap_ok) {
            ctx->fault = "wordwrap argument error";
            return jinx_oracle_zero_value();
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "convert_uuencode")) {
        ret = jinx_oracle_uuencode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "convert_uudecode")) {
        ret = jinx_oracle_uudecode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "strcoll")) {
        ret = jinx_oracle_strcoll_value(arg0, arg1);
        if (ret.type == 0u) {
            ctx->fault = "strcoll native allocation failed";
            return ret;
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "substr_replace")) {
        if (arg0.type != 3u || arg1.type != 3u) {
            ctx->fault = "substr_replace array overload is not native yet";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_substr_replace_scalar_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            jinx_oracle_call_arg(ctx, 3u),
            argc
        );
        if (ret.type == 0u) {
            ctx->fault = "substr_replace native allocation failed";
            return ret;
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "substr_compare")) {
        int64_t offset = jinx_oracle_intish(jinx_oracle_call_arg(ctx, 2u));
        int64_t haystack_len = (int64_t)jinx_oracle_string_len(arg0);

        if (offset > haystack_len) {
            ctx->fault = "substr_compare offset must be contained in haystack";
            return jinx_oracle_zero_value();
        }
        if (argc >= 4u && jinx_oracle_call_arg(ctx, 3u).type != 0u &&
            jinx_oracle_intish(jinx_oracle_call_arg(ctx, 3u)) < 0) {
            ctx->fault = "substr_compare length must be greater than or equal to 0";
            return jinx_oracle_zero_value();
        }

        ret = jinx_oracle_substr_compare_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            jinx_oracle_call_arg(ctx, 3u),
            jinx_oracle_call_arg(ctx, 4u),
            argc
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "utf8_encode")) {
        ret = jinx_oracle_utf8_encode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "utf8_decode")) {
        ret = jinx_oracle_utf8_decode_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "strnatcmp", "strnatcasecmp")) {
        ret = jinx_oracle_int_value((int64_t)jinx_oracle_strnatcmp_bytes(
            jinx_oracle_string_bytes(arg0),
            jinx_oracle_string_len(arg0),
            jinx_oracle_string_bytes(arg1),
            jinx_oracle_string_len(arg1),
            jinx_oracle_name_is(name, "strnatcasecmp")
        ));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in4(name, "strcmp", "strcasecmp", "strncmp", "strncasecmp")) {
        if ((jinx_oracle_name_is(name, "strncmp") || jinx_oracle_name_is(name, "strncasecmp")) &&
            jinx_oracle_intish(jinx_oracle_call_arg(ctx, 2u)) < 0) {
            ctx->fault = "string comparison length must be greater than or equal to 0";
            return jinx_oracle_zero_value();
        }
        ret = jinx_oracle_int_value((int64_t)jinx_oracle_string_compare_value(
            arg0,
            arg1,
            jinx_oracle_call_arg(ctx, 2u),
            jinx_oracle_name_starts(name, "strn") ? 3u : 2u,
            jinx_oracle_name_is(name, "strcasecmp") || jinx_oracle_name_is(name, "strncasecmp")
        ));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "substr_count")) {
        uint32_t haystack_len = jinx_oracle_string_len(arg0);
        uint32_t needle_len = jinx_oracle_string_len(arg1);
        int64_t offset = argc >= 3u ? jinx_oracle_intish(jinx_oracle_call_arg(ctx, 2u)) : 0;
        int64_t normalized = offset < 0 ? (int64_t)haystack_len + offset : offset;

        if (needle_len == 0u) {
            ctx->fault = "substr_count needle must not be empty";
            return jinx_oracle_zero_value();
        }
        if (normalized < 0 || normalized > (int64_t)haystack_len) {
            ctx->fault = "substr_count offset must be contained in haystack";
            return jinx_oracle_zero_value();
        }
        if (argc >= 4u && jinx_oracle_call_arg(ctx, 3u).type != 0u) {
            int64_t remaining = (int64_t)haystack_len - normalized;
            int64_t length = jinx_oracle_intish(jinx_oracle_call_arg(ctx, 3u));
            int64_t effective = length < 0 ? remaining + length : length;
            if (effective < 0 || effective > remaining) {
                ctx->fault = "substr_count length must be contained in haystack";
                return jinx_oracle_zero_value();
            }
        }

        ret = jinx_oracle_substr_count_value(arg0, arg1, jinx_oracle_call_arg(ctx, 2u), jinx_oracle_call_arg(ctx, 3u), argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "getrandmax", "mt_getrandmax")) {
        ret = jinx_oracle_int_value(INT64_C(2147483647));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "gettype")) {
        const char *type_name = "unknown type";
        switch (arg0.type) {
            case 0u: type_name = "NULL"; break;
            case 1u: type_name = "integer"; break;
            case 2u: type_name = "boolean"; break;
            case 3u: type_name = "string"; break;
            case 4u:
            case JINX_ORACLE_VALUE_ZEND_ARRAY: type_name = "array"; break;
            case 5u: type_name = "double"; break;
            default: break;
        }
        ret = jinx_oracle_string_value(type_name);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "get_debug_type")) {
        const char *type_name = "unknown";
        switch (arg0.type) {
            case 0u: type_name = "null"; break;
            case 1u: type_name = "int"; break;
            case 2u: type_name = "bool"; break;
            case 3u: type_name = "string"; break;
            case 4u:
            case JINX_ORACLE_VALUE_ZEND_ARRAY: type_name = "array"; break;
            case 5u: type_name = "float"; break;
            default: break;
        }
        ret = jinx_oracle_string_value(type_name);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "is_countable", "is_iterable")) {
        ret = jinx_oracle_bool_value(
            arg0.type == 4u || arg0.type == JINX_ORACLE_VALUE_ZEND_ARRAY
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in2(name, "is_object", "is_resource")) {
        ret = jinx_oracle_bool_value(0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "is_null")) {
        ret = jinx_oracle_bool_value(arg0.type == 0u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "is_bool")) {
        ret = jinx_oracle_bool_value(arg0.type == 2u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "is_int", "is_integer", "is_long")) {
        ret = jinx_oracle_bool_value(arg0.type == 1u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "is_float", "is_double", "is_real")) {
        ret = jinx_oracle_bool_value(arg0.type == 5u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "is_string")) {
        ret = jinx_oracle_bool_value(arg0.type == 3u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "is_array")) {
        ret = jinx_oracle_bool_value(arg0.type == 4u || arg0.type == 6u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "is_scalar")) {
        ret = jinx_oracle_bool_value(arg0.type == 1u || arg0.type == 2u || arg0.type == 3u || arg0.type == 5u);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "is_numeric")) {
        ret = jinx_oracle_bool_value(
            arg0.type == 1u ||
            arg0.type == 5u ||
            (arg0.type == 3u && jinx_oracle_string_is_numeric(arg0))
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "boolval")) {
        ret = jinx_oracle_bool_value(jinx_oracle_boolish(arg0));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "intval")) {
        int base = argc >= 2u ? (int)jinx_oracle_intish(arg1) : 10;
        ret = jinx_oracle_int_value(
            arg0.type == 3u && base != 10
                ? jinx_oracle_parse_int_base(arg0, base)
                : jinx_oracle_intish(arg0)
        );
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "floatval")) {
        ret = jinx_oracle_float_value(jinx_oracle_floatish(arg0));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "strval")) {
        ret = jinx_oracle_strval_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "strlen")) {
        ret = jinx_oracle_int_value((int64_t)jinx_oracle_string_len(arg0));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "count")) {
        ret = jinx_oracle_int_value(arg0.type == 4u ? (int64_t)arg0.flags : jinx_oracle_intish(arg0));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "abs")) {
        if (arg0.type == 5u) {
            ret = jinx_oracle_float_value(fabs(arg0.as.f64));
        } else {
            int64_t value = jinx_oracle_intish(arg0);
            ret = jinx_oracle_int_value(value < 0 ? -value : value);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in8(name, "fmod", "intdiv", "deg2rad", "rad2deg", "pi", "hypot", "is_finite", "is_infinite") ||
        jinx_oracle_name_in2(name, "is_nan", "fdiv")) {
        double x = jinx_oracle_floatish(arg0);
        double y = argc > 1u ? jinx_oracle_floatish(arg1) : 0.0;

        if (jinx_oracle_name_is(name, "fmod")) {
            ret = jinx_oracle_float_value(fmod(x, y));
        } else if (jinx_oracle_name_is(name, "fdiv")) {
            ret = jinx_oracle_float_value(x / y);
        } else if (jinx_oracle_name_is(name, "intdiv")) {
            int64_t dividend = jinx_oracle_intish(arg0);
            int64_t divisor = jinx_oracle_intish(arg1);
            if (divisor == 0) {
                ctx->fault = "intdiv division by zero";
                return jinx_oracle_zero_value();
            }
            if (dividend == INT64_MIN && divisor == -1) {
                ctx->fault = "intdiv integer overflow";
                return jinx_oracle_zero_value();
            }
            ret = jinx_oracle_int_value(dividend / divisor);
        } else if (jinx_oracle_name_is(name, "deg2rad")) {
            ret = jinx_oracle_float_value(x * jinx_oracle_pi() / 180.0);
        } else if (jinx_oracle_name_is(name, "rad2deg")) {
            ret = jinx_oracle_float_value(x * 180.0 / jinx_oracle_pi());
        } else if (jinx_oracle_name_is(name, "pi")) {
            ret = jinx_oracle_float_value(jinx_oracle_pi());
        } else if (jinx_oracle_name_is(name, "hypot")) {
            ret = jinx_oracle_float_value(hypot(x, y));
        } else if (jinx_oracle_name_is(name, "is_finite")) {
            ret = jinx_oracle_bool_value(isfinite(x));
        } else if (jinx_oracle_name_is(name, "is_infinite")) {
            ret = jinx_oracle_bool_value(isinf(x));
        } else {
            ret = jinx_oracle_bool_value(isnan(x));
        }

        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "str_contains", "str_starts_with", "str_ends_with")) {
        const unsigned char *haystack = jinx_oracle_string_bytes(arg0);
        const unsigned char *needle = jinx_oracle_string_bytes(arg1);
        uint32_t haystack_len = jinx_oracle_string_len(arg0);
        uint32_t needle_len = jinx_oracle_string_len(arg1);

        if (jinx_oracle_name_is(name, "str_contains")) {
            ret = jinx_oracle_bool_value(jinx_oracle_mem_contains(haystack, haystack_len, needle, needle_len));
        } else if (jinx_oracle_name_is(name, "str_starts_with")) {
            ret = jinx_oracle_bool_value(needle_len <= haystack_len && memcmp(haystack, needle, needle_len) == 0);
        } else {
            ret = jinx_oracle_bool_value(needle_len <= haystack_len && memcmp(haystack + haystack_len - needle_len, needle, needle_len) == 0);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_starts(name, "ctype_")) {
        if (jinx_oracle_name_is(name, "ctype_alnum")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isalnum));
        } else if (jinx_oracle_name_is(name, "ctype_alpha")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isalpha));
        } else if (jinx_oracle_name_is(name, "ctype_cntrl")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, iscntrl));
        } else if (jinx_oracle_name_is(name, "ctype_digit")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isdigit));
        } else if (jinx_oracle_name_is(name, "ctype_graph")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isgraph));
        } else if (jinx_oracle_name_is(name, "ctype_lower")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, islower));
        } else if (jinx_oracle_name_is(name, "ctype_print")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isprint));
        } else if (jinx_oracle_name_is(name, "ctype_punct")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, ispunct));
        } else if (jinx_oracle_name_is(name, "ctype_space")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isspace));
        } else if (jinx_oracle_name_is(name, "ctype_upper")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isupper));
        } else if (jinx_oracle_name_is(name, "ctype_xdigit")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isxdigit));
        } else {
            ret = jinx_oracle_bool_value(0);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in8(name, "acos", "acosh", "asin", "asinh", "atan", "atanh", "cos", "cosh") ||
        jinx_oracle_name_in8(name, "atan2", "ceil", "floor", "sqrt", "sin", "sinh", "tan", "tanh") ||
        jinx_oracle_name_in4(name, "exp", "expm1", "log", "log10") ||
        jinx_oracle_name_is(name, "log1p")) {
        double x = jinx_oracle_floatish(arg0);
        double y = argc > 1 ? jinx_oracle_floatish(arg1) : 0.0;

        if (jinx_oracle_name_is(name, "acos")) {
            ret = jinx_oracle_float_value(acos(x));
        } else if (jinx_oracle_name_is(name, "acosh")) {
            ret = jinx_oracle_float_value(acosh(x));
        } else if (jinx_oracle_name_is(name, "asin")) {
            ret = jinx_oracle_float_value(asin(x));
        } else if (jinx_oracle_name_is(name, "asinh")) {
            ret = jinx_oracle_float_value(asinh(x));
        } else if (jinx_oracle_name_is(name, "atan")) {
            ret = jinx_oracle_float_value(atan(x));
        } else if (jinx_oracle_name_is(name, "atan2")) {
            ret = jinx_oracle_float_value(atan2(x, y));
        } else if (jinx_oracle_name_is(name, "atanh")) {
            ret = jinx_oracle_float_value(atanh(x));
        } else if (jinx_oracle_name_is(name, "ceil")) {
            ret = jinx_oracle_float_value(ceil(x));
        } else if (jinx_oracle_name_is(name, "floor")) {
            ret = jinx_oracle_float_value(floor(x));
        } else if (jinx_oracle_name_is(name, "sqrt")) {
            ret = jinx_oracle_float_value(sqrt(x));
        } else if (jinx_oracle_name_is(name, "sin")) {
            ret = jinx_oracle_float_value(sin(x));
        } else if (jinx_oracle_name_is(name, "sinh")) {
            ret = jinx_oracle_float_value(sinh(x));
        } else if (jinx_oracle_name_is(name, "tan")) {
            ret = jinx_oracle_float_value(tan(x));
        } else if (jinx_oracle_name_is(name, "tanh")) {
            ret = jinx_oracle_float_value(tanh(x));
        } else if (jinx_oracle_name_is(name, "exp")) {
            ret = jinx_oracle_float_value(exp(x));
        } else if (jinx_oracle_name_is(name, "expm1")) {
            ret = jinx_oracle_float_value(expm1(x));
        } else if (jinx_oracle_name_is(name, "log")) {
            ret = jinx_oracle_float_value(log(x));
        } else if (jinx_oracle_name_is(name, "log10")) {
            ret = jinx_oracle_float_value(log10(x));
        } else if (jinx_oracle_name_is(name, "log1p")) {
            ret = jinx_oracle_float_value(log1p(x));
        } else {
            ret = jinx_oracle_float_value(cos(x));
        }

        jinx_oracle_return(ctx, ret);
        return ret;
    }

    ctx->fault = "No exact native Oracle ASM handler for builtin";
    return ret;
}

static inline JinxValue jinx_oracle_asm_call_method_builtin(JinxOracleAsmContext *ctx, const char *name, uint32_t argc) {
    return jinx_oracle_asm_call_builtin(ctx, name, argc);
}

static inline JinxValue jinx_oracle_asm_mov(JinxOracleAsmContext *ctx, uint32_t dst, uint32_t src) {
    JinxValue value = jinx_oracle_zero_value();
    if (ctx != NULL && src < 64) {
        value = ctx->registers[src];
    }
    if (ctx != NULL && dst < 64) {
        ctx->registers[dst] = value;
    }
    return value;
}

#define JINX_ORA_LOAD_ARG(ctx, reg, index, name_literal) \
    jinx_oracle_asm_load_arg((ctx), (reg), (index))
#define JINX_ORA_PUSH_ARG(ctx, reg) \
    jinx_oracle_asm_push_arg((ctx), (reg))
#define JINX_ORA_PUSH_ARG_REF(ctx, reg) \
    jinx_oracle_asm_push_arg_ref((ctx), (reg))
#define JINX_ORA_PUSH_ARG_VARIADIC(ctx, reg) \
    jinx_oracle_asm_push_arg_variadic((ctx), (reg))
#define JINX_ORA_CALL_BUILTIN(ctx, name_literal, argc_literal) \
    jinx_oracle_asm_call_builtin((ctx), (name_literal), (argc_literal))
#define JINX_ORA_CALL_METHOD_BUILTIN(ctx, name_literal, argc_literal) \
    jinx_oracle_asm_call_method_builtin((ctx), (name_literal), (argc_literal))
#define JINX_ORA_MOV(ctx, dst, src) \
    jinx_oracle_asm_mov((ctx), (dst), (src))

#endif /* JINX_ORACLE_ASM_RUNTIME_H */
