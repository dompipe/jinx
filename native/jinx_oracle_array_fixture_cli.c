#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "../runtime/jinx_builtin_dispatch.h"
#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_zend_array_delete.h"

#define JINX_FIXTURE_ARGC 8u

typedef struct FixtureValue {
    JinxValue value;
    JinxZendArray *owned_array;
} FixtureValue;

static void usage(const char *argv0) {
    printf("JINX Oracle array fixture CLI\n\n");
    printf("Usage:\n");
    printf("  %s oracle-call <function> [typed-args...]\n", argv0);
    printf("\nTyped args:\n");
    printf("  za:sample       native Zend array [10, 20, name => 30, keep => 40]\n");
    printf("  za:deleted      same array with index 1 and key name tombstoned\n");
    printf("  i:<int>         integer value\n");
    printf("  s:<text>        string value\n");
    printf("  null            null value\n");
}

static JinxZendArray *make_fixture_array(int deleted) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    if (array == 0) {
        return 0;
    }

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40))) {
        jinx_zend_array_release(array);
        return 0;
    }

    if (deleted) {
        jinx_zend_array_delete_index(array, 1u);
        jinx_zend_array_delete_string(array, "name", 4);
    }

    return array;
}

static FixtureValue parse_fixture_value(const char *text) {
    FixtureValue fixture;
    fixture.value = jinx_value_null();
    fixture.owned_array = 0;

    if (text == 0 || strcmp(text, "null") == 0) {
        return fixture;
    }

    if (strcmp(text, "za:sample") == 0 || strcmp(text, "za:deleted") == 0) {
        fixture.owned_array = make_fixture_array(strcmp(text, "za:deleted") == 0);
        fixture.value = jinx_oracle_zend_array_value_borrowed(fixture.owned_array);
        return fixture;
    }

    if (strncmp(text, "i:", 2) == 0) {
        fixture.value = jinx_value_int(atoll(text + 2));
        return fixture;
    }

    if (strncmp(text, "s:", 2) == 0) {
        fixture.value = jinx_value_string(text + 2, (uint32_t)strlen(text + 2));
        return fixture;
    }

    fixture.value = jinx_value_string(text, (uint32_t)strlen(text));
    return fixture;
}

static void release_fixture_value(FixtureValue fixture) {
    if (fixture.owned_array != 0) {
        jinx_zend_array_release(fixture.owned_array);
    }
    jinx_oracle_zend_array_value_release(fixture.value);
}

static void print_value(JinxValue value) {
    JinxZendArray *array;

    switch (value.type) {
        case 1u:
            printf("int:%lld\n", (long long)value.as.i64);
            return;
        case 2u:
            printf("bool:%s\n", value.as.i64 ? "true" : "false");
            return;
        case 3u:
            printf("string:%.*s\n", (int)value.flags, (const char *)value.as.ptr);
            return;
        case 4u:
            printf("array-count:%u\n", value.flags);
            return;
        case 5u:
            printf("float:%g\n", value.as.f64);
            return;
        default:
            break;
    }

    array = jinx_oracle_zend_array_ptr(value);
    if (array != 0) {
        printf("zend-array:%zu\n", jinx_zend_array_live_count(array));
        return;
    }

    printf("null\n");
}

static int command_oracle_call(int argc, char **argv) {
    const char *name;
    FixtureValue fixtures[JINX_FIXTURE_ARGC];
    JinxValue args[JINX_FIXTURE_ARGC];
    JinxValue result;
    int supplied_argc;

    for (size_t i = 0; i < JINX_FIXTURE_ARGC; i++) {
        fixtures[i].value = jinx_value_null();
        fixtures[i].owned_array = 0;
        args[i] = jinx_value_null();
    }

    if (argc < 3) {
        fprintf(stderr, "FAIL: oracle-call requires a function name\n");
        return 1;
    }

    name = argv[2];
    supplied_argc = argc - 3;
    if ((size_t)supplied_argc > JINX_FIXTURE_ARGC) {
        fprintf(stderr, "FAIL: too many arguments, max %u\n", JINX_FIXTURE_ARGC);
        return 1;
    }

    if (jinx_lookup_oracle_wrapper(name) == 0) {
        fprintf(stderr, "missing: %s\n", name);
        return 1;
    }

    for (int i = 0; i < supplied_argc; i++) {
        fixtures[i] = parse_fixture_value(argv[i + 3]);
        args[i] = fixtures[i].value;
        if ((strncmp(argv[i + 3], "za:", 3) == 0) && fixtures[i].owned_array == 0) {
            fprintf(stderr, "FAIL: could not create Zend array fixture\n");
            for (int n = 0; n <= i; n++) {
                release_fixture_value(fixtures[n]);
            }
            return 1;
        }
    }

    result = jinx_call_builtin_through_oracle(name, args, JINX_FIXTURE_ARGC);
    if (result.type == 0u) {
        fprintf(stderr, "null/fault: %s\n", name);
        for (int i = 0; i < supplied_argc; i++) {
            release_fixture_value(fixtures[i]);
        }
        return 1;
    }

    print_value(result);
    jinx_oracle_zend_array_value_release(result);
    for (int i = 0; i < supplied_argc; i++) {
        release_fixture_value(fixtures[i]);
    }
    return 0;
}

int main(int argc, char **argv) {
    if (argc >= 2 && strcmp(argv[1], "oracle-call") == 0) {
        return command_oracle_call(argc, argv);
    }

    usage(argv[0]);
    return 1;
}
