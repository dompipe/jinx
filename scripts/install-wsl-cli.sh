#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BIN_DIR="${HOME}/.local/bin"
TARGET="${BIN_DIR}/jinx"

"$ROOT_DIR/scripts/build-native-jinx.sh"
mkdir -p "$BIN_DIR"
cp "$ROOT_DIR/jinx" "$TARGET"
chmod +x "$TARGET" "$ROOT_DIR/jinx" 2>/dev/null || true

case ":${PATH}:" in
    *":${BIN_DIR}:"*) ;;
    *)
        echo "Installed jinx at ${TARGET}"
        echo "Add this to your shell profile if needed:"
        echo "export PATH=\"\$HOME/.local/bin:\$PATH\""
        exit 0
        ;;
esac

echo "Installed jinx at ${TARGET}"
