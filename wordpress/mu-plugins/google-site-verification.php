<?php
/**
 * Plugin Name: Koloda Google Site Verification
 * Description: Adds the Google site verification meta tag for kolodahearthstone.ru.
 */

defined('ABSPATH') || exit;

add_action('wp_head', static function (): void {
    if (is_admin()) {
        return;
    }

    echo "\n" . '<meta name="google-site-verification" content="VXNIx4w05QWxLDMQw5z_cVyaPd5-x0WX92H-c2XWFzg" />' . "\n";
}, 1);
