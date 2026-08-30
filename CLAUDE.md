# Claude Code entry point

Перед любой задачей прочитайте `AGENTS.md`, запустите `git status --short` и `.agents/skills/kolodahearthstone-project/scripts/context-snapshot.sh`.

Канонические инструкции хранятся в `.agents/skills`. Каталоги `.claude/skills` и `.codex/skills` генерируются скриптом `ops/sync-ai-skills.sh`; редактировать их напрямую нельзя.

Для Blocksy используйте `$blocksy-theme`; для WordPress-плагинов и runtime — профильные skills из `config/ai-skills.json`. Production promotion выполняется только для commit, который прошёл staging.
