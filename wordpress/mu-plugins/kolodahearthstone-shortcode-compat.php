<?php
/**
 * Plugin Name: KolodaHearthstone Shortcode Formatting Compatibility
 * Description: Preserves hs-tooltip paragraphs while Shortcodes Ultimate custom formatting is enabled.
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the Shortcodes Ultimate formatter without letting it unwrap inline
 * hs-tooltip paragraphs.
 *
 * Shortcodes Ultimate replaces every `<p>[` sequence, including shortcodes
 * owned by other plugins. An hs_card or hs_bg shortcode renders as an inline
 * span, so removing its paragraph makes Blocksy treat the text as a loose node
 * outside the constrained content flow.
 *
 * @param string $content Filtered post content.
 * @return string
 */
function khs_shortcode_compat_format_content($content)
{
    if (!function_exists('su_filter_custom_formatting')) {
        return $content;
    }

    if (strpos($content, '[hs_bg') === false && strpos($content, '[hs_card') === false) {
        return su_filter_custom_formatting($content);
    }

    $protected_paragraphs = [];
    $token_prefix = '<!--khs-shortcode-compat-' . md5($content) . '-';

    $protected_content = preg_replace_callback(
        '~<p(?:\s[^>]*)?>.*?</p>~is',
        static function ($matches) use (&$protected_paragraphs, $token_prefix) {
            if (!preg_match('~\[hs_(?:bg|card)\b~i', $matches[0])) {
                return $matches[0];
            }

            $token = $token_prefix . count($protected_paragraphs) . '-->';
            $protected_paragraphs[$token] = $matches[0];

            return $token;
        },
        $content
    );

    if ($protected_content === null || $protected_paragraphs === []) {
        return su_filter_custom_formatting($content);
    }

    $formatted_content = su_filter_custom_formatting($protected_content);

    return strtr($formatted_content, $protected_paragraphs);
}

/**
 * Replace the over-broad formatter only when Shortcodes Ultimate enabled it.
 *
 * Shortcodes Ultimate 7.x creates its main plugin object on init at priority 1.
 * The su/ready hook installs the bridge immediately after that object is ready;
 * the late init hook is a fallback for versions where su/ready is unavailable.
 *
 * @return void
 */
function khs_shortcode_compat_bootstrap()
{
    if (!function_exists('su_filter_custom_formatting')) {
        return;
    }

    $priority = has_filter('the_content', 'su_filter_custom_formatting');
    if ($priority === false) {
        return;
    }

    if (!remove_filter('the_content', 'su_filter_custom_formatting', (int) $priority)) {
        return;
    }

    add_filter('the_content', 'khs_shortcode_compat_format_content', (int) $priority);
}

add_action('su/ready', 'khs_shortcode_compat_bootstrap', PHP_INT_MAX);
add_action('init', 'khs_shortcode_compat_bootstrap', PHP_INT_MAX);
