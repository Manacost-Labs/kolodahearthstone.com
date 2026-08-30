# Mulligan Stimulation

WordPress-плагин: тренажёр муллигана для Hearthstone. Автор пишет статью в Classic Editor (TinyMCE), нажимает кнопку **Муллиган**, вставляет код колоды, отмечает «правильные» карты для муллигана — плагин создаёт запись и вставляет шорткод. На фронте читатель получает 4 случайные стартовые карты из этой колоды, выбирает, какие оставить, и получает разбор.

## Установка

1. Скопировать папку в `wp-content/plugins/mulligan/`.
2. Активировать «Mulligan Stimulation» в админке.

## Использование

1. В редакторе записи (Classic Editor) нажать **Муллиган** в панели TinyMCE.
2. Ввести название и код колоды Hearthstone, нажать **Декодировать колоду**.
3. Кликнуть на карты, которые правильно оставлять при муллигане → **Создать и вставить**.
4. В тексте появится шорткод вида `[hs_mulligan id="123"]`.

Готовые тренажёры доступны и в админ-меню **Муллиганы**.

## Как это работает

- **Декодер deckstring** реализован в PHP по спецификации [HearthSim/hearthstone-deckstrings](https://github.com/HearthSim/hearthstone-deckstrings) (base64 → байт-резерв 0 → varint поля).
- **Данные карт** берутся с [HearthstoneJSON](https://hearthstonejson.com/) (`cards.collectible.json`, ruRU с фолбэком enUS) и кешируются в transient на сутки.
- **Изображения** карт — через CDN HearthstoneJSON по `cardId`.

## Файлы

- `mulligan-stimulation.php` — главный файл, CPT, AJAX, шорткод.
- `includes/class-deckstring.php` — декодер deckstring.
- `includes/class-card-data.php` — загрузка/кеш данных карт.
- `assets/tinymce-mulligan.js` — кнопка и модалка TinyMCE.
- `assets/mulligan-frontend.js` / `assets/mulligan-frontend.css` — фронтовый симулятор.

## Безопасность

- Все AJAX-эндпоинты защищены `check_ajax_referer` + `current_user_can('edit_posts')`.
- Входные данные санитизируются (`sanitize_text_field` / `sanitize_textarea_field` / `intval`).
- Шорткод проверяет тип и статус поста, экранирует имена карт через `esc_*`.
- На фронте имена/URL экранируются на стороне JS перед вставкой в DOM.
