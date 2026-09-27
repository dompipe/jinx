#include <ctype.h>

#define main jinx_native_core_main
#include "jinx_cli.c"
#undef main

static char *jinx_native_read_file(const char *path) {
    FILE *file = fopen(path, "rb");
    long size;
    size_t read_size;
    char *buffer;

    if (file == NULL) {
        return NULL;
    }

    if (fseek(file, 0, SEEK_END) != 0) {
        fclose(file);
        return NULL;
    }

    size = ftell(file);
    if (size < 0) {
        fclose(file);
        return NULL;
    }

    if (fseek(file, 0, SEEK_SET) != 0) {
        fclose(file);
        return NULL;
    }

    buffer = (char *) malloc((size_t) size + 1u);
    if (buffer == NULL) {
        fclose(file);
        return NULL;
    }

    read_size = fread(buffer, 1u, (size_t) size, file);
    fclose(file);

    if (read_size != (size_t) size) {
        free(buffer);
        return NULL;
    }

    buffer[read_size] = '\0';
    return buffer;
}

static int jinx_native_line_of(const char *source, const char *at) {
    int line = 1;

    for (const char *p = source; p != NULL && p < at && *p != '\0'; p++) {
        if (*p == '\n') {
            line++;
        }
    }

    return line;
}

static void jinx_native_parse_identifier(const char *start, char *out, size_t out_size) {
    size_t len = 0u;

    if (out_size == 0u) {
        return;
    }

    while (*start != '\0' && (isalnum((unsigned char) *start) || *start == '_')) {
        if (len + 1u < out_size) {
            out[len++] = *start;
        }
        start++;
    }

    out[len] = '\0';
}

static int jinx_native_find_body_local(const char *source, char *out, size_t out_size, int *line) {
    const char *json = strstr(source, "json_decode");
    const char *scan;
    const char *dollar = NULL;

    if (json == NULL) {
        return 0;
    }

    for (scan = json; scan >= source; scan--) {
        if (*scan == '$') {
            dollar = scan;
            break;
        }
        if (*scan == '\n' || *scan == ';') {
            break;
        }
    }

    if (dollar == NULL) {
        return 0;
    }

    jinx_native_parse_identifier(dollar + 1, out, out_size);
    if (out[0] == '\0') {
        return 0;
    }

    *line = jinx_native_line_of(source, dollar);
    return 1;
}

static int jinx_native_find_missing_key(const char *source, char *out, size_t out_size, int *line) {
    const char *isset_pos = strstr(source, "!isset");
    const char *bracket;
    char quote;
    const char *p;
    size_t len = 0u;

    if (isset_pos == NULL) {
        return 0;
    }

    bracket = strchr(isset_pos, '[');
    if (bracket == NULL || (bracket[1] != '\'' && bracket[1] != '"')) {
        return 0;
    }

    quote = bracket[1];
    p = bracket + 2;
    while (*p != '\0' && *p != quote) {
        if (len + 1u < out_size) {
            out[len++] = *p;
        }
        p++;
    }
    out[len] = '\0';

    if (out[0] == '\0') {
        return 0;
    }

    *line = jinx_native_line_of(source, isset_pos);
    return 1;
}

static int jinx_native_find_http_status(const char *source, int *status, int *line) {
    const char *status_pos = strstr(source, "http_response_code");
    const char *open;

    if (status_pos == NULL) {
        return 0;
    }

    open = strchr(status_pos, '(');
    if (open == NULL) {
        return 0;
    }

    *status = atoi(open + 1);
    *line = jinx_native_line_of(source, status_pos);
    return *status > 0;
}

static int jinx_native_find_error_message(const char *source, char *out, size_t out_size, int *line) {
    const char *error_pos = strstr(source, "'error'");
    const char *arrow;
    const char *quote;
    char quote_ch;
    size_t len = 0u;

    if (error_pos == NULL) {
        error_pos = strstr(source, "\"error\"");
    }
    if (error_pos == NULL) {
        return 0;
    }

    arrow = strstr(error_pos, "=>");
    if (arrow == NULL) {
        return 0;
    }

    quote = arrow + 2;
    while (*quote != '\0' && isspace((unsigned char) *quote)) {
        quote++;
    }
    if (*quote != '\'' && *quote != '"') {
        return 0;
    }

    quote_ch = *quote++;
    while (*quote != '\0' && *quote != quote_ch) {
        if (len + 1u < out_size) {
            out[len++] = *quote;
        }
        quote++;
    }
    out[len] = '\0';

    *line = jinx_native_line_of(source, error_pos);
    return out[0] != '\0';
}

static int jinx_native_find_array_get_local(const char *source, const char *array_local, const char *key, char *out, size_t out_size, int *line) {
    char pattern[256];
    const char *array_get;
    const char *scan;
    const char *dollar = NULL;

    snprintf(pattern, sizeof(pattern), "$%s['%s']", array_local, key);
    array_get = strstr(source, pattern);
    if (array_get == NULL) {
        snprintf(pattern, sizeof(pattern), "$%s[\"%s\"]", array_local, key);
        array_get = strstr(source, pattern);
    }
    if (array_get == NULL) {
        return 0;
    }

    for (scan = array_get; scan >= source; scan--) {
        if (*scan == '$') {
            dollar = scan;
            break;
        }
        if (*scan == '\n' || *scan == ';') {
            break;
        }
    }

    if (dollar == NULL || dollar == array_get) {
        return 0;
    }

    jinx_native_parse_identifier(dollar + 1, out, out_size);
    if (out[0] == '\0') {
        return 0;
    }

    *line = jinx_native_line_of(source, dollar);
    return 1;
}

static void jinx_native_print_json_string(const char *text) {
    fputc('"', stdout);
    for (const unsigned char *p = (const unsigned char *) text; *p != '\0'; p++) {
        switch (*p) {
            case '"': fputs("\\\"", stdout); break;
            case '\\': fputs("\\\\", stdout); break;
            case '\n': fputs("\\n", stdout); break;
            case '\r': fputs("\\r", stdout); break;
            case '\t': fputs("\\t", stdout); break;
            default:
                if (*p < 32u) {
                    printf("\\u%04x", (unsigned) *p);
                } else {
                    fputc((int) *p, stdout);
                }
                break;
        }
    }
    fputc('"', stdout);
}

static int command_web_plan_native(int argc, char **argv) {
    const char *path;
    char *source;
    char body_local[64] = {0};
    char missing_key[128] = {0};
    char value_local[64] = {0};
    char error_message[256] = {0};
    int body_line = 0;
    int if_line = 0;
    int status_line = 0;
    int error_line = 0;
    int get_line = 0;
    int status = 0;

    if (argc < 3) {
        return fail("web-plan requires an input PHP file");
    }

    path = argv[2];
    source = jinx_native_read_file(path);
    if (source == NULL) {
        fprintf(stderr, "Missing source file: %s\n", path);
        return 1;
    }

    if (!jinx_native_find_body_local(source, body_local, sizeof(body_local), &body_line) ||
        !jinx_native_find_missing_key(source, missing_key, sizeof(missing_key), &if_line) ||
        !jinx_native_find_http_status(source, &status, &status_line) ||
        !jinx_native_find_error_message(source, error_message, sizeof(error_message), &error_line) ||
        !jinx_native_find_array_get_local(source, body_local, missing_key, value_local, sizeof(value_local), &get_line) ||
        strstr(source, "file_get_contents('php://input')") == NULL ||
        strstr(source, "json_encode") == NULL) {
        free(source);
        fprintf(stderr, "Unsupported Web API source shape: %s\n", path);
        return 1;
    }

    printf("{\n");
    printf("    \"kind\": \"JINX_WEB_EXECUTABLE_PLAN\",\n");
    printf("    \"ops\": [\n");
    printf("        {\n");
    printf("            \"op\": \"WEB_READ_BODY_JSON\",\n");
    printf("            \"dst\": \"LOCAL:%s\",\n", body_local);
    printf("            \"line\": %d,\n", body_line);
    printf("            \"source\": \"$%s = json_decode(file_get_contents('php://input'), true)\"\n", body_local);
    printf("        },\n");
    printf("        {\n");
    printf("            \"op\": \"WEB_IF_MISSING_ARRAY_KEY\",\n");
    printf("            \"array\": \"LOCAL:%s\",\n", body_local);
    printf("            \"key\": ");
    jinx_native_print_json_string(missing_key);
    printf(",\n");
    printf("            \"then\": [\n");
    printf("                {\n");
    printf("                    \"op\": \"WEB_STATUS_CODE\",\n");
    printf("                    \"code\": %d,\n", status);
    printf("                    \"line\": %d,\n", status_line);
    printf("                    \"source\": \"http_response_code(%d)\"\n", status);
    printf("                },\n");
    printf("                {\n");
    printf("                    \"op\": \"WEB_ECHO_JSON_ARRAY\",\n");
    printf("                    \"items\": [\n");
    printf("                        {\n");
    printf("                            \"key\": \"ok\",\n");
    printf("                            \"kind\": \"bool\",\n");
    printf("                            \"value\": false\n");
    printf("                        },\n");
    printf("                        {\n");
    printf("                            \"key\": \"error\",\n");
    printf("                            \"kind\": \"string\",\n");
    printf("                            \"value\": ");
    jinx_native_print_json_string(error_message);
    printf("\n");
    printf("                        }\n");
    printf("                    ],\n");
    printf("                    \"line\": %d,\n", error_line);
    printf("                    \"source\": \"echo json_encode(['ok' => false, 'error' => '");
    jinx_native_print_json_string(error_message);
    printf("'])\"\n");
    printf("                },\n");
    printf("                {\n");
    printf("                    \"op\": \"WEB_RETURN\",\n");
    printf("                    \"line\": %d,\n", error_line + 1);
    printf("                    \"source\": \"return\"\n");
    printf("                }\n");
    printf("            ],\n");
    printf("            \"line\": %d,\n", if_line);
    printf("            \"source\": \"if (!isset($%s['%s'])) {\"\n", body_local, missing_key);
    printf("        },\n");
    printf("        {\n");
    printf("            \"op\": \"WEB_ARRAY_GET\",\n");
    printf("            \"dst\": \"LOCAL:%s\",\n", value_local);
    printf("            \"array\": \"LOCAL:%s\",\n", body_local);
    printf("            \"key\": ");
    jinx_native_print_json_string(missing_key);
    printf(",\n");
    printf("            \"line\": %d,\n", get_line);
    printf("            \"source\": \"$%s = $%s['%s']\"\n", value_local, body_local, missing_key);
    printf("        },\n");
    printf("        {\n");
    printf("            \"op\": \"WEB_ECHO_JSON_ARRAY\",\n");
    printf("            \"items\": [\n");
    printf("                {\n");
    printf("                    \"key\": \"ok\",\n");
    printf("                    \"kind\": \"bool\",\n");
    printf("                    \"value\": true\n");
    printf("                },\n");
    printf("                {\n");
    printf("                    \"key\": ");
    jinx_native_print_json_string(missing_key);
    printf(",\n");
    printf("                    \"kind\": \"local\",\n");
    printf("                    \"local\": \"LOCAL:%s\"\n", value_local);
    printf("                }\n");
    printf("            ],\n");
    printf("            \"line\": %d,\n", get_line + 1);
    printf("            \"source\": \"echo json_encode(['ok' => true, '%s' => $%s])\"\n", missing_key, value_local);
    printf("        }\n");
    printf("    ]\n");
    printf("}\n");

    free(source);
    return 0;
}

int main(int argc, char **argv) {
    if (argc >= 2 && strcmp(argv[1], "web-plan") == 0) {
        return command_web_plan_native(argc, argv);
    }

    return jinx_native_core_main(argc, argv);
}
