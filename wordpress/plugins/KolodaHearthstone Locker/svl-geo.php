<?php
/**
 * SVL Geo — определение страны без хранения IP.
 * Приоритет: CF-IPCountry header (Cloudflare) → ip-api.com с кешем 24ч.
 */
if (!defined('ABSPATH')) exit;

/**
 * Получает 2-буквенный код страны для текущего запроса.
 * Возвращает '' если определить не удалось.
 */
function svl_geo_country() {
    static $cached = null;
    if ($cached !== null) return $cached;

    // 1) Cloudflare заголовок (мгновенно, без запросов)
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $cc = strtoupper(substr($_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2));
        if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX') {
            return $cached = $cc;
        }
    }

    // 2) Прочие популярные заголовки CDN
    foreach (array('HTTP_X_COUNTRY_CODE','HTTP_X_GEO_COUNTRY','HTTP_X_AO_COUNTRY') as $h) {
        if (!empty($_SERVER[$h])) {
            $cc = strtoupper(substr($_SERVER[$h], 0, 2));
            if (preg_match('/^[A-Z]{2}$/', $cc)) return $cached = $cc;
        }
    }

    // 3) ip-api.com с кешем — только если включён в настройках
    if (!get_option('svl_geo_external', 0)) return $cached = '';

    $ip = svl_geo_real_ip();
    if (!$ip || $ip === '127.0.0.1') return $cached = '';

    $key = 'svl_geo_' . md5($ip);
    $hit = get_transient($key);
    if ($hit !== false) return $cached = $hit;

    $resp = wp_remote_get('http://ip-api.com/json/' . $ip . '?fields=countryCode', array('timeout' => 2));
    if (is_wp_error($resp)) { set_transient($key, '', HOUR_IN_SECONDS); return $cached = ''; }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $cc = is_array($body) && !empty($body['countryCode']) ? strtoupper($body['countryCode']) : '';
    set_transient($key, $cc, DAY_IN_SECONDS);
    return $cached = $cc;
}

/**
 * Возвращает реальный IP пользователя (через CDN-заголовки если есть).
 */
function svl_geo_real_ip() {
    $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

/**
 * Локализованное название страны по 2-буквенному коду.
 */
function svl_geo_country_name($cc) {
    $map = array(
        'RU' => array('Россия',         '🇷🇺'),
        'BY' => array('Беларусь',       '🇧🇾'),
        'KZ' => array('Казахстан',      '🇰🇿'),
        'UA' => array('Украина',        '🇺🇦'),
        'UZ' => array('Узбекистан',     '🇺🇿'),
        'AM' => array('Армения',        '🇦🇲'),
        'AZ' => array('Азербайджан',    '🇦🇿'),
        'GE' => array('Грузия',         '🇬🇪'),
        'KG' => array('Киргизия',       '🇰🇬'),
        'TJ' => array('Таджикистан',    '🇹🇯'),
        'MD' => array('Молдова',        '🇲🇩'),
        'EE' => array('Эстония',        '🇪🇪'),
        'LV' => array('Латвия',         '🇱🇻'),
        'LT' => array('Литва',          '🇱🇹'),
        'PL' => array('Польша',         '🇵🇱'),
        'DE' => array('Германия',       '🇩🇪'),
        'TR' => array('Турция',         '🇹🇷'),
        'CN' => array('Китай',          '🇨🇳'),
        'US' => array('США',            '🇺🇸'),
        'GB' => array('Великобритания', '🇬🇧'),
        'FR' => array('Франция',        '🇫🇷'),
        'IT' => array('Италия',         '🇮🇹'),
        'ES' => array('Испания',        '🇪🇸'),
        'IL' => array('Израиль',        '🇮🇱'),
    );
    if (isset($map[$cc])) return array('name' => $map[$cc][0], 'flag' => $map[$cc][1]);
    return array('name' => $cc ?: 'Неизвестно', 'flag' => $cc ? '🌐' : '❓');
}

/**
 * Распределение по странам для кода (читает из svl_unlocks таблицы).
 */
function svl_geo_breakdown($code = '') {
    global $wpdb;
    $tbl = $wpdb->prefix . SVL_TABLE_LOG;
    if (!$wpdb->get_var("SHOW COLUMNS FROM $tbl LIKE 'country'")) return array();
    if ($code !== '') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT country, COUNT(*) AS n FROM $tbl WHERE code=%s AND country!='' GROUP BY country ORDER BY n DESC LIMIT 12", $code
        ));
    } else {
        $rows = $wpdb->get_results("SELECT country, COUNT(*) AS n FROM $tbl WHERE country!='' GROUP BY country ORDER BY n DESC LIMIT 12");
    }
    $out = array();
    foreach ($rows as $r) {
        $info = svl_geo_country_name($r->country);
        $out[] = array('code' => $r->country, 'name' => $info['name'], 'flag' => $info['flag'], 'n' => (int) $r->n);
    }
    return $out;
}

// =====================================================
// Миграция: добавить столбец country в существующую таблицу
// =====================================================
add_action('plugins_loaded', 'svl_geo_maybe_migrate_column', 5);
function svl_geo_maybe_migrate_column() {
    if (get_option('svl_geo_column_added') === '1') return;
    global $wpdb;
    $tbl = $wpdb->prefix . SVL_TABLE_LOG;
    if ($wpdb->get_var("SHOW TABLES LIKE '$tbl'")) {
        $exists = $wpdb->get_var("SHOW COLUMNS FROM $tbl LIKE 'country'");
        if (!$exists) {
            $wpdb->query("ALTER TABLE $tbl ADD COLUMN country CHAR(2) NOT NULL DEFAULT '' AFTER ref_host, ADD INDEX idx_country (country)");
        }
    }
    update_option('svl_geo_column_added', '1');
}

// Расширяем svl_pro_log() — добавляем country при вставке
add_filter('svl_pro_log_args', 'svl_geo_attach_country');
function svl_geo_attach_country($args) {
    if (!isset($args['country'])) $args['country'] = svl_geo_country();
    return $args;
}
