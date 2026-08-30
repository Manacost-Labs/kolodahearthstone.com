.PHONY: check php-lint test config skill-audit skills-sync-check blocksy-audit shared-plugin-check

check: config php-lint test skill-audit skills-sync-check shared-plugin-check blocksy-audit

config:
	@python3 ops/validate-config.py

php-lint:
	@find wordpress ops -type f -name '*.php' -print0 | xargs -0 -n 1 php -l >/dev/null
	@echo 'PHP syntax: OK'

test:
	@python3 -m unittest discover -s tests -v

skill-audit:
	@python3 ops/skill-audit.py

skills-sync-check:
	@tmpdir="$$(mktemp -d)"; trap 'rm -rf "$$tmpdir"' EXIT; ops/sync-ai-skills.sh >/dev/null; diff -qr .agents/skills .claude/skills; diff -qr .agents/skills .codex/skills

blocksy-audit:
	@python3 .agents/skills/blocksy-theme/scripts/audit_blocksy_change.py --repo . --base HEAD --include-untracked

shared-plugin-check:
	@python3 ops/verify-shared-plugin.py
