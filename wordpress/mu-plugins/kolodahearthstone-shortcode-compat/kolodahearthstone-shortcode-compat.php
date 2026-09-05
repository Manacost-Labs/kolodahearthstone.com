<?php
/**
 * Plugin Name: KolodaHearthstone Shortcode Formatting Compatibility
 * Description: Preserves inline Hearthstone shortcode paragraphs across WordPress and Shortcodes Ultimate formatting.
 * Version: 1.0.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run a paragraph formatter without letting it unwrap inline Hearthstone
 * shortcode paragraphs.
 *
 * @param string $content Filtered post content.
 * @param callable $formatter Original WordPress or plugin formatter.
 * @param bool $standalone_only Protect only paragraphs whose outer shortcode is inline.
 * @return string
 */
function khs_shortcode_compat_apply_formatter($content, $formatter, $standalone_only = false)
{
    if (
        strpos($content, '[hs_bg') === false &&
        strpos($content, '[hs_card') === false &&
        strpos($content, '[hs_deck_link') === false
    ) {
        return call_user_func($formatter, $content);
    }

    $protected_paragraphs = [];
    $token_prefix = '<!--khs-shortcode-compat-' . md5($content) . '-';
    $standalone_shortcode_pattern = $standalone_only
        ? '~^<p(?:\s[^>]*)?>\s*' . get_shortcode_regex(['hs_bg', 'hs_card', 'hs_deck_link']) . '\s*</p>$~is'
        : null;

    $protected_content = preg_replace_callback(
        '~<p(?:\s[^>]*)?>.*?</p>~is',
        static function ($matches) use (
            &$protected_paragraphs,
            $token_prefix,
            $standalone_only,
            $standalone_shortcode_pattern
        ) {
            $should_protect = $standalone_only
                ? preg_match($standalone_shortcode_pattern, $matches[0])
                : preg_match('~\[hs_(?:bg|card|deck_link)\b~i', $matches[0]);

            if (!$should_protect) {
                return $matches[0];
            }

            $token = $token_prefix . count($protected_paragraphs) . '-->';
            $protected_paragraphs[$token] = $matches[0];

            return $token;
        },
        $content
    );

    if ($protected_content === null || $protected_paragraphs === []) {
        return call_user_func($formatter, $content);
    }

    $formatted_content = call_user_func($formatter, $protected_content);

    return strtr($formatted_content, $protected_paragraphs);
}

/**
 * Run WordPress shortcode_unautop while preserving inline shortcode paragraphs.
 *
 * WordPress removes paragraphs containing only a shortcode. That is correct for
 * block shortcodes, but hs_card, hs_bg and hs_deck_link render inline content
 * that must stay inside Blocksy's constrained content flow.
 *
 * @param string $content Filtered post content.
 * @return string
 */
function khs_shortcode_compat_unautop_content($content)
{
    return khs_shortcode_compat_apply_formatter($content, 'shortcode_unautop', true);
}

/**
 * Run Shortcodes Ultimate custom formatting while preserving inline shortcode
 * paragraphs owned by other plugins.
 *
 * @param string $content Filtered post content.
 * @return string
 */
function khs_shortcode_compat_format_content($content)
{
    return khs_shortcode_compat_apply_formatter($content, 'su_filter_custom_formatting');
}

/**
 * Replace a paragraph formatter with its scoped compatibility wrapper.
 *
 * @param string $formatter Original formatter callback.
 * @param string $wrapper Compatibility wrapper callback.
 * @return void
 */
function khs_shortcode_compat_replace_filter($formatter, $wrapper)
{
    if (!function_exists($formatter)) {
        return;
    }

    $priority = has_filter('the_content', $formatter);
    if ($priority === false) {
        return;
    }

    if (!remove_filter('the_content', $formatter, (int) $priority)) {
        return;
    }

    add_filter('the_content', $wrapper, (int) $priority);
}

/**
 * Install compatibility wrappers after WordPress and Shortcodes Ultimate have
 * registered their content filters.
 *
 * Shortcodes Ultimate 7.x creates its main plugin object on init at priority 1.
 * The su/ready hook installs the bridge immediately after that object is ready;
 * the late init hook is a fallback for versions where su/ready is unavailable.
 *
 * @return void
 */
function khs_shortcode_compat_bootstrap()
{
    khs_shortcode_compat_replace_filter('shortcode_unautop', 'khs_shortcode_compat_unautop_content');
    khs_shortcode_compat_replace_filter('su_filter_custom_formatting', 'khs_shortcode_compat_format_content');
}

add_action('su/ready', 'khs_shortcode_compat_bootstrap', PHP_INT_MAX);
add_action('init', 'khs_shortcode_compat_bootstrap', PHP_INT_MAX);
