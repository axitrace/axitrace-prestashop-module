#!/usr/bin/env bash
# Static checks for the rules the PrestaShop module validator (and the AxiTrace text rules) enforce.
#
#   bash scripts/lint-validator.sh [module-dir]
#
# Exit 0 when the tree is clean, 1 with one line per violation otherwise. Scope: the files that ship
# in the ZIP (vendor/, tests/, build/ and dotfiles are skipped, like scripts/build-zip.sh does).
#
#  1. every PHP file starts with the `if (!defined('_PS_VERSION_')) { exit; }` guard
#     (directory-listing index.php files are skipped: they only redirect and exit)
#  2. every directory has an index.php
#  3. no curl_exec / file_get_contents('http...') outside src/Adapter/HttpTransport*
#  4. SQL table names always go through the shop prefix (_DB_PREFIX_ / DbGateway::prefix())
#  5. the cron front controller checks its token with hash_equals()
#  6. no em/en dash (character or HTML entity) in views/, translations/, README.md
#  7. no Polish letters in views/, src/, translations/ (English-only)
#  8. no `axitrace-config"` element id without the -prestashop infix in views/ and src/

set -euo pipefail

# Byte-wise matching: the dash and letter patterns below are raw UTF-8 byte sequences.
export LC_ALL=C

MODULE_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$MODULE_DIR"

violations=0

report() {
    # report <rule> <location> <message>
    printf 'rule %s: %s: %s\n' "$1" "$2" "$3"
    violations=$((violations + 1))
}

# Shipped files only. -print0 keeps paths with spaces intact.
shipped_files() {
    find . \
        \( -path ./vendor -o -path ./tests -o -path ./build -o -path ./node_modules -o -name '.*' ! -name . \) -prune \
        -o -type f "$@" -print0
}

is_listing_guard() {
    [ "$(basename "$1")" = 'index.php' ] && grep -q "header('Location: ../');" "$1"
}

# --- 1. _PS_VERSION_ exit guard ------------------------------------------------------------------
while IFS= read -r -d '' file; do
    is_listing_guard "$file" && continue
    if ! perl -0777 -ne 'exit(/if\s*\(\s*!\s*defined\s*\(\s*[\x27"]_PS_VERSION_[\x27"]\s*\)\s*\)\s*\{?\s*exit\b/ ? 0 : 1)' "$file"; then
        report 1 "$file" "missing the if (!defined('_PS_VERSION_')) { exit; } guard"
    fi
done < <(shipped_files -name '*.php')

# --- 2. index.php in every directory -------------------------------------------------------------
while IFS= read -r -d '' dir; do
    [ -f "$dir/index.php" ] || report 2 "$dir" 'directory has no index.php'
done < <(find . \
    \( -path ./vendor -o -path ./tests -o -path ./build -o -path ./node_modules -o -name '.*' ! -name . \) -prune \
    -o -type d -print0)

# --- 3. raw HTTP outside the transport adapter ---------------------------------------------------
while IFS= read -r -d '' file; do
    case "$file" in ./src/Adapter/HttpTransport*) continue ;; esac
    while IFS= read -r hit; do
        report 3 "$file:${hit%%:*}" 'raw HTTP call; use the HttpTransport adapter'
    done < <(grep -nE "curl_exec[[:space:]]*\(|file_get_contents[[:space:]]*\([[:space:]]*['\"]https?:" "$file" || true)
done < <(shipped_files -name '*.php')

# --- 4. SQL table names without the shop prefix --------------------------------------------------
# A table literal right after an SQL keyword is a hard-coded name: prefixed names are built with
# sprintf('%s...') / _DB_PREFIX_ . '...', and DbQuery::from() adds the prefix itself.
while IFS= read -r -d '' file; do
    while IFS= read -r hit; do
        report 4 "$file:${hit%%:*}" 'SQL table name without _DB_PREFIX_'
    done < <(grep -nE '(FROM|JOIN|INTO|UPDATE|TABLE( IF( NOT)? EXISTS)?)[[:space:]]+(`[a-z][a-z0-9_]*`|(ps|axitrace)_[a-z0-9_]+)' "$file" || true)
done < <(shipped_files -name '*.php')

# --- 5. cron controller token check --------------------------------------------------------------
if [ -d controllers/front ]; then
    while IFS= read -r -d '' file; do
        if ! grep -q 'hash_equals[[:space:]]*(' "$file" || ! grep -qi 'token' "$file"; then
            report 5 "$file" 'cron controller without a hash_equals() token check'
        fi
    done < <(find controllers/front -type f -iname '*cron*.php' -print0)
fi

# --- 6. long dashes in customer-visible text -----------------------------------------------------
for path in views translations README.md; do
    [ -e "$path" ] || continue
    while IFS= read -r hit; do
        report 6 "$hit" 'em/en dash; use a plain hyphen'
    done < <(grep -rnI -e "$(printf '\342\200\223')" -e "$(printf '\342\200\224')" -e '&mdash;' -e '&ndash;' "$path" | cut -d: -f1,2 || true)
done

# --- 7. English-only: no Polish letters ----------------------------------------------------------
for path in views src translations; do
    [ -d "$path" ] || continue
    while IFS= read -r hit; do
        report 7 "$hit" 'Polish letter; module text is English-only'
    done < <(grep -rnI -e "$(printf '\304[\204\205\206\207\230\231]')" -e "$(printf '\305[\201\202\203\204\232\233\271\272\273\274]')" -e "$(printf '\303[\223\263]')" "$path" | cut -d: -f1,2 || true)
done

# --- 8. element id must carry the -prestashop infix ----------------------------------------------
for path in views src; do
    [ -d "$path" ] || continue
    while IFS= read -r hit; do
        report 8 "$hit" 'use axitrace-prestashop-config, never axitrace-config'
    done < <(grep -rnIE "axitrace-config[\"']" "$path" | cut -d: -f1,2 || true)
done

if [ "$violations" -gt 0 ]; then
    printf '%d validator rule violation(s).\n' "$violations"
    exit 1
fi

echo 'Validator lint: OK'
