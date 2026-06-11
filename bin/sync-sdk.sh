#!/usr/bin/env bash
#
# Re-copies the shared PHP SDK (packages/php-sdk/src) into this module's
# bundled fallback location (lib/php-sdk/). Run after any SDK change.
# BundledSdkSyncTest fails the unit suite if the copies drift.
#
# Usage: ./bin/sync-sdk.sh

set -euo pipefail

cd "$(dirname "$0")/.."
MODULE_ROOT="$(pwd)"
SDK_SRC="${MODULE_ROOT}/../../packages/php-sdk/src"

if [ ! -d "${SDK_SRC}" ]; then
  echo "error: ${SDK_SRC} not found — run from inside the monorepo." >&2
  exit 1
fi

rm -rf "${MODULE_ROOT}/lib/php-sdk"
mkdir -p "${MODULE_ROOT}/lib/php-sdk"
cp "${SDK_SRC}"/*.php "${MODULE_ROOT}/lib/php-sdk/"

echo "✓ Synced $(ls "${MODULE_ROOT}/lib/php-sdk" | wc -l | tr -d ' ') SDK files into lib/php-sdk/"
