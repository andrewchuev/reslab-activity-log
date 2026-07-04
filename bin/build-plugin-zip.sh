#!/usr/bin/env bash
#
# Builds a clean, wordpress.org-submission-ready ZIP of the plugin: runtime
# files only, none of the dev tooling (tests, vendor, the marketing/docs
# site, composer/phpunit config). Codifies the exclude list previously kept
# only as a one-off manual command, so it doesn't have to be re-derived
# (or accidentally get out of sync) every release.
#
# Usage: bin/build-plugin-zip.sh [output-dir]

set -euo pipefail

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
SLUG="reslab-activity-log"
VERSION="$( grep -m1 '^define( .RESLAB_AL_VERSION.' "$PLUGIN_DIR/reslab-activity-log.php" | grep -oP "(?<=')[0-9][0-9.]*(?=')" )"
OUT_DIR="${1:-/tmp}"
WORK_DIR="$( mktemp -d )"
trap 'rm -rf "$WORK_DIR"' EXIT

rsync -a "$PLUGIN_DIR/" "$WORK_DIR/$SLUG/" \
	--exclude 'tests/' \
	--exclude 'vendor/' \
	--exclude 'node_modules/' \
	--exclude 'site/' \
	--exclude '.wordpress-org/' \
	--exclude 'bin/' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpunit.xml.dist' \
	--exclude '.gitignore' \
	--exclude '.phpunit.result.cache' \
	--exclude '.phpunit.cache/' \
	--exclude '.git/' \
	--exclude 'languages/.gitkeep'

ZIP_PATH="$OUT_DIR/${SLUG}-${VERSION}-wpo-submission.zip"
rm -f "$ZIP_PATH"
( cd "$WORK_DIR" && zip -r -X -q "$ZIP_PATH" "$SLUG" )

echo "Built: $ZIP_PATH"
unzip -l "$ZIP_PATH"
