#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
canonical="$repo_root/.agents/skills"

for target in "$repo_root/.claude/skills" "$repo_root/.codex/skills"; do
	mkdir -p "$target"
	rsync -a --delete --delete-excluded \
		--exclude='__pycache__/' \
		--exclude='*.pyc' \
		"$canonical/" "$target/"
	done

find "$repo_root/.claude/skills" "$repo_root/.codex/skills" -type f \
	\( -name '*.css' -o -name '*.html' -o -name '*.js' -o -name '*.json' \
	-o -name '*.md' -o -name '*.mjs' -o -name '*.php' -o -name '*.py' \
	-o -name '*.sh' -o -name '*.txt' -o -name '*.yaml' -o -name '*.yml' \) \
	-exec sed -i 's/\r$//' {} +

echo 'AI skills synchronized from .agents/skills'
