# Правила работы AI-агентов на KolodaHearthstone

Этот репозиторий — исходный код и безопасная конфигурация WordPress-сайта `kolodahearthstone.com`.

## Домены и runtime

- `https://kolodahearthstone.com` — канонический индексируемый production.
- `https://kolodahearthstone.ru` — legacy-домен с одним редиректом на `.com`.
- `https://test.kolodahearthstone.com` — изолированный staging, всегда `noindex`.
- Production и legacy-домен используют один WordPress runtime; staging имеет отдельную БД и media-контур.
- Московский и новосибирский прокси обслуживают тот же origin и не являются отдельными копиями WordPress.

## Обязательный порядок

1. Перед любой работой выполнить `git status --short` и `.agents/skills/kolodahearthstone-project/scripts/context-snapshot.sh`.
2. Для исходного кода, плагинов, темы, CI и конфигурации запустить `wordpress-change-impact` до редактирования и повторно на итоговом diff.
3. Работать только в source-репозитории. `/var/www`, базы, uploads, S3, кэши и логи — runtime/data, не исходники.
4. Любой плагин или тему сначала проверять на staging. Production меняется только после успешного staging и явного promotion.
5. Перед изменением WordPress-поведения добавить тест или воспроизводимую проверку; для UI — браузерную проверку desktop/mobile.
6. Завершать работу `make check` и `/home/debian/server/tools/ai-quality/bin/ai-security-check staged`.
7. Коммит должен быть атомарным, содержать rollback-описание и пройти code review.

## Blocksy

Активная тема — Blocksy 2.1.40 и Blocksy Companion 2.1.40. Родительская тема не редактируется. PHP/CSS/JS для темы размещаются в `wordpress/themes/blocksy-child` либо в собственном плагине. Для темы используется `$blocksy-theme`.

## Shared plugins

`hs-tooltip` имеет единственный канонический исходник и фиксируется commit SHA/SHA256 в `config/shared-plugin-lock.json`. При расхождении файлов CI останавливает выпуск. Обновление проверяется на Blocksy; инструкции Newspaper из другого проекта сюда не переносятся.

## Секреты и данные

Запрещено коммитить `.env`, `wp-config.php`, пароли, токены Telegram/S3/Cloudflare, лицензии, cookies, персональные данные, SQL-дампы, uploads, кэши, логи и резервные копии. Настройки темы и плагинов экспортируются только по allowlist с редактированием секретных значений.

## Проверки

```bash
make check
make skills-sync-check
make blocksy-audit
```

Для release дополнительно выполняются integration, visual, accessibility, media, SEO/cache и региональные smoke-проверки.
