<?php
/**
 * Mulligan Stimulation — uninstall handler.
 * Чистит все данные плагина при удалении через wp-admin → Plugins → Delete.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// Удаляем все CPT-записи
$post_types = array('hs_mulligan', 'hs_matchups');
foreach ($post_types as $type) {
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $type));
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }
}

// Удаляем сиротские meta (на всякий случай — wp_delete_post их обычно сносит)
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_hs_mulligan_%' OR meta_key LIKE '_hs_matchups_%'");

// Удаляем options + transient'ы
$option_like = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s";
$wpdb->query($wpdb->prepare($option_like, 'hs_mulligan_%', '_transient_hs_mulligan_%'));
$wpdb->query($wpdb->prepare($option_like, '_transient_timeout_hs_mulligan_%', 'hs_mul_%'));

// Cron-хуки
wp_clear_scheduled_hook('hs_mulligan_daily_refresh');
wp_clear_scheduled_hook('hs_mulligan_refresh_cards');
