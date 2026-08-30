#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../../" && pwd)"

printf '%s\n' 'KolodaHearthstone project context'
printf '%s\n' "Source: $repo_root"
printf '%s\n' "Git: branch=$(git -C "$repo_root" branch --show-current) commit=$(git -C "$repo_root" rev-parse --short HEAD 2>/dev/null || echo none)"
printf '%s\n' 'Sites'
printf '%s\n' '- Canonical: https://kolodahearthstone.com'
printf '%s\n' '- Legacy redirect: https://kolodahearthstone.ru'
printf '%s\n' '- Staging: https://test.kolodahearthstone.com'
printf '%s\n' 'Runtime contract'
printf '%s\n' '- WordPress 6.9.7; PHP-FPM 8.4; Blocksy 2.1.40'
printf '%s\n' '- Production and legacy redirect share the current WordPress runtime'
printf '%s\n' 'Next: read AGENTS.md, choose the route in config/ai-skills.json, and inspect the owning source/test files.'
