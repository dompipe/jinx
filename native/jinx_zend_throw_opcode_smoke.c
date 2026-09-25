#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_throw_opcode.h"

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendErrorState state;
    JinxZendCatchFrame catch_frame;
    JinxZendClassEntry exception_ce = jinx_zend_throwable_class_entry("Exception");
    JinxZendClassEntry runtime_ce = jinx_zend_throwable_class_entry("RuntimeException");
    JinxZendObject *exception;
    JinxZendObject *runtime_exception;
    JinxZendThrowOpcodeResult result;

    jinx_zend_executor_init(&executor);
    jinx_zend_error_state_init(&state);

    exception = jinx_zend_throwable_new(&exception_ce, "boom", 77, "throw.php", 12u);
    if (exception == 0) {
        return fail("could not allocate Exception throwable");
    }

    result = jinx_zend_throw_value(
        &state,
        &executor,
        jinx_zend_object_value(exception),
        "throw-site.php",
        44u
    );
    if (result != JINX_ZEND_THROW_OK) {
        return fail("THROW did not accept object throwable");
    }
    if (!jinx_zend_error_state_has_throwable(&state) || state.throwable != exception) {
        return fail("THROW did not store throwable in error state");
    }
    if (state.level != JINX_ZEND_E_EXCEPTION || strcmp(state.message, "boom") != 0) {
        return fail("THROW did not set exception message/level");
    }
    if (state.file == 0 || strcmp(state.file, "throw-site.php") != 0 || state.line != 44u) {
        return fail("THROW did not set throw site file/line");
    }
    if (executor.error_level != JINX_ZEND_E_EXCEPTION || executor.executed_ops != 1u) {
        return fail("THROW did not update executor exception state/op count");
    }

    jinx_zend_catch_frame_init(&catch_frame, "RuntimeException");
    result = jinx_zend_catch_exception(&state, &catch_frame);
    if (result != JINX_ZEND_CATCH_MISS || catch_frame.matched || catch_frame.caught != 0) {
        return fail("CATCH should miss non-matching throwable class");
    }

    jinx_zend_catch_frame_init(&catch_frame, "Exception");
    result = jinx_zend_catch_exception(&state, &catch_frame);
    if (result != JINX_ZEND_CATCH_MATCH || !catch_frame.matched || catch_frame.caught != exception) {
        return fail("CATCH should match throwable class");
    }

    jinx_zend_clear_exception(&state, &executor);
    if (jinx_zend_error_state_has_error(&state) || jinx_zend_error_state_has_throwable(&state)) {
        return fail("CLEAR_EXCEPTION did not clear error state");
    }
    if (executor.last_error != 0 || executor.error_level != 0u) {
        return fail("CLEAR_EXCEPTION did not clear executor error");
    }

    jinx_zend_catch_frame_init(&catch_frame, "Exception");
    result = jinx_zend_catch_exception(&state, &catch_frame);
    if (result != JINX_ZEND_CATCH_EMPTY) {
        return fail("CATCH without active throwable should be empty");
    }

    result = jinx_zend_throw_value(&state, &executor, jinx_zend_long(9), "bad.php", 3u);
    if (result != JINX_ZEND_THROW_TYPE_ERROR) {
        return fail("THROW should reject non-object value");
    }
    if (state.level != JINX_ZEND_E_ERROR || strcmp(state.message, "throw expects object") != 0) {
        return fail("bad THROW did not set type error state");
    }
    if (jinx_zend_error_state_has_throwable(&state)) {
        return fail("bad THROW should not store throwable object");
    }

    jinx_zend_clear_exception(&state, &executor);

    runtime_exception = jinx_zend_throwable_new(&runtime_ce, "runtime", 99, "runtime.php", 5u);
    if (runtime_exception == 0) {
        return fail("could not allocate RuntimeException throwable");
    }

    result = jinx_zend_throw_value(&state, &executor, jinx_zend_object_value(runtime_exception), 0, 0u);
    if (result != JINX_ZEND_THROW_OK) {
        return fail("THROW did not accept RuntimeException object");
    }
    jinx_zend_catch_frame_init(&catch_frame, 0);
    result = jinx_zend_catch_exception(&state, &catch_frame);
    if (result != JINX_ZEND_CATCH_MATCH || catch_frame.caught != runtime_exception) {
        return fail("catch-all should catch active throwable");
    }

    jinx_zend_object_release(runtime_exception);
    jinx_zend_object_release(exception);

    printf("PASS: Zend throw/catch opcode smoke passed\n");
    printf("PASS: THROW/CATCH/CLEAR_EXCEPTION manage throwable state and bad throws become errors\n");
    return 0;
}
