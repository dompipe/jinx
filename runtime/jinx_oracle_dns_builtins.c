#include "jinx_oracle_dns_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <arpa/inet.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_RESOLV
#include <arpa/nameser.h>
#include <resolv.h>

typedef struct JinxOracleDnsType {
    const char *constant_name;
    int rr_type;
    const char *label;
} JinxOracleDnsType;

static const JinxOracleDnsType dns_types[] = {
    { "DNS_A", 1, "A" },
    { "DNS_NS", 2, "NS" },
    { "DNS_CNAME", 5, "CNAME" },
    { "DNS_SOA", 6, "SOA" },
    { "DNS_PTR", 12, "PTR" },
    { "DNS_HINFO", 13, "HINFO" },
    { "DNS_MX", 15, "MX" },
    { "DNS_TXT", 16, "TXT" },
    { "DNS_AAAA", 28, "AAAA" },
    { "DNS_SRV", 33, "SRV" },
    { "DNS_NAPTR", 35, "NAPTR" },
    { "DNS_CAA", 257, "CAA" },
};

static char *dns_dup(JinxValue value) {
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

static int64_t dns_constant(const char *name, int64_t fallback) {
    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        const JinxNativeConstantMeta *meta = &jinx_native_constant_metadata[i];
        if (meta->type == 1u && strcmp(meta->name, name) == 0) {
            return meta->i64;
        }
    }
    return fallback;
}

static int dns_assoc_string(
    JinxZendArray *array,
    const char *key,
    const char *value
) {
    JinxZendString *string;
    int ok;
    if (array == NULL || key == NULL || value == NULL) return 0;
    string = jinx_zend_string_new(value, strlen(value));
    if (string == NULL) return 0;
    ok = jinx_zend_array_add_assoc(
        array, key, strlen(key), jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static int dns_assoc_bytes(
    JinxZendArray *array,
    const char *key,
    const unsigned char *bytes,
    size_t len
) {
    JinxZendString *string;
    int ok;
    if (array == NULL || key == NULL) return 0;
    string = jinx_zend_string_new(
        bytes != NULL ? (const char *)bytes : "",
        len
    );
    if (string == NULL) return 0;
    ok = jinx_zend_array_add_assoc(
        array, key, strlen(key), jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static int dns_assoc_long(
    JinxZendArray *array,
    const char *key,
    int64_t value
) {
    return jinx_zend_array_add_assoc(
        array, key, strlen(key), jinx_zend_long(value)
    );
}

static int dns_expand_name(
    const unsigned char *packet,
    size_t packet_len,
    const unsigned char *rdata,
    char *out,
    size_t out_len,
    int *consumed
) {
    int n = dn_expand(
        packet,
        packet + packet_len,
        rdata,
        out,
        (int)out_len
    );
    if (n < 0) return 0;
    if (consumed != NULL) *consumed = n;
    return 1;
}

static int dns_add_base(
    JinxZendArray *record,
    const ns_rr *rr,
    const char *label
) {
    return dns_assoc_string(record, "host", ns_rr_name(*rr)) &&
        dns_assoc_string(record, "class", "IN") &&
        dns_assoc_long(record, "ttl", (int64_t)ns_rr_ttl(*rr)) &&
        dns_assoc_string(record, "type", label);
}

static int dns_decode_txt(
    JinxZendArray *record,
    const unsigned char *rdata,
    size_t rdlen
) {
    JinxZendArray *entries = jinx_zend_array_new_packed(4u);
    unsigned char *joined;
    size_t pos = 0u;
    size_t out_pos = 0u;
    int ok = 1;

    if (entries == NULL) return 0;
    joined = (unsigned char *)malloc(rdlen + 1u);
    if (joined == NULL) {
        jinx_zend_array_release(entries);
        return 0;
    }

    while (pos < rdlen) {
        size_t n = rdata[pos++];
        JinxZendString *piece;
        if (pos + n > rdlen) {
            ok = 0;
            break;
        }
        piece = jinx_zend_string_new((const char *)(rdata + pos), n);
        if (piece == NULL ||
            !jinx_zend_array_append(entries, jinx_zend_string_value(piece))) {
            jinx_zend_string_release(piece);
            ok = 0;
            break;
        }
        jinx_zend_string_release(piece);
        memcpy(joined + out_pos, rdata + pos, n);
        out_pos += n;
        pos += n;
    }

    if (ok) {
        ok = dns_assoc_bytes(record, "txt", joined, out_pos) &&
            jinx_zend_array_add_assoc(
                record,
                "entries",
                7u,
                jinx_zend_array_value(entries)
            );
    }

    free(joined);
    jinx_zend_array_release(entries);
    return ok;
}

static int dns_decode_char_string(
    const unsigned char **cursor,
    const unsigned char *end,
    char *out,
    size_t out_len
) {
    size_t len;
    if (*cursor >= end) return 0;
    len = *(*cursor)++;
    if ((size_t)(end - *cursor) < len || len + 1u > out_len) return 0;
    memcpy(out, *cursor, len);
    out[len] = '\0';
    *cursor += len;
    return 1;
}

static JinxZendArray *dns_record_from_rr(
    const unsigned char *packet,
    size_t packet_len,
    const ns_rr *rr,
    int raw
) {
    JinxZendArray *record = jinx_zend_array_new_packed(10u);
    const unsigned char *rdata = ns_rr_rdata(*rr);
    size_t rdlen = ns_rr_rdlen(*rr);
    int rr_type = ns_rr_type(*rr);
    const char *label = NULL;

    if (record == NULL) return NULL;

    for (size_t i = 0u; i < sizeof(dns_types) / sizeof(dns_types[0]); i++) {
        if (dns_types[i].rr_type == rr_type) {
            label = dns_types[i].label;
            break;
        }
    }
    if (label == NULL) label = "UNKNOWN";

    if (raw) {
        if (!dns_assoc_string(record, "host", ns_rr_name(*rr)) ||
            !dns_assoc_string(record, "class", "IN") ||
            !dns_assoc_long(record, "ttl", (int64_t)ns_rr_ttl(*rr)) ||
            !dns_assoc_long(record, "type", rr_type) ||
            !dns_assoc_bytes(record, "data", rdata, rdlen)) {
            jinx_zend_array_release(record);
            return NULL;
        }
        return record;
    }

    if (!dns_add_base(record, rr, label)) {
        jinx_zend_array_release(record);
        return NULL;
    }

    if (rr_type == 1 && rdlen == 4u) {
        char ip[INET_ADDRSTRLEN];
        if (inet_ntop(AF_INET, rdata, ip, sizeof(ip)) == NULL ||
            !dns_assoc_string(record, "ip", ip)) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 28 && rdlen == 16u) {
        char ip[INET6_ADDRSTRLEN];
        if (inet_ntop(AF_INET6, rdata, ip, sizeof(ip)) == NULL ||
            !dns_assoc_string(record, "ipv6", ip)) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 15) {
        char target[NS_MAXDNAME];
        if (rdlen < 3u ||
            !dns_expand_name(
                packet, packet_len, rdata + 2u,
                target, sizeof(target), NULL
            ) ||
            !dns_assoc_long(record, "pri", ns_get16(rdata)) ||
            !dns_assoc_string(record, "target", target)) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 2 || rr_type == 5 || rr_type == 12) {
        char target[NS_MAXDNAME];
        if (!dns_expand_name(
                packet, packet_len, rdata,
                target, sizeof(target), NULL
            ) ||
            !dns_assoc_string(record, "target", target)) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 16) {
        if (!dns_decode_txt(record, rdata, rdlen)) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 6) {
        const unsigned char *cursor = rdata;
        const unsigned char *end = rdata + rdlen;
        char mname[NS_MAXDNAME];
        char rname[NS_MAXDNAME];
        int used = 0;
        if (!dns_expand_name(
                packet, packet_len, cursor,
                mname, sizeof(mname), &used
            )) {
            jinx_zend_array_release(record);
            return NULL;
        }
        cursor += used;
        if (cursor >= end ||
            !dns_expand_name(
                packet, packet_len, cursor,
                rname, sizeof(rname), &used
            )) {
            jinx_zend_array_release(record);
            return NULL;
        }
        cursor += used;
        if ((size_t)(end - cursor) < 20u ||
            !dns_assoc_string(record, "mname", mname) ||
            !dns_assoc_string(record, "rname", rname) ||
            !dns_assoc_long(record, "serial", ns_get32(cursor)) ||
            !dns_assoc_long(record, "refresh", ns_get32(cursor + 4u)) ||
            !dns_assoc_long(record, "retry", ns_get32(cursor + 8u)) ||
            !dns_assoc_long(record, "expire", ns_get32(cursor + 12u)) ||
            !dns_assoc_long(record, "minimum-ttl", ns_get32(cursor + 16u))) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 33) {
        char target[NS_MAXDNAME];
        if (rdlen < 7u ||
            !dns_expand_name(
                packet, packet_len, rdata + 6u,
                target, sizeof(target), NULL
            ) ||
            !dns_assoc_long(record, "pri", ns_get16(rdata)) ||
            !dns_assoc_long(record, "weight", ns_get16(rdata + 2u)) ||
            !dns_assoc_long(record, "port", ns_get16(rdata + 4u)) ||
            !dns_assoc_string(record, "target", target)) {
            jinx_zend_array_release(record);
            return NULL;
        }
    } else if (rr_type == 257) {
        char tag[256];
        const unsigned char *cursor = rdata;
        const unsigned char *end = rdata + rdlen;
        if (rdlen < 2u) {
            jinx_zend_array_release(record);
            return NULL;
        }
        {
            uint8_t flags = *cursor++;
            size_t tag_len = *cursor++;
            if ((size_t)(end - cursor) < tag_len || tag_len >= sizeof(tag)) {
                jinx_zend_array_release(record);
                return NULL;
            }
            memcpy(tag, cursor, tag_len);
            tag[tag_len] = '\0';
            cursor += tag_len;
            if (!dns_assoc_long(record, "flags", flags) ||
                !dns_assoc_string(record, "tag", tag) ||
                !dns_assoc_bytes(
                    record, "value", cursor, (size_t)(end - cursor)
                )) {
                jinx_zend_array_release(record);
                return NULL;
            }
        }
    } else if (rr_type == 35) {
        const unsigned char *cursor = rdata;
        const unsigned char *end = rdata + rdlen;
        char flags[256];
        char services[256];
        char regex[256];
        char replacement[NS_MAXDNAME];
        if (rdlen < 5u) {
            jinx_zend_array_release(record);
            return NULL;
        }
        {
            uint16_t order = ns_get16(cursor);
            uint16_t pref = ns_get16(cursor + 2u);
            cursor += 4u;
            if (!dns_decode_char_string(&cursor, end, flags, sizeof(flags)) ||
                !dns_decode_char_string(
                    &cursor, end, services, sizeof(services)
                ) ||
                !dns_decode_char_string(&cursor, end, regex, sizeof(regex)) ||
                cursor >= end ||
                !dns_expand_name(
                    packet, packet_len, cursor,
                    replacement, sizeof(replacement), NULL
                ) ||
                !dns_assoc_long(record, "order", order) ||
                !dns_assoc_long(record, "pref", pref) ||
                !dns_assoc_string(record, "flags", flags) ||
                !dns_assoc_string(record, "services", services) ||
                !dns_assoc_string(record, "regex", regex) ||
                !dns_assoc_string(record, "replacement", replacement)) {
                jinx_zend_array_release(record);
                return NULL;
            }
        }
    }

    return record;
}

static int dns_query_type(
    const char *host,
    int rr_type,
    int raw,
    JinxZendArray *out
) {
    unsigned char answer[65536];
    int answer_len = res_query(
        host, ns_c_in, rr_type, answer, sizeof(answer)
    );
    ns_msg message;
    int count;

    if (answer_len < 0) return 1;
    if (ns_initparse(answer, answer_len, &message) != 0) return 0;
    count = ns_msg_count(message, ns_s_an);

    for (int i = 0; i < count; i++) {
        ns_rr rr;
        JinxZendArray *record;
        if (ns_parserr(&message, ns_s_an, i, &rr) != 0) return 0;
        if (rr_type != ns_t_any && ns_rr_type(rr) != rr_type) continue;
        record = dns_record_from_rr(
            answer, (size_t)answer_len, &rr, raw
        );
        if (record == NULL) return 0;
        if (!jinx_zend_array_append(out, jinx_zend_array_value(record))) {
            jinx_zend_array_release(record);
            return 0;
        }
        jinx_zend_array_release(record);
    }
    return 1;
}

JinxValue jinx_oracle_dns_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();

    if (handled != NULL) *handled = 0;
    if (name == NULL || strcmp(name, "dns_get_record") != 0) {
        return result;
    }

    if (args == NULL || argc < 1u || argc > 5u ||
        args[0].type != 3u) {
        return result;
    }

    /*
     * Authoritative/additional outputs are by-reference. Until the native
     * call bridge carries by-ref slots, fail closed when callers request them.
     */
    if ((argc >= 3u && args[2].type != 0u) ||
        (argc >= 4u && args[3].type != 0u)) {
        return result;
    }

    {
        char *host = dns_dup(args[0]);
        int raw = argc >= 5u && jinx_oracle_boolish(args[4]);
        int64_t requested;
        JinxZendArray *out;
        int ok = 1;

        if (host == NULL) return result;
        requested = argc >= 2u && args[1].type != 0u
            ? jinx_oracle_intish(args[1])
            : dns_constant("DNS_ANY", 268435456LL);

        out = jinx_zend_array_new_packed(8u);
        if (out == NULL) {
            free(host);
            return result;
        }

        if (raw) {
            if (requested < 1 || requested > 65535) {
                jinx_zend_array_release(out);
                free(host);
                return result;
            }
            ok = dns_query_type(host, (int)requested, 1, out);
        } else if (requested == dns_constant("DNS_ANY", 268435456LL)) {
            ok = dns_query_type(host, ns_t_any, 0, out);
        } else {
            int matched = 0;
            for (size_t i = 0u; i < sizeof(dns_types) / sizeof(dns_types[0]); i++) {
                int64_t bit = dns_constant(dns_types[i].constant_name, 0);
                if (bit != 0 && (requested & bit) != 0) {
                    matched = 1;
                    if (!dns_query_type(
                        host, dns_types[i].rr_type, 0, out
                    )) {
                        ok = 0;
                        break;
                    }
                }
            }
            if (!matched) ok = 0;
        }

        free(host);
        if (!ok) {
            jinx_zend_array_release(out);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(out);
    }
}

#else

JinxValue jinx_oracle_dns_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    (void)name;
    (void)args;
    (void)argc;
    if (handled != NULL) *handled = 0;
    return jinx_oracle_zero_value();
}

#endif
