<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['khs_test_hooks'] = [];

function add_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['khs_test_hooks'][$hook][$priority][] = $callback;
}

function add_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['khs_test_hooks'][$hook][$priority][] = $callback;
}

function has_filter(string $hook, $callback = false)
{
    if (!isset($GLOBALS['khs_test_hooks'][$hook])) {
        return false;
    }

    foreach ($GLOBALS['khs_test_hooks'][$hook] as $priority => $callbacks) {
        foreach ($callbacks as $registered_callback) {
            if ($callback === false || $registered_callback === $callback) {
                return $priority;
            }
        }
    }

    return false;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    if (!isset($GLOBALS['khs_test_hooks'][$hook][$priority])) {
        return false;
    }

    foreach ($GLOBALS['khs_test_hooks'][$hook][$priority] as $index => $registered_callback) {
        if ($registered_callback === $callback) {
            unset($GLOBALS['khs_test_hooks'][$hook][$priority][$index]);
            return true;
        }
    }

    return false;
}

function khs_test_run_hook(string $hook, ?string $value = null): ?string
{
    if (!isset($GLOBALS['khs_test_hooks'][$hook])) {
        return $value;
    }

    ksort($GLOBALS['khs_test_hooks'][$hook]);

    foreach ($GLOBALS['khs_test_hooks'][$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            if ($value === null) {
                $callback();
            } else {
                $value = $callback($value);
            }
        }
    }

    return $value;
}

function su_filter_custom_formatting(string $content): string
{
    return strtr(
        $content,
        [
            '<p>[' => '[',
            ']</p>' => ']',
            ']<br />' => ']',
        ]
    );
}

function get_shortcode_regex(?array $tagnames = null): string
{
    $tagnames = $tagnames ?? ['hs_bg', 'hs_card', 'hs_deck_link', 'su_box'];
    $tagregexp = implode('|', array_map('preg_quote', $tagnames));

    return '\\['
        . '(\\[?)'
        . "($tagregexp)"
        . '(?![\\w-])'
        . '('
        . '[^\\]\\/]*'
        . '(?:\\/(?!\\])[^\\]\\/]*)*?'
        . ')'
        . '(?:'
        . '(\\/)'
        . '\\]'
        . '|'
        . '\\]'
        . '(?:'
        . '('
        . '[^\\[]*+'
        . '(?:\\[(?!\\/\\2\\])[^\\[]*+)*+'
        . ')'
        . '\\[\\/\\2\\]'
        . ')?'
        . ')'
        . '(\\]?)';
}

function shortcode_unautop(string $content): string
{
    $tagregexp = 'hs_bg|hs_card|hs_deck_link|su_box';

    return (string) preg_replace(
        '~<p>\s*(\[(' . $tagregexp . ')(?![\w-])[^\]\/]*(?:\/(?!\])[^\]\/]*)*?(?:\/\]|\](?:[^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+\[\/\2\])?))\s*</p>~is',
        '$1',
        $content
    );
}

function khs_test_assert_same(string $expected, string $actual, string $message): void
{
    if ($expected === $actual) {
        return;
    }

    fwrite(STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual:   {$actual}\n");
    exit(1);
}

add_action(
    'init',
    static function (): void {
        add_filter('the_content', 'shortcode_unautop', 10);
        add_filter('the_content', 'su_filter_custom_formatting', 10);
    },
    1
);

require dirname(__DIR__) . '/kolodahearthstone-shortcode-compat.php';

khs_test_run_hook('init');

$tooltip_content = implode(
    "\n",
    [
        '<p>[hs_bg id="BG36_MagicItem_370"]Малдраксийский кинжал[/hs_bg] — текст абзаца.</p>',
        '<p class="lead">[hs_card id="EX1_001"]Карта[/hs_card] завершает абзац.</p>',
        '<p>Абзац заканчивается картой [hs_bg id="BG36_HERO_105"]Ксавий[/hs_bg]</p>',
    ]
);

khs_test_assert_same(
    $tooltip_content,
    (string) khs_test_run_hook('the_content', $tooltip_content),
    'Tooltip paragraphs must keep their opening and closing paragraph tags.'
);

$inline_deck_content = '<p>[hs_deck_link image_id="6738"]Ренатал Хайлендер Воин[/hs_deck_link]<br />Победы: 54,2% | Популярность: 9,1%</p>';

khs_test_assert_same(
    $inline_deck_content,
    (string) khs_test_run_hook('the_content', $inline_deck_content),
    'Inline deck link paragraphs must keep their opening and closing paragraph tags.'
);

$standalone_deck_content = implode(
    "\n",
    [
        '<p><img class="aligncenter" src="separator.png" alt="" /></p>',
        '<p>[hs_deck_link image_id="6771"]Хостедж Разбойник[/hs_deck_link]</p>',
        '<p>Победы: 48,8% | Популярность: 0,6%</p>',
    ]
);

khs_test_assert_same(
    $standalone_deck_content,
    (string) khs_test_run_hook('the_content', $standalone_deck_content),
    'Standalone deck link paragraphs removed by shortcode_unautop must remain constrained.'
);

$loose_inline_shortcode_content = implode(
    "\n",
    [
        '<p>[hs_card id="EX1_001"]</p>',
        '<p>[hs_bg id="BG36_HERO_105" /]</p>',
    ]
);

khs_test_assert_same(
    $loose_inline_shortcode_content,
    (string) khs_test_run_hook('the_content', $loose_inline_shortcode_content),
    'Opening-only and self-closing inline shortcodes must keep their paragraphs.'
);

$block_shortcode_with_deck_content = '<p>[su_box]Блок с [hs_deck_link image_id="6771"]колодой[/hs_deck_link][/su_box]</p>';

khs_test_assert_same(
    '[su_box]Блок с [hs_deck_link image_id="6771"]колодой[/hs_deck_link][/su_box]',
    (string) khs_test_run_hook('the_content', $block_shortcode_with_deck_content),
    'A block shortcode containing an inline deck link must still be unwrapped.'
);

$shortcodes_ultimate_content = '<p>[su_box]Содержимое[/su_box]</p>';

khs_test_assert_same(
    '[su_box]Содержимое[/su_box]',
    (string) khs_test_run_hook('the_content', $shortcodes_ultimate_content),
    'Shortcodes Ultimate formatting must remain unchanged for its own shortcodes.'
);

$ordinary_content = '<p>Обычный абзац без шорткодов.</p>';

khs_test_assert_same(
    $ordinary_content,
    (string) khs_test_run_hook('the_content', $ordinary_content),
    'Ordinary content must remain unchanged.'
);

fwrite(STDOUT, "OK: shortcode formatting compatibility\n");
