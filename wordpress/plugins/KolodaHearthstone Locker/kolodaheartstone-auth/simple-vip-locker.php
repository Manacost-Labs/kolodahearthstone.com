<?php
/*
Plugin Name: VIP Locker Pro (Cache & Nesting Fix)
Description: Надежная блокировка контента. Поддерживает вложенные шорткоды, кэширование, нечувствительность к регистру, статистику, совместимость с lightbox-плагинами и выпадающий список подписки.
Version: 3.1
Author: Gemini
*/

if (!defined('ABSPATH')) {
    exit;
}

// ==========================================
// КОНСТАНТЫ
// ==========================================

define('SVL_VERSION', '4.0');
define('SVL_TELEGRAM_URL', 'https://t.me/manacost_ru');
define('SVL_BOOSTY_URL', 'https://boosty.to/kolodahearthstone');

// Подключаем SEO модуль и Pro-фичи
require_once __DIR__ . '/svl-seo.php';
require_once __DIR__ . '/svl-pro.php';
require_once __DIR__ . '/svl-geo.php';
require_once __DIR__ . '/svl-magic.php';
require_once __DIR__ . '/svl-block.php';
require_once __DIR__ . '/svl-bot.php';

register_activation_hook(__FILE__, 'svl_activate');
function svl_activate() {
    if (function_exists('svl_seo_activate')) svl_seo_activate();
    if (function_exists('svl_pro_install_tables')) svl_pro_install_tables();
    update_option('svl_pro_rewrite_flushed', '0');
    flush_rewrite_rules(false);
}

// ==========================================
// 0. HELPERS
// ==========================================

/**
 * Определяем URL баннера.
 * Приоритет: атрибут image → файл wallpaper.{jpg,jpeg,png,webp,gif} в папке плагина → ''.
 */
function svl_resolve_banner_url($explicit = '') {
    if (!empty($explicit)) {
        return esc_url_raw($explicit);
    }
    $dir = plugin_dir_path(__FILE__);
    $url = plugin_dir_url(__FILE__);
    foreach (array('jpg','jpeg','png','webp','gif') as $ext) {
        if (file_exists($dir . 'wallpaper.' . $ext)) {
            return $url . 'wallpaper.' . $ext;
        }
    }
    return '';
}

/**
 * Резолвим URL гирлянды.
 * Приоритет: атрибут shortcode → garland.png/jpg/webp в папке плагина → SVG-фолбэк.
 */
function svl_resolve_garland_url($explicit = '') {
    if (!empty($explicit)) return esc_url_raw($explicit);
    $dir = plugin_dir_path(__FILE__);
    $url = plugin_dir_url(__FILE__);
    foreach (array('png','webp','jpg','jpeg','gif') as $ext) {
        if (file_exists($dir . 'garland.' . $ext)) return $url . 'garland.' . $ext;
    }
    return ''; // SVG-фолбэк через CSS background-image (data:)
}

/**
 * Inline SVG-фолбэк гирлянды (data URI).
 */
function svl_garland_svg_fallback() {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 80" preserveAspectRatio="xMidYMin meet">'
        . '<defs><linearGradient id="s" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#fde047"/><stop offset=".5" stop-color="#fbbf24"/><stop offset="1" stop-color="#f59e0b"/></linearGradient></defs>'
        . '<path d="M 0 8 Q 80 18 160 8 T 320 8" stroke="#7c3aed" stroke-width="2" fill="none"/>';
    $stars = array(
        array(20, 32), array(50, 24), array(80, 38), array(110, 28),
        array(140, 36), array(170, 22), array(200, 34), array(230, 28),
        array(260, 38), array(290, 26),
    );
    foreach ($stars as $s) {
        $x = $s[0]; $top = $s[1];
        $svg .= '<line x1="' . $x . '" y1="10" x2="' . $x . '" y2="' . $top . '" stroke="#7c3aed" stroke-width="1"/>';
        $cx = $x; $cy = $top + 12;
        // 5-конечная звезда
        $points = array();
        for ($i = 0; $i < 10; $i++) {
            $r = ($i % 2) ? 5 : 11;
            $a = -M_PI / 2 + $i * M_PI / 5;
            $points[] = round($cx + $r * cos($a), 1) . ',' . round($cy + $r * sin($a), 1);
        }
        $svg .= '<polygon points="' . implode(' ', $points) . '" fill="url(#s)" stroke="#d97706" stroke-width=".5"/>';
    }
    $svg .= '</svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

/**
 * Подключаем frontend-стили (inline, чтобы не плодить файлов).
 */
function svl_enqueue_front_styles() {
    static $done = false;
    if ($done) return;
    $done = true;

    $css = '
    .svl-wrapper { position: relative; clear: both; margin: 24px 0; }
    .svl-card {
        position: relative;
        background:
            radial-gradient(ellipse at top, rgba(255,255,255,.5), transparent 70%),
            linear-gradient(135deg, #fdf4dd 0%, #f5e8c2 50%, #ead7a5 100%);
        border: 3px solid #8b6332;
        border-radius: 18px;
        padding: 22px 26px;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.6),
            inset 0 -1px 0 rgba(139,99,50,.2),
            0 2px 0 #6b4a26,
            0 8px 24px rgba(75,46,16,.25);
        max-width: 100%;
        overflow: visible;
        transition: box-shadow .3s, transform .3s;
    }
    /* Двойная рамка — золотистая внутренняя обводка */
    .svl-card::before {
        content: ""; position: absolute; inset: 4px;
        border: 1px solid rgba(217,165,89,.5);
        border-radius: 13px;
        pointer-events: none;
    }
    /* Декоративные уголки — золотые ромбы */
    .svl-card::after {
        content: "";
        position: absolute;
        top: -4px; left: -4px; right: -4px; bottom: -4px;
        border-radius: 22px;
        background:
            radial-gradient(circle at 12px 12px, #d4a447 0 4px, transparent 4px),
            radial-gradient(circle at calc(100% - 12px) 12px, #d4a447 0 4px, transparent 4px),
            radial-gradient(circle at 12px calc(100% - 12px), #d4a447 0 4px, transparent 4px),
            radial-gradient(circle at calc(100% - 12px) calc(100% - 12px), #d4a447 0 4px, transparent 4px);
        pointer-events: none;
        z-index: 1;
    }
    .svl-card:hover {
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.6),
            inset 0 -1px 0 rgba(139,99,50,.2),
            0 2px 0 #6b4a26,
            0 12px 32px rgba(75,46,16,.3);
    }
    .svl-card > * { position: relative; z-index: 2; }

    /* === ГИРЛЯНДА === висит ПОД карточкой, не на кнопках === */
    .svl-garland {
        position: absolute;
        left: -2px; right: -2px; bottom: -78px;
        height: 78px;
        background-repeat: repeat-x;
        background-position: center top;
        background-size: auto 100%;
        pointer-events: none;
        opacity: .98;
        animation: svlSway 5s ease-in-out infinite alternate;
        transform-origin: top center;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,.15));
        z-index: 1;
        transition: opacity .25s;
    }
    /* Когда выпадающий список «Подписка» открыт — приглушаем гирлянду */
    .svl-wrapper.svl-dropdown-open .svl-garland {
        opacity: .15;
        filter: blur(1px) drop-shadow(0 2px 4px rgba(0,0,0,.1));
    }
    /* Поднимаем интерактивные элементы над гирляндой */
    .svl-card .svl-body,
    .svl-row,
    .svl-subscribe { position: relative; }
    .svl-card .svl-body { z-index: 10; }
    .svl-subscribe { z-index: 50; }
    @keyframes svlSway {
        0%   { transform: rotate(-0.5deg); }
        100% { transform: rotate(0.5deg); }
    }
    .svl-wrapper { padding-bottom: 80px; }
    .svl-wrapper.svl-is-unlocked .svl-garland { display: none; }

    /* === ЛОК-ИКОНКА И INPUT === */
    .svl-input-wrap {
        position: relative;
        margin-bottom: 12px;
    }
    .svl-lock-icon {
        position: absolute !important;
        left: 16px !important; top: 50% !important;
        transform: translateY(-50%) !important;
        width: 20px !important; height: 20px !important;
        color: #8b6332;
        opacity: .5;
        transition: opacity .2s, color .2s;
        pointer-events: none;
        z-index: 2;
    }
    .svl-input-wrap:focus-within .svl-lock-icon { opacity: 1; color: #c2570b; }
    /* Тряска замка при ошибке */
    .svl-input-wrap.svl-shake .svl-lock-icon {
        color: #d63638 !important;
        opacity: 1 !important;
        animation: svlLockShake .6s cubic-bezier(.36,.07,.19,.97);
    }
    @keyframes svlLockShake {
        0%, 100% { transform: translateY(-50%) rotate(0deg) scale(1); }
        15% { transform: translateY(-50%) rotate(-18deg) scale(1.15); }
        30% { transform: translateY(-50%) rotate(15deg) scale(1.1); }
        45% { transform: translateY(-50%) rotate(-12deg) scale(1.12); }
        60% { transform: translateY(-50%) rotate(8deg) scale(1.08); }
        75% { transform: translateY(-50%) rotate(-4deg) scale(1.05); }
        90% { transform: translateY(-55%) rotate(2deg) scale(1.02); }
    }

    /* === КНОПКА «Почему платный сайт?» === */
    .svl-why {
        margin: 0 0 16px;
        background: rgba(255,251,235,.6);
        border: 1px solid rgba(217,119,6,.25);
        border-radius: 10px;
        overflow: hidden;
        transition: border-color .2s, background .2s;
    }
    .svl-why[open] { background: rgba(255,251,235,.9); border-color: rgba(217,119,6,.5); }
    .svl-why summary {
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 600;
        color: #92400e;
        cursor: pointer;
        list-style: none;
        user-select: none;
        transition: color .15s;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .svl-why summary::-webkit-details-marker { display: none; }
    .svl-why summary::after {
        content: "▼";
        margin-left: auto;
        font-size: 10px;
        opacity: .5;
        transition: transform .25s;
    }
    .svl-why[open] summary::after { transform: rotate(180deg); }
    .svl-why summary:hover { color: #c2410c; }
    .svl-why-body {
        padding: 4px 18px 16px;
        font-size: 14px;
        line-height: 1.65;
        color: #5c4023;
        animation: svlSlideDown .3s ease-out;
    }
    .svl-why-body p { margin: 0 0 10px; }
    .svl-why-body p:last-child { margin: 0; }
    .svl-why-body strong { color: #92400e; }
    .svl-why-thanks {
        margin-top: 12px !important;
        padding-top: 12px;
        border-top: 1px dashed rgba(217,119,6,.3);
        text-align: center;
        font-size: 14px;
        color: #92400e !important;
    }

    /* === СЧЁТЧИК "уже разблокировали" === */
    .svl-social-proof {
        margin-top: 10px;
        padding: 8px 14px;
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border-radius: 99px;
        font-size: 13px;
        color: #78350f;
        text-align: center;
        animation: svlPulseSoft 2.5s ease-in-out infinite;
    }
    .svl-social-proof strong { color: #92400e; font-weight: 700; }
    @keyframes svlPulseSoft {
        0%, 100% { box-shadow: 0 0 0 0 rgba(217,119,6,.15); }
        50%      { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
    }

    /* === Бейдж magic-link === */
    .svl-magic-badge {
        display: inline-flex; align-items: center; gap: 6px;
        margin: 0 0 12px;
        padding: 8px 14px;
        background: linear-gradient(135deg, #ddd6fe, #c4b5fd);
        color: #5b21b6;
        border-radius: 99px;
        font-size: 13px;
        font-weight: 600;
        animation: svlSlideDown .4s ease-out;
    }
    .svl-input-len {
        position: absolute;
        right: 14px; top: 50%; transform: translateY(-50%);
        font-size: 11px;
        color: #9ca3af;
        font-family: ui-monospace, SFMono-Regular, monospace;
        opacity: 0;
        transition: opacity .2s;
    }
    .svl-input-wrap.has-value .svl-input-len { opacity: 1; }
    .svl-banner {
        width: 100%;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 18px;
        line-height: 0;
        border: 2px solid #8b6332;
        box-shadow:
            inset 0 0 0 1px rgba(217,165,89,.5),
            0 4px 12px rgba(75,46,16,.2);
        position: relative;
    }
    .svl-banner img {
        width: 100%;
        height: auto;
        display: block;
        object-fit: cover;
    }
    .svl-body { padding: 4px 2px 8px; }
    .svl-message {
        margin: 0 0 18px;
        font-weight: 600;
        font-size: 16px;
        line-height: 1.55;
        color: #3d2817;
        text-shadow: 0 1px 0 rgba(255,255,255,.4);
    }
    .svl-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: stretch;
    }
    .svl-wrapper .svl-input {
        width: 100%;
        box-sizing: border-box;
        padding: 16px 56px 16px 50px !important;
        border: 2px solid #c8b58a;
        border-radius: 10px;
        background: linear-gradient(180deg, #fffaf0 0%, #fdf4dd 100%);
        font-size: 18px;
        font-weight: 500;
        font-family: ui-monospace, SFMono-Regular, "Cascadia Code", Consolas, monospace;
        color: #3d2817;
        outline: none;
        letter-spacing: .5px;
        transition: border-color .2s, box-shadow .2s, transform .15s;
        box-shadow: inset 0 1px 3px rgba(139,99,50,.15);
    }
    .svl-input::placeholder { font-family: -apple-system, system-ui, sans-serif; font-weight: 400; color: #b8a47a; letter-spacing: 0; }
    .svl-input:hover { border-color: #a08552; }
    .svl-input:focus {
        border-color: #d97706;
        box-shadow: 0 0 0 4px rgba(217,119,6,.2), inset 0 1px 3px rgba(139,99,50,.1);
        transform: translateY(-1px);
        background: #fffdf7;
    }
    .svl-input.svl-error-state { border-color: #d63638; box-shadow: 0 0 0 4px rgba(214,54,56,.18), inset 0 1px 3px rgba(214,54,56,.1); }
    .svl-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 13px 24px;
        font-size: 15px;
        font-weight: 600;
        line-height: 1;
        border: 0;
        border-radius: 10px;
        cursor: pointer;
        transition: background-color .2s, transform .15s, box-shadow .2s;
        text-decoration: none;
        white-space: nowrap;
        gap: 6px;
    }
    .svl-btn-primary {
        background: linear-gradient(180deg, #e89742 0%, #d97706 50%, #b45309 100%);
        color: #fff;
        text-shadow: 0 1px 1px rgba(75,46,16,.4);
        border: 1px solid #8b4d10;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.3),
            inset 0 -1px 0 rgba(75,46,16,.3),
            0 2px 0 #6b3a08,
            0 4px 12px rgba(180,83,9,.4);
    }
    .svl-btn-primary:hover {
        background: linear-gradient(180deg, #f5a649 0%, #e07a1f 50%, #c2570b 100%);
        color: #fff;
        transform: translateY(-1px);
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.3),
            inset 0 -1px 0 rgba(75,46,16,.3),
            0 3px 0 #6b3a08,
            0 6px 18px rgba(180,83,9,.5);
    }
    .svl-btn-primary:active { transform: translateY(1px); box-shadow: inset 0 1px 0 rgba(255,255,255,.3), 0 1px 0 #6b3a08, 0 2px 6px rgba(180,83,9,.4); }
    .svl-btn-submit { flex: 1 1 auto; min-width: 180px; }
    .svl-btn.is-loading .svl-btn-label { opacity: .4; }
    .svl-btn-loader {
        position: absolute;
        width: 18px; height: 18px;
        border: 2px solid rgba(255,255,255,.3);
        border-top-color: #fff;
        border-radius: 50%;
        opacity: 0;
        animation: svlSpin .7s linear infinite;
    }
    .svl-btn.is-loading .svl-btn-loader { opacity: 1; }
    @keyframes svlSpin { to { transform: rotate(360deg); } }
    .svl-subscribe { position: relative; display: inline-block; }
    .svl-caret { margin-left: 6px; font-size: 12px; }
    .svl-sub-menu {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        min-width: 220px;
        background: linear-gradient(180deg, #fffaf0 0%, #f5e8c2 100%);
        border: 2px solid #8b6332;
        border-radius: 10px;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.6),
            0 8px 24px rgba(75,46,16,.3);
        z-index: 100000;
        overflow: hidden;
        isolation: isolate;
    }
    .svl-sub-item {
        display: block;
        padding: 13px 18px;
        text-decoration: none;
        color: #3d2817;
        font-weight: 600;
        border-bottom: 1px solid rgba(139,99,50,.25);
        transition: background-color .15s, padding-left .15s;
    }
    .svl-sub-item:last-child { border-bottom: 0; }
    .svl-sub-item:hover { background: rgba(217,119,6,.12); padding-left: 22px; }
    .svl-sub-tg { color: #0088cc; }
    .svl-sub-boosty { color: #f15f2c; }
    .svl-error {
        display: none;
        margin-top: 14px;
        padding: 10px 14px;
        background: #fef2f2;
        color: #b91c1c;
        border-radius: 8px;
        border-left: 3px solid #d63638;
        font-weight: 500;
        font-size: 14px;
        animation: svlSlideDown .25s ease-out;
    }
    @keyframes svlSlideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

    /* === SUCCESS-АНИМАЦИЯ === */
    .svl-success {
        display: none;
        margin-top: 14px;
        padding: 12px 16px;
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        color: #065f46;
        border-radius: 10px;
        align-items: center;
        gap: 12px;
        font-weight: 600;
        font-size: 15px;
        animation: svlSlideDown .3s ease-out;
    }
    .svl-success.show { display: flex; }
    .svl-success svg circle { stroke-dasharray: 151; stroke-dashoffset: 151; animation: svlDraw .5s .1s forwards ease-out; }
    .svl-success svg path { stroke-dasharray: 60; stroke-dashoffset: 60; animation: svlDraw .4s .5s forwards ease-out; }
    @keyframes svlDraw { to { stroke-dashoffset: 0; } }

    /* === SPARKLES === */
    .svl-sparkles { position: absolute; pointer-events: none; inset: 0; overflow: visible; }
    .svl-sparkle {
        position: absolute;
        font-size: 20px;
        opacity: 0;
        animation: svlSparkle 1.2s ease-out forwards;
    }
    @keyframes svlSparkle {
        0%   { opacity: 0; transform: translate(0,0) scale(.3) rotate(0deg); }
        20%  { opacity: 1; }
        100% { opacity: 0; transform: translate(var(--svl-tx, 60px), var(--svl-ty, -80px)) scale(1.2) rotate(180deg); }
    }
    .svl-content-protector { transition: opacity .5s; }
    .svl-public-teaser {
        margin: 0 auto 16px;
        padding: 14px 18px;
        background: linear-gradient(135deg, #fffaf0, #fdf4dd);
        border-left: 4px solid #d97706;
        border-radius: 0 8px 8px 0;
        font-size: 15px;
        line-height: 1.6;
        color: #3d2817;
        box-shadow: 0 2px 6px rgba(75,46,16,.08);
    }
    .svl-public-teaser p { margin: 0 0 10px; }
    .svl-public-teaser p:last-child { margin: 0; }
    .svl-wrapper.svl-is-unlocked .svl-public-teaser { display: none; }
    @media (max-width: 520px) {
        .svl-row { flex-direction: column; }
        .svl-subscribe, .svl-subscribe .svl-sub-toggle { width: 100%; }
        .svl-sub-menu { left: 0; right: 0; }
    }
    ';
    wp_register_style('svl-front', false, array(), SVL_VERSION);
    wp_enqueue_style('svl-front');
    wp_add_inline_style('svl-front', $css);
    if (function_exists('svl_pro_theme_css')) {
        wp_add_inline_style('svl-front', svl_pro_theme_css());
    }
}

// ==========================================
// 1. АДМИНКА: меню, тема, страницы, виджет
// ==========================================

// --- Опции плагина (URL подписок, дефолтный код/сообщение/баннер) ---

function svl_default_options() {
    return array(
        'svl_telegram_url'    => SVL_TELEGRAM_URL,
        'svl_boosty_url'      => SVL_BOOSTY_URL,
        'svl_default_code'    => '12345',
        'svl_default_message' => 'Этот материал доступен нашим платным подписчикам на Tribute и Boosty. Ваша поддержка помогает сайту развиваться и создавать для вас еще больше качественного контента. Спасибо!',
        'svl_default_banner'  => '',
        'svl_cookie_days'     => 7,
        'svl_theme'           => 'cream',
        'svl_garland_enabled' => 1,
        'svl_geo_external'    => 0,
    );
}
function svl_opt($k) {
    $defs = svl_default_options();
    return get_option($k, isset($defs[$k]) ? $defs[$k] : '');
}

// Активация: дефолты для опций самого замка (SEO-опции инициализирует свой модуль)
add_action('admin_init', 'svl_seed_options');
function svl_seed_options() {
    foreach (svl_default_options() as $k => $v) add_option($k, $v);
}

// --- МЕНЮ ---

add_action('admin_menu', 'svl_add_admin_menu');
function svl_add_admin_menu() {
    add_menu_page(
        'VIP Locker', 'VIP Locker', 'manage_options',
        'svl-stats', 'svl_stats_page_html', 'dashicons-lock', 90
    );
    add_submenu_page('svl-stats', 'Статистика',  '📊 Статистика',  'manage_options', 'svl-stats',     'svl_stats_page_html');
    add_submenu_page('svl-stats', 'Настройки',   '⚙️ Настройки',   'manage_options', 'svl-settings',  'svl_settings_page_html');
}

// --- ОБЩАЯ ТЕМА АДМИНКИ ---

add_action('admin_enqueue_scripts', 'svl_admin_shell_assets');
function svl_admin_shell_assets($hook) {
    if (!isset($_GET['page'])) return;
    $page = $_GET['page'];
    if (!in_array($page, array('svl-stats', 'svl-settings', 'svl-seo', 'svl-magic'), true)) return;

    if ($page === 'svl-settings') wp_enqueue_media();

    $css = '
    /* ====== SVL Admin Shell — современная тема ====== */
    .svl-shell { --p:#e07a1f; --p2:#f59e0b; --b:#2271b1; --g:#00a32a; --r:#d63638; --y:#dba617; --pp:#8b5cf6;
        --bg:#fbfbfd; --card:#fff; --br:#e4e7ec; --text:#1d2327; --muted:#6b7280; --soft:#f6f7fb; }
    .svl-shell { background: var(--bg); margin: 10px -20px 0 -20px; padding: 0 20px 60px; min-height: calc(100vh - 32px); }
    .svl-shell * { box-sizing: border-box; }
    .svl-shell .svl-hero {
        position: relative; overflow: hidden; border-radius: 16px;
        background: linear-gradient(135deg, #1f1235 0%, #2d1b4e 50%, #4a2c7a 100%);
        color: #fff; padding: 28px 32px; margin: 0 0 28px;
        box-shadow: 0 10px 40px rgba(31,18,53,.15);
    }
    .svl-shell .svl-hero::before {
        content: ""; position: absolute; right: -80px; top: -80px;
        width: 280px; height: 280px; border-radius: 50%;
        background: radial-gradient(circle, rgba(224,122,31,.6), transparent 70%); pointer-events: none;
    }
    .svl-shell .svl-hero::after {
        content: ""; position: absolute; left: -40px; bottom: -60px;
        width: 200px; height: 200px; border-radius: 50%;
        background: radial-gradient(circle, rgba(139,92,246,.4), transparent 70%); pointer-events: none;
    }
    .svl-shell .svl-hero h1 { color:#fff; font-size: 26px; margin: 0 0 6px; padding: 0; display: flex; align-items: center; gap: 12px; }
    .svl-shell .svl-hero p { margin: 0; opacity: .85; font-size: 14px; max-width: 720px; }
    .svl-shell .svl-hero .svl-hero-meta { position: relative; display: flex; gap: 20px; margin-top: 18px; flex-wrap: wrap; }
    .svl-shell .svl-hero .svl-hero-meta span { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; opacity: .9; }
    .svl-shell .svl-hero .svl-hero-meta strong { color: #fff; }

    .svl-shell .svl-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin: 0 0 28px; }
    .svl-shell .svl-card {
        background: var(--card); border: 1px solid var(--br); border-radius: 12px;
        padding: 20px 22px; transition: transform .2s, box-shadow .2s;
        position: relative; overflow: hidden;
    }
    .svl-shell .svl-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.06); }
    .svl-shell .svl-card .svl-card-ico {
        position: absolute; right: 16px; top: 16px; width: 36px; height: 36px;
        border-radius: 10px; display:flex; align-items:center; justify-content:center;
        font-size: 18px; opacity: .9;
    }
    .svl-shell .svl-card .svl-card-label { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 8px; font-weight: 600; }
    .svl-shell .svl-card .svl-card-value { font-size: 30px; font-weight: 700; color: var(--text); line-height: 1.1; word-break: break-word; }
    .svl-shell .svl-card .svl-card-sub { font-size: 12px; color: var(--muted); margin-top: 6px; }
    .svl-shell .svl-card.c-orange .svl-card-ico { background:#fff3e6; color:#c2570b; }
    .svl-shell .svl-card.c-blue .svl-card-ico   { background:#e7f1fb; color:#2271b1; }
    .svl-shell .svl-card.c-green .svl-card-ico  { background:#e6f7ec; color:#007e21; }
    .svl-shell .svl-card.c-purple .svl-card-ico { background:#f1ebfe; color:#5b2dd6; }

    .svl-shell .svl-panel {
        background: var(--card); border: 1px solid var(--br); border-radius: 12px;
        padding: 22px 24px; margin: 0 0 24px;
    }
    .svl-shell .svl-panel h2 { margin: 0 0 4px; font-size: 18px; display: flex; align-items: center; gap: 8px; }
    .svl-shell .svl-panel .svl-panel-desc { color: var(--muted); font-size: 13px; margin: 0 0 16px; }

    .svl-shell .svl-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .svl-shell .svl-table th, .svl-shell .svl-table td { padding: 12px 14px; text-align: left; }
    .svl-shell .svl-table thead th { background: var(--soft); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); border-bottom: 1px solid var(--br); }
    .svl-shell .svl-table thead th:first-child { border-top-left-radius: 8px; }
    .svl-shell .svl-table thead th:last-child  { border-top-right-radius: 8px; }
    .svl-shell .svl-table tbody tr:hover { background: var(--soft); }
    .svl-shell .svl-table tbody td { border-bottom: 1px solid var(--br); }

    .svl-shell .svl-bar-wrap { background: #eef0f4; border-radius: 99px; height: 8px; overflow: hidden; }
    .svl-shell .svl-bar { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--p), var(--p2)); transition: width .8s ease; }

    .svl-shell .svl-pill {
        display: inline-flex; padding: 4px 12px; background: #fff7ed; border: 1px solid #fed7aa;
        border-radius: 99px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 13px; color: #9a3412;
    }
    .svl-shell .svl-rank {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 28px; height: 28px; border-radius: 50%; background: #eef0f4; color: #4b5563;
        font-weight: 700; font-size: 12px;
    }
    .svl-shell .svl-rank.r1 { background: linear-gradient(135deg, #fde68a, #f59e0b); color: #78350f; }
    .svl-shell .svl-rank.r2 { background: linear-gradient(135deg, #e5e7eb, #9ca3af); color: #1f2937; }
    .svl-shell .svl-rank.r3 { background: linear-gradient(135deg, #fed7aa, #ea580c); color: #7c2d12; }

    .svl-shell .svl-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 500;
        border: 1px solid var(--br); background: #fff; color: var(--text); cursor: pointer;
        text-decoration: none; transition: all .15s;
    }
    .svl-shell .svl-btn:hover { background: var(--soft); border-color: #d1d5db; }
    .svl-shell .svl-btn.svl-btn-primary { background: linear-gradient(135deg, var(--p), var(--p2)); border: 0; color: #fff; }
    .svl-shell .svl-btn.svl-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(224,122,31,.3); }
    .svl-shell .svl-btn.svl-btn-danger { color: var(--r); }
    .svl-shell .svl-btn.svl-btn-danger:hover { background: #fef2f2; border-color: #fca5a5; }

    .svl-shell .svl-empty { text-align: center; padding: 60px 20px; color: var(--muted); }
    .svl-shell .svl-empty-ico { font-size: 48px; margin-bottom: 12px; opacity: .4; }

    /* Settings page */
    .svl-shell .svl-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media (max-width: 900px) { .svl-shell .svl-grid-2 { grid-template-columns: 1fr; } }
    .svl-shell .svl-field { margin-bottom: 22px; }
    .svl-shell .svl-field label.svl-lbl { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; color: var(--text); }
    .svl-shell .svl-field input[type=text], .svl-shell .svl-field input[type=url], .svl-shell .svl-field input[type=number], .svl-shell .svl-field textarea {
        width: 100%; padding: 10px 14px; border: 1px solid var(--br); border-radius: 8px;
        font-size: 14px; transition: border-color .15s, box-shadow .15s; background: #fff;
    }
    .svl-shell .svl-field input:focus, .svl-shell .svl-field textarea:focus {
        outline: 0; border-color: var(--b); box-shadow: 0 0 0 3px rgba(34,113,177,.15);
    }
    .svl-shell .svl-field .svl-help { font-size: 12px; color: var(--muted); margin-top: 6px; line-height: 1.5; }
    .svl-shell .svl-field .svl-help code { background: var(--soft); padding: 1px 6px; border-radius: 3px; font-size: 11px; }
    .svl-shell .svl-img-pick { display: flex; gap: 8px; align-items: flex-start; }
    .svl-shell .svl-img-pick input { flex: 1; }
    .svl-shell .svl-img-pick-prev img { max-width: 220px; height: auto; border-radius: 8px; border: 1px solid var(--br); margin-top: 10px; display: block; }

    /* SEO settings page унификация */
    .svl-set { max-width: 1100px !important; }
    .svl-set h1 { font-size: 26px !important; }
    .svl-set .nav-tab-wrapper { border-bottom: 0; margin-bottom: 0 !important; gap: 4px; display: flex; padding: 0; background: var(--card); border-radius: 12px 12px 0 0; padding: 8px 8px 0; border: 1px solid var(--br); border-bottom: 0; }
    .svl-set .nav-tab { background: transparent; border: 0 !important; border-radius: 8px 8px 0 0; padding: 10px 16px; font-weight: 500; color: var(--muted); margin: 0 !important; }
    .svl-set .nav-tab:hover { background: var(--soft); color: var(--text); }
    .svl-set .nav-tab-active { background: var(--soft) !important; color: var(--p) !important; box-shadow: inset 0 -3px 0 var(--p); }
    .svl-set .svl-section { border-radius: 0 12px 12px 12px !important; border: 1px solid var(--br) !important; }
    .svl-set .svl-submit { border-radius: 0 0 12px 12px !important; border: 1px solid var(--br) !important; border-top: 0 !important; margin-top: -1px; background: var(--soft) !important; }

    /* Дашборд-виджет */
    #svl_dashboard_widget .svl-mini { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
    #svl_dashboard_widget .svl-mini > div { background: var(--soft); padding: 12px; border-radius: 8px; }
    #svl_dashboard_widget .svl-mini .v { font-size: 22px; font-weight: 700; color: var(--text); }
    #svl_dashboard_widget .svl-mini .l { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 4px; }
    ';
    wp_register_style('svl-admin', false);
    wp_enqueue_style('svl-admin');
    wp_add_inline_style('svl-admin', $css);
}

// Применяем класс svl-shell к .wrap всех наших страниц
add_action('admin_head', 'svl_admin_class');
function svl_admin_class() {
    if (!isset($_GET['page'])) return;
    if (!in_array($_GET['page'], array('svl-stats', 'svl-settings', 'svl-seo', 'svl-magic'), true)) return;
    echo '<style>body.toplevel_page_svl-stats #wpbody-content > .wrap, body[class*="svl-"] #wpbody-content > .wrap { padding-right: 0; } </style>';
    echo '<script>document.addEventListener("DOMContentLoaded", function(){ var w=document.querySelector("#wpbody-content > .wrap"); if(w) w.classList.add("svl-shell"); });</script>';
}

// ==========================================
// 2. СТРАНИЦА СТАТИСТИКИ
// ==========================================

function svl_scan_shortcode_mappings() {
    global $wpdb;

    $statuses = array('publish','draft','private','future','pending');
    $types_excluded = array('revision','attachment','nav_menu_item');

    // 1) Все посты, упоминающие [vip_locker (включая wp_block — reusable blocks)
    $rows = $wpdb->get_results("
        SELECT ID, post_content, post_type FROM {$wpdb->posts}
        WHERE post_status IN ('publish','draft','private','future','pending','inherit')
          AND post_type NOT IN ('revision','attachment','nav_menu_item')
          AND post_content LIKE '%[vip_locker%'
    ");

    $map = array();
    $reusable_codes = array(); // ref_block_id => array(codes)

    // Используем официальный WP shortcode parser — он надёжнее regex
    $pattern = get_shortcode_regex(array('vip_locker'));

    $extract_codes = function ($content) use ($pattern) {
        $codes = array();
        // Декодируем HTML-сущности (на случай если редактор сохранил &quot; вместо ")
        $variants = array($content, html_entity_decode($content, ENT_QUOTES | ENT_HTML5));
        foreach ($variants as $variant) {
            if (preg_match_all('/' . $pattern . '/', $variant, $matches)) {
                if (!empty($matches[3])) {
                    foreach ($matches[3] as $attr_str) {
                        $atts = shortcode_parse_atts($attr_str);
                        if (!empty($atts['code'])) {
                            $code = trim($atts['code']);
                            if ($code !== '') $codes[$code] = true;
                        }
                    }
                }
            }
        }
        // Fallback regex — если стандартный парсер не сработал
        if (empty($codes)) {
            foreach ($variants as $variant) {
                if (preg_match_all('/\[vip_locker\b[^\]]*?\bcode\s*=\s*(?:(["\'])(.*?)\1|([^\s\]\'"]+))/iu', $variant, $m)) {
                    $list = array();
                    foreach ($m[2] as $i => $v) {
                        $list[] = $v !== '' ? $v : ($m[3][$i] ?? '');
                    }
                    foreach ($list as $code) {
                        $code = trim($code);
                        if ($code !== '') $codes[$code] = true;
                    }
                }
            }
        }
        return array_keys($codes);
    };

    foreach ($rows as $r) {
        $codes = $extract_codes($r->post_content);
        if (empty($codes)) continue;

        if ($r->post_type === 'wp_block') {
            // Reusable block — запоминаем коды, привяжем к статьям-референтам ниже
            $reusable_codes[(int) $r->ID] = $codes;
        } else {
            foreach ($codes as $code) {
                if (!isset($map[$code])) $map[$code] = array();
                if (!in_array($r->ID, $map[$code], true)) $map[$code][] = $r->ID;
            }
        }
    }

    // 2) Reusable blocks: ищем все посты, использующие эти блоки через "ref":N
    if (!empty($reusable_codes)) {
        foreach ($reusable_codes as $block_id => $codes) {
            $like1 = '%"ref":' . intval($block_id) . '%';
            $like2 = '%"ref":' . intval($block_id) . ',%';
            $referrers = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status IN ('publish','draft','private','future','pending')
                   AND post_type NOT IN ('revision','attachment','nav_menu_item','wp_block')
                   AND (post_content LIKE %s OR post_content LIKE %s)",
                $like1, $like2
            ));
            foreach ($referrers as $rid) {
                $rid = (int) $rid;
                foreach ($codes as $code) {
                    if (!isset($map[$code])) $map[$code] = array();
                    if (!in_array($rid, $map[$code], true)) $map[$code][] = $rid;
                }
            }
        }
    }

    // 3) Применяем кап на 50 статей на код
    foreach ($map as $code => $arr) {
        if (count($arr) > 50) $map[$code] = array_slice($arr, -50);
    }

    update_option('svl_code_posts', $map, false);
    update_option('svl_code_posts_scanned_at', time(), false);
    return $map;
}

function svl_stats_page_html() {
    if (
        isset($_POST['svl_reset_stats']) &&
        current_user_can('manage_options') &&
        check_admin_referer('svl_reset_stats_action', 'svl_reset_stats_nonce')
    ) {
        update_option('svl_locker_stats', array());
        update_option('svl_failed_attempts', array());
        update_option('svl_code_posts', array());
        echo '<div class="notice notice-success is-dismissible"><p>Статистика сброшена.</p></div>';
    }
    if (
        isset($_POST['svl_reset_fails']) &&
        current_user_can('manage_options') &&
        check_admin_referer('svl_reset_fails_action', 'svl_reset_fails_nonce')
    ) {
        update_option('svl_failed_attempts', array());
        echo '<div class="notice notice-success is-dismissible"><p>Лог неудачных попыток очищен.</p></div>';
    }
    if (
        isset($_POST['svl_rescan']) &&
        current_user_can('manage_options') &&
        check_admin_referer('svl_rescan_action', 'svl_rescan_nonce')
    ) {
        $m = svl_scan_shortcode_mappings();
        $found_codes = count($m);
        $found_posts = 0;
        foreach ($m as $arr) $found_posts += count($arr);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Сканирование завершено: <strong>' . intval($found_codes) . '</strong> кодов в <strong>' . intval($found_posts) . '</strong> привязках.</p></div>';
    }

    // Авто-скан при первой загрузке: если есть статистика, но пустой маппинг — сканируем
    $existing_map = get_option('svl_code_posts', array());
    $existing_stats = get_option('svl_locker_stats', array());
    if (!empty($existing_stats) && empty($existing_map) && !get_option('svl_code_posts_scanned_at')) {
        svl_scan_shortcode_mappings();
        echo '<div class="notice notice-info is-dismissible"><p>🔄 Привязки кодов к статьям автоматически просканированы. Нажмите «Пересканировать» если добавили новые шорткоды.</p></div>';
    }

    $stats = get_option('svl_locker_stats', array());
    arsort($stats);
    $total_unlocks = array_sum(array_map('intval', $stats));
    $unique_codes  = count($stats);
    $top_code      = $unique_codes ? key($stats) : '';
    $top_count     = $unique_codes ? intval(reset($stats)) : 0;
    $max_count     = $top_count > 0 ? $top_count : 1;
    $avg = $unique_codes ? round($total_unlocks / $unique_codes, 1) : 0;

    // ----- Загружаем привязки и неудачные попытки -----
    $code_posts = get_option('svl_code_posts', array());
    $all_fails  = get_option('svl_failed_attempts', array());
    $total_fails = count($all_fails);

    // Группируем фейлы по коду + последние 50 на код
    $fails_by_code = array();
    foreach ($all_fails as $f) {
        if (empty($f['c'])) continue;
        $fails_by_code[$f['c']][] = $f;
    }
    foreach ($fails_by_code as $c => &$arr) {
        usort($arr, function($a, $b){ return $b['t'] - $a['t']; });
        $arr = array_slice($arr, 0, 50);
    }
    unset($arr);

    // Хелпер: классификатор причины ошибки
    $classify_fail = function($attempted, $code) {
        $a = mb_strtolower($attempted);
        $c = mb_strtolower($code);
        if ($a === '') return array('label' => 'Пустое поле', 'class' => 'r-empty');
        // Используем побайтный levenshtein — корректно работает на ASCII; для UTF-8 даст приближённую оценку
        $dist = function_exists('levenshtein') ? @levenshtein($a, $c) : abs(mb_strlen($a) - mb_strlen($c));
        if ($dist === 0) return array('label' => 'Регистр/пробелы', 'class' => 'r-case');
        if ($dist <= 2) return array('label' => 'Похоже на опечатку', 'class' => 'r-typo');
        if (mb_strlen($a) < mb_strlen($c) * 0.5) return array('label' => 'Короткий ввод', 'class' => 'r-short');
        return array('label' => 'Совершенно другой код', 'class' => 'r-other');
    };

    // Готовим данные для каждого кода (для JSON в data-attr)
    $modal_data = array();
    foreach ($stats as $code => $cnt) {
        $posts_meta = array();
        if (!empty($code_posts[$code]) && is_array($code_posts[$code])) {
            foreach ($code_posts[$code] as $pid) {
                $p = get_post($pid);
                if (!$p) continue;
                $posts_meta[] = array(
                    'id'    => $pid,
                    'title' => get_the_title($p) ?: ('#' . $pid),
                    'url'   => get_permalink($p),
                    'edit'  => get_edit_post_link($pid, ''),
                    'status'=> $p->post_status,
                    'thumb' => get_the_post_thumbnail_url($p, array(80, 80)),
                );
            }
        }
        $fails_meta = array();
        if (!empty($fails_by_code[$code])) {
            foreach ($fails_by_code[$code] as $f) {
                $cls = $classify_fail($f['a'], $code);
                $p = $f['p'] ? get_post($f['p']) : null;
                $fails_meta[] = array(
                    'time'      => $f['t'],
                    'time_str'  => date_i18n('Y-m-d H:i', $f['t']),
                    'attempted' => $f['a'],
                    'reason'    => $cls['label'],
                    'reason_cls'=> $cls['class'],
                    'post_title'=> $p ? get_the_title($p) : '',
                    'post_url'  => $p ? get_permalink($p) : '',
                );
            }
        }
        $referers_for_code  = function_exists('svl_pro_referer_breakdown') ? svl_pro_referer_breakdown($code) : array();
        $countries_for_code = function_exists('svl_geo_breakdown')         ? svl_geo_breakdown($code)         : array();
        $modal_data[$code] = array(
            'code'      => $code,
            'success'   => intval($cnt),
            'fails'     => count($fails_by_code[$code] ?? array()),
            'posts'     => $posts_meta,
            'attempts'  => $fails_meta,
            'referers'  => $referers_for_code,
            'countries' => $countries_for_code,
        );
    }
    ?>
    <div class="wrap">
        <div class="svl-hero">
            <h1>📊 Статистика VIP Locker</h1>
            <p>Сколько раз ваши читатели разблокировали закрытый контент. Используйте эти данные, чтобы понять, какие коды раздаёте чаще, и где есть утечки.</p>
            <div class="svl-hero-meta">
                <span>🔒 Версия плагина: <strong><?php echo esc_html(SVL_VERSION); ?></strong></span>
                <span>⚙️ <a href="<?php echo esc_url(admin_url('admin.php?page=svl-settings')); ?>" style="color:#fff; text-decoration:underline;">Настройки замка</a></span>
                <span>🔍 <a href="<?php echo esc_url(admin_url('admin.php?page=svl-seo')); ?>" style="color:#fff; text-decoration:underline;">SEO-настройки</a></span>
            </div>
        </div>

        <div class="svl-cards">
            <div class="svl-card c-orange">
                <div class="svl-card-ico">🔓</div>
                <div class="svl-card-label">Всего разблокировок</div>
                <div class="svl-card-value"><?php echo number_format_i18n($total_unlocks); ?></div>
                <div class="svl-card-sub">успешных вводов кода</div>
            </div>
            <div class="svl-card c-blue">
                <div class="svl-card-ico">🔑</div>
                <div class="svl-card-label">Уникальных кодов</div>
                <div class="svl-card-value"><?php echo number_format_i18n($unique_codes); ?></div>
                <div class="svl-card-sub">разных кодов использовали</div>
            </div>
            <div class="svl-card c-purple">
                <div class="svl-card-ico">❌</div>
                <div class="svl-card-label">Неудачных попыток</div>
                <div class="svl-card-value"><?php echo number_format_i18n($total_fails); ?></div>
                <div class="svl-card-sub">всего неверных вводов</div>
            </div>
            <div class="svl-card c-green">
                <div class="svl-card-ico">🏆</div>
                <div class="svl-card-label">Топ-код</div>
                <div class="svl-card-value" style="font-size:22px;">
                    <?php if ($top_code !== ''): ?>
                        <?php echo esc_html($top_code); ?>
                    <?php else: ?>
                        <span style="color:var(--muted);">—</span>
                    <?php endif; ?>
                </div>
                <div class="svl-card-sub"><?php echo $top_code ? number_format_i18n($top_count) . ' вводов' : 'нет данных'; ?></div>
            </div>
        </div>

        <?php if (function_exists('svl_pro_timeseries')):
            $series = svl_pro_timeseries(30);
            $max_y = 0; foreach ($series as $d) $max_y = max($max_y, $d['s'], $d['f']);
        ?>
        <div class="svl-panel">
            <h2>📈 Активность за 30 дней</h2>
            <p class="svl-panel-desc">Разблокировки <strong style="color:#10b981;">●</strong> и неудачные попытки <strong style="color:#ef4444;">●</strong> по дням.</p>
            <?php if ($max_y > 0): ?>
            <div style="background:#fff; border:1px solid var(--br); border-radius:10px; padding:18px;">
                <canvas id="svl-chart" style="width:100%; height:220px; display:block;"></canvas>
                <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:11px; color:var(--muted); font-family:ui-monospace, monospace;" id="svl-chart-labels"></div>
            </div>
            <script>
            (function(){
                var data = <?php echo wp_json_encode(array_values($series), JSON_NUMERIC_CHECK); ?>;
                var labels = <?php echo wp_json_encode(array_keys($series)); ?>;
                var canvas = document.getElementById('svl-chart');
                var labelsEl = document.getElementById('svl-chart-labels');
                if (!canvas || !canvas.getContext) return;
                var ctx = canvas.getContext('2d');
                function draw(){
                    var W = canvas.clientWidth, H = canvas.clientHeight;
                    var dpr = window.devicePixelRatio || 1;
                    canvas.width = W * dpr; canvas.height = H * dpr;
                    ctx.scale(dpr, dpr);
                    ctx.clearRect(0,0,W,H);
                    var pad = 30;
                    var n = data.length;
                    var maxY = Math.max.apply(null, data.map(function(d){return Math.max(d.s||0, d.f||0);}));
                    if (maxY < 5) maxY = 5;
                    var stepX = (W - pad*2) / Math.max(1, n - 1);

                    // Горизонтальные линии сетки
                    ctx.strokeStyle = '#f0f0f4';
                    ctx.lineWidth = 1;
                    ctx.font = '10px ui-monospace, monospace';
                    ctx.fillStyle = '#9ca3af';
                    for (var g = 0; g <= 4; g++) {
                        var y = pad + (H - pad*2) * g / 4;
                        var v = Math.round(maxY - maxY * g / 4);
                        ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(W - pad/2, y); ctx.stroke();
                        ctx.fillText(v, 4, y + 3);
                    }

                    function pathLine(field, color, fillColor) {
                        ctx.beginPath();
                        for (var i = 0; i < n; i++) {
                            var x = pad + i * stepX;
                            var y = H - pad - (H - pad*2) * (data[i][field] / maxY);
                            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                        }
                        // Stroke line
                        ctx.strokeStyle = color; ctx.lineWidth = 2;
                        ctx.stroke();
                        // Fill area
                        ctx.lineTo(pad + (n-1)*stepX, H - pad);
                        ctx.lineTo(pad, H - pad);
                        ctx.closePath();
                        ctx.fillStyle = fillColor;
                        ctx.fill();
                        // Dots
                        for (var j = 0; j < n; j++) {
                            var dx = pad + j * stepX;
                            var dy = H - pad - (H - pad*2) * (data[j][field] / maxY);
                            ctx.beginPath(); ctx.arc(dx, dy, 3, 0, Math.PI*2); ctx.fillStyle = color; ctx.fill();
                        }
                    }
                    pathLine('s', '#10b981', 'rgba(16,185,129,.12)');
                    pathLine('f', '#ef4444', 'rgba(239,68,68,.10)');
                }
                draw();
                window.addEventListener('resize', draw);

                // Подписи дат — каждые 5 дней
                var html = '';
                for (var i = 0; i < labels.length; i += 5) {
                    var d = labels[i].split('-');
                    html += '<span>' + d[2] + '.' + d[1] + '</span>';
                }
                html += '<span>' + (function(l){ var d = l.split('-'); return d[2] + '.' + d[1]; })(labels[labels.length-1]) + '</span>';
                labelsEl.innerHTML = html;
            })();
            </script>
            <?php else: ?>
                <div class="svl-empty" style="padding:30px;">
                    <p style="margin:0;">Активности за последние 30 дней пока нет.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="svl-panel">
            <h2>📋 Разбивка по кодам</h2>
            <p class="svl-panel-desc">Все коды, отсортированные по убыванию популярности. <strong>Кликните на код</strong>, чтобы увидеть к какой статье он привязан и какие были неудачные попытки ввода.</p>

            <?php if (!empty($stats)): ?>
            <table class="svl-table svl-stats-table">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th style="width:180px;">Код</th>
                        <th>Привязано к статье</th>
                        <th style="width:110px;">Вводов</th>
                        <th style="width:110px;">Ошибок</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 0; foreach ($stats as $code => $count): $i++;
                        $rclass = $i <= 3 ? ' r' . $i : '';
                        $code_fails = isset($fails_by_code[$code]) ? count($fails_by_code[$code]) : 0;
                        $posts_for_code = isset($code_posts[$code]) && is_array($code_posts[$code]) ? $code_posts[$code] : array();
                    ?>
                        <tr>
                            <td><span class="svl-rank<?php echo esc_attr($rclass); ?>"><?php echo intval($i); ?></span></td>
                            <td>
                                <button type="button" class="svl-pill svl-code-btn" data-code="<?php echo esc_attr($code); ?>" title="Подробности по коду" style="border:0; cursor:pointer;">
                                    <?php echo esc_html($code); ?>
                                    <span style="margin-left:6px; opacity:.6; font-size:11px;">▶</span>
                                </button>
                            </td>
                            <td>
                                <?php if (!empty($posts_for_code)): ?>
                                    <div class="svl-post-list">
                                    <?php $shown = 0; foreach ($posts_for_code as $pid):
                                        if ($shown >= 3) break;
                                        $title = get_the_title($pid); if (!$title) continue;
                                        $thumb = get_the_post_thumbnail_url($pid, array(80, 80));
                                        $edit_url = get_edit_post_link($pid, '');
                                        $shown++; ?>
                                        <a href="<?php echo esc_url($edit_url); ?>" target="_blank" class="svl-post-link">
                                            <?php if ($thumb): ?>
                                                <img src="<?php echo esc_url($thumb); ?>" class="svl-post-thumb" alt="" loading="lazy">
                                            <?php else: ?>
                                                <div class="svl-post-thumb svl-no-thumb">📄</div>
                                            <?php endif; ?>
                                            <span class="svl-post-title"><?php echo esc_html($title); ?></span>
                                        </a>
                                    <?php endforeach;
                                    $more = count($posts_for_code) - $shown;
                                    if ($more > 0): ?>
                                        <span class="svl-post-more">+ ещё <?php echo intval($more); ?></span>
                                    <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--muted); font-style:italic;">— не найдено —</span>
                                <?php endif; ?>
                            </td>
                            <td><strong style="font-size:15px;"><?php echo number_format_i18n(intval($count)); ?></strong></td>
                            <td>
                                <?php if ($code_fails > 0): ?>
                                    <span style="display:inline-flex; align-items:center; gap:4px; background:#fef2f2; color:#b91c1c; padding:2px 8px; border-radius:99px; font-size:12px; font-weight:600;">❌ <?php echo number_format_i18n($code_fails); ?></span>
                                <?php else: ?>
                                    <span style="color:var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:18px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <form method="post" style="margin:0;">
                    <?php wp_nonce_field('svl_rescan_action', 'svl_rescan_nonce'); ?>
                    <button type="submit" name="svl_rescan" value="1" class="svl-btn svl-btn-primary">🔄 Пересканировать привязки</button>
                </form>
                <?php
                $scanned_at = (int) get_option('svl_code_posts_scanned_at', 0);
                if ($scanned_at): ?>
                    <span style="color:var(--muted); font-size:12px;">Последнее сканирование: <?php echo esc_html(human_time_diff($scanned_at, time())); ?> назад</span>
                <?php endif; ?>
                <span style="flex:1;"></span>
                <?php if ($total_fails > 0): ?>
                <form method="post" onsubmit="return confirm('Очистить только лог неудачных попыток?');" style="margin:0;">
                    <?php wp_nonce_field('svl_reset_fails_action', 'svl_reset_fails_nonce'); ?>
                    <button type="submit" name="svl_reset_fails" value="1" class="svl-btn">🧹 Очистить лог попыток</button>
                </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Сбросить ВСЮ статистику? Это действие нельзя отменить.');" style="margin:0;">
                    <?php wp_nonce_field('svl_reset_stats_action', 'svl_reset_stats_nonce'); ?>
                    <button type="submit" name="svl_reset_stats" value="1" class="svl-btn svl-btn-danger">🗑 Сбросить всё</button>
                </form>
            </div>
            <?php else: ?>
                <div class="svl-empty">
                    <div class="svl-empty-ico">📭</div>
                    <p style="font-size:16px; margin-bottom:6px;">Пока никто не разблокировал контент</p>
                    <p>Добавьте шорткод <code style="background:#fff7ed; padding:2px 8px; border-radius:4px; color:#c2410c;">[vip_locker code="ВАШ_КОД"]контент[/vip_locker]</code> в любую запись.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_fails > 0):
            // ============ Аналитика ошибок ============
            $now = time();
            $day  = $now - DAY_IN_SECONDS;
            $week = $now - 7 * DAY_IN_SECONDS;
            $fails_24h = 0; $fails_7d = 0;
            $reason_counts = array('r-empty' => 0, 'r-case' => 0, 'r-typo' => 0, 'r-short' => 0, 'r-other' => 0);
            $reason_labels = array(
                'r-empty' => 'Пустое поле',
                'r-case'  => 'Регистр/пробелы',
                'r-typo'  => 'Похоже на опечатку',
                'r-short' => 'Слишком короткий ввод',
                'r-other' => 'Совершенно другой код',
            );
            $reason_colors = array(
                'r-empty' => '#9ca3af',
                'r-case'  => '#3b82f6',
                'r-typo'  => '#f59e0b',
                'r-short' => '#6366f1',
                'r-other' => '#dc2626',
            );
            $top_attempted = array();
            $fails_per_code = array();
            foreach ($all_fails as $f) {
                if ($f['t'] >= $day)  $fails_24h++;
                if ($f['t'] >= $week) $fails_7d++;
                $cls = $classify_fail($f['a'], $f['c']);
                if (isset($reason_counts[$cls['class']])) $reason_counts[$cls['class']]++;
                $key = mb_strtolower($f['a']);
                if (!isset($top_attempted[$key])) $top_attempted[$key] = array('val' => $f['a'], 'n' => 0);
                $top_attempted[$key]['n']++;
                $fails_per_code[$f['c']] = ($fails_per_code[$f['c']] ?? 0) + 1;
            }
            uasort($top_attempted, function($a, $b){ return $b['n'] - $a['n']; });
            arsort($fails_per_code);
            $max_reason = max($reason_counts) ?: 1;
        ?>
        <div class="svl-panel">
            <h2>🔍 Анализ ошибок и проблем</h2>
            <p class="svl-panel-desc">Что именно пытаются вводить пользователи и где возникают трудности.</p>

            <div class="svl-cards" style="margin-bottom: 24px;">
                <div class="svl-card c-purple">
                    <div class="svl-card-ico">📅</div>
                    <div class="svl-card-label">Ошибок за 24 часа</div>
                    <div class="svl-card-value"><?php echo number_format_i18n($fails_24h); ?></div>
                </div>
                <div class="svl-card c-blue">
                    <div class="svl-card-ico">📊</div>
                    <div class="svl-card-label">Ошибок за 7 дней</div>
                    <div class="svl-card-value"><?php echo number_format_i18n($fails_7d); ?></div>
                </div>
                <div class="svl-card c-orange">
                    <div class="svl-card-ico">⚠️</div>
                    <div class="svl-card-label">Самый «сложный» код</div>
                    <?php
                    $hardest_code = '';
                    $hardest_n = 0;
                    foreach ($fails_per_code as $c => $n) { $hardest_code = $c; $hardest_n = $n; break; }
                    ?>
                    <div class="svl-card-value" style="font-size:18px;"><?php echo $hardest_code !== '' ? esc_html($hardest_code) : '—'; ?></div>
                    <div class="svl-card-sub"><?php echo $hardest_code !== '' ? number_format_i18n($hardest_n) . ' ошибок' : 'нет данных'; ?></div>
                </div>
                <div class="svl-card c-green">
                    <div class="svl-card-ico">🎯</div>
                    <div class="svl-card-label">Конверсия в успех</div>
                    <?php $conv = ($total_unlocks + $total_fails) > 0 ? round($total_unlocks * 100 / ($total_unlocks + $total_fails), 1) : 0; ?>
                    <div class="svl-card-value"><?php echo number_format_i18n($conv, 1); ?>%</div>
                    <div class="svl-card-sub">из всех попыток ввода</div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div>
                    <h4 style="font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); margin: 0 0 14px;">📈 Распределение по типам</h4>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($reason_counts as $cls => $cnt):
                            if ($cnt === 0) continue;
                            $pct = round($cnt * 100 / $max_reason);
                            $share = round($cnt * 100 / $total_fails, 1);
                        ?>
                        <div>
                            <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-size:13px;">
                                <span style="color:var(--text);"><?php echo esc_html($reason_labels[$cls]); ?></span>
                                <span style="color:var(--muted);"><strong style="color:var(--text);"><?php echo number_format_i18n($cnt); ?></strong> · <?php echo esc_html($share); ?>%</span>
                            </div>
                            <div class="svl-bar-wrap">
                                <div class="svl-bar" style="width: <?php echo intval($pct); ?>%; background: <?php echo esc_attr($reason_colors[$cls]); ?>;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h4 style="font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); margin: 0 0 14px;">🔥 Топ неверных вводов</h4>
                    <?php if (!empty($top_attempted)): ?>
                    <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; max-height:280px; overflow-y:auto;">
                        <?php $shown_top = 0; foreach ($top_attempted as $item):
                            if ($shown_top++ >= 10) break; ?>
                        <li style="display:flex; align-items:center; justify-content:space-between; gap:10px; background:#fafafa; padding:8px 12px; border-radius:8px;">
                            <code style="background:transparent; font-family: ui-monospace, monospace; color:#1d2327; font-size:13px; word-break:break-all;">«<?php echo esc_html(mb_strimwidth($item['val'], 0, 40, '…')); ?>»</code>
                            <span style="background:#fef2f2; color:#b91c1c; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:600; flex-shrink:0;"><?php echo number_format_i18n($item['n']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="font-size:12px; color:var(--muted); margin-top:10px;">💡 Если одно и то же значение часто пытаются ввести — возможно, в статье опечатка или непонятная подсказка.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ======= МОДАЛ: подробности по коду ======= -->
    <div id="svl-modal" class="svl-modal" aria-hidden="true">
        <div class="svl-modal-backdrop"></div>
        <div class="svl-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="svl-modal-title">
            <div class="svl-modal-head">
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--muted); margin-bottom:4px;">Код доступа</div>
                    <h3 id="svl-modal-title" style="margin:0; font-size:22px;"></h3>
                </div>
                <button type="button" class="svl-modal-close" aria-label="Закрыть">✕</button>
            </div>
            <div class="svl-modal-body" id="svl-modal-body"></div>
        </div>
    </div>

    <style>
    .svl-shell .svl-code-btn { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; transition: all .15s; }
    .svl-shell .svl-code-btn:hover { background:#ffedd5; border-color:#fb923c; transform: scale(1.03); }
    .svl-shell .svl-stats-table tbody tr { cursor: default; }
    .svl-shell .svl-stats-table tbody td { vertical-align: middle; }

    /* Список привязанных статей с миниатюрами */
    .svl-shell .svl-post-list { display: flex; flex-direction: column; gap: 8px; }
    .svl-shell .svl-post-link {
        display: flex; align-items: center; gap: 12px;
        text-decoration: none; color: var(--text);
        padding: 6px; margin: -6px; border-radius: 8px;
        transition: background .15s;
    }
    .svl-shell .svl-post-link:hover { background: var(--soft); }
    .svl-shell .svl-post-link:hover .svl-post-title { color: #2271b1; }
    .svl-shell .svl-post-thumb {
        width: 48px; height: 48px; border-radius: 8px;
        object-fit: cover; flex-shrink: 0;
        border: 1px solid var(--br); background: var(--soft);
    }
    .svl-shell .svl-no-thumb {
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: #9ca3af;
    }
    .svl-shell .svl-post-title { font-size: 13px; line-height: 1.4; font-weight: 500; flex: 1; }
    .svl-shell .svl-post-more {
        display: inline-block; font-size: 12px; color: var(--muted);
        padding: 4px 10px; background: var(--soft); border-radius: 99px; margin-left: 60px;
    }

    .svl-modal { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; }
    .svl-modal.open { display: flex; animation: svlFadeIn .15s ease; }
    .svl-modal-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.6); backdrop-filter: blur(4px); }
    .svl-modal-dialog {
        position: relative; background: #fff; border-radius: 16px;
        width: min(720px, 94vw); max-height: 88vh; overflow: hidden;
        display: flex; flex-direction: column;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,.35);
        animation: svlSlideUp .22s cubic-bezier(.16,1,.3,1);
    }
    .svl-modal-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 22px 24px 16px; border-bottom: 1px solid #e4e7ec; }
    .svl-modal-close {
        background: #f6f7fb; border: 0; width: 32px; height: 32px; border-radius: 50%;
        font-size: 16px; cursor: pointer; color: #6b7280; flex-shrink: 0;
        transition: background .15s, color .15s;
    }
    .svl-modal-close:hover { background: #fef2f2; color: #d63638; }
    .svl-modal-body { padding: 20px 24px 24px; overflow-y: auto; }

    .svl-modal-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px; }
    .svl-modal-stats > div { background: #f6f7fb; padding: 12px 14px; border-radius: 10px; }
    .svl-modal-stats .l { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; margin-bottom: 4px; }
    .svl-modal-stats .v { font-size: 20px; font-weight: 700; color: #1d2327; }
    .svl-modal-stats .v.green { color: #007e21; }
    .svl-modal-stats .v.red   { color: #b91c1c; }

    .svl-modal h4 { font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; margin: 0 0 10px; font-weight: 600; }

    .svl-posts-list { list-style: none; margin: 0 0 20px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .svl-posts-list li { background: #f6f7fb; padding: 10px 14px; border-radius: 10px; display: flex; align-items: center; gap: 12px; font-size: 13px; }
    .svl-mp-thumb { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; flex-shrink: 0; border: 1px solid #e4e7ec; background: #fff; }
    .svl-mp-nothumb { display: flex; align-items: center; justify-content: center; font-size: 24px; color: #9ca3af; }
    .svl-posts-list li a { color: #1d2327; text-decoration: none; font-weight: 500; }
    .svl-posts-list li a:hover { color: #2271b1; }
    .svl-posts-list .svl-post-status { font-size: 11px; padding: 2px 8px; border-radius: 99px; background: #e6f7ec; color: #007e21; }
    .svl-posts-list .svl-post-status.draft { background: #fef3c7; color: #92400e; }
    .svl-posts-list .svl-actions a { font-size: 11px; padding: 4px 8px; background: #fff; border: 1px solid #e4e7ec; border-radius: 6px; margin-left: 6px; color: #4b5563 !important; }
    .svl-posts-list .svl-actions a:hover { background: #2271b1; color: #fff !important; border-color: #2271b1; }

    .svl-attempts-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; max-height: 320px; overflow-y: auto; }
    .svl-attempts-list li {
        display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center;
        background: #fff; border: 1px solid #e4e7ec; padding: 10px 14px; border-radius: 8px; font-size: 13px;
    }
    .svl-attempts-list .at-time { color: #6b7280; font-size: 12px; font-family: ui-monospace, monospace; min-width: 110px; }
    .svl-attempts-list .at-val { font-family: ui-monospace, monospace; color: #1d2327; word-break: break-all; }
    .svl-attempts-list .at-val .at-arrow { color: #d1d5db; margin: 0 6px; }
    .svl-attempts-list .at-val .at-correct { color: #00a32a; }
    .svl-attempts-list .at-reason {
        font-size: 11px; padding: 3px 10px; border-radius: 99px; font-weight: 600; white-space: nowrap;
    }
    .svl-attempts-list .at-reason.r-empty  { background: #f3f4f6; color: #4b5563; }
    .svl-attempts-list .at-reason.r-typo   { background: #fef3c7; color: #92400e; }
    .svl-attempts-list .at-reason.r-case   { background: #dbeafe; color: #1e40af; }
    .svl-attempts-list .at-reason.r-short  { background: #e0e7ff; color: #3730a3; }
    .svl-attempts-list .at-reason.r-other  { background: #fee2e2; color: #991b1b; }

    .svl-no-data { text-align: center; padding: 30px 20px; color: #6b7280; font-size: 13px; background: #fafafa; border-radius: 8px; border: 1px dashed #d1d5db; }

    .svl-geo-list { list-style: none; margin: 0 0 18px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .svl-geo-list li { display: grid; grid-template-columns: 24px 130px 1fr auto; gap: 10px; align-items: center; background: #fff; border: 1px solid #e4e7ec; padding: 8px 12px; border-radius: 8px; font-size: 13px; }
    .svl-geo-flag { font-size: 18px; }
    .svl-geo-name { font-weight: 500; color: #1d2327; }
    .svl-geo-bar { background: #f0f0f4; border-radius: 99px; height: 6px; overflow: hidden; }
    .svl-geo-bar > div { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #6366f1, #8b5cf6); transition: width .8s; }
    .svl-geo-cnt { color: #6b7280; font-size: 12px; min-width: 80px; text-align: right; }
    .svl-geo-cnt strong { color: #1d2327; }

    .svl-ref-list { list-style: none; margin: 0 0 18px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .svl-ref-list li { display: grid; grid-template-columns: 12px 130px 1fr auto; gap: 10px; align-items: center; background: #fff; border: 1px solid #e4e7ec; padding: 8px 12px; border-radius: 8px; font-size: 13px; }
    .svl-ref-dot { width: 10px; height: 10px; border-radius: 50%; }
    .svl-ref-name { font-weight: 500; color: #1d2327; }
    .svl-ref-bar { background: #f0f0f4; border-radius: 99px; height: 6px; overflow: hidden; }
    .svl-ref-bar > div { height: 100%; border-radius: 99px; transition: width .8s; }
    .svl-ref-cnt { color: #6b7280; font-size: 12px; min-width: 80px; text-align: right; }
    .svl-ref-cnt strong { color: #1d2327; }

    @keyframes svlFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes svlSlideUp { from { opacity: 0; transform: translateY(20px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    </style>

    <script>
    (function(){
        var modalData = <?php echo wp_json_encode($modal_data, JSON_UNESCAPED_UNICODE); ?>;
        var modal = document.getElementById('svl-modal');
        var titleEl = document.getElementById('svl-modal-title');
        var bodyEl = document.getElementById('svl-modal-body');

        function escHtml(s){ return String(s).replace(/[&<>"']/g, function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }

        function open(code) {
            var d = modalData[code];
            if (!d) return;
            titleEl.textContent = code;
            var html = '';
            html += '<div class="svl-modal-stats">';
            html += '<div><div class="l">Успешных вводов</div><div class="v green">' + d.success + '</div></div>';
            html += '<div><div class="l">Неудачных попыток</div><div class="v red">' + d.fails + '</div></div>';
            html += '</div>';

            // Posts
            html += '<h4>📝 Привязано к статьям (' + d.posts.length + ')</h4>';
            if (d.posts.length) {
                html += '<ul class="svl-posts-list">';
                d.posts.forEach(function(p){
                    var statusCls = p.status === 'draft' ? ' draft' : '';
                    var statusLabel = p.status === 'publish' ? 'Опубликовано' : (p.status === 'draft' ? 'Черновик' : p.status);
                    var thumbHtml = p.thumb
                        ? '<img src="' + escHtml(p.thumb) + '" class="svl-mp-thumb" alt="">'
                        : '<div class="svl-mp-thumb svl-mp-nothumb">📄</div>';
                    html += '<li>';
                    html += thumbHtml;
                    html += '<div style="flex:1; min-width:0;">';
                    html +=   '<a href="' + escHtml(p.url) + '" target="_blank" style="display:block; font-weight:500; line-height:1.4;">' + escHtml(p.title) + '</a>';
                    html +=   '<span class="svl-post-status' + statusCls + '" style="margin-top:4px; display:inline-block;">' + escHtml(statusLabel) + '</span>';
                    html += '</div>';
                    html += '<div class="svl-actions" style="flex-shrink:0;">';
                    if (p.url)  html += '<a href="' + escHtml(p.url) + '" target="_blank">Открыть</a>';
                    if (p.edit) html += '<a href="' + escHtml(p.edit) + '" target="_blank">Редактировать</a>';
                    html += '</div>';
                    html += '</li>';
                });
                html += '</ul>';
            } else {
                html += '<div class="svl-no-data">Этот код пока не использовался ни на одной статье<br><span style="font-size:12px;">(возможно, статистика накопилась до того, как заработало отслеживание привязок)</span></div>';
            }

            // География
            if (d.countries && d.countries.length) {
                var totalC = d.countries.reduce(function(s,r){return s+r.n;}, 0);
                html += '<h4 style="margin-top:18px;">🌍 География (по странам)</h4>';
                html += '<ul class="svl-geo-list">';
                d.countries.forEach(function(c){
                    var pct = totalC ? Math.round(c.n * 100 / totalC) : 0;
                    html += '<li>';
                    html +=   '<span class="svl-geo-flag">' + escHtml(c.flag) + '</span>';
                    html +=   '<span class="svl-geo-name">' + escHtml(c.name) + '</span>';
                    html +=   '<div class="svl-geo-bar"><div style="width:' + pct + '%;"></div></div>';
                    html +=   '<span class="svl-geo-cnt"><strong>' + c.n + '</strong> · ' + pct + '%</span>';
                    html += '</li>';
                });
                html += '</ul>';
            }

            // Источники трафика
            if (d.referers && d.referers.length) {
                var totalRef = d.referers.reduce(function(s,r){return s+r.n;}, 0);
                html += '<h4 style="margin-top:18px;">🌐 Источники трафика</h4>';
                html += '<ul class="svl-ref-list">';
                d.referers.forEach(function(r){
                    var pct = totalRef ? Math.round(r.n * 100 / totalRef) : 0;
                    html += '<li>';
                    html +=   '<span class="svl-ref-dot" style="background:' + escHtml(r.color) + ';"></span>';
                    html +=   '<span class="svl-ref-name">' + escHtml(r.label) + '</span>';
                    html +=   '<div class="svl-ref-bar"><div style="width:' + pct + '%; background:' + escHtml(r.color) + ';"></div></div>';
                    html +=   '<span class="svl-ref-cnt"><strong>' + r.n + '</strong> · ' + pct + '%</span>';
                    html += '</li>';
                });
                html += '</ul>';
            }

            // Attempts
            html += '<h4 style="margin-top:18px;">❌ Последние неудачные попытки (' + d.attempts.length + ')</h4>';
            if (d.attempts.length) {
                html += '<ul class="svl-attempts-list">';
                d.attempts.forEach(function(a){
                    html += '<li>';
                    html += '<span class="at-time">' + escHtml(a.time_str) + '</span>';
                    html += '<span class="at-val">';
                    html +=   '<strong>«' + escHtml(a.attempted) + '»</strong>';
                    html +=   '<span class="at-arrow">→ должно быть</span>';
                    html +=   '<span class="at-correct">«' + escHtml(d.code) + '»</span>';
                    html += '</span>';
                    html += '<span class="at-reason ' + escHtml(a.reason_cls) + '">' + escHtml(a.reason) + '</span>';
                    html += '</li>';
                });
                html += '</ul>';
            } else {
                html += '<div class="svl-no-data">Неудачных попыток для этого кода не было 🎉</div>';
            }

            bodyEl.innerHTML = html;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.svl-code-btn').forEach(function(btn){
            btn.addEventListener('click', function(){ open(btn.getAttribute('data-code')); });
        });
        modal.querySelector('.svl-modal-backdrop').addEventListener('click', close);
        modal.querySelector('.svl-modal-close').addEventListener('click', close);
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.classList.contains('open')) close(); });
    })();
    </script>
    <?php
}

// ==========================================
// 3. СТРАНИЦА НАСТРОЕК
// ==========================================

function svl_settings_page_html() {
    if (!current_user_can('manage_options')) wp_die();

    if (isset($_POST['svl_settings_save']) && check_admin_referer('svl_settings', 'svl_settings_nonce')) {
        update_option('svl_telegram_url',    esc_url_raw(wp_unslash($_POST['svl_telegram_url'] ?? '')));
        update_option('svl_boosty_url',      esc_url_raw(wp_unslash($_POST['svl_boosty_url'] ?? '')));
        update_option('svl_default_code',    sanitize_text_field(wp_unslash($_POST['svl_default_code'] ?? '')));
        update_option('svl_default_message', sanitize_textarea_field(wp_unslash($_POST['svl_default_message'] ?? '')));
        update_option('svl_default_banner',  esc_url_raw(wp_unslash($_POST['svl_default_banner'] ?? '')));
        update_option('svl_cookie_days',     max(1, min(365, intval($_POST['svl_cookie_days'] ?? 7))));
        $theme_in = sanitize_text_field(wp_unslash($_POST['svl_theme'] ?? 'cream'));
        $valid = array_keys(svl_pro_themes());
        update_option('svl_theme', in_array($theme_in, $valid, true) ? $theme_in : 'cream');
        update_option('svl_garland_enabled', !empty($_POST['svl_garland_enabled']) ? 1 : 0);
        update_option('svl_geo_external',    !empty($_POST['svl_geo_external']) ? 1 : 0);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Настройки сохранены.</p></div>';
    }
    ?>
    <div class="wrap">
        <div class="svl-hero">
            <h1>⚙️ Настройки VIP Locker</h1>
            <p>Здесь задаются значения по умолчанию для всех ваших шорткодов. Каждый из них можно переопределить в конкретном <code style="background:rgba(255,255,255,.15); padding:2px 6px; border-radius:4px; color:#fff;">[vip_locker]</code>.</p>
        </div>

        <form method="post">
            <?php wp_nonce_field('svl_settings', 'svl_settings_nonce'); ?>

            <div class="svl-grid-2">
                <div class="svl-panel">
                    <h2>🔗 Ссылки на подписки</h2>
                    <p class="svl-panel-desc">Кнопки в выпадающем меню «Оплатить подписку» внутри замка.</p>

                    <div class="svl-field">
                        <label class="svl-lbl" for="svl_telegram_url">✈️ Telegram (канал/бот)</label>
                        <input type="url" id="svl_telegram_url" name="svl_telegram_url" value="<?php echo esc_attr(svl_opt('svl_telegram_url')); ?>" placeholder="https://t.me/yourchannel">
                        <p class="svl-help">Ссылка на Telegram-канал или бота с приёмом платежей. Откроется в новой вкладке.</p>
                    </div>

                    <div class="svl-field">
                        <label class="svl-lbl" for="svl_boosty_url">🧡 Boosty / Patreon / другая платформа</label>
                        <input type="url" id="svl_boosty_url" name="svl_boosty_url" value="<?php echo esc_attr(svl_opt('svl_boosty_url')); ?>" placeholder="https://boosty.to/yourpage">
                        <p class="svl-help">Прямая ссылка на страницу автора на платформе подписок.</p>
                    </div>
                </div>

                <div class="svl-panel">
                    <h2>🔑 Параметры замка по умолчанию</h2>
                    <p class="svl-panel-desc">Используются если в шорткоде атрибут не задан.</p>

                    <div class="svl-field">
                        <label class="svl-lbl" for="svl_default_code">Код доступа по умолчанию</label>
                        <input type="text" id="svl_default_code" name="svl_default_code" value="<?php echo esc_attr(svl_opt('svl_default_code')); ?>" maxlength="64">
                        <p class="svl-help">Если в шорткоде не указан атрибут <code>code="..."</code>, будет использован этот код. Регистр не учитывается.</p>
                    </div>

                    <div class="svl-field">
                        <label class="svl-lbl" for="svl_cookie_days">Срок куки разблокировки (дней)</label>
                        <input type="number" id="svl_cookie_days" name="svl_cookie_days" value="<?php echo esc_attr(svl_opt('svl_cookie_days')); ?>" min="1" max="365">
                        <p class="svl-help">Сколько дней пользователь не вводит код повторно после успешной разблокировки. <strong>7</strong> — стандарт.</p>
                    </div>
                </div>
            </div>

            <div class="svl-panel">
                <h2>💬 Сообщение по умолчанию</h2>
                <p class="svl-panel-desc">Текст под баннером, объясняющий почему контент закрыт. Поддерживается обычный текст.</p>

                <div class="svl-field">
                    <textarea name="svl_default_message" rows="4" placeholder="Этот материал доступен подписчикам..."><?php echo esc_textarea(svl_opt('svl_default_message')); ?></textarea>
                    <p class="svl-help">💡 <strong>Совет:</strong> объясните, <em>что</em> получает подписчик и <em>почему</em> это ценно. Призыв к действию повышает конверсию.</p>
                </div>
            </div>

            <div class="svl-panel">
                <h2>🎨 Тема оформления замка</h2>
                <p class="svl-panel-desc">Выберите визуальный стиль формы ввода кода. Применяется ко всем шорткодам без явного атрибута <code>theme="..."</code>.</p>
                <div class="svl-field">
                    <div class="svl-themes-grid">
                        <?php $current_theme = svl_opt('svl_theme') ?: 'cream';
                        foreach (svl_pro_themes() as $key => $info): ?>
                        <label class="svl-theme-card<?php echo $current_theme === $key ? ' active' : ''; ?>">
                            <input type="radio" name="svl_theme" value="<?php echo esc_attr($key); ?>" <?php checked($current_theme, $key); ?>>
                            <div class="svl-theme-preview" style="background:<?php echo esc_attr($info['preview']); ?>;">
                                <div class="svl-theme-mock"></div>
                                <div class="svl-theme-mock-btn"></div>
                            </div>
                            <div class="svl-theme-name"><?php echo esc_html($info['name']); ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="svl-help">💡 Можно переопределить в каждом шорткоде: <code>[vip_locker theme="dark"]</code></p>
                </div>

                <div class="svl-field" style="margin-top:24px; padding-top:20px; border-top:1px solid var(--br);">
                    <label class="svl-lbl" style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="svl_garland_enabled" value="1" <?php checked(svl_opt('svl_garland_enabled'), 1); ?> style="width:18px; height:18px;">
                        <span>✨ Декоративная гирлянда под замком</span>
                    </label>
                    <p class="svl-help">
                        Добавляет анимированную гирлянду из звёзд под формой ввода кода. Создаёт праздничную атмосферу и привлекает внимание.<br>
                        🖼️ <strong>Своя картинка:</strong> сохраните файл <code>garland.png</code> (или .webp/.jpg) в папке плагина — будет использован вместо встроенной SVG-гирлянды.<br>
                        🎯 <strong>В шорткоде:</strong> отключить — <code>garland="0"</code>, своя картинка — <code>garland_url="https://..."</code>
                    </p>
                </div>

                <div class="svl-field" style="margin-top:24px; padding-top:20px; border-top:1px solid var(--br);">
                    <label class="svl-lbl" style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="svl_geo_external" value="1" <?php checked(svl_opt('svl_geo_external'), 1); ?> style="width:18px; height:18px;">
                        <span>🌍 Определять страну посетителя через ip-api.com</span>
                    </label>
                    <p class="svl-help">
                        Если ваш сайт <strong>за Cloudflare</strong> или другим CDN с гео-заголовками — оставьте <strong>выключенным</strong>: страна определится автоматически из заголовков, без внешних запросов.<br>
                        Если CDN нет — включите эту опцию: плагин будет делать запрос к ip-api.com (бесплатный, лимит 45 req/min). IP пользователя <strong>не сохраняется</strong>, только 2-буквенный код страны.<br>
                        📊 Распределение по странам показывается в модальном окне каждого кода в статистике.
                    </p>
                </div>
                <style>
                .svl-themes-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
                .svl-theme-card { display: block; border: 2px solid var(--br); border-radius: 12px; padding: 8px; cursor: pointer; transition: all .15s; background: #fff; }
                .svl-theme-card:hover { border-color: #c5d9ed; }
                .svl-theme-card.active { border-color: var(--p); box-shadow: 0 0 0 3px rgba(224,122,31,.15); }
                .svl-theme-card input { position: absolute; opacity: 0; }
                .svl-theme-preview { height: 80px; border-radius: 8px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; }
                .svl-theme-mock { height: 8px; background: rgba(0,0,0,.15); border-radius: 99px; width: 70%; }
                .svl-theme-mock-btn { height: 24px; background: rgba(0,0,0,.25); border-radius: 6px; width: 80px; align-self: flex-start; }
                .svl-theme-name { text-align: center; font-size: 12px; font-weight: 500; margin-top: 8px; color: var(--text); }
                </style>
            </div>

            <div class="svl-panel">
                <h2>🖼️ Баннер по умолчанию</h2>
                <p class="svl-panel-desc">Картинка, которая отображается над сообщением замка. Если в шорткоде не задан атрибут <code>image="..."</code>, используется этот баннер.</p>

                <div class="svl-field">
                    <div class="svl-img-pick">
                        <input type="url" id="svl_default_banner" name="svl_default_banner" value="<?php echo esc_attr(svl_opt('svl_default_banner')); ?>" placeholder="https://...">
                        <button type="button" class="svl-btn" id="svl-pick-banner">🖼️ Выбрать из библиотеки</button>
                        <button type="button" class="svl-btn svl-btn-danger" id="svl-clear-banner">Очистить</button>
                    </div>
                    <div class="svl-img-pick-prev" id="svl-banner-prev">
                        <?php if (svl_opt('svl_default_banner')): ?><img src="<?php echo esc_url(svl_opt('svl_default_banner')); ?>" alt=""><?php endif; ?>
                    </div>
                    <p class="svl-help">📐 <strong>Рекомендуемый размер:</strong> 1200×400 (или любой широкий формат). Если не задан и нет файла <code>wallpaper.{jpg|png|webp}</code> в папке плагина — баннер не показывается.</p>
                </div>
            </div>

            <div class="svl-panel">
                <h2>📚 Как использовать шорткод</h2>
                <p class="svl-panel-desc">Минимальный пример и все доступные атрибуты.</p>

                <div style="background:var(--soft); border-radius:8px; padding:14px 18px; font-family:ui-monospace, monospace; font-size:13px; line-height:1.7; color:#1f2937; overflow-x:auto;">
[vip_locker <span style="color:#7c3aed;">code</span>=<span style="color:#16a34a;">"SECRET"</span>
&nbsp;&nbsp;<span style="color:#7c3aed;">teaser</span>=<span style="color:#16a34a;">"Краткое публичное описание для SEO и краулеров"</span>
&nbsp;&nbsp;<span style="color:#7c3aed;">seo_title</span>=<span style="color:#16a34a;">"SEO-заголовок"</span>
&nbsp;&nbsp;<span style="color:#7c3aed;">seo_desc</span>=<span style="color:#16a34a;">"Meta description"</span>
&nbsp;&nbsp;<span style="color:#7c3aed;">keywords</span>=<span style="color:#16a34a;">"ключ1, ключ2"</span>
&nbsp;&nbsp;<span style="color:#7c3aed;">image</span>=<span style="color:#16a34a;">"https://..."</span>
&nbsp;&nbsp;<span style="color:#7c3aed;">message</span>=<span style="color:#16a34a;">"Кастомный текст"</span>]
&nbsp;&nbsp;Здесь закрытый контент (виден только после ввода кода)
[/vip_locker]
                </div>
                <p class="svl-help" style="margin-top:14px;">
                    🎯 <strong>Все атрибуты опциональны.</strong> Если не задан — берётся значение из настроек выше.<br>
                    🔒 Закрытый контент <strong>не индексируется</strong> поисковиками (кодируется Base64+ROT13).<br>
                    🌐 Атрибут <strong><code>teaser</code></strong> виден всем — это ваш «крючок» для SEO и читателей.
                </p>
            </div>

            <p style="margin: 28px 0;">
                <button type="submit" name="svl_settings_save" class="svl-btn svl-btn-primary" style="font-size:14px; padding:11px 24px;">💾 Сохранить настройки</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=svl-stats')); ?>" class="svl-btn" style="margin-left:8px;">📊 К статистике</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=svl-seo')); ?>" class="svl-btn" style="margin-left:8px;">🔍 SEO-настройки</a>
            </p>
        </form>
    </div>

    <script>
    (function(){
        function escHtml(s){ return String(s).replace(/[&<>"']/g, function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
        var inp = document.getElementById('svl_default_banner');
        var prev = document.getElementById('svl-banner-prev');
        var pick = document.getElementById('svl-pick-banner');
        var clr  = document.getElementById('svl-clear-banner');
        var frame;
        function setPrev(url){ prev.innerHTML = url ? '<img src="'+escHtml(url)+'" alt="">' : ''; }
        pick.addEventListener('click', function(e){
            e.preventDefault();
            if (!window.wp || !wp.media) return;
            if (!frame) {
                frame = wp.media({ title: 'Выбрать баннер', button: { text: 'Использовать' }, library: { type: 'image' }, multiple: false });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    var url = att.sizes && att.sizes.large ? att.sizes.large.url : att.url;
                    inp.value = url; setPrev(url);
                });
            }
            frame.open();
        });
        clr.addEventListener('click', function(e){ e.preventDefault(); inp.value=''; setPrev(''); });
        inp.addEventListener('change', function(){ setPrev(inp.value.trim()); });
    })();
    </script>
    <?php
}

// ==========================================
// 4. ДАШБОРД-ВИДЖЕТ
// ==========================================

add_action('wp_dashboard_setup', 'svl_register_dashboard_widget');
function svl_register_dashboard_widget() {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget('svl_dashboard_widget', '🔒 VIP Locker — кратко', 'svl_dashboard_widget_html');
}

function svl_dashboard_widget_html() {
    $stats = get_option('svl_locker_stats', array());
    arsort($stats);
    $total = array_sum(array_map('intval', $stats));
    $unique = count($stats);
    $top_code = $unique ? key($stats) : '—';
    $top_count = $unique ? intval(reset($stats)) : 0;
    ?>
    <style>
    #svl_dashboard_widget .svl-mini { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
    #svl_dashboard_widget .svl-mini > div { background: #f6f7fb; padding: 12px; border-radius: 8px; text-align: center; }
    #svl_dashboard_widget .svl-mini .v { font-size: 22px; font-weight: 700; color: #1d2327; }
    #svl_dashboard_widget .svl-mini .l { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; margin-bottom: 4px; }
    #svl_dashboard_widget .svl-row3 { display: flex; gap: 8px; margin-top: 10px; }
    #svl_dashboard_widget .svl-row3 a { flex:1; text-align:center; padding: 8px; background:#f6f7fb; border-radius:6px; text-decoration:none; color:#1d2327; font-size: 12px; }
    #svl_dashboard_widget .svl-row3 a:hover { background:#eef0f4; }
    </style>
    <div class="svl-mini">
        <div><div class="l">Разблокировок</div><div class="v"><?php echo number_format_i18n($total); ?></div></div>
        <div><div class="l">Кодов</div><div class="v"><?php echo number_format_i18n($unique); ?></div></div>
        <div><div class="l">Топ-код</div><div class="v" style="font-size:14px;"><?php echo esc_html($top_code); ?> <span style="color:#6b7280; font-weight:400;">(<?php echo number_format_i18n($top_count); ?>)</span></div></div>
    </div>
    <div class="svl-row3">
        <a href="<?php echo esc_url(admin_url('admin.php?page=svl-stats')); ?>">📊 Статистика</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=svl-settings')); ?>">⚙️ Настройки</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=svl-seo')); ?>">🔍 SEO</a>
    </div>
    <?php
}


// ==========================================
// 2. ШОРТКОД (С БУФЕРИЗАЦИЕЙ)
// ==========================================

add_shortcode('vip_locker', 'svl_render_locker');

function svl_render_locker($atts, $content = null) {
    $atts = shortcode_atts(array(
        'code'      => svl_opt('svl_default_code')    ?: '12345',
        'image'     => svl_opt('svl_default_banner')  ?: '',
        'message'   => svl_opt('svl_default_message') ?: 'Этот материал доступен подписчикам.',
        'teaser'    => '', // Публичный тизер (виден краулерам и пользователям)
        'seo_title' => '', // Override SEO title
        'seo_desc'  => '', // Override meta description
        'keywords'  => '', // Keywords
        'theme'     => svl_opt('svl_theme') ?: 'cream',
        'garland'   => svl_opt('svl_garland_enabled') ? '1' : '1', // 1 — показывать, 0 — нет
        'garland_url' => '', // переопределение URL гирлянды
    ), $atts);
    // Безопасность темы: только из белого списка
    $valid_themes = array_keys(svl_pro_themes());
    $theme = in_array($atts['theme'], $valid_themes, true) ? $atts['theme'] : 'cream';
    $show_garland = (string) $atts['garland'] !== '0' && (string) $atts['garland'] !== 'false';
    $garland_url = $show_garland ? svl_resolve_garland_url($atts['garland_url']) : '';
    if ($show_garland && !$garland_url) $garland_url = svl_garland_svg_fallback();

    // Регистрируем данные панели в SEO-модуле (для контекста и paywall разметки)
    if (function_exists('svl_seo_register_panel')) {
        svl_seo_register_panel(array(
            'teaser'    => $atts['teaser'],
            'seo_title' => $atts['seo_title'],
            'seo_desc'  => $atts['seo_desc'],
            'keywords'  => $atts['keywords'],
        ));
    }

    $correct_code = trim($atts['code']);
    $locker_id    = uniqid('vip_');

    // Регистрируем связь "код → ID статьи" для статистики
    $current_post_id = 0;
    if (is_singular()) {
        $current_post_id = (int) get_the_ID();
        if ($current_post_id && $correct_code) {
            $map = get_option('svl_code_posts', array());
            $key = $correct_code;
            if (!isset($map[$key]) || !is_array($map[$key])) $map[$key] = array();
            if (!in_array($current_post_id, $map[$key], true)) {
                $map[$key][] = $current_post_id;
                if (count($map[$key]) > 50) $map[$key] = array_slice($map[$key], -50);
                update_option('svl_code_posts', $map, false);
            }
        }
    }

    // Подключаем JS/CSS
    wp_enqueue_script('jquery');
    svl_enqueue_front_styles();

    // Ищем баннер: явный атрибут или файл wallpaper.* в каталоге плагина
    $banner_url = svl_resolve_banner_url($atts['image']);

    // --- НАЧАЛО БУФЕРИЗАЦИИ ---
    // Это решает проблему "выпадающих" шорткодов, которые делают echo.
    // Также прогоняем контент через фильтры the_content (без wpautop/shortcode-unautop повторно),
    // чтобы плагины вроде Simple Lightbox успели добавить свои классы/атрибуты к ссылкам и картинкам.
    ob_start();
    echo do_shortcode($content);
    $buffered_content = ob_get_clean();

    // Даём сторонним плагинам шанс обработать внутренний контент (lightbox, галереи, спойлеры и т.д.).
    // Используем отдельный фильтр, чтобы не ловить бесконечную рекурсию the_content.
    $buffered_content = apply_filters('svl_inner_content', $buffered_content);
    // --- КОНЕЦ БУФЕРИЗАЦИИ ---

    $nonce = wp_create_nonce('svl_track_stat');

    // Кодируем защищённый контент (Base64+ROT13). Поисковики не индексируют <script type="text/template">.
    $encoded_content = function_exists('svl_seo_encode_locked')
        ? svl_seo_encode_locked($buffered_content)
        : base64_encode(str_rot13($buffered_content));

    // Публичный тизер для индексации
    $teaser_html = '';
    if (!empty($atts['teaser'])) {
        $teaser_html = '<div class="svl-public-teaser" itemprop="description">' . wp_kses_post(wpautop($atts['teaser'])) . '</div>';
    }

    ob_start();
    ?>
    <div class="svl-wrapper svl-theme-<?php echo esc_attr($theme); ?>" id="<?php echo esc_attr($locker_id); ?>" data-code="<?php echo esc_attr($correct_code); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-post-id="<?php echo esc_attr($current_post_id); ?>">
        <input type="text" name="vip_check" tabindex="-1" autocomplete="off" style="position:absolute; left:-9999px; opacity:0; pointer-events:none;" aria-hidden="true">

        <?php echo $teaser_html; ?>

        <div class="svl-form-container svl-card">
            <?php if ($banner_url): ?>
                <div class="svl-banner">
                    <img src="<?php echo esc_url($banner_url); ?>" alt="" loading="lazy" />
                </div>
            <?php endif; ?>

            <div class="svl-body">
                <p class="svl-message"><?php echo esc_html($atts['message']); ?></p>

                <details class="svl-why">
                    <summary>💡 Почему сайт платный?</summary>
                    <div class="svl-why-body">
                        <p>Платный сайт помогает нашей команде не только разрабатывать <strong>гайды и материалы</strong> для игроков, но и заниматься <strong>публикацией новостей</strong>, поиском колод и <strong>поддержкой русскоязычного сообщества Hearthstone</strong>.</p>
                        <p>Наши принципы основаны на стремлении развивать сообщество: не только помогать игрокам улучшать навыки в игре, но и просто радовать их интересным чтивом.</p>
                        <p class="svl-why-thanks">💛 <em>Спасибо за поддержку!</em></p>
                    </div>
                </details>

                <div class="svl-input-wrap">
                    <svg class="svl-lock-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm0 2a3 3 0 0 1 3 3v3H9V7a3 3 0 0 1 3-3zm0 9a2 2 0 0 1 1 3.732V18a1 1 0 1 1-2 0v-1.268A2 2 0 0 1 12 13z"/>
                    </svg>
                    <input type="text" class="svl-input" placeholder="Введите код доступа" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Код доступа">
                    <span class="svl-input-len" aria-hidden="true"></span>
                </div>

                <div class="svl-row">
                    <button type="button" class="svl-btn svl-btn-primary svl-btn-submit">
                        <span class="svl-btn-label">Разблокировать</span>
                        <span class="svl-btn-loader" aria-hidden="true"></span>
                    </button>

                    <div class="svl-subscribe">
                        <button type="button" class="svl-sub-toggle svl-btn svl-btn-primary" aria-haspopup="true" aria-expanded="false">
                            💎 Подписка <span class="svl-caret">▾</span>
                        </button>
                        <div class="svl-sub-menu" role="menu">
                            <?php $tg = svl_opt('svl_telegram_url') ?: SVL_TELEGRAM_URL;
                                  $bo = svl_opt('svl_boosty_url')   ?: SVL_BOOSTY_URL; ?>
                            <a href="<?php echo esc_url($tg); ?>" target="_blank" rel="noopener noreferrer" role="menuitem" class="svl-sub-item svl-sub-tg">✈️ Telegram</a>
                            <a href="<?php echo esc_url($bo); ?>" target="_blank" rel="noopener noreferrer" role="menuitem" class="svl-sub-item svl-sub-boosty">🧡 Boosty</a>
                        </div>
                    </div>
                </div>

                <?php
                // Социальное доказательство — счётчик разблокировок (показываем только если ≥ 5)
                $stats_arr = get_option('svl_locker_stats', array());
                $unlock_cnt = isset($stats_arr[$correct_code]) ? intval($stats_arr[$correct_code]) : 0;
                if ($unlock_cnt >= 5): ?>
                    <div class="svl-social-proof">
                        ⭐ Уже разблокировали <strong><?php echo number_format_i18n($unlock_cnt); ?></strong> <?php
                            $n10 = $unlock_cnt % 10; $n100 = $unlock_cnt % 100;
                            if ($n10 === 1 && $n100 !== 11) echo 'человек';
                            elseif ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) echo 'человека';
                            else echo 'человек';
                        ?>
                    </div>
                <?php endif; ?>

                <div class="svl-error" role="alert">⚠️ Неверный код. Проверьте правильность ввода.</div>
                <div class="svl-success" role="status" aria-hidden="true">
                    <svg viewBox="0 0 52 52" width="40" height="40"><circle cx="26" cy="26" r="24" fill="none" stroke="#10b981" stroke-width="3"/><path d="M14 27l8 8 16-18" fill="none" stroke="#10b981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Доступ открыт!</span>
                </div>
                <div class="svl-sparkles" aria-hidden="true"></div>
            </div>

            <?php if ($show_garland && $garland_url): ?>
                <div class="svl-garland" role="presentation" style="background-image: url('<?php echo esc_url($garland_url); ?>');"></div>
            <?php endif; ?>
        </div>

        <div class="svl-content-protector" style="display: none !important;">
            <div class="svl-inner-content"></div>
            <script type="text/template" class="svl-encoded-content"><?php echo esc_html($encoded_content); ?></script>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// ==========================================
// 3. JAVASCRIPT & AJAX
// ==========================================

add_action('wp_footer', 'svl_footer_scripts');

function svl_footer_scripts() {
    ?>
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        var ajaxUrl = "<?php echo esc_url_raw(admin_url('admin-ajax.php')); ?>";

        // Находим все локеры
        var lockers = document.querySelectorAll('.svl-wrapper');

        lockers.forEach(function(locker) {
            var correctCode = locker.getAttribute('data-code');
            var nonce       = locker.getAttribute('data-nonce') || '';
            var postId      = locker.getAttribute('data-post-id') || '0';
            // Приводим к нижнему регистру для имени куки
            var cookieName = 'vip_access_' + correctCode.toLowerCase().replace(/[^a-z0-9]/g, '');

            var input            = locker.querySelector('.svl-input');
            var inputWrap        = locker.querySelector('.svl-input-wrap');
            var lenIndicator     = locker.querySelector('.svl-input-len');
            var btn              = locker.querySelector('.svl-btn-submit') || locker.querySelector('.svl-btn');
            var errorMsg         = locker.querySelector('.svl-error');
            var successMsg      = locker.querySelector('.svl-success');
            var sparkleEl       = locker.querySelector('.svl-sparkles');
            var formContainer    = locker.querySelector('.svl-form-container');
            var contentContainer = locker.querySelector('.svl-content-protector');

            // Авто-чистка при paste и при вводе
            input.addEventListener('paste', function(){
                setTimeout(function(){ input.value = input.value.trim(); updateLen(); }, 0);
            });
            function updateLen(){
                var v = input.value;
                if (lenIndicator) lenIndicator.textContent = v.length ? v.length : '';
                if (inputWrap) inputWrap.classList.toggle('has-value', v.length > 0);
                input.classList.remove('svl-error-state');
                if (errorMsg) errorMsg.style.display = 'none';
            }
            input.addEventListener('input', updateLen);
            updateLen();

            // --- Подписка: выпадающий список ---
            var subToggle = locker.querySelector('.svl-sub-toggle');
            var subMenu   = locker.querySelector('.svl-sub-menu');
            if (subToggle && subMenu) {
                subToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var isOpen = subMenu.style.display === 'block';
                    // Закрываем все открытые меню и снимаем класс с wrapper'ов
                    document.querySelectorAll('.svl-sub-menu').forEach(function(m){ m.style.display = 'none'; });
                    document.querySelectorAll('.svl-sub-toggle').forEach(function(t){ t.setAttribute('aria-expanded','false'); });
                    document.querySelectorAll('.svl-wrapper.svl-dropdown-open').forEach(function(w){ w.classList.remove('svl-dropdown-open'); });
                    if (!isOpen) {
                        subMenu.style.display = 'block';
                        subToggle.setAttribute('aria-expanded', 'true');
                        locker.classList.add('svl-dropdown-open');
                    }
                });
            }

            // 1. Проверяем Куку сразу
            if (getCookie(cookieName)) {
                unlockContent(false);
            }

            // 2. Обработчики событий
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                checkCode();
            });

            input.addEventListener("keypress", function(event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    checkCode();
                }
            });

            function spawnSparkles() {
                if (!sparkleEl) return;
                var icons = ['✨', '⭐', '🌟', '💫', '✨'];
                for (var i = 0; i < 12; i++) {
                    var s = document.createElement('span');
                    s.className = 'svl-sparkle';
                    s.textContent = icons[Math.floor(Math.random() * icons.length)];
                    var angle = (Math.PI * 2) * (i / 12) + Math.random() * 0.4;
                    var dist = 80 + Math.random() * 60;
                    s.style.left = (50 + Math.random() * 10 - 5) + '%';
                    s.style.top = (40 + Math.random() * 20) + '%';
                    s.style.setProperty('--svl-tx', Math.cos(angle) * dist + 'px');
                    s.style.setProperty('--svl-ty', Math.sin(angle) * dist + 'px');
                    s.style.animationDelay = (Math.random() * 0.2) + 's';
                    sparkleEl.appendChild(s);
                    setTimeout((function(el){ return function(){ if (el.parentNode) el.parentNode.removeChild(el); }; })(s), 1500);
                }
            }

            function setLoading(state) {
                if (!btn) return;
                btn.classList.toggle('is-loading', !!state);
                btn.disabled = !!state;
                input.disabled = !!state;
            }

            function checkCode() {
                var userVal = input.value.trim();
                if (!userVal) { showError(); return; }
                setLoading(true);
                // Небольшая задержка чтобы пользователь увидел loading-state
                setTimeout(function(){
                    if (userVal.toLowerCase() === correctCode.toLowerCase()) {
                        setCookie(cookieName, 'true', <?php echo intval(svl_opt('svl_cookie_days') ?: 7); ?>);
                        // Показываем success + sparkles, потом раскрываем
                        if (successMsg) successMsg.classList.add('show');
                        spawnSparkles();
                        setTimeout(function(){ unlockContent(true); }, 700);
                    } else {
                        setLoading(false);
                        showError();
                    }
                }, 250);
            }

            function decodeContent() {
                var tpl = locker.querySelector('script.svl-encoded-content');
                var inner = locker.querySelector('.svl-inner-content');
                if (!tpl || !inner || inner.getAttribute('data-decoded') === '1') return;
                var b64 = (tpl.textContent || '').trim();
                try {
                    // base64 → бинарная строка
                    var bin = atob(b64);
                    // rot13 (только ASCII a-zA-Z, мультибайтовые UTF-8 байты пропускаются)
                    var rot = bin.replace(/[a-zA-Z]/g, function(c){
                        var code = c.charCodeAt(0);
                        var base = code < 91 ? 65 : 97;
                        return String.fromCharCode(((code - base + 13) % 26) + base);
                    });
                    // UTF-8 → строка
                    var html;
                    try { html = decodeURIComponent(escape(rot)); } catch(e) { html = rot; }
                    inner.innerHTML = html;
                    inner.setAttribute('data-decoded', '1');
                } catch(e) {}
            }

            function unlockContent(sendStats) {
                decodeContent();
                locker.classList.add('svl-is-unlocked');
                // Скрываем форму
                formContainer.style.display = 'none';

                // Показываем контент (удаляем !important через свойство стиля)
                contentContainer.style.display = 'block';
                contentContainer.style.setProperty('display', 'block', 'important');

                // Эффект появления
                contentContainer.style.opacity = 0;
                contentContainer.style.transition = 'opacity 0.5s';
                setTimeout(function() { contentContainer.style.opacity = 1; }, 50);

                // --- Интеграция со сторонними плагинами ---
                // После раскрытия даём шанс плагинам (Simple Lightbox, спойлеры, галереи, табы и т.д.)
                // переинициализироваться на только что показанных элементах.
                setTimeout(function() {
                    notifyThirdPartyPlugins(contentContainer);
                }, 100);

                // Отправляем статистику (только если это ввод кода, а не загрузка из куки)
                if (sendStats) {
                    var hp = locker.querySelector('input[name="vip_check"]');
                    var hpVal = hp ? hp.value : '';
                    var ref = (document.referrer || '').slice(0, 255);
                    var xhr = new XMLHttpRequest();
                    xhr.open("POST", ajaxUrl, true);
                    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    xhr.send("action=svl_track_stat" +
                        "&code=" + encodeURIComponent(correctCode) +
                        "&post_id=" + encodeURIComponent(postId) +
                        "&referer=" + encodeURIComponent(ref) +
                        "&vip_check=" + encodeURIComponent(hpVal) +
                        "&nonce=" + encodeURIComponent(nonce));
                }
            }

            function showError() {
                errorMsg.style.display = 'block';
                input.classList.add('svl-error-state');
                if (inputWrap) {
                    inputWrap.classList.add('svl-shake');
                    setTimeout(function(){ inputWrap.classList.remove('svl-shake'); }, 700);
                }
                // Анимация тряски input
                input.animate([
                    { transform: 'translateX(0)' },
                    { transform: 'translateX(-8px)' },
                    { transform: 'translateX(8px)' },
                    { transform: 'translateX(-6px)' },
                    { transform: 'translateX(6px)' },
                    { transform: 'translateX(-2px)' },
                    { transform: 'translateX(0)' }
                ], { duration: 500, easing: 'cubic-bezier(.36,.07,.19,.97)' });
                // Возврат фокуса для удобного перенабора
                setTimeout(function(){ input.focus(); input.select(); }, 50);
                // Логируем неудачную попытку (троттлинг — на сервере)
                try {
                    var attempted = (input.value || '').slice(0, 64);
                    if (attempted) {
                        var hp = locker.querySelector('input[name="vip_check"]');
                        var hpVal = hp ? hp.value : '';
                        var ref = (document.referrer || '').slice(0, 255);
                        var x = new XMLHttpRequest();
                        x.open("POST", ajaxUrl, true);
                        x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                        x.send("action=svl_track_fail&attempted=" + encodeURIComponent(attempted) +
                               "&code=" + encodeURIComponent(correctCode) +
                               "&post_id=" + encodeURIComponent(postId) +
                               "&referer=" + encodeURIComponent(ref) +
                               "&vip_check=" + encodeURIComponent(hpVal) +
                               "&nonce=" + encodeURIComponent(nonce));
                    }
                } catch(e) {}
            }
        });

        // Закрытие меню подписки по клику вне
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.svl-subscribe')) {
                document.querySelectorAll('.svl-sub-menu').forEach(function(m){ m.style.display = 'none'; });
                document.querySelectorAll('.svl-sub-toggle').forEach(function(t){ t.setAttribute('aria-expanded','false'); });
                document.querySelectorAll('.svl-wrapper.svl-dropdown-open').forEach(function(w){ w.classList.remove('svl-dropdown-open'); });
            }
        });

        // ==============================================================
        // Переинициализация сторонних плагинов после раскрытия контента.
        // Поддерживаются: Simple Lightbox (SLB), FooBox, FooGallery, Lightbox-плагины,
        // спойлеры (WP-Shortcodes, Shortcodes Ultimate), разные тулкиты.
        // ==============================================================
        function notifyThirdPartyPlugins(container) {
            if (!container) return;

            // 1. Нативное событие — можно слушать в кастомных скриптах темы
            try {
                var evt = new CustomEvent('svl:unlocked', { bubbles: true, detail: { container: container } });
                container.dispatchEvent(evt);
                document.dispatchEvent(new CustomEvent('svl:unlocked', { bubbles: true, detail: { container: container } }));
            } catch (e) {}

            // 2. jQuery-события — многие WP-плагины слушают 'post-load' / 'ready' / 'updated_wc_div'
            if (window.jQuery) {
                var $ = window.jQuery;
                try {
                    // Simple Lightbox и ряд других плагинов слушают 'post-load'
                    $(document).trigger('post-load');
                    $(container).trigger('post-load');
                    // Распространённые события для lazy-load / masonry / галерей
                    $(window).trigger('resize');
                    $(window).trigger('scroll');
                    // WooCommerce-подобные (на всякий случай)
                    $(document.body).trigger('wc_fragments_refreshed');
                } catch (e) {}

                // 3. Simple Lightbox прямая активация, если плагин экспортирует API
                try {
                    if (typeof window.SLB !== 'undefined') {
                        // SLB.Viewer или SLB.activate — в разных версиях по-разному
                        if (window.SLB.Viewer && typeof window.SLB.Viewer.init === 'function') {
                            window.SLB.Viewer.init();
                        }
                        if (typeof window.SLB.activate === 'function') {
                            window.SLB.activate($(container).find('a'));
                        }
                    }
                } catch (e) {}

                // 4. Общая активация кликабельных картинок внутри контейнера:
                //    если у ссылки href ведёт на картинку, но нет lightbox-класса,
                //    триггерим click-событие lightbox-плагинов после обновления DOM.
                try {
                    $(container).find('a').each(function() {
                        var href = (this.getAttribute('href') || '').toLowerCase();
                        if (/\.(jpe?g|png|gif|webp|bmp|svg)(\?|#|$)/.test(href)) {
                            // Пометим для SLB, если не помечено
                            if (!$(this).hasClass('slb-active') && !$(this).attr('data-slb-active')) {
                                $(this).addClass('slb-active');
                            }
                        }
                    });
                    // Повторный trigger после разметки
                    $(document).trigger('post-load');
                } catch (e) {}
            }

            // 5. Нативный ресайз — триггерит ре-лэйаут многих JS-плагинов
            try {
                window.dispatchEvent(new Event('resize'));
            } catch (e) {}
        }

        // --- Cookie Helpers ---
        function setCookie(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + (value || "") + expires + "; path=/";
        }

        function getCookie(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        }
    });
    </script>
    <?php
}

// ==========================================
// 4. AJAX ОБРАБОТЧИК
// ==========================================

add_action('wp_ajax_svl_track_stat', 'svl_track_stat_handler');
add_action('wp_ajax_nopriv_svl_track_stat', 'svl_track_stat_handler');

function svl_track_stat_handler() {
    if (!empty($_POST['nonce'])) {
        check_ajax_referer('svl_track_stat', 'nonce', false);
    }
    // Honeypot
    if (function_exists('svl_pro_honeypot_tripped') && svl_pro_honeypot_tripped()) wp_die();

    $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
    if ($code !== '') {
        $stats = get_option('svl_locker_stats', array());
        $stats[$code] = isset($stats[$code]) ? $stats[$code] + 1 : 1;
        update_option('svl_locker_stats', $stats);

        if (function_exists('svl_pro_log')) {
            svl_pro_log(array(
                'code'    => $code,
                'post_id' => isset($_POST['post_id']) ? intval($_POST['post_id']) : 0,
                'referer' => isset($_POST['referer']) ? esc_url_raw(wp_unslash($_POST['referer'])) : '',
                'is_fail' => 0,
            ));
        }
    }
    wp_die();
}

// Логирование неудачных попыток
add_action('wp_ajax_svl_track_fail', 'svl_track_fail_handler');
add_action('wp_ajax_nopriv_svl_track_fail', 'svl_track_fail_handler');

function svl_track_fail_handler() {
    // Honeypot — тихо игнорируем (бот не должен знать что сработал)
    if (function_exists('svl_pro_honeypot_tripped') && svl_pro_honeypot_tripped()) wp_die();

    // Throttle: не более 30 неудачных попыток с одного IP в минуту
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($ip) {
        $tk = 'svl_fail_thr_' . md5($ip);
        $cnt = (int) get_transient($tk);
        if ($cnt >= 30) wp_die();
        set_transient($tk, $cnt + 1, MINUTE_IN_SECONDS);
    }

    $attempted = isset($_POST['attempted']) ? sanitize_text_field(wp_unslash($_POST['attempted'])) : '';
    $code      = isset($_POST['code'])      ? sanitize_text_field(wp_unslash($_POST['code']))      : '';
    $pid       = isset($_POST['post_id'])   ? intval($_POST['post_id'])                            : 0;
    $referer   = isset($_POST['referer'])   ? esc_url_raw(wp_unslash($_POST['referer']))           : '';
    if ($attempted === '' || $code === '') wp_die();

    // Защита от dos: ограничение длины
    $attempted = mb_substr($attempted, 0, 64);
    $code      = mb_substr($code, 0, 64);

    $fails = get_option('svl_failed_attempts', array());
    $fails[] = array(
        't' => time(),
        'a' => $attempted,
        'c' => $code,
        'p' => $pid,
    );
    if (count($fails) > 1000) $fails = array_slice($fails, -1000);
    update_option('svl_failed_attempts', $fails, false);

    if (function_exists('svl_pro_log')) {
        svl_pro_log(array(
            'code'      => $code,
            'post_id'   => $pid,
            'referer'   => $referer,
            'is_fail'   => 1,
            'attempted' => $attempted,
        ));
    }
    wp_die();
}
