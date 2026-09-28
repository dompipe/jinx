#define main jinx_web_plan_core_main
#include "jinx_cli_with_web_plan.c"
#undef main

static int command_bench_first100_tolerant(int argc, char **argv) {
    long iterations = 100000;
    int strict = 0;
    size_t calls = first100_count();
    size_t concrete = 0;
    size_t placeholders = 0;
    size_t reported = 0;

    if (argc >= 3) {
        iterations = atol(argv[2]);
    }
    if (argc >= 4 && strcmp(argv[3], "--strict") == 0) {
        strict = 1;
    }
    if (iterations <= 0) {
        iterations = 1;
    }

    for (size_t n = 0; n < calls; n++) {
        JinxValue result;
        if (call_first100_name(first100_names[n], &result)) {
            concrete++;
        } else {
            if (reported < 20u) {
                fprintf(stderr, "bench-first100 placeholder: %s\n", first100_names[n]);
                reported++;
            }
            placeholders++;
        }
    }

    if (strict && placeholders != 0u) {
        fprintf(stderr, "FAIL: %zu/%zu first100 functions returned null/fault placeholders under --strict\n", placeholders, calls);
        return 1;
    }

    clock_t start = clock();

    for (long i = 0; i < iterations; i++) {
        for (size_t n = 0; n < calls; n++) {
            JinxValue result;
            (void) call_first100_name(first100_names[n], &result);
        }
    }

    clock_t elapsed = clock() - start;
    double seconds = (double) elapsed / (double) CLOCKS_PER_SEC;
    double total_dispatches = (double) iterations * (double) calls;

    printf("JINX native first 100 Oracle wrapper benchmark\n");
    printf("Functions: %zu\n", calls);
    printf("Concrete non-null first-pass returns: %zu/%zu\n", concrete, calls);
    printf("Null/fault placeholder first-pass returns: %zu/%zu\n", placeholders, calls);
    printf("Iterations per function: %ld\n", iterations);
    printf("Total dispatch attempts: %.0f\n", total_dispatches);
    printf("Elapsed ms: %.3f\n", seconds * 1000.0);
    printf("Per dispatch ns: %.1f\n", seconds * 1000000000.0 / total_dispatches);
    if (placeholders != 0u) {
        printf("Note: placeholder dispatches are included for throughput only; use --strict to fail on them.\n");
    }
    return 0;
}

int main(int argc, char **argv) {
    if (argc >= 2 && strcmp(argv[1], "bench-first100") == 0) {
        return command_bench_first100_tolerant(argc, argv);
    }

    return jinx_web_plan_core_main(argc, argv);
}
