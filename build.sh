#!/usr/bin/env bash
set -euo pipefail
PLUGIN_SLUG='hyros-woo'
VERSION=$(grep 'Version:' hyros-woo.php | awk '{print $2}')
echo "Building ${PLUGIN_SLUG} v${VERSION}..."
rm -rf dist/
mkdir -p "dist/${PLUGIN_SLUG}"
rsync -a --exclude='.git' --exclude='build.sh' --exclude='dist/' --exclude='.DS_Store' . "dist/${PLUGIN_SLUG}/"
cd dist/ && zip -r "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}/"
echo "Built: dist/${PLUGIN_SLUG}-${VERSION}.zip"
