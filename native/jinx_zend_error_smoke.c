#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_error.h"

static int expect(int condition, const char *message) {
    if (!condition) {
        fprintf(stderr, "FAIL: %s\n", message);
        return 0;
    }
    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendErrorState state;
    JinxZendClassEntry exception_ce = jinx_zend_throwable_class_entry("Exception");
    JinxZendObject *throwable;
    JinxZendThrowable described;

    jinx_zend_executor_init(&executor);
    jinx_zend_error_state_init(&state);

    jinx_zend_error_state_raise(
        &state,
        &executor,
        JINX_ZEND_E_WARNING,
        "sample warning",
        "fixture.php",
        12u
    );

    if (!expect(jinx_zend_error_state_has_error(&state), "warning state should be set")) return 1;
    if (!expect(state.level == JINX_ZEND_E_WARNING, "warning level should be stored")) return 1;
    if (!expect(strcmp(jinx_zend_error_level_name(state.level), "warning") == 0, "warning name should resolve")) return 1;
    if (!expect(executor.last_error != 0 && strcmp(executor.last_error, "sample warning") == 0, "executor warning should be set")) return 1;
    if (!expect(executor.error_level == (uint32_t)JINX_ZEND_E_WARNING, "executor warning level should be set")) return 1;

    jinx_zend_executor_clear_error(&executor);
    if (!expect(executor.last_error == 0 && executor.error_level == 0u, "executor error should clear")) return 1;

    throwable = jinx_zend_throwable_new(&exception_ce, "boom", 99, "test.php", 44u);
    if (!expect(throwable != 0, "throwable object should allocate")) return 1;
    if (!expect(throwable->class_name != 0 && strcmp(throwable->class_name, "Exception") == 0, "throwable class should be Exception")) return 1;

    described = jinx_zend_throwable_describe(throwable);
    if (!expect(described.object == throwable, "describe should point at throwable")) return 1;
    if (!expect(described.message != 0 && strcmp(described.message, "boom") == 0, "throwable message should describe")) return 1;
    if (!expect(described.code == 99, "throwable code should describe")) return 1;
    if (!expect(described.file != 0 && strcmp(described.file, "test.php") == 0, "throwable file should describe")) return 1;
    if (!expect(described.line == 44u, "throwable line should describe")) return 1;

    jinx_zend_error_state_throw(&state, &executor, throwable, 0, 0u);
    if (!expect(state.level == JINX_ZEND_E_EXCEPTION, "throw should set exception level")) return 1;
    if (!expect(state.throwable == throwable, "throw should store throwable")) return 1;
    if (!expect(jinx_zend_error_state_has_throwable(&state), "throwable state should be true")) return 1;
    if (!expect(state.message != 0 && strcmp(state.message, "boom") == 0, "throw should use throwable message")) return 1;
    if (!expect(state.file != 0 && strcmp(state.file, "test.php") == 0, "throw should use throwable file")) return 1;
    if (!expect(state.line == 44u, "throw should use throwable line")) return 1;
    if (!expect(executor.last_error != 0 && strcmp(executor.last_error, "boom") == 0, "executor should carry exception message")) return 1;
    if (!expect(executor.error_level == (uint32_t)JINX_ZEND_E_EXCEPTION, "executor exception level should be set")) return 1;

    jinx_zend_object_release(throwable);

    printf("PASS: Zend error/throwable smoke passed\n");
    printf("PASS: warning state, throwable object properties, throw propagation, and executor error clearing work\n");
    return 0;
}
