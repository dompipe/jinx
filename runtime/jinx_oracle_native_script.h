#ifndef JINX_ORACLE_NATIVE_SCRIPT_H
#define JINX_ORACLE_NATIVE_SCRIPT_H

/* Executes PHP source inside JINX. Never starts a frontend process. */
int jinx_oracle_native_script(const char *path);
/* Returns 2 when source syntax is unsupported, before any target execution. */
int jinx_oracle_native_script_try(const char *path);

#endif
