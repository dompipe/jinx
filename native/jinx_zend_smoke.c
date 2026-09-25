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
    printf("PASS: zend_string owned/refcount/COW/hash smoke passed\n");
    printf("PASS: zend_array packed buckets/append/iteration smoke passed\n");
    printf("PASS: zend_array mixed string-key lookup/update smoke passed\n");
    printf("Zend rewrite families: %zu\n", family_count);
    printf("%-24s %-12s %s\n", "family", "state", "oracle-sm target");
    printf("----------------------------------------------------------------\n");
    for (size_t i = 0; i < family_count; i++) {
        printf("%-24s %-12s %s\n", families[i].family, families[i].state, families[i].oracle_sm_target);
    }

    return 0;
}
