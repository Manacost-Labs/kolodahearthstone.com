<?php
/**
 * Plugin Name: KolodaHearthstone Staging Guard
 * Description: Keeps the isolated staging copy private and unable to affect production.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging') {
    return;
}

function khs_staging_block_mail($return, array $mail): bool
{
    unset($return, $mail);
    return true;
}

function khs_staging_force_private($value): string
{
    unset($value);
    return '0';
}

function khs_staging_robots(array $robots): array
{
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    $robots['noarchive'] = true;
    return $robots;
}

function khs_staging_disable_production_plugins(array $plugins): array
{
    $blocked = [
        'cloudflare/cloudflare.php',
        'w3-total-cache/w3-total-cache.php',
        'redis-cache/redis-cache.php',
    ];
    return array_values(array_diff($plugins, $blocked));
}

function khs_staging_badge(): void
{
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    echo '<div id="khs-staging-badge" role="status">ТЕСТОВЫЙ САЙТ</div>';
    echo '<style>#khs-staging-badge{position:fixed;right:12px;bottom:12px;z-index:2147483647;padding:7px 10px;border-radius:7px;background:#b42318;color:#fff;font:700 12px/1.2 system-ui,sans-serif;letter-spacing:.04em;box-shadow:0 2px 10px #0005;pointer-events:none}</style>';
}

add_filter('pre_wp_mail', 'khs_staging_block_mail', PHP_INT_MIN, 2);
add_filter('pre_option_blog_public', 'khs_staging_force_private', PHP_INT_MIN);
add_filter('wp_robots', 'khs_staging_robots', PHP_INT_MIN);
add_filter('option_active_plugins', 'khs_staging_disable_production_plugins', PHP_INT_MIN);
add_filter('automatic_updater_disabled', '__return_true', PHP_INT_MIN);
add_filter('auto_update_core', '__return_false', PHP_INT_MIN);
add_filter('auto_update_plugin', '__return_false', PHP_INT_MIN);
add_filter('auto_update_theme', '__return_false', PHP_INT_MIN);
add_action('wp_footer', 'khs_staging_badge', PHP_INT_MAX);
add_action('admin_footer', 'khs_staging_badge', PHP_INT_MAX);
