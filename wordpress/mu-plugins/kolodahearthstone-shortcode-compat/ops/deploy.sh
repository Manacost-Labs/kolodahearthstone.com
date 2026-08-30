#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd "${script_dir}/.." && pwd)"
source_file="${plugin_dir}/kolodahearthstone-shortcode-compat.php"
test_file="${plugin_dir}/tests/test-shortcode-formatting-compat.php"
target_file="/var/www/koloda/data/www/kolodahearthstone.ru/wp-content/mu-plugins/kolodahearthstone-shortcode-compat.php"

php "${test_file}"
php -l "${source_file}"

sudo install -o koloda -g koloda -m 0644 "${source_file}" "${target_file}"

echo "Installed ${target_file}"
