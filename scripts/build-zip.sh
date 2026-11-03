#!/usr/bin/env bash
# Build the AxiTrace PrestaShop module ZIP that merchants upload in Modules > Module Manager.
#
# Usage: scripts/build-zip.sh [output.zip]
#   default output: ../landing-page/public/downloads/axitrace-prestashop-module-latest.zip
#   (monorepo layout); a <output>.sha256 file is written next to the ZIP.
#
# ZIP structure (PrestaShop requires the module folder as the single root entry):
#   axitrace/axitrace.php, config.xml, logo.png, index.php, LICENSE, README.md, CHANGELOG.md,
#   composer.json, src/, views/, upgrade/, vendor/ (production autoloader only)
#
# Excluded: tests/, scripts/, build/, dotfiles (.git, .gitignore, ...), phpunit.xml.dist,
# phpstan.neon, composer.lock, development dependencies.
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_OUTPUT="${MODULE_DIR}/../landing-page/public/downloads/axitrace-prestashop-module-latest.zip"
OUTPUT="${1:-${DEFAULT_OUTPUT}}"

case "${OUTPUT}" in
    *.zip) ;;
    *) echo "Output must end in .zip: ${OUTPUT}" >&2; exit 1 ;;
esac

mkdir -p "$(dirname "${OUTPUT}")"
OUTPUT="$(cd "$(dirname "${OUTPUT}")" && pwd)/$(basename "${OUTPUT}")"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT
STAGING="${TMP_DIR}/axitrace"
mkdir -p "${STAGING}"

rsync -a \
    --exclude='/vendor/' \
    --exclude='/tests/' \
    --exclude='/scripts/' \
    --exclude='/build/' \
    --exclude='.*' \
    --exclude='/phpunit.xml.dist' \
    --exclude='/phpunit.xml' \
    --exclude='/phpstan.neon' \
    --exclude='/phpstan-prestashop.neon' \
    --exclude='/phpstan.neon.dist' \
    --exclude='*.swp' \
    "${MODULE_DIR}/" "${STAGING}/"

# Production autoloader only (no dev dependencies), resolved from the committed lock file.
cp "${MODULE_DIR}/composer.lock" "${STAGING}/composer.lock"
composer install \
    --working-dir="${STAGING}" \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    --no-progress \
    --quiet
rm -f "${STAGING}/composer.lock"

# `zip -r` updates an existing archive in place and never removes entries, so start fresh.
rm -f "${OUTPUT}" "${OUTPUT}.sha256"
( cd "${TMP_DIR}" && zip -r -X -q "${OUTPUT}" "axitrace" )
( cd "$(dirname "${OUTPUT}")" && shasum -a 256 "$(basename "${OUTPUT}")" > "$(basename "${OUTPUT}").sha256" )

echo "Built:   ${OUTPUT}"
echo "Size:    $(du -h "${OUTPUT}" | cut -f1)"
echo "SHA-256: $(cut -d' ' -f1 < "${OUTPUT}.sha256")"
