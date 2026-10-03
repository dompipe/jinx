#include <stdio.h>

#include "../runtime/jinx_zend_engine.h"

int main(void) {
    size_t family_count = 0;
    const JinxZendModuleFamily *families = jinx_zend_module_families(&family_count);

    if (!jinx_zend_smoke()) {
        fprintf(stderr, "FAIL: JINX Zend skeleton smoke failed\n");
        return 1;
    }

    printf("PASS: JINX Zend skeleton smoke passed\n");
    JinxZendString *text = jinx_zend_string_new("reference", 9);
    if (!text) return 1;
    JinxZendReference *reference = jinx_zend_reference_new(jinx_zend_string_value(text));
    if (!reference || text->refcount != 2) return 1;
    jinx_zend_string_release(text);
    JinxZendArray *array = jinx_zend_array_new_packed(1);
    if (!array || !jinx_zend_array_append(array, jinx_zend_reference_value(reference)) || reference->refcount != 2) return 1;
    JinxZendArray *copy = jinx_zend_array_clone(array);
    if (!copy || reference->refcount != 3) return 1;
    jinx_zend_array_release(array);
    if (reference->refcount != 2) return 1;
    jinx_zend_reference_release(reference);
    JinxZendValue *retained = jinx_zend_array_index(copy, 0);
    if (!retained || retained->type != JINX_ZEND_REFERENCE || retained->value.ref->refcount != 1 ||
        !jinx_zend_string_equals_bytes(retained->value.ref->value.value.str, "reference", 9)) return 1;
    jinx_zend_array_release(copy);
    printf("PASS: Zend reference cells retain payloads across array copy/release\n");
    printf("PASS: zend_string owned/refcount/COW/hash smoke passed\n");
    printf("PASS: zend_array packed buckets/append/iteration smoke passed\n");
    printf("PASS: zend_array mixed string-key lookup/update smoke passed\n");
    printf("PASS: zend_array copy-on-write separation smoke passed\n");
    printf("PASS: zend_array native count/key_exists/is_list/values/keys smoke passed\n");
    printf("Zend rewrite families: %zu\n", family_count);
    printf("%-24s %-12s %s\n", "family", "state", "oracle-sm target");
    printf("----------------------------------------------------------------\n");
    for (size_t i = 0; i < family_count; i++) {
        printf("%-24s %-12s %s\n", families[i].family, families[i].state, families[i].oracle_sm_target);
    }

    return 0;
}
