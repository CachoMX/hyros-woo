#!/usr/bin/env bash
set -euo pipefail
PLUGIN_SLUG='hyros-woo'
VERSION=$(grep 'Version:' hyros-woo.php | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
echo "Building ${PLUGIN_SLUG} v${VERSION}..."
rm -rf dist/
mkdir -p "dist/${PLUGIN_SLUG}"
cp -r admin includes hyros-woo.php uninstall.php readme.txt "dist/${PLUGIN_SLUG}/"
# Use zip if available, else fall back to PowerShell (Windows)
if command -v zip &>/dev/null; then
    cd dist/ && zip -r "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}/"
else
    powershell.exe -Command "Compress-Archive -Path 'dist/${PLUGIN_SLUG}' -DestinationPath 'dist/${PLUGIN_SLUG}-${VERSION}.zip'"
fi
echo "Built: dist/${PLUGIN_SLUG}-${VERSION}.zip"
