#include <stdio.h>

#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_zend_array_delete.h"

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxValue borrowed;
    JinxValue retained;
    JinxZendArray *from_borrowed;
    JinxZendArray *from_retained;
    JinxZendArray *values;
    JinxZendValue *slot;

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate Zend array\n");
        return 1;
    }

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30))) {
        fprintf(stderr, "FAIL: could not seed Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    borrowed = jinx_oracle_zend_array_value_borrowed(array);
    if (!jinx_oracle_value_is_zend_array(borrowed)) {
        fprintf(stderr, "FAIL: borrowed JinxValue did not carry Zend array tag\n");
        jinx_zend_array_release(array);
        return 1;
    }

    from_borrowed = jinx_oracle_zend_array_ptr(borrowed);
    if (from_borrowed != array || jinx_zend_array_live_count(from_borrowed) != 3u) {
        fprintf(stderr, "FAIL: borrowed JinxValue did not unwrap original Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!jinx_zend_array_delete_index(from_borrowed, 1u) ||
        jinx_zend_array_live_count(from_borrowed) != 2u ||
        jinx_zend_array_live_key_exists_index(from_borrowed, 1u) ||
        !jinx_zend_array_live_key_exists_string(from_borrowed, "name", 4)) {
        fprintf(stderr, "FAIL: live-aware helpers failed through borrowed JinxValue carrier\n");
        jinx_zend_array_release(array);
        return 1;
    }

    values = jinx_zend_array_live_values(from_borrowed);
    if (values == 0 || jinx_zend_array_live_count(values) != 2u) {
        fprintf(stderr, "FAIL: live values failed through borrowed JinxValue carrier\n");
        jinx_zend_array_release(values);
        jinx_zend_array_release(array);
        return 1;
    }

    slot = jinx_zend_array_index(values, 1u);
    if (slot == 0 || slot->type != JINX_ZEND_LONG || slot->value.lval != 30) {
        fprintf(stderr, "FAIL: live values did not preserve carried Zend array insertion order\n");
        jinx_zend_array_release(values);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_zend_array_release(values);

    retained = jinx_oracle_zend_array_value_retained(array);
    if (!jinx_oracle_value_is_zend_array(retained) || array->refcount != 2u) {
        fprintf(stderr, "FAIL: retained JinxValue did not retain Zend array\n");
        jinx_oracle_zend_array_value_release(retained);
        jinx_zend_array_release(array);
        return 1;
    }

    from_retained = jinx_oracle_zend_array_ptr(retained);
    if (from_retained != array || jinx_zend_array_live_count(from_retained) != 2u) {
        fprintf(stderr, "FAIL: retained JinxValue did not unwrap retained Zend array\n");
        jinx_oracle_zend_array_value_release(retained);
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_oracle_zend_array_value_release(retained);
    if (array->refcount != 1u) {
        fprintf(stderr, "FAIL: retained JinxValue release did not drop Zend array refcount\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_array_release(array);
    printf("PASS: Oracle JinxValue Zend-array carrier smoke passed\n");
    printf("PASS: borrowed/retained carriers unwrap JinxZendArray and route live-aware array helpers\n");
    return 0;
}
