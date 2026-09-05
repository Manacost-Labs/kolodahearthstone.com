# KolodaHearthstone Shortcode Formatting Compatibility

MU-плагин совместимости WordPress и Shortcodes Ultimate с инлайновыми
шорткодами Hearthstone.

WordPress `shortcode_unautop()` удаляет абзац вокруг одиночного шорткода, а
Shortcodes Ultimate с включённой опцией Custom Formatting снимает открывающий
`<p>` у любого абзаца, начинающегося с шорткода. Для инлайновых `[hs_bg]`,
`[hs_card]` и `[hs_deck_link]` это создаёт невалидный HTML и выводит текст из
ограниченного по ширине потока Blocksy.

Плагин заменяет оба фильтра совместимыми обёртками. Абзацы с инлайновыми
шорткодами Hearthstone временно защищаются, а весь остальной контент по-прежнему
обрабатывается оригинальными функциями `shortcode_unautop()` и
`su_filter_custom_formatting()`.

## Проверка

```bash
php tests/test-shortcode-formatting-compat.php
php -l kolodahearthstone-shortcode-compat.php
```

## Развёртывание

```bash
bash ops/deploy.sh
```

Скрипт устанавливает единственный PHP-файл в `wp-content/mu-plugins`; активация
через панель WordPress не требуется.
