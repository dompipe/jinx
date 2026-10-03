#ifndef JINX_ORACLE_NATIVE_SCRIPT_H
#define JINX_ORACLE_NATIVE_SCRIPT_H

/* Executes PHP source inside JINX. Never starts a frontend process. */
int jinx_oracle_native_script(const char *path);

#endif
