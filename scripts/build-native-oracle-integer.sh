#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mkdir -p "$root/build/native"
cc -std=c11 -O2 -Wall -Wextra -Werror -DJINX_ORACLE_INTEGER_STANDALONE "$root/native/jinx_oracle_integer_vm.c" -o "$root/build/native/jinx-oracle-int"
