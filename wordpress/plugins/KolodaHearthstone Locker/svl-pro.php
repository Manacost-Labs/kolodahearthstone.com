<?php
/**
 * SVL Pro — расширенные фичи: DB-таблицы, темы, honeypot, sitemap, кнопки редактора, Gutenberg preview.
 */
if (!defined('ABSPATH')) exit;

define('SVL_DB_VERSION', '1');
define('SVL_TABLE_LOG', 'svl_unlocks');

// =====================================================
// 1. DB MIGRATION — таблица для лога разблокировок/попыток
// =====================================================

function svl_pro_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $tbl     = $wpdb->prefix . SVL_TABLE_LOG;

    $sql = "CREATE TABLE $tbl (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(64) NOT NULL DEFAULT '',
        post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        referer VARCHAR(255) NOT NULL DEFAULT '',
        ref_host VARCHAR(120) NOT NULL DEFAULT '',
        is_fail TINYINT(1) NOT NULL DEFAULT 0,
        attempted VARCHAR(64) NOT NULL DEFAULT '',
        created_at INT(10) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_code (code),
        KEY idx_created (created_at),
        KEY idx_fail_created (is_fail, created_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('svl_db_version', SVL_DB_VERSION);
}

add_action('plugins_loaded', 'svl_pro_maybe_migrate', 1);
function svl_pro_maybe_migrate() {
    if (get_option('svl_db_version') !== SVL_DB_VERSION) {
        svl_pro_install_tables();
    }
}

/**
 * Вставка строки в лог.
 */
function svl_pro_log($args) {
    global $wpdb;
    $a = array_merge(array(
        'code' => '', 'post_id' => 0, 'referer' => '',
        'is_fail' => 0, 'attempted' => '', 'country' => '',
    ), $args);
    // Расширяемость: хук позволяет подмешивать country и др. поля
    $a = apply_filters('svl_pro_log_args', $a);

    $referer = mb_substr((string) $a['referer'], 0, 255);
    $host = '';
    if ($referer) {
        $h = wp_parse_url($referer, PHP_URL_HOST);
        if ($h) $host = mb_strtolower(mb_substr($h, 0, 120));
    }
    $country = preg_match('/^[A-Z]{2}$/', (string) $a['country']) ? $a['country'] : '';

    $tbl = $wpdb->prefix . SVL_TABLE_LOG;
    $insert = array(
        'code'       => mb_substr((string) $a['code'], 0, 64),
        'post_id'    => (int) $a['post_id'],
        'referer'    => $referer,
        'ref_host'   => $host,
        'is_fail'    => $a['is_fail'] ? 1 : 0,
        'attempted'  => mb_substr((string) $a['attempted'], 0, 64),
        'created_at' => time(),
    );
    $formats = array('%s','%d','%s','%s','%d','%s','%d');
    // Добавляем country если столбец существует
    if ($wpdb->get_var("SHOW COLUMNS FROM $tbl LIKE 'country'")) {
        $insert['country'] = $country;
        $formats[] = '%s';
    }
    $wpdb->insert($tbl, $insert, $formats);

    // Защита от роста: каждые 1000 вставок — обрезаем старые > 90 дней
    if (function_exists('mt_rand') && mt_rand(1, 100) === 1) {
        $cutoff = time() - 90 * DAY_IN_SECONDS;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}" . SVL_TABLE_LOG . " WHERE created_at < %d", $cutoff));
    }
}

// =====================================================
// 2. HONEYPOT — проверка скрытого поля
// =====================================================

function svl_pro_honeypot_tripped() {
    return !empty($_POST['vip_check']);
}

// =====================================================
// 3. КЛАССИФИКАТОР REFERER
// =====================================================

function svl_pro_classify_referer($host) {
    if (!$host) return array('label' => 'Прямые заходы', 'color' => '#9ca3af');
    $h = mb_strtolower($host);

    $rules = array(
        'google.'    => array('Google',     '#4285f4'),
        'yandex.'    => array('Яндекс',     '#fc3f1d'),
        'bing.'      => array('Bing',       '#008373'),
        'duckduckgo' => array('DuckDuckGo', '#de5833'),
        'mail.ru'    => array('Mail.ru',    '#0078d3'),
        't.me'       => array('Telegram',   '#0088cc'),
        'telegram'   => array('Telegram',   '#0088cc'),
        'vk.com'     => array('VK',         '#0077ff'),
        'vk.ru'      => array('VK',         '#0077ff'),
        'facebook'   => array('Facebook',   '#1877f2'),
        'fb.com'     => array('Facebook',   '#1877f2'),
        'twitter.'   => array('Twitter',    '#1da1f2'),
        'x.com'      => array('X (Twitter)','#000000'),
        'youtube.'   => array('YouTube',    '#ff0000'),
        'youtu.be'   => array('YouTube',    '#ff0000'),
        'reddit.'    => array('Reddit',     '#ff4500'),
        'discord.'   => array('Discord',    '#5865f2'),
        'boosty.'    => array('Boosty',     '#f15f2c'),
        'patreon.'   => array('Patreon',    '#ff424d'),
        'instagram.' => array('Instagram',  '#e4405f'),
        'pikabu.'    => array('Pikabu',     '#00a850'),
        'dzen.'      => array('Дзен',       '#000000'),
        'zen.yandex' => array('Дзен',       '#000000'),
    );
    foreach ($rules as $needle => $info) {
        if (strpos($h, $needle) !== false) return array('label' => $info[0], 'color' => $info[1]);
    }
    $self = wp_parse_url(home_url(), PHP_URL_HOST);
    if ($self && $h === mb_strtolower($self)) return array('label' => 'Свой сайт', 'color' => '#10b981');
    return array('label' => $h, 'color' => '#6b7280');
}

/**
 * Получить распределение источников для конкретного кода (или общее).
 */
function svl_pro_referer_breakdown($code = '', $is_fail = null) {
    global $wpdb;
    $tbl = $wpdb->prefix . SVL_TABLE_LOG;
    $where = array('1=1');
    $args  = array();
    if ($code !== '') {
        $where[] = 'code = %s';
        $args[]  = $code;
    }
    if ($is_fail !== null) {
        $where[] = 'is_fail = %d';
        $args[]  = $is_fail ? 1 : 0;
    }
    $sql = "SELECT ref_host, COUNT(*) AS n FROM $tbl WHERE " . implode(' AND ', $where) . " GROUP BY ref_host ORDER BY n DESC LIMIT 20";
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

    $out = array();
    foreach ($rows as $r) {
        $info = svl_pro_classify_referer($r->ref_host);
        $key = $info['label'];
        if (!isset($out[$key])) $out[$key] = array('label' => $key, 'color' => $info['color'], 'n' => 0);
        $out[$key]['n'] += (int) $r->n;
    }
    uasort($out, function ($a, $b) { return $b['n'] - $a['n']; });
    return array_values($out);
}

/**
 * Временной ряд за N дней: [date_str => array('s'=>, 'f'=>)]
 */
function svl_pro_timeseries($days = 30) {
    global $wpdb;
    $tbl = $wpdb->prefix . SVL_TABLE_LOG;
    $since = time() - $days * DAY_IN_SECONDS;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(FROM_UNIXTIME(created_at)) AS d, is_fail, COUNT(*) AS n
         FROM $tbl WHERE created_at >= %d
         GROUP BY d, is_fail ORDER BY d ASC", $since
    ));
    $series = array();
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * DAY_IN_SECONDS);
        $series[$d] = array('s' => 0, 'f' => 0);
    }
    foreach ($rows as $r) {
        if (!isset($series[$r->d])) continue;
        if ($r->is_fail) $series[$r->d]['f'] = (int) $r->n;
        else             $series[$r->d]['s'] = (int) $r->n;
    }
    return $series;
}

// =====================================================
// 4. ТЕМЫ ОФОРМЛЕНИЯ ЗАМКА
// =====================================================

function svl_pro_themes() {
    return array(
        'cream'   => array('name' => 'Кремовая (стандарт)', 'preview' => '#fbf5e6'),
        'dark'    => array('name' => 'Тёмная',              'preview' => '#1f2937'),
        'minimal' => array('name' => 'Минимализм',          'preview' => '#ffffff'),
        'gaming'  => array('name' => 'Gaming (неон)',       'preview' => '#0f172a'),
        'sunset'  => array('name' => 'Sunset (закат)',      'preview' => '#fed7aa'),
    );
}

function svl_pro_theme_css() {
    return '
    /* === DARK === */
    .svl-wrapper.svl-theme-dark .svl-card { background:#1f2937; border-color:#374151; color:#f9fafb; }
    .svl-wrapper.svl-theme-dark .svl-message { color:#f9fafb; }
    .svl-wrapper.svl-theme-dark .svl-input { background:#111827; border-color:#374151; color:#f9fafb; }
    .svl-wrapper.svl-theme-dark .svl-input::placeholder { color:#9ca3af; }
    .svl-wrapper.svl-theme-dark .svl-input:focus { border-color:#fbbf24; box-shadow:0 0 0 3px rgba(251,191,36,.2); }
    .svl-wrapper.svl-theme-dark .svl-btn-primary { background:#fbbf24; color:#1f2937; }
    .svl-wrapper.svl-theme-dark .svl-btn-primary:hover { background:#f59e0b; }
    .svl-wrapper.svl-theme-dark .svl-sub-menu { background:#1f2937; border-color:#374151; }
    .svl-wrapper.svl-theme-dark .svl-sub-item { color:#f9fafb; border-color:#374151; }
    .svl-wrapper.svl-theme-dark .svl-sub-item:hover { background:#111827; }
    .svl-wrapper.svl-theme-dark .svl-public-teaser { color:#d1d5db; }

    /* === MINIMAL === */
    .svl-wrapper.svl-theme-minimal .svl-card { background:#ffffff; border:1px solid #e5e7eb; box-shadow:0 1px 3px rgba(0,0,0,.04); }
    .svl-wrapper.svl-theme-minimal .svl-input { border-color:#d1d5db; }
    .svl-wrapper.svl-theme-minimal .svl-btn-primary { background:#111827; color:#fff; }
    .svl-wrapper.svl-theme-minimal .svl-btn-primary:hover { background:#374151; }

    /* === GAMING === */
    .svl-wrapper.svl-theme-gaming .svl-card {
        background:linear-gradient(135deg,#0f172a 0%, #1e1b4b 100%);
        border:1px solid #6366f1; color:#e0e7ff;
        box-shadow:0 0 30px rgba(99,102,241,.4), inset 0 0 60px rgba(99,102,241,.1);
    }
    .svl-wrapper.svl-theme-gaming .svl-message { color:#e0e7ff; }
    .svl-wrapper.svl-theme-gaming .svl-input { background:#1e1b4b; border-color:#4338ca; color:#e0e7ff; }
    .svl-wrapper.svl-theme-gaming .svl-input::placeholder { color:#a5b4fc; }
    .svl-wrapper.svl-theme-gaming .svl-input:focus { border-color:#a78bfa; box-shadow:0 0 0 3px rgba(167,139,250,.3), 0 0 20px rgba(167,139,250,.4); }
    .svl-wrapper.svl-theme-gaming .svl-btn-primary {
        background:linear-gradient(135deg,#6366f1,#a78bfa);
        box-shadow:0 0 15px rgba(99,102,241,.5);
    }
    .svl-wrapper.svl-theme-gaming .svl-btn-primary:hover { background:linear-gradient(135deg,#4338ca,#7c3aed); }
    .svl-wrapper.svl-theme-gaming .svl-sub-menu { background:#1e1b4b; border-color:#4338ca; }
    .svl-wrapper.svl-theme-gaming .svl-sub-item { color:#e0e7ff; border-color:#4338ca; }
    .svl-wrapper.svl-theme-gaming .svl-sub-item:hover { background:#312e81; }
    .svl-wrapper.svl-theme-gaming .svl-public-teaser { color:#c7d2fe; }

    /* === SUNSET === */
    .svl-wrapper.svl-theme-sunset .svl-card {
        background:linear-gradient(135deg,#fef3c7 0%, #fed7aa 50%, #fda4af 100%);
        border:0; color:#7c2d12;
        box-shadow:0 8px 32px rgba(249,115,22,.2);
    }
    .svl-wrapper.svl-theme-sunset .svl-message { color:#7c2d12; }
    .svl-wrapper.svl-theme-sunset .svl-input { background:rgba(255,255,255,.85); border-color:#fdba74; color:#7c2d12; }
    .svl-wrapper.svl-theme-sunset .svl-input:focus { border-color:#ea580c; box-shadow:0 0 0 3px rgba(234,88,12,.2); }
    .svl-wrapper.svl-theme-sunset .svl-btn-primary { background:linear-gradient(135deg,#f97316,#dc2626); }
    ';
}

// =====================================================
// 5. SITEMAP /sitemap-vip.xml
// =====================================================

add_action('init', 'svl_pro_register_sitemap_rewrite');
function svl_pro_register_sitemap_rewrite() {
    add_rewrite_rule('^sitemap-vip\.xml$', 'index.php?svl_sitemap=1', 'top');
}

add_filter('query_vars', function ($vars) { $vars[] = 'svl_sitemap'; return $vars; });

add_action('template_redirect', 'svl_pro_serve_sitemap');
function svl_pro_serve_sitemap() {
    if (!get_query_var('svl_sitemap')) return;

    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT ID, post_modified_gmt, post_title FROM {$wpdb->posts}
        WHERE post_status = 'publish'
          AND post_type IN ('post','page')
          AND post_content LIKE '%[vip_locker%'
        ORDER BY post_modified_gmt DESC
        LIMIT 5000
    ");

    if (function_exists('nocache_headers')) nocache_headers();
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Robots-Tag: noindex, follow');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('/wp-includes/css/sitemap.xsl')) . '"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

    // Главная (с ссылками на VIP-разделы)
    echo "<url>\n";
    echo '  <loc>' . esc_url(home_url('/')) . '</loc>' . "\n";
    echo '  <changefreq>daily</changefreq>' . "\n";
    echo '  <priority>1.0</priority>' . "\n";
    echo "</url>\n";

    foreach ($rows as $r) {
        $url = get_permalink($r->ID);
        if (!$url) continue;
        echo "<url>\n";
        echo '  <loc>' . esc_url($url) . '</loc>' . "\n";
        echo '  <lastmod>' . esc_html(mysql2date('c', $r->post_modified_gmt, false)) . '</lastmod>' . "\n";
        echo '  <changefreq>weekly</changefreq>' . "\n";
        echo '  <priority>0.8</priority>' . "\n";

        $thumb_id = get_post_thumbnail_id($r->ID);
        if ($thumb_id) {
            $img = wp_get_attachment_image_src($thumb_id, 'svl_seo_og');
            if (!$img) $img = wp_get_attachment_image_src($thumb_id, 'full');
            if ($img && !empty($img[0])) {
                echo '  <image:image>' . "\n";
                echo '    <image:loc>' . esc_url($img[0]) . '</image:loc>' . "\n";
                echo '    <image:title>' . esc_html(html_entity_decode($r->post_title, ENT_QUOTES, 'UTF-8')) . '</image:title>' . "\n";
                echo '  </image:image>' . "\n";
            }
        }
        echo "</url>\n";
    }
    echo '</urlset>';
    exit;
}

// Сбрасываем правила перезаписи при первой активации
add_action('admin_init', 'svl_pro_maybe_flush_rewrite');
function svl_pro_maybe_flush_rewrite() {
    if (get_option('svl_pro_rewrite_flushed') !== '1') {
        flush_rewrite_rules(false);
        update_option('svl_pro_rewrite_flushed', '1');
    }
}

// =====================================================
// 6. TINYMCE PLUGIN + QUICKTAGS BUTTON
// =====================================================

add_filter('mce_external_plugins', 'svl_pro_register_mce_plugin');
function svl_pro_register_mce_plugin($plugins) {
    if (!current_user_can('edit_posts')) return $plugins;
    $plugins['svl_vip'] = plugins_url('svl-tinymce.js', __FILE__) . '?v=' . SVL_VERSION;
    return $plugins;
}

add_filter('mce_buttons', 'svl_pro_register_mce_button');
function svl_pro_register_mce_button($buttons) {
    if (!current_user_can('edit_posts')) return $buttons;
    array_push($buttons, 'svl_vip_btn');
    return $buttons;
}

// Quicktags-кнопка для HTML-режима Classic Editor
add_action('admin_print_footer_scripts', 'svl_pro_quicktags_button', 100);
function svl_pro_quicktags_button() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !in_array($screen->base, array('post'), true)) return;
    if (!current_user_can('edit_posts')) return;
    ?>
    <script>
    (function(){
        if (typeof QTags === 'undefined') return;
        function rnd(n){ var s='', c='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'; for (var i=0;i<n;i++) s+=c[Math.floor(Math.random()*c.length)]; return s; }
        QTags.addButton('svl_vip_qt', '🔒 VIP', function(){
            var code = prompt('Код доступа (Enter — сгенерировать случайный):', '');
            if (code === null) return;
            code = (code || '').trim();
            if (!code) code = rnd(8);
            var teaser = prompt('Публичный тизер для SEO (можно пропустить):', '');
            var attrs = 'code="' + code.replace(/"/g, '') + '"';
            if (teaser && teaser.trim()) attrs += ' teaser="' + teaser.replace(/"/g, '&quot;') + '"';
            QTags.insertContent('[vip_locker ' + attrs + ']\nЗакрытый контент здесь\n[/vip_locker]');
        });
    })();
    </script>
    <?php
}

// =====================================================
// 7. GUTENBERG PREVIEW кнопка в SEO-метабоксе
// =====================================================

add_action('wp_ajax_svl_preview_locker', 'svl_pro_preview_handler');
function svl_pro_preview_handler() {
    check_ajax_referer('svl_preview', 'nonce');
    if (!current_user_can('edit_posts')) wp_die('-1');
    $code   = isset($_POST['code'])   ? sanitize_text_field(wp_unslash($_POST['code']))   : 'PREVIEW';
    $teaser = isset($_POST['teaser']) ? sanitize_textarea_field(wp_unslash($_POST['teaser'])) : '';
    $theme  = isset($_POST['theme'])  ? sanitize_text_field(wp_unslash($_POST['theme']))  : '';

    // 1) Принудительно регистрируем стили
    if (function_exists('svl_enqueue_front_styles')) svl_enqueue_front_styles();

    // 2) Вызываем рендер ШОРТКОДА напрямую через функцию (надёжнее do_shortcode в AJAX)
    $atts_arr = array('code' => $code);
    if ($teaser) $atts_arr['teaser'] = $teaser;
    if ($theme)  $atts_arr['theme'] = $theme;

    if (function_exists('svl_render_locker')) {
        $rendered = svl_render_locker($atts_arr, '<p><em>Здесь будет ваш закрытый контент.</em></p>');
    } else {
        $rendered = do_shortcode('[vip_locker code="' . esc_attr($code) . '"]<p><em>Закрытый контент</em></p>[/vip_locker]');
    }

    // 3) Берём inline CSS напрямую из реестра (wp_head в AJAX не всегда печатает)
    global $wp_styles;
    $inline_css = '';
    if ($wp_styles instanceof WP_Styles && isset($wp_styles->registered['svl-front'])) {
        $reg = $wp_styles->registered['svl-front'];
        if (!empty($reg->extra['after']) && is_array($reg->extra['after'])) {
            $inline_css = implode("\n", $reg->extra['after']);
        }
    }

    // 4) Захватываем footer-скрипты (интерактив замка)
    ob_start();
    if (function_exists('svl_footer_scripts')) svl_footer_scripts();
    $footer_html = ob_get_clean();

    // 5) Выдаём полную HTML-страницу
    ?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview · VIP Locker</title>
    <script src="<?php echo esc_url(includes_url('js/jquery/jquery.min.js')); ?>"></script>
    <style><?php echo $inline_css; ?></style>
    <style>
        html, body { margin: 0; padding: 0; }
        body {
            background:
                radial-gradient(circle at 20% 20%, rgba(217,119,6,.1), transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(99,102,241,.08), transparent 50%),
                #f4ecd9;
            padding: 40px 30px 80px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .preview-note {
            max-width: 760px; margin: 0 auto 24px;
            padding: 12px 16px; background: rgba(255,255,255,.65);
            border-left: 3px solid #d97706; border-radius: 0 8px 8px 0;
            font-size: 13px; color: #5c4023; box-shadow: 0 2px 6px rgba(75,46,16,.08);
        }
        .preview-content { max-width: 760px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="preview-note">👁 <strong>Превью VIP-замка</strong> — так выглядит блок на сайте. Все интерактивные элементы (ввод кода, разблокировка) работают как на реальной странице.</div>
    <div class="preview-content"><?php echo $rendered; ?></div>
    <?php echo $footer_html; ?>
</body>
</html>
<?php
    wp_die();
}

add_action('admin_footer-post.php', 'svl_pro_preview_button_js');
add_action('admin_footer-post-new.php', 'svl_pro_preview_button_js');
function svl_pro_preview_button_js() {
    if (!current_user_can('edit_posts')) return;
    $nonce = wp_create_nonce('svl_preview');
    $ajax  = admin_url('admin-ajax.php');
    ?>
    <style>
    #svl-preview-modal { position: fixed; inset: 0; z-index: 100001; display: none; align-items: center; justify-content: center; }
    #svl-preview-modal.open { display: flex; }
    #svl-preview-modal .bd { position: absolute; inset: 0; background: rgba(15,23,42,.6); backdrop-filter: blur(4px); }
    #svl-preview-modal .dlg { position: relative; background:#fff; border-radius:14px; width: min(900px, 94vw); height: 80vh; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,.35); }
    #svl-preview-modal .hd { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #e4e7ec; }
    #svl-preview-modal .hd h3 { margin:0; font-size:16px; }
    #svl-preview-modal iframe { flex:1; border:0; border-radius: 0 0 14px 14px; background:#f6f7fb; }
    #svl-preview-modal .x { background:#f6f7fb; border:0; width:32px; height:32px; border-radius:50%; cursor:pointer; font-size:16px; }
    #svl-preview-modal .x:hover { background:#fef2f2; color:#d63638; }
    .svl-preview-trigger {
        margin-top: 12px; padding: 10px 16px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff; border: 0; border-radius: 8px; cursor: pointer;
        font-weight: 500; font-size: 13px;
        box-shadow: 0 2px 8px rgba(99,102,241,.3);
    }
    .svl-preview-trigger:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,.4); }
    </style>

    <div id="svl-preview-modal" aria-hidden="true">
        <div class="bd"></div>
        <div class="dlg" role="dialog" aria-modal="true">
            <div class="hd">
                <h3>👁 Предпросмотр VIP-замка</h3>
                <button type="button" class="x" aria-label="Закрыть">✕</button>
            </div>
            <iframe id="svl-preview-iframe" sandbox="allow-same-origin allow-scripts"></iframe>
        </div>
    </div>

    <script>
    (function(){
        var ajax  = <?php echo wp_json_encode($ajax); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var modal = document.getElementById('svl-preview-modal');
        var iframe = document.getElementById('svl-preview-iframe');
        if (!modal) return;

        function close(){ modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); document.body.style.overflow=''; iframe.srcdoc = ''; }
        modal.querySelector('.bd').addEventListener('click', close);
        modal.querySelector('.x').addEventListener('click', close);
        document.addEventListener('keydown', function(e){ if (e.key==='Escape' && modal.classList.contains('open')) close(); });

        function findShortcodes() {
            var content = '';
            try { if (window.wp && wp.data && wp.data.select('core/editor')) content = wp.data.select('core/editor').getEditedPostContent() || ''; } catch(e){}
            if (!content) {
                try { if (window.tinymce && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) content = tinymce.activeEditor.getContent() || ''; } catch(e){}
            }
            if (!content) { var ta = document.getElementById('content'); content = ta ? ta.value : ''; }
            var found = [];
            var re = /\[vip_locker\b[^\]]*\]/gi;
            var m;
            while ((m = re.exec(content)) !== null) {
                var atts = {};
                var attrRe = /(\w+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s\]]+))/g;
                var ma;
                while ((ma = attrRe.exec(m[0])) !== null) {
                    atts[ma[1]] = ma[2] !== undefined ? ma[2] : (ma[3] !== undefined ? ma[3] : ma[4]);
                }
                found.push(atts);
            }
            return found;
        }

        function openPreview() {
            var shortcodes = findShortcodes();
            var atts;
            if (!shortcodes.length) {
                atts = { code: prompt('Код для тестового превью:', 'TESTCODE') || 'TEST', teaser: '' };
            } else if (shortcodes.length === 1) {
                atts = shortcodes[0];
            } else {
                var idx = prompt('Найдено ' + shortcodes.length + ' замков. Какой показать? (1-' + shortcodes.length + ')', '1');
                if (idx === null) return;
                atts = shortcodes[Math.max(0, Math.min(shortcodes.length - 1, parseInt(idx, 10) - 1))];
            }
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            iframe.srcdoc = '<p style="padding:30px; font-family:sans-serif; color:#6b7280;">Загрузка превью...</p>';

            var fd = new FormData();
            fd.append('action', 'svl_preview_locker');
            fd.append('nonce', nonce);
            fd.append('code', atts.code || 'PREVIEW');
            fd.append('teaser', atts.teaser || '');
            fd.append('theme', atts.theme || '');

            fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.text(); })
                .then(function(html){ iframe.srcdoc = html; })
                .catch(function(){ iframe.srcdoc = '<p style="padding:30px; color:red;">Ошибка загрузки</p>'; });
        }

        // Добавляем кнопку в SEO-метабокс
        function injectButton() {
            var mb = document.getElementById('svl_seo_metabox');
            if (!mb || mb.querySelector('.svl-preview-trigger')) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'svl-preview-trigger';
            btn.textContent = '👁 Предпросмотр VIP-замка';
            btn.addEventListener('click', openPreview);
            var inside = mb.querySelector('.inside') || mb;
            inside.appendChild(btn);
        }
        document.addEventListener('DOMContentLoaded', injectButton);
        // Для Gutenberg который рендерит метабоксы динамически
        setTimeout(injectButton, 1000);
        setTimeout(injectButton, 3000);
    })();
    </script>
    <?php
}
