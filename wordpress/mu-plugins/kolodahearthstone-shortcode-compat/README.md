# KolodaHearthstone Shortcode Formatting Compatibility

MU-плагин совместимости между Shortcodes Ultimate и инлайновыми шорткодами
Hearthstone.

Когда в Shortcodes Ultimate включена опция Custom Formatting, его фильтр
удаляет открывающий `<p>` у любого абзаца, начинающегося с шорткода. Для
инлайновых `[hs_bg]`, `[hs_card]` и `[hs_deck_link]` это создаёт невалидный HTML
и выводит текст из ограниченного по ширине потока Blocksy.

Плагин заменяет фильтр Shortcodes Ultimate совместимой обёрткой. Абзацы с
инлайновыми шорткодами Hearthstone временно защищаются, а весь остальной контент
по-прежнему обрабатывается оригинальной функцией
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
