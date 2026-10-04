#define _POSIX_C_SOURCE 200809L
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <errno.h>
#include <time.h>
#include <inttypes.h>

enum { CONSTANT, ADD, SUB, MUL, RETURN_CONSTANT, RETURN_ADD, RETURN_SUB, RETURN_MUL };
typedef struct { int op; unsigned dst, left, right; int64_t value; } Instruction;
typedef struct {
    Instruction code[4096];
    unsigned slots, inputs, count;
    unsigned input_slots[256];
    char names[256][256];
} Program;

static int integer(const char *text, int64_t *value) {
    char *end;
    errno = 0;
    *value = strtoll(text, &end, 10);
    return *text && !*end && errno != ERANGE;
}

static int load(FILE *file, Program *program) {
    char magic[32], name[32], number[64];
    if (fscanf(file, "%31s", magic) != 1 || strcmp(magic, "JXOR_INT_1") ||
        fscanf(file, "%u %u %u", &program->slots, &program->inputs, &program->count) != 3 ||
        program->slots > 256 || program->inputs > program->slots || !program->count || program->count > 4096) return 0;
    unsigned char initialized[256] = {0};
    for (unsigned i = 0; i < program->inputs; i++) {
        if (fscanf(file, "%31s %u %255s", name, &program->input_slots[i], program->names[i]) != 3 ||
            strcmp(name, "INPUT") || program->input_slots[i] >= program->slots || initialized[program->input_slots[i]]) return 0;
        for (unsigned j = 0; j < i; j++) if (!strcmp(program->names[i], program->names[j])) return 0;
        initialized[program->input_slots[i]] = 1;
    }
    for (unsigned i = 0; i < program->count; i++) {
        Instruction *op = &program->code[i];
        if (fscanf(file, "%31s", name) != 1) return 0;
        if (!strcmp(name, "CONST")) {
            op->op = CONSTANT;
            if (fscanf(file, "%u %63s", &op->dst, number) != 2 || op->dst >= program->slots || !integer(number, &op->value)) return 0;
            initialized[op->dst] = 1;
        } else if (!strcmp(name, "RETURN_CONST")) {
            op->op = RETURN_CONSTANT;
            if (fscanf(file, "%63s", number) != 1 || !integer(number, &op->value)) return 0;
        } else {
            int returning = !strncmp(name, "RETURN_", 7);
            const char *operation = returning ? name + 7 : name;
            int opcode = !strcmp(operation, "ADD") ? ADD : !strcmp(operation, "SUB") ? SUB : !strcmp(operation, "MUL") ? MUL : -1;
            if (opcode < 0) return 0;
            op->op = returning ? opcode + 4 : opcode;
            if (!returning && (fscanf(file, "%u", &op->dst) != 1 || op->dst >= program->slots)) return 0;
            if (fscanf(file, "%u %u", &op->left, &op->right) != 2 || op->left >= program->slots ||
                op->right >= program->slots || !initialized[op->left] || !initialized[op->right]) return 0;
            if (!returning) initialized[op->dst] = 1;
        }
        if ((op->op >= RETURN_CONSTANT) != (i + 1 == program->count)) return 0;
    }
    return fscanf(file, "%31s", name) == EOF;
}

static int execute(const Program *program, const int64_t *inputs, int64_t *result) {
    int64_t locals[256];
    for (unsigned i = 0; i < program->inputs; i++) locals[program->input_slots[i]] = inputs[i];
    for (unsigned i = 0; i < program->count; i++) {
        const Instruction *op = &program->code[i];
        if (op->op == CONSTANT) { locals[op->dst] = op->value; continue; }
        if (op->op == RETURN_CONSTANT) { *result = op->value; return 1; }
        int operation = op->op >= RETURN_CONSTANT ? op->op - 4 : op->op;
        int64_t value;
        int overflow = operation == ADD ? __builtin_add_overflow(locals[op->left], locals[op->right], &value) :
            operation == SUB ? __builtin_sub_overflow(locals[op->left], locals[op->right], &value) :
            __builtin_mul_overflow(locals[op->left], locals[op->right], &value);
        if (overflow) return 0; /* PHP promotes overflow to float; this integer backend fails closed. */
        if (op->op >= RETURN_CONSTANT) { *result = value; return 1; }
        locals[op->dst] = value;
    }
    return 0;
}

int main(int argc, char **argv) {
    if (argc < 2) { fprintf(stderr, "Usage: jinx-oracle-int program.jxo [--iterations=N] [name=value ...]\n"); return 1; }
    Program program = {0};
    FILE *file = fopen(argv[1], "r");
    if (!file) { perror("Oracle artifact"); return 1; }
    int loaded = load(file, &program);
    fclose(file);
    if (!loaded) { fprintf(stderr, "Invalid or unsupported Oracle artifact\n"); return 1; }
    int64_t inputs[256] = {0}, iterations = 1;
    unsigned char supplied[256] = {0};
    const char *vary_name = NULL;
    for (int a = 2; a < argc; a++) {
        if (!strncmp(argv[a], "--vary=", 7)) { vary_name = argv[a] + 7; continue; }
        if (!strncmp(argv[a], "--iterations=", 13)) {
            if (!integer(argv[a] + 13, &iterations) || iterations < 1) return 1;
            continue;
        }
        const char *equals = strchr(argv[a], '=');
        if (!equals) return 1;
        int found = 0;
        for (unsigned i = 0; i < program.inputs; i++) {
            if (strlen(program.names[i]) == (size_t)(equals - argv[a]) && !strncmp(program.names[i], argv[a], (size_t)(equals - argv[a]))) {
                if (supplied[i] || !integer(equals + 1, &inputs[i])) return 1;
                supplied[i] = 1;
                found = 1;
                break;
            }
        }
        if (!found) { fprintf(stderr, "Unknown runtime input\n"); return 1; }
    }
    for (unsigned i = 0; i < program.inputs; i++) if (!supplied[i]) {
        fprintf(stderr, "Missing runtime input: %s\n", program.names[i]); return 1;
    }
    int vary = -1;
    if (vary_name) {
        for (unsigned i = 0; i < program.inputs; i++) if (!strcmp(vary_name, program.names[i])) vary = (int)i;
        if (vary < 0) { fprintf(stderr, "Unknown varying input\n"); return 1; }
    }
    int64_t base = vary >= 0 ? inputs[vary] : 0;
    struct timespec start, end;
    int64_t result = 0;
    volatile uint64_t checksum = 0;
    clock_gettime(CLOCK_MONOTONIC, &start);
    for (int64_t i = 0; i < iterations; i++) {
        if (vary >= 0 && __builtin_add_overflow(base, i % 1024, &inputs[vary])) return 1;
        if (!execute(&program, inputs, &result)) { fprintf(stderr, "Integer Oracle overflow; float promotion is unsupported\n"); return 1; }
        checksum += (uint64_t)result;
    }
    clock_gettime(CLOCK_MONOTONIC, &end);
    double elapsed = (end.tv_sec - start.tv_sec) * 1e9 + end.tv_nsec - start.tv_nsec;
    printf("{\"return\":%" PRId64 ",\"iterations\":%" PRId64 ",\"ns_per_execution\":%.3f,\"checksum\":%" PRIu64 "}\n",
        result, iterations, elapsed / iterations, checksum);
    return 0;
}
