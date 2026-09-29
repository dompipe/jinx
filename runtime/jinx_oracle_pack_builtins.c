#include "jinx_oracle_pack_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"

#include <ctype.h>
#include <limits.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct JinxPackBuffer {
    unsigned char *data;
    size_t len;
    size_t pos;
    size_t cap;
} JinxPackBuffer;

static int pack_host_little_endian(void) {
    const uint16_t one = 1u;
    return *((const unsigned char *)&one) == 1u;
}

static int pack_ensure(JinxPackBuffer *buffer, size_t needed) {
    size_t next;
    unsigned char *grown;

    if (buffer == NULL) return 0;
    if (needed <= buffer->cap) return 1;

    next = buffer->cap == 0u ? 64u : buffer->cap;
    while (next < needed) {
        if (next > SIZE_MAX / 2u) {
            next = needed;
            break;
        }
        next *= 2u;
    }

    grown = (unsigned char *)realloc(buffer->data, next);
    if (grown == NULL) return 0;
    if (next > buffer->cap) {
        memset(grown + buffer->cap, 0, next - buffer->cap);
    }
    buffer->data = grown;
    buffer->cap = next;
    return 1;
}

static int pack_write(JinxPackBuffer *buffer, const void *bytes, size_t len) {
    size_t end;
    if (buffer == NULL || (bytes == NULL && len != 0u)) return 0;
    if (len > SIZE_MAX - buffer->pos) return 0;
    end = buffer->pos + len;
    if (!pack_ensure(buffer, end)) return 0;
    if (len != 0u) memcpy(buffer->data + buffer->pos, bytes, len);
    buffer->pos = end;
    if (end > buffer->len) buffer->len = end;
    return 1;
}

static int pack_fill(JinxPackBuffer *buffer, unsigned char byte, size_t len) {
    size_t end;
    if (buffer == NULL) return 0;
    if (len > SIZE_MAX - buffer->pos) return 0;
    end = buffer->pos + len;
    if (!pack_ensure(buffer, end)) return 0;
    if (len != 0u) memset(buffer->data + buffer->pos, byte, len);
    buffer->pos = end;
    if (end > buffer->len) buffer->len = end;
    return 1;
}

static int pack_seek_absolute(JinxPackBuffer *buffer, size_t pos) {
    if (buffer == NULL) return 0;
    if (!pack_ensure(buffer, pos)) return 0;
    if (pos > buffer->len) {
        memset(buffer->data + buffer->len, 0, pos - buffer->len);
        buffer->len = pos;
    }
    buffer->pos = pos;
    return 1;
}

static JinxValue pack_copy_bytes(const unsigned char *bytes, size_t len) {
    char *out;
    if (len > UINT32_MAX || (bytes == NULL && len != 0u)) {
        return jinx_oracle_zero_value();
    }
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u) memcpy(out, bytes, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

static int pack_hex_nibble(unsigned char c) {
    if (c >= '0' && c <= '9') return (int)(c - '0');
    if (c >= 'a' && c <= 'f') return 10 + (int)(c - 'a');
    if (c >= 'A' && c <= 'F') return 10 + (int)(c - 'A');
    return -1;
}

static int pack_parse_repeat(
    const unsigned char *format,
    size_t format_len,
    size_t *pos,
    size_t *repeat,
    int *star
) {
    size_t value = 0u;
    int saw_digit = 0;

    if (format == NULL || pos == NULL || repeat == NULL || star == NULL) {
        return 0;
    }

    *star = 0;
    if (*pos < format_len && format[*pos] == '*') {
        *star = 1;
        *repeat = 0u;
        (*pos)++;
        return 1;
    }

    while (*pos < format_len && isdigit((unsigned char)format[*pos])) {
        size_t digit = (size_t)(format[*pos] - '0');
        if (value > (SIZE_MAX - digit) / 10u) return 0;
        value = value * 10u + digit;
        saw_digit = 1;
        (*pos)++;
    }

    *repeat = saw_digit ? value : 1u;
    return 1;
}

static int pack_write_uint(
    JinxPackBuffer *buffer,
    uint64_t value,
    size_t bytes,
    int little
) {
    unsigned char raw[8];
    if (bytes == 0u || bytes > sizeof(raw)) return 0;
    for (size_t i = 0u; i < bytes; i++) {
        size_t shift_index = little ? i : (bytes - 1u - i);
        raw[i] = (unsigned char)((value >> (shift_index * 8u)) & 0xffu);
    }
    return pack_write(buffer, raw, bytes);
}

static uint64_t pack_read_uint(
    const unsigned char *bytes,
    size_t width,
    int little
) {
    uint64_t value = 0u;
    if (bytes == NULL || width == 0u || width > 8u) return 0u;
    for (size_t i = 0u; i < width; i++) {
        size_t shift_index = little ? i : (width - 1u - i);
        value |= (uint64_t)bytes[i] << (shift_index * 8u);
    }
    return value;
}

static int pack_write_numeric(
    JinxPackBuffer *buffer,
    unsigned char code,
    JinxValue value
) {
    int little = pack_host_little_endian();
    int64_t integer = jinx_oracle_intish(value);

    switch (code) {
        case 'c':
        case 'C': {
            unsigned char byte = (unsigned char)integer;
            return pack_write(buffer, &byte, 1u);
        }
        case 's':
        case 'S': {
            uint16_t raw = (uint16_t)integer;
            return pack_write_uint(buffer, raw, 2u, little);
        }
        case 'n':
            return pack_write_uint(buffer, (uint16_t)integer, 2u, 0);
        case 'v':
            return pack_write_uint(buffer, (uint16_t)integer, 2u, 1);
        case 'i':
        case 'I': {
            unsigned int raw = (unsigned int)integer;
            return pack_write(buffer, &raw, sizeof(raw));
        }
        case 'l':
        case 'L': {
            uint32_t raw = (uint32_t)integer;
            return pack_write_uint(buffer, raw, 4u, little);
        }
        case 'N':
            return pack_write_uint(buffer, (uint32_t)integer, 4u, 0);
        case 'V':
            return pack_write_uint(buffer, (uint32_t)integer, 4u, 1);
        case 'q':
        case 'Q':
            return pack_write_uint(buffer, (uint64_t)integer, 8u, little);
        case 'J':
            return pack_write_uint(buffer, (uint64_t)integer, 8u, 0);
        case 'P':
            return pack_write_uint(buffer, (uint64_t)integer, 8u, 1);
        case 'f': {
            float number = (float)jinx_oracle_floatish(value);
            return pack_write(buffer, &number, sizeof(number));
        }
        case 'g':
        case 'G': {
            float number = (float)jinx_oracle_floatish(value);
            uint32_t bits = 0u;
            memcpy(&bits, &number, sizeof(bits));
            return pack_write_uint(buffer, bits, 4u, code == 'g');
        }
        case 'd': {
            double number = jinx_oracle_floatish(value);
            return pack_write(buffer, &number, sizeof(number));
        }
        case 'e':
        case 'E': {
            double number = jinx_oracle_floatish(value);
            uint64_t bits = 0u;
            memcpy(&bits, &number, sizeof(bits));
            return pack_write_uint(buffer, bits, 8u, code == 'e');
        }
        default:
            return 0;
    }
}

static int pack_code_is_numeric(unsigned char code) {
    return strchr("cCsSnviIlLNVqQJPfgGdeE", (int)code) != NULL;
}

static JinxValue jinx_pack_values(
    JinxValue format_value,
    JinxValue *values,
    size_t value_count,
    int *ok
) {
    const unsigned char *format;
    size_t format_len;
    size_t pos = 0u;
    size_t value_index = 0u;
    JinxPackBuffer out = {0};
    JinxValue result = jinx_oracle_zero_value();

    if (ok != NULL) *ok = 0;
    if (format_value.type != 3u) return result;

    format = jinx_oracle_string_bytes(format_value);
    format_len = jinx_oracle_string_len(format_value);

    while (pos < format_len) {
        unsigned char code = format[pos++];
        size_t repeat = 1u;
        int star = 0;

        if (!pack_parse_repeat(format, format_len, &pos, &repeat, &star)) {
            free(out.data);
            return result;
        }

        if (code == 'a' || code == 'A' || code == 'Z') {
            const unsigned char *bytes;
            uint32_t len;
            size_t take;
            unsigned char pad = code == 'A' ? (unsigned char)' ' : 0u;

            if (value_index >= value_count || values[value_index].type != 3u) {
                free(out.data);
                return result;
            }
            bytes = jinx_oracle_string_bytes(values[value_index]);
            len = jinx_oracle_string_len(values[value_index++]);

            if (star) {
                repeat = code == 'Z' ? (size_t)len + 1u : (size_t)len;
            }
            if (repeat == 0u) continue;

            take = code == 'Z'
                ? ((size_t)len < repeat - 1u ? (size_t)len : repeat - 1u)
                : ((size_t)len < repeat ? (size_t)len : repeat);

            if (!pack_write(&out, bytes, take)) {
                free(out.data);
                return result;
            }
            if (repeat > take && !pack_fill(&out, pad, repeat - take)) {
                free(out.data);
                return result;
            }
            continue;
        }

        if (code == 'h' || code == 'H') {
            const unsigned char *hex;
            uint32_t hex_len;
            size_t nibbles;
            size_t bytes_needed;

            if (value_index >= value_count || values[value_index].type != 3u) {
                free(out.data);
                return result;
            }
            hex = jinx_oracle_string_bytes(values[value_index]);
            hex_len = jinx_oracle_string_len(values[value_index++]);
            nibbles = star ? (size_t)hex_len : repeat;
            if (nibbles > hex_len) nibbles = hex_len;
            bytes_needed = (nibbles + 1u) / 2u;

            for (size_t i = 0u; i < bytes_needed; i++) {
                int first = pack_hex_nibble(hex[i * 2u]);
                int second = i * 2u + 1u < nibbles
                    ? pack_hex_nibble(hex[i * 2u + 1u])
                    : 0;
                unsigned char byte;
                if (first < 0 || second < 0) {
                    free(out.data);
                    return result;
                }
                byte = code == 'H'
                    ? (unsigned char)((first << 4) | second)
                    : (unsigned char)(first | (second << 4));
                if (!pack_write(&out, &byte, 1u)) {
                    free(out.data);
                    return result;
                }
            }
            continue;
        }

        if (code == 'x') {
            if (star || !pack_fill(&out, 0u, repeat)) {
                free(out.data);
                return result;
            }
            continue;
        }

        if (code == 'X') {
            if (star || repeat > out.pos) {
                free(out.data);
                return result;
            }
            out.pos -= repeat;
            continue;
        }

        if (code == '@') {
            if (star || !pack_seek_absolute(&out, repeat)) {
                free(out.data);
                return result;
            }
            continue;
        }

        if (pack_code_is_numeric(code)) {
            size_t count = star ? value_count - value_index : repeat;
            if (count > value_count - value_index) {
                free(out.data);
                return result;
            }
            for (size_t i = 0u; i < count; i++) {
                if (!pack_write_numeric(&out, code, values[value_index++])) {
                    free(out.data);
                    return result;
                }
            }
            continue;
        }

        free(out.data);
        return result;
    }

    result = pack_copy_bytes(out.data, out.len);
    free(out.data);
    if (result.type == 3u && ok != NULL) *ok = 1;
    return result;
}

static int unpack_add_value(
    JinxZendArray *array,
    const unsigned char *name,
    size_t name_len,
    size_t index,
    size_t count,
    size_t *numeric_index,
    JinxZendValue value
) {
    char key[256];
    size_t key_len;

    if (array == NULL || numeric_index == NULL) return 0;

    if (name_len == 0u) {
        int written = snprintf(key, sizeof(key), "%zu", (*numeric_index)++);
        if (written <= 0 || (size_t)written >= sizeof(key)) return 0;
        key_len = (size_t)written;
    } else if (count != 1u) {
        if (name_len >= sizeof(key) - 32u) return 0;
        memcpy(key, name, name_len);
        {
            int written = snprintf(key + name_len, sizeof(key) - name_len, "%zu", index + 1u);
            if (written <= 0 || name_len + (size_t)written >= sizeof(key)) return 0;
            key_len = name_len + (size_t)written;
        }
    } else {
        if (name_len >= sizeof(key)) return 0;
        memcpy(key, name, name_len);
        key_len = name_len;
    }

    return jinx_zend_array_add_assoc(array, key, key_len, value);
}

static int unpack_add_string(
    JinxZendArray *array,
    const unsigned char *name,
    size_t name_len,
    size_t index,
    size_t count,
    size_t *numeric_index,
    const unsigned char *bytes,
    size_t len
) {
    JinxZendString *string;
    int added;

    string = jinx_zend_string_new((const char *)bytes, len);
    if (string == NULL) return 0;
    added = unpack_add_value(
        array,
        name,
        name_len,
        index,
        count,
        numeric_index,
        jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return added;
}

static size_t unpack_numeric_width(unsigned char code) {
    switch (code) {
        case 'c':
        case 'C':
            return 1u;
        case 's':
        case 'S':
        case 'n':
        case 'v':
            return 2u;
        case 'i':
        case 'I':
            return sizeof(unsigned int);
        case 'l':
        case 'L':
        case 'N':
        case 'V':
        case 'f':
        case 'g':
        case 'G':
            return 4u;
        case 'q':
        case 'Q':
        case 'J':
        case 'P':
        case 'd':
        case 'e':
        case 'E':
            return 8u;
        default:
            return 0u;
    }
}

static int unpack_read_numeric(
    unsigned char code,
    const unsigned char *bytes,
    JinxZendValue *out
) {
    int little = pack_host_little_endian();

    if (bytes == NULL || out == NULL) return 0;

    switch (code) {
        case 'c':
            *out = jinx_zend_long((int64_t)(int8_t)bytes[0]);
            return 1;
        case 'C':
            *out = jinx_zend_long((int64_t)bytes[0]);
            return 1;
        case 's': {
            int16_t value;
            memcpy(&value, bytes, sizeof(value));
            *out = jinx_zend_long((int64_t)value);
            return 1;
        }
        case 'S': {
            uint16_t value;
            memcpy(&value, bytes, sizeof(value));
            *out = jinx_zend_long((int64_t)value);
            return 1;
        }
        case 'n':
            *out = jinx_zend_long((int64_t)pack_read_uint(bytes, 2u, 0));
            return 1;
        case 'v':
            *out = jinx_zend_long((int64_t)pack_read_uint(bytes, 2u, 1));
            return 1;
        case 'i': {
            int value;
            memcpy(&value, bytes, sizeof(value));
            *out = jinx_zend_long((int64_t)value);
            return 1;
        }
        case 'I': {
            unsigned int value;
            memcpy(&value, bytes, sizeof(value));
            *out = jinx_zend_long((int64_t)value);
            return 1;
        }
        case 'l':
            *out = jinx_zend_long((int64_t)(int32_t)pack_read_uint(bytes, 4u, little));
            return 1;
        case 'L':
            *out = jinx_zend_long((int64_t)(uint32_t)pack_read_uint(bytes, 4u, little));
            return 1;
        case 'N':
            *out = jinx_zend_long((int64_t)(uint32_t)pack_read_uint(bytes, 4u, 0));
            return 1;
        case 'V':
            *out = jinx_zend_long((int64_t)(uint32_t)pack_read_uint(bytes, 4u, 1));
            return 1;
        case 'q':
            *out = jinx_zend_long((int64_t)pack_read_uint(bytes, 8u, little));
            return 1;
        case 'Q':
            *out = jinx_zend_long((int64_t)pack_read_uint(bytes, 8u, little));
            return 1;
        case 'J':
            *out = jinx_zend_long((int64_t)pack_read_uint(bytes, 8u, 0));
            return 1;
        case 'P':
            *out = jinx_zend_long((int64_t)pack_read_uint(bytes, 8u, 1));
            return 1;
        case 'f': {
            float value;
            memcpy(&value, bytes, sizeof(value));
            *out = jinx_zend_double((double)value);
            return 1;
        }
        case 'g':
        case 'G': {
            uint32_t bits = (uint32_t)pack_read_uint(bytes, 4u, code == 'g');
            float value;
            memcpy(&value, &bits, sizeof(value));
            *out = jinx_zend_double((double)value);
            return 1;
        }
        case 'd': {
            double value;
            memcpy(&value, bytes, sizeof(value));
            *out = jinx_zend_double(value);
            return 1;
        }
        case 'e':
        case 'E': {
            uint64_t bits = pack_read_uint(bytes, 8u, code == 'e');
            double value;
            memcpy(&value, &bits, sizeof(value));
            *out = jinx_zend_double(value);
            return 1;
        }
        default:
            return 0;
    }
}

static JinxValue jinx_unpack_values(
    JinxValue format_value,
    JinxValue input_value,
    JinxValue offset_value,
    size_t argc,
    int *ok
) {
    const unsigned char *format;
    size_t format_len;
    const unsigned char *input;
    size_t input_len;
    int64_t offset_i = argc >= 3u ? jinx_oracle_intish(offset_value) : 0;
    size_t cursor;
    size_t token_start = 0u;
    size_t numeric_index = 1u;
    JinxZendArray *array;
    JinxValue result = jinx_oracle_zero_value();

    if (ok != NULL) *ok = 0;
    if (format_value.type != 3u || input_value.type != 3u || offset_i < 0) {
        return result;
    }

    format = jinx_oracle_string_bytes(format_value);
    format_len = jinx_oracle_string_len(format_value);
    input = jinx_oracle_string_bytes(input_value);
    input_len = jinx_oracle_string_len(input_value);

    if ((uint64_t)offset_i > input_len) return result;
    cursor = (size_t)offset_i;

    array = jinx_zend_array_new_packed(8u);
    if (array == NULL) return result;

    while (token_start < format_len) {
        size_t token_end = token_start;
        size_t pos;
        unsigned char code;
        size_t repeat = 1u;
        int star = 0;
        const unsigned char *name;
        size_t name_len;

        while (token_end < format_len && format[token_end] != '/') token_end++;
        if (token_end == token_start) {
            token_start = token_end + 1u;
            continue;
        }

        pos = token_start;
        code = format[pos++];
        if (!pack_parse_repeat(format, token_end, &pos, &repeat, &star)) {
            jinx_zend_array_release(array);
            return result;
        }
        name = format + pos;
        name_len = token_end - pos;

        if (code == 'x') {
            size_t count = star ? input_len - cursor : repeat;
            if (count > input_len - cursor) {
                jinx_zend_array_release(array);
                return result;
            }
            cursor += count;
            token_start = token_end + 1u;
            continue;
        }

        if (code == 'X') {
            if (star || repeat > cursor - (size_t)offset_i) {
                jinx_zend_array_release(array);
                return result;
            }
            cursor -= repeat;
            token_start = token_end + 1u;
            continue;
        }

        if (code == '@') {
            size_t target;
            if (star) {
                target = input_len;
            } else {
                if (repeat > input_len - (size_t)offset_i) {
                    jinx_zend_array_release(array);
                    return result;
                }
                target = (size_t)offset_i + repeat;
            }
            cursor = target;
            token_start = token_end + 1u;
            continue;
        }

        if (code == 'a' || code == 'A' || code == 'Z') {
            size_t count = star ? input_len - cursor : repeat;
            size_t out_len = count;
            if (count > input_len - cursor) {
                jinx_zend_array_release(array);
                return result;
            }

            if (code == 'A') {
                while (out_len > 0u) {
                    unsigned char c = input[cursor + out_len - 1u];
                    if (c == 0u || c == ' ' || c == '\t' || c == '\r' || c == '\n') {
                        out_len--;
                    } else {
                        break;
                    }
                }
            } else if (code == 'Z') {
                out_len = 0u;
                while (out_len < count && input[cursor + out_len] != 0u) out_len++;
            }

            if (!unpack_add_string(
                    array, name, name_len, 0u, 1u, &numeric_index,
                    input + cursor, out_len)) {
                jinx_zend_array_release(array);
                return result;
            }
            cursor += count;
            token_start = token_end + 1u;
            continue;
        }

        if (code == 'h' || code == 'H') {
            static const char hex[] = "0123456789abcdef";
            size_t nibble_count = star ? (input_len - cursor) * 2u : repeat;
            size_t bytes_needed = (nibble_count + 1u) / 2u;
            unsigned char *text;

            if (bytes_needed > input_len - cursor) {
                jinx_zend_array_release(array);
                return result;
            }
            text = (unsigned char *)malloc(nibble_count == 0u ? 1u : nibble_count);
            if (text == NULL) {
                jinx_zend_array_release(array);
                return result;
            }

            for (size_t i = 0u; i < nibble_count; i++) {
                unsigned char byte = input[cursor + i / 2u];
                unsigned char nibble;
                if (code == 'H') {
                    nibble = (i & 1u) == 0u ? (byte >> 4u) : (byte & 0x0fu);
                } else {
                    nibble = (i & 1u) == 0u ? (byte & 0x0fu) : (byte >> 4u);
                }
                text[i] = (unsigned char)hex[nibble];
            }

            if (!unpack_add_string(
                    array, name, name_len, 0u, 1u, &numeric_index,
                    text, nibble_count)) {
                free(text);
                jinx_zend_array_release(array);
                return result;
            }
            free(text);
            cursor += bytes_needed;
            token_start = token_end + 1u;
            continue;
        }

        if (pack_code_is_numeric(code)) {
            size_t width = unpack_numeric_width(code);
            size_t count;
            if (width == 0u) {
                jinx_zend_array_release(array);
                return result;
            }

            count = star ? (input_len - cursor) / width : repeat;
            if (count > (input_len - cursor) / width) {
                jinx_zend_array_release(array);
                return result;
            }

            for (size_t i = 0u; i < count; i++) {
                JinxZendValue value;
                if (!unpack_read_numeric(code, input + cursor, &value) ||
                    !unpack_add_value(
                        array, name, name_len, i, count, &numeric_index, value)) {
                    jinx_zend_array_release(array);
                    return result;
                }
                cursor += width;
            }

            token_start = token_end + 1u;
            continue;
        }

        jinx_zend_array_release(array);
        return result;
    }

    result = jinx_oracle_zend_array_value_owned(array);
    if (ok != NULL) *ok = 1;
    return result;
}

JinxValue jinx_oracle_pack_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    int ok = 0;

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "pack") == 0) {
        if (args == NULL || argc < 1u) return result;
        result = jinx_pack_values(args[0], args + 1u, argc - 1u, &ok);
        if (ok && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "unpack") == 0) {
        JinxValue offset = jinx_oracle_int_value(0);
        if (args == NULL || argc < 2u || argc > 3u) return result;
        if (argc >= 3u) offset = args[2];
        result = jinx_unpack_values(args[0], args[1], offset, argc, &ok);
        if (ok && handled != NULL) *handled = 1;
        return result;
    }

    return result;
}
