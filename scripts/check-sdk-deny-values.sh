#!/usr/bin/env bash
# Fails when the consent deny-list of the web SDK drifted from the module's copy.
# Usage: scripts/check-sdk-deny-values.sh <path to web-sdk/velitrack-sdk.js>
# The module copies (src/Consent/ConsentGate.php, views/js/axitrace-storefront.js) are pinned to
# tests/fixtures/sdk_deny_values.txt by the PHP and JS unit tests.
set -euo pipefail

sdk="${1:?usage: $0 <path to velitrack-sdk.js>}"
fixture="$(cd "$(dirname "$0")/.." && pwd)/tests/fixtures/sdk_deny_values.txt"

actual="$(grep -o 'const CONSENT_DENY_VALUES = \[[^]]*\]' "$sdk" | sed 's/^const CONSENT_DENY_VALUES = //')"
expected="$(cat "$fixture")"

if [ "$actual" != "$expected" ]; then
    echo "SDK CONSENT_DENY_VALUES drifted from tests/fixtures/sdk_deny_values.txt" >&2
    echo "  sdk:     $actual" >&2
    echo "  fixture: $expected" >&2
    exit 1
fi
echo "sdk deny values: OK"
