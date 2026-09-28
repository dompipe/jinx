#include "jinx_oracle_ftp_builtins.h"
#include "jinx_oracle_resource_registry.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <netinet/in.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/select.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

typedef struct JinxOracleFtpConnection {
    int fd;
    FILE *io;
    int timeout_sec;
    int autoseek;
    int use_pasv_address;
    int passive_enabled;
    char passive_host[INET6_ADDRSTRLEN];
    int passive_port;
    int transfer_type;
    int logged_in;
    int closed;
    char last_response[4096];
    char *cached_pwd;
    char *cached_syst;
} JinxOracleFtpConnection;

static char *ftp_dup(JinxValue value) {
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

static JinxValue ftp_copy(const char *text, size_t len) {
    char *out;
    if (text == NULL || len > UINT32_MAX) return jinx_oracle_zero_value();
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u) memcpy(out, text, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

static int64_t ftp_constant(const char *name, int64_t fallback) {
    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        const JinxNativeConstantMeta *meta = &jinx_native_constant_metadata[i];
        if (meta->type == 1u && strcmp(meta->name, name) == 0) {
            return meta->i64;
        }
    }
    return fallback;
}

static int ftp_object_set_resource(
    JinxZendObject *object,
    const char *key,
    void *ptr
) {
    JinxZendValue value = jinx_zend_null();
    if (object == NULL || object->properties == NULL) return 0;
    value.type = JINX_ZEND_RESOURCE;
    value.value.ptr = ptr;
    return jinx_zend_array_add_assoc(
        object->properties, key, strlen(key), value
    );
}

static JinxOracleFtpConnection *ftp_from_value(JinxValue value) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "FTP\\Connection") != 0 ||
        object->properties == NULL) {
        return NULL;
    }
    slot = jinx_zend_array_find(object->properties, "__ftp", 5u);
    if (slot == NULL || slot->type != JINX_ZEND_RESOURCE) return NULL;
    return (JinxOracleFtpConnection *)slot->value.ptr;
}

static void ftp_clear_object_resource(JinxValue value) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    if (object == NULL || object->properties == NULL) return;
    slot = jinx_zend_array_find(object->properties, "__ftp", 5u);
    if (slot != NULL && slot->type == JINX_ZEND_RESOURCE) {
        slot->value.ptr = NULL;
    }
}

static int ftp_set_socket_timeouts(int fd, int timeout_sec) {
    struct timeval tv;
    if (timeout_sec <= 0) return 0;
    tv.tv_sec = timeout_sec;
    tv.tv_usec = 0;
    return setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv)) == 0 &&
        setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof(tv)) == 0;
}

static int ftp_connect_fd(
    const char *host,
    int port,
    int timeout_sec
) {
    struct addrinfo hints;
    struct addrinfo *list = NULL;
    struct addrinfo *it;
    char service[16];
    int fd = -1;

    if (host == NULL || port <= 0 || port > 65535 || timeout_sec <= 0) {
        return -1;
    }

    snprintf(service, sizeof(service), "%d", port);
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;

    if (getaddrinfo(host, service, &hints, &list) != 0) return -1;

    for (it = list; it != NULL; it = it->ai_next) {
        int flags;
        int rc;
        fd_set wfds;
        struct timeval tv;

        fd = socket(it->ai_family, it->ai_socktype, it->ai_protocol);
        if (fd < 0) continue;

        flags = fcntl(fd, F_GETFL, 0);
        if (flags < 0 || fcntl(fd, F_SETFL, flags | O_NONBLOCK) != 0) {
            close(fd);
            fd = -1;
            continue;
        }

        rc = connect(fd, it->ai_addr, it->ai_addrlen);
        if (rc != 0 && errno != EINPROGRESS) {
            close(fd);
            fd = -1;
            continue;
        }

        if (rc != 0) {
            int so_error = 0;
            socklen_t so_len = sizeof(so_error);
            FD_ZERO(&wfds);
            FD_SET(fd, &wfds);
            tv.tv_sec = timeout_sec;
            tv.tv_usec = 0;
            rc = select(fd + 1, NULL, &wfds, NULL, &tv);
            if (rc <= 0 ||
                getsockopt(fd, SOL_SOCKET, SO_ERROR, &so_error, &so_len) != 0 ||
                so_error != 0) {
                close(fd);
                fd = -1;
                continue;
            }
        }

        (void)fcntl(fd, F_SETFL, flags);
        if (!ftp_set_socket_timeouts(fd, timeout_sec)) {
            close(fd);
            fd = -1;
            continue;
        }
        break;
    }

    freeaddrinfo(list);
    return fd;
}

static int ftp_read_line(
    JinxOracleFtpConnection *ftp,
    char *buffer,
    size_t cap
) {
    size_t len;
    if (ftp == NULL || ftp->io == NULL || buffer == NULL || cap < 2u) return 0;
    if (fgets(buffer, (int)cap, ftp->io) == NULL) return 0;
    len = strlen(buffer);
    while (len != 0u &&
           (buffer[len - 1u] == '\n' || buffer[len - 1u] == '\r')) {
        buffer[--len] = '\0';
    }
    return 1;
}

static int ftp_append_line(JinxZendArray *array, const char *line) {
    JinxZendString *string;
    int ok;
    if (array == NULL || line == NULL) return 0;
    string = jinx_zend_string_new(line, strlen(line));
    if (string == NULL) return 0;
    ok = jinx_zend_array_append(array, jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}

static int ftp_read_response(
    JinxOracleFtpConnection *ftp,
    int *code_out,
    JinxZendArray *lines
) {
    char line[4096];
    int code = 0;
    int multiline = 0;

    if (!ftp_read_line(ftp, line, sizeof(line))) return 0;
    if (lines != NULL && !ftp_append_line(lines, line)) return 0;

    if (line[0] < '0' || line[0] > '9' ||
        line[1] < '0' || line[1] > '9' ||
        line[2] < '0' || line[2] > '9') {
        return 0;
    }

    code = (line[0] - '0') * 100 + (line[1] - '0') * 10 + (line[2] - '0');
    multiline = line[3] == '-';

    while (multiline) {
        if (!ftp_read_line(ftp, line, sizeof(line))) return 0;
        if (lines != NULL && !ftp_append_line(lines, line)) return 0;
        if (line[0] == (char)('0' + (code / 100) % 10) &&
            line[1] == (char)('0' + (code / 10) % 10) &&
            line[2] == (char)('0' + code % 10) &&
            line[3] == ' ') {
            break;
        }
    }

    snprintf(ftp->last_response, sizeof(ftp->last_response), "%s", line);
    if (code_out != NULL) *code_out = code;
    return 1;
}

static int ftp_send_raw(
    JinxOracleFtpConnection *ftp,
    const char *command
) {
    if (ftp == NULL || ftp->io == NULL || command == NULL || ftp->closed) return 0;
    return fprintf(ftp->io, "%s\r\n", command) >= 0 && fflush(ftp->io) == 0;
}

static int ftp_command(
    JinxOracleFtpConnection *ftp,
    const char *verb,
    const char *arg,
    int *code_out,
    JinxZendArray *lines
) {
    char *command;
    size_t verb_len;
    size_t arg_len = arg != NULL ? strlen(arg) : 0u;
    size_t total;
    int ok;

    if (ftp == NULL || verb == NULL) return 0;
    verb_len = strlen(verb);
    total = verb_len + (arg != NULL ? 1u + arg_len : 0u);
    command = (char *)malloc(total + 1u);
    if (command == NULL) return 0;

    memcpy(command, verb, verb_len);
    if (arg != NULL) {
        command[verb_len] = ' ';
        memcpy(command + verb_len + 1u, arg, arg_len);
    }
    command[total] = '\0';

    ok = ftp_send_raw(ftp, command) &&
        ftp_read_response(ftp, code_out, lines);
    free(command);
    return ok;
}

static const char *ftp_response_text(const char *line) {
    if (line == NULL) return "";
    if (strlen(line) >= 4u &&
        line[0] >= '0' && line[0] <= '9' &&
        line[1] >= '0' && line[1] <= '9' &&
        line[2] >= '0' && line[2] <= '9' &&
        (line[3] == ' ' || line[3] == '-')) {
        return line + 4u;
    }
    return line;
}

static char *ftp_extract_quoted(const char *line, const char *fallback) {
    const char *first;
    const char *last;
    size_t len;
    char *out;

    if (line == NULL) return fallback != NULL ? strdup(fallback) : NULL;
    first = strchr(line, '"');
    if (first == NULL) return fallback != NULL ? strdup(fallback) : NULL;
    first++;
    last = strrchr(first, '"');
    if (last == NULL || last < first) return NULL;

    len = (size_t)(last - first);
    out = (char *)malloc(len + 1u);
    if (out == NULL) return NULL;
    memcpy(out, first, len);
    out[len] = '\0';
    return out;
}

static JinxValue ftp_new_connection(
    int fd,
    int timeout_sec
) {
    JinxOracleFtpConnection *ftp;
    JinxZendObject *object;
    FILE *io;

    io = fdopen(fd, "r+");
    if (io == NULL) {
        close(fd);
        return jinx_oracle_zero_value();
    }
    setvbuf(io, NULL, _IONBF, 0);

    ftp = (JinxOracleFtpConnection *)calloc(1u, sizeof(*ftp));
    if (ftp == NULL) {
        fclose(io);
        return jinx_oracle_zero_value();
    }

    ftp->fd = fd;
    ftp->io = io;
    ftp->timeout_sec = timeout_sec;
    ftp->autoseek = 1;
    ftp->use_pasv_address = 1;
    ftp->passive_enabled = 0;
    ftp->passive_port = 0;
    ftp->transfer_type = 0;

    object = jinx_zend_object_new("FTP\\Connection");
    if (object == NULL || !ftp_object_set_resource(object, "__ftp", ftp)) {
        fclose(io);
        free(ftp);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_object_value_owned(object);
}

static void ftp_free_connection(
    JinxValue value,
    JinxOracleFtpConnection *ftp
) {
    if (ftp == NULL) return;
    if (ftp->io != NULL) fclose(ftp->io);
    else if (ftp->fd >= 0) close(ftp->fd);
    ftp->io = NULL;
    ftp->fd = -1;
    ftp->closed = 1;
    free(ftp->cached_pwd);
    free(ftp->cached_syst);
    free(ftp);
    ftp_clear_object_resource(value);
}

static int ftp_parse_pasv(
    JinxOracleFtpConnection *ftp,
    const char *line
) {
    unsigned a, b, c, d, p1, p2;
    const char *open;
    if (ftp == NULL || line == NULL) return 0;
    open = strchr(line, '(');
    if (open == NULL) return 0;
    if (sscanf(
        open + 1u, "%u,%u,%u,%u,%u,%u",
        &a, &b, &c, &d, &p1, &p2
    ) != 6) return 0;
    if (a > 255u || b > 255u || c > 255u || d > 255u ||
        p1 > 255u || p2 > 255u) return 0;

    snprintf(
        ftp->passive_host, sizeof(ftp->passive_host),
        "%u.%u.%u.%u", a, b, c, d
    );
    ftp->passive_port = (int)(p1 * 256u + p2);
    ftp->passive_enabled = 1;
    return 1;
}

static JinxValue ftp_bool_result(int value, int *handled) {
    if (handled != NULL) *handled = 1;
    return jinx_oracle_bool_value(value);
}

JinxValue jinx_oracle_ftp_builtin_with_context(
    JinxOracleAsmContext *ctx,
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    if (handled != NULL) *handled = 0;
    if (ctx == NULL || name == NULL) return result;

    if (strcmp(name, "ftp_alloc") == 0) {
        JinxOracleFtpConnection *ftp;
        int64_t size;
        char size_text[64];
        int code;
        int ok;
        if (args == NULL || argc < 2u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed || args[1].type != 1u) return result;
        size = args[1].as.i64;
        if (size <= 0) return result;
        snprintf(size_text, sizeof(size_text), "%lld", (long long)size);
        ok = ftp_command(ftp, "ALLO", size_text, &code, NULL);
        if (argc >= 3u) {
            JinxValue response = ftp_copy(
                ftp_response_text(ftp->last_response),
                strlen(ftp_response_text(ftp->last_response))
            );
            if (!jinx_oracle_write_ref_arg(ctx, 2u, response)) return result;
        }
        return ftp_bool_result(ok && code >= 200 && code < 300, handled);
    }

    return result;
}

JinxValue jinx_oracle_ftp_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "ftp_connect") == 0) {
        char *host;
        int port = 21;
        int timeout = 90;
        int fd;
        JinxValue connection;
        JinxOracleFtpConnection *ftp;
        int code;

        if (args == NULL || argc < 1u || argc > 3u || args[0].type != 3u) {
            return result;
        }
        host = ftp_dup(args[0]);
        if (host == NULL) return result;
        if (argc >= 2u && args[1].type != 0u) port = (int)jinx_oracle_intish(args[1]);
        if (argc >= 3u && args[2].type != 0u) timeout = (int)jinx_oracle_intish(args[2]);
        if (port <= 0 || port > 65535 || timeout <= 0) {
            free(host);
            return result;
        }

        fd = ftp_connect_fd(host, port, timeout);
        free(host);
        if (fd < 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        connection = ftp_new_connection(fd, timeout);
        if (connection.type == 0u) return result;
        ftp = ftp_from_value(connection);
        if (ftp == NULL || !ftp_read_response(ftp, &code, NULL) || code != 220) {
            if (ftp != NULL) ftp_free_connection(connection, ftp);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return connection;
    }

    if (strcmp(name, "ftp_ssl_connect") == 0) {
        /* Explicit FTPS needs a TLS control/data-channel carrier. */
        return result;
    }

    if (strcmp(name, "ftp_login") == 0) {
        JinxOracleFtpConnection *ftp;
        char *user;
        char *pass;
        int code;
        int ok;
        if (args == NULL || argc < 3u ||
            args[1].type != 3u || args[2].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        user = ftp_dup(args[1]);
        pass = ftp_dup(args[2]);
        if (user == NULL || pass == NULL) {
            free(user); free(pass); return result;
        }

        ok = ftp_command(ftp, "USER", user, &code, NULL);
        if (ok && code == 331) {
            ok = ftp_command(ftp, "PASS", pass, &code, NULL);
        }
        free(user); free(pass);
        if (ok && code == 230) ftp->logged_in = 1;
        return ftp_bool_result(ok && code == 230, handled);
    }

    if (strcmp(name, "ftp_pwd") == 0) {
        JinxOracleFtpConnection *ftp;
        int code;
        char *pwd;
        if (args == NULL || argc < 1u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        if (ftp->cached_pwd != NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_string_value(ftp->cached_pwd);
        }
        if (!ftp_command(ftp, "PWD", NULL, &code, NULL) || code != 257) {
            return ftp_bool_result(0, handled);
        }
        pwd = ftp_extract_quoted(ftp->last_response, NULL);
        if (pwd == NULL) return ftp_bool_result(0, handled);
        ftp->cached_pwd = pwd;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value(ftp->cached_pwd);
    }

    if (strcmp(name, "ftp_cdup") == 0 ||
        strcmp(name, "ftp_chdir") == 0) {
        JinxOracleFtpConnection *ftp;
        char *dir = NULL;
        int code;
        int ok;
        if (args == NULL || argc < 1u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        if (strcmp(name, "ftp_chdir") == 0) {
            if (argc < 2u || args[1].type != 3u) return result;
            dir = ftp_dup(args[1]);
            if (dir == NULL) return result;
        }
        ok = ftp_command(
            ftp,
            strcmp(name, "ftp_cdup") == 0 ? "CDUP" : "CWD",
            dir,
            &code,
            NULL
        );
        free(dir);
        if (ok && code == 250) {
            free(ftp->cached_pwd);
            ftp->cached_pwd = NULL;
        }
        return ftp_bool_result(ok && code == 250, handled);
    }

    if (strcmp(name, "ftp_exec") == 0 ||
        strcmp(name, "ftp_site") == 0) {
        JinxOracleFtpConnection *ftp;
        char *command;
        int code;
        int ok;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        command = ftp_dup(args[1]);
        if (command == NULL) return result;
        ok = ftp_command(
            ftp,
            strcmp(name, "ftp_exec") == 0 ? "SITE EXEC" : "SITE",
            command,
            &code,
            NULL
        );
        free(command);
        return ftp_bool_result(ok && code == 200, handled);
    }

    if (strcmp(name, "ftp_raw") == 0) {
        JinxOracleFtpConnection *ftp;
        char *command;
        JinxZendArray *lines;
        int code;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        command = ftp_dup(args[1]);
        if (command == NULL) return result;
        lines = jinx_zend_array_new_packed(4u);
        if (lines == NULL) {
            free(command);
            return result;
        }
        if (!ftp_send_raw(ftp, command) ||
            !ftp_read_response(ftp, &code, lines)) {
            free(command);
            jinx_zend_array_release(lines);
            return result;
        }
        free(command);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(lines);
    }

    if (strcmp(name, "ftp_mkdir") == 0) {
        JinxOracleFtpConnection *ftp;
        char *directory;
        char *created;
        int code;
        int ok;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        directory = ftp_dup(args[1]);
        if (directory == NULL) return result;
        ok = ftp_command(ftp, "MKD", directory, &code, NULL);
        if (!ok || code != 257) {
            free(directory);
            return ftp_bool_result(0, handled);
        }
        created = ftp_extract_quoted(ftp->last_response, directory);
        free(directory);
        if (created == NULL) return ftp_bool_result(0, handled);
        result = ftp_copy(created, strlen(created));
        free(created);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "ftp_rmdir") == 0 ||
        strcmp(name, "ftp_delete") == 0) {
        JinxOracleFtpConnection *ftp;
        char *path;
        int code;
        int ok;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        path = ftp_dup(args[1]);
        if (path == NULL) return result;
        ok = ftp_command(
            ftp,
            strcmp(name, "ftp_rmdir") == 0 ? "RMD" : "DELE",
            path,
            &code,
            NULL
        );
        free(path);
        return ftp_bool_result(ok && code == 250, handled);
    }

    if (strcmp(name, "ftp_chmod") == 0) {
        JinxOracleFtpConnection *ftp;
        char *filename;
        char *argument;
        int code;
        int ok;
        int mode;
        size_t len;
        if (args == NULL || argc < 3u ||
            args[1].type != 1u || args[2].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        mode = (int)args[1].as.i64;
        filename = ftp_dup(args[2]);
        if (filename == NULL) return result;
        len = strlen(filename) + 32u;
        argument = (char *)malloc(len);
        if (argument == NULL) {
            free(filename);
            return result;
        }
        snprintf(argument, len, "CHMOD %o %s", mode, filename);
        free(filename);
        ok = ftp_command(ftp, "SITE", argument, &code, NULL);
        free(argument);
        if (!ok || code != 200) return ftp_bool_result(0, handled);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(mode);
    }

    if (strcmp(name, "ftp_systype") == 0) {
        JinxOracleFtpConnection *ftp;
        int code;
        const char *text;
        const char *end;
        size_t len;
        char *copy;
        if (args == NULL || argc < 1u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        if (ftp->cached_syst != NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_string_value(ftp->cached_syst);
        }
        if (!ftp_command(ftp, "SYST", NULL, &code, NULL) || code != 215) {
            return ftp_bool_result(0, handled);
        }
        text = ftp_response_text(ftp->last_response);
        while (*text == ' ') text++;
        end = strchr(text, ' ');
        len = end != NULL ? (size_t)(end - text) : strlen(text);
        copy = (char *)malloc(len + 1u);
        if (copy == NULL) return result;
        memcpy(copy, text, len);
        copy[len] = '\0';
        ftp->cached_syst = copy;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value(ftp->cached_syst);
    }

    if (strcmp(name, "ftp_pasv") == 0) {
        JinxOracleFtpConnection *ftp;
        int enable;
        int code;
        int ok;
        if (args == NULL || argc < 2u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        enable = jinx_oracle_boolish(args[1]);
        if (!enable) {
            ftp->passive_enabled = 0;
            ftp->passive_port = 0;
            ftp->passive_host[0] = '\0';
            return ftp_bool_result(1, handled);
        }
        ok = ftp_command(ftp, "PASV", NULL, &code, NULL);
        if (!ok || code != 227 ||
            !ftp_parse_pasv(ftp, ftp->last_response)) {
            return ftp_bool_result(0, handled);
        }
        return ftp_bool_result(1, handled);
    }

    if (strcmp(name, "ftp_size") == 0 ||
        strcmp(name, "ftp_mdtm") == 0) {
        JinxOracleFtpConnection *ftp;
        char *path;
        int code;
        int ok;
        const char *text;
        char *end = NULL;
        long long value = -1;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        path = ftp_dup(args[1]);
        if (path == NULL) return result;
        ok = ftp_command(
            ftp,
            strcmp(name, "ftp_size") == 0 ? "SIZE" : "MDTM",
            path,
            &code,
            NULL
        );
        free(path);
        if (ok && code == 213) {
            text = ftp_response_text(ftp->last_response);
            if (strcmp(name, "ftp_size") == 0) {
                errno = 0;
                value = strtoll(text, &end, 10);
                if (errno != 0 || end == text) value = -1;
            } else {
                struct tm tmv;
                memset(&tmv, 0, sizeof(tmv));
                if (strlen(text) >= 14u &&
                    sscanf(
                        text, "%4d%2d%2d%2d%2d%2d",
                        &tmv.tm_year,
                        &tmv.tm_mon,
                        &tmv.tm_mday,
                        &tmv.tm_hour,
                        &tmv.tm_min,
                        &tmv.tm_sec
                    ) == 6) {
                    tmv.tm_year -= 1900;
                    tmv.tm_mon -= 1;
                    tmv.tm_isdst = 0;
                    value = (long long)timegm(&tmv);
                }
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(value);
    }

    if (strcmp(name, "ftp_rename") == 0) {
        JinxOracleFtpConnection *ftp;
        char *from;
        char *to;
        int code;
        int ok;
        if (args == NULL || argc < 3u ||
            args[1].type != 3u || args[2].type != 3u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        from = ftp_dup(args[1]);
        to = ftp_dup(args[2]);
        if (from == NULL || to == NULL) {
            free(from); free(to); return result;
        }
        ok = ftp_command(ftp, "RNFR", from, &code, NULL);
        if (ok && code == 350) {
            ok = ftp_command(ftp, "RNTO", to, &code, NULL);
        }
        free(from); free(to);
        return ftp_bool_result(ok && code == 250, handled);
    }

    if (strcmp(name, "ftp_get_option") == 0 ||
        strcmp(name, "ftp_set_option") == 0) {
        JinxOracleFtpConnection *ftp;
        int64_t option;
        int64_t timeout_opt = ftp_constant("FTP_TIMEOUT_SEC", 0);
        int64_t autoseek_opt = ftp_constant("FTP_AUTOSEEK", 1);
        int64_t pasvaddr_opt = ftp_constant("FTP_USEPASVADDRESS", 2);
        if (args == NULL || argc < 2u || args[1].type != 1u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL || ftp->closed) return result;
        option = args[1].as.i64;

        if (strcmp(name, "ftp_get_option") == 0) {
            if (handled != NULL) *handled = 1;
            if (option == timeout_opt) return jinx_oracle_int_value(ftp->timeout_sec);
            if (option == autoseek_opt) return jinx_oracle_bool_value(ftp->autoseek);
            if (option == pasvaddr_opt) return jinx_oracle_bool_value(ftp->use_pasv_address);
            return result;
        }

        if (argc < 3u) return result;
        if (option == timeout_opt) {
            int timeout;
            if (args[2].type != 1u) return result;
            timeout = (int)args[2].as.i64;
            if (timeout <= 0) return result;
            ftp->timeout_sec = timeout;
            if (!ftp_set_socket_timeouts(ftp->fd, timeout)) return ftp_bool_result(0, handled);
        } else if (option == autoseek_opt) {
            if (args[2].type != 2u) return result;
            ftp->autoseek = args[2].as.i64 != 0;
        } else if (option == pasvaddr_opt) {
            if (args[2].type != 2u) return result;
            ftp->use_pasv_address = args[2].as.i64 != 0;
        } else {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    if (strcmp(name, "ftp_close") == 0 ||
        strcmp(name, "ftp_quit") == 0) {
        JinxOracleFtpConnection *ftp;
        int code = 0;
        int ok = 1;
        if (args == NULL || argc < 1u) return result;
        ftp = ftp_from_value(args[0]);
        if (ftp == NULL) return result;
        if (!ftp->closed && ftp->io != NULL) {
            ok = ftp_command(ftp, "QUIT", NULL, &code, NULL) && code == 221;
        }
        ftp_free_connection(args[0], ftp);
        return ftp_bool_result(ok, handled);
    }

    return result;
}
