<?php
/**
 * SVL Telegram Bot bridge.
 *
 * REST endpoints used by an external Telegram bot to issue short-lived,
 * one-time magic-link URLs for subscribers. Reuses the svl_magic_tokens
 * storage from svl-magic.php — the redeem flow already lives there.
 *
 *   GET  /wp-json/vip/v1/lockers   -> list articles with [vip_locker] / svl/locker blocks
 *   POST /wp-json/vip/v1/issue     -> { post_id, code, telegram_user_id, ttl? } -> { url, token, expires_at }
 *
 * Admin page: VIP Locker → Telegram бот (bearer secret + stats).
 */
if (!defined('ABSPATH')) exit;

if (!defined('SVL_BOT_OPT_SECRET'))   define('SVL_BOT_OPT_SECRET',   'svl_bot_secret');
if (!defined('SVL_BOT_OPT_TTL'))      define('SVL_BOT_OPT_TTL',      'svl_bot_token_ttl');   // seconds
if (!defined('SVL_BOT_DEFAULT_TTL'))  define('SVL_BOT_DEFAULT_TTL',  900);                   // 15 min

// =====================================================
// 1. SETTINGS REGISTRATION
// =====================================================

add_action('admin_init', 'svl_bot_register_settings');
function svl_bot_register_settings() {
    register_setting('svl_bot_opts', SVL_BOT_OPT_SECRET);
    register_setting('svl_bot_opts', SVL_BOT_OPT_TTL);
}

// =====================================================
// 2. REST ROUTES
// =====================================================

add_action('rest_api_init', 'svl_bot_register_routes');
function svl_bot_register_routes() {
    register_rest_route('vip/v1', '/lockers', array(
        'methods'             => 'GET',
        'callback'            => 'svl_bot_rest_lockers',
        'permission_callback' => 'svl_bot_check_bearer',
    ));
    register_rest_route('vip/v1', '/issue', array(
        'methods'             => 'POST',
        'callback'            => 'svl_bot_rest_issue',
        'permission_callback' => 'svl_bot_check_bearer',
    ));
}

function svl_bot_check_bearer($request) {
    $expected = trim((string) get_option(SVL_BOT_OPT_SECRET, ''));
    if ($expected === '') {
        return new WP_Error('svl_bot_no_secret', 'Bot secret is not configured', array('status' => 503));
    }
    $auth = $request->get_header('authorization');
    if (!$auth) $auth = $request->get_header('x_vip_bearer');
    if (!$auth) {
        return new WP_Error('svl_bot_auth', 'Missing Authorization header', array('status' => 401));
    }
    $given = (stripos($auth, 'Bearer ') === 0) ? trim(substr($auth, 7)) : trim($auth);
    if (!hash_equals($expected, $given)) {
        return new WP_Error('svl_bot_auth', 'Invalid bearer', array('status' => 401));
    }
    return true;
}

// =====================================================
// 3. /lockers — list of posts containing a VIP locker
// =====================================================

function svl_bot_rest_lockers($request) {
    return rest_ensure_response(svl_bot_collect_lockers());
}

/**
 * Scans published posts/pages for both classic shortcodes and Gutenberg blocks
 * and returns a deduplicated list of (post_id, code, title, url).
 */
function svl_bot_collect_lockers() {
    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT ID, post_title, post_content, post_date, post_type
        FROM {$wpdb->posts}
        WHERE post_status = 'publish'
          AND post_type IN ('post','page')
          AND (post_content LIKE '%[vip_locker%' OR post_content LIKE '%wp:svl/locker%')
        ORDER BY post_date DESC
        LIMIT 500
    ");
    $default_code = function_exists('svl_opt') ? (string) svl_opt('svl_default_code') : '';
    if ($default_code === '') $default_code = '12345';

    $out = array();
    $seen = array();
    foreach ($rows as $r) {
        $codes = svl_bot_extract_codes($r->post_content, $default_code);
        foreach ($codes as $code) {
            $key = $r->ID . '|' . $code;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = array(
                'post_id' => (int) $r->ID,
                'code'    => $code,
                'title'   => html_entity_decode(get_the_title($r->ID), ENT_QUOTES, 'UTF-8'),
                'url'     => get_permalink($r->ID),
                'date'    => $r->post_date,
                'type'    => $r->post_type,
            );
        }
    }
    return $out;
}

function svl_bot_extract_codes($content, $default_code) {
    $codes = array();

    // Shortcode form: [vip_locker code="..."]   or  [vip_locker]  (default code)
    if (preg_match_all('/\[vip_locker\b([^\]]*)\]/i', $content, $m)) {
        foreach ($m[1] as $attrs) {
            if (preg_match('/\bcode\s*=\s*(["\'])([^"\']+)\1/i', $attrs, $cm)) {
                $codes[] = trim($cm[2]);
            } else {
                $codes[] = $default_code;
            }
        }
    }

    // Gutenberg block form: <!-- wp:svl/locker {"code":"..."} -->
    if (preg_match_all('/wp:svl\/locker(\s+(\{[^}]*\}))?\s*-->/i', $content, $bm)) {
        foreach ($bm[2] as $json) {
            if (!$json) { $codes[] = $default_code; continue; }
            $decoded = json_decode($json, true);
            if (is_array($decoded) && !empty($decoded['code'])) {
                $codes[] = (string) $decoded['code'];
            } else {
                $codes[] = $default_code;
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('trim', $codes), 'strlen')));
}

// =====================================================
// 4. /issue — create a magic-link token for a subscriber
// =====================================================

function svl_bot_rest_issue($request) {
    $params = $request->get_json_params();
    if (!is_array($params)) $params = $request->get_params();

    $code     = isset($params['code'])     ? sanitize_text_field((string) $params['code']) : '';
    $post_id  = isset($params['post_id'])  ? intval($params['post_id']) : 0;
    $tg_user  = isset($params['telegram_user_id']) ? intval($params['telegram_user_id']) : 0;
    $ttl_in   = isset($params['ttl'])      ? intval($params['ttl']) : 0;

    if ($code === '' || $tg_user === 0) {
        return new WP_Error('svl_bot_bad_input', 'Missing code or telegram_user_id', array('status' => 400));
    }
    if ($post_id > 0 && !get_post($post_id)) {
        return new WP_Error('svl_bot_no_post', 'Post not found', array('status' => 404));
    }

    $ttl_default = max(60, intval(get_option(SVL_BOT_OPT_TTL, SVL_BOT_DEFAULT_TTL)));
    $ttl = $ttl_in > 0 ? max(60, min(86400, $ttl_in)) : $ttl_default;

    $token = svl_bot_create_token($code, $ttl, 'tg-bot:' . $tg_user);
    if (!$token) {
        return new WP_Error('svl_bot_token', 'Token creation failed', array('status' => 500));
    }

    $base = ($post_id > 0) ? get_permalink($post_id) : home_url('/');
    $url  = add_query_arg('vip_token', $token, $base);

    return rest_ensure_response(array(
        'token'      => $token,
        'url'        => $url,
        'code'       => $code,
        'ttl'        => $ttl,
        'expires_at' => gmdate('c', time() + $ttl),
    ));
}

/**
 * Writes a short-lived (seconds-based) token directly into svl_magic_tokens.
 * The svl-magic.php redeem handler will pick it up on ?vip_token=... and burn it.
 */
function svl_bot_create_token($code, $ttl_seconds, $note = '') {
    if (!function_exists('svl_magic_get_all') || !function_exists('svl_magic_save_all') || !function_exists('svl_magic_generate_token')) {
        return false;
    }
    $code = trim((string) $code);
    if ($code === '') return false;

    $arr   = svl_magic_get_all();
    $token = svl_magic_generate_token();
    $arr[$token] = array(
        'code'    => $code,
        'created' => time(),
        'expires' => time() + max(60, intval($ttl_seconds)),
        'used_at' => 0,
        'used_ip' => '',
        'note'    => mb_substr((string) $note, 0, 200),
    );
    svl_magic_save_all($arr);
    return $token;
}

// =====================================================
// 5. ADMIN PAGE
// =====================================================

add_action('admin_menu', 'svl_bot_admin_menu', 35);
function svl_bot_admin_menu() {
    // Use 'svl-stats' as parent (same as Magic Links submenu) when present.
    $parent = 'svl-stats';
    add_submenu_page(
        $parent,
        'Telegram бот',
        '🤖 Telegram бот',
        'manage_options',
        'svl-bot',
        'svl_bot_admin_page'
    );
}

function svl_bot_admin_page() {
    if (!current_user_can('manage_options')) wp_die();

    if (isset($_POST['svl_bot_regen']) && check_admin_referer('svl_bot_regen', 'svl_bot_regen_nonce')) {
        update_option(SVL_BOT_OPT_SECRET, bin2hex(random_bytes(32)));
        echo '<div class="notice notice-success is-dismissible"><p>✅ Новый секрет сгенерирован.</p></div>';
    }

    $secret = (string) get_option(SVL_BOT_OPT_SECRET, '');
    $ttl    = intval(get_option(SVL_BOT_OPT_TTL, SVL_BOT_DEFAULT_TTL));

    $tokens = function_exists('svl_magic_get_all') ? svl_magic_get_all() : array();
    $bot_tokens = array_filter($tokens, function($t){ return !empty($t['note']) && strpos($t['note'], 'tg-bot:') === 0; });
    $total = count($bot_tokens);
    $used  = 0; $active = 0; $expired = 0;
    foreach ($bot_tokens as $t) {
        if (!empty($t['used_at'])) $used++;
        elseif (!empty($t['expires']) && $t['expires'] < time()) $expired++;
        else $active++;
    }
    ?>
    <div class="wrap">
        <h1>🤖 Telegram бот</h1>
        <p>Внешний Telegram-бот выдаёт подписчикам одноразовые ссылки разблокировки. Использует механизм Magic Links (см. подменю «🪄 Magic Links»).</p>

        <h2 style="margin-top:24px;">Bearer-секрет</h2>
        <form method="post" action="options.php">
            <?php settings_fields('svl_bot_opts'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="svl_bot_secret">Секрет</label></th>
                    <td>
                        <input type="text" id="svl_bot_secret" name="<?php echo esc_attr(SVL_BOT_OPT_SECRET); ?>" value="<?php echo esc_attr($secret); ?>" class="large-text code" readonly onclick="this.select()">
                        <p class="description">Бот шлёт его в заголовке <code>Authorization: Bearer ...</code>. Без секрета REST-эндпоинты возвращают 503.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="svl_bot_token_ttl">TTL ссылки (сек)</label></th>
                    <td>
                        <input type="number" id="svl_bot_token_ttl" name="<?php echo esc_attr(SVL_BOT_OPT_TTL); ?>" value="<?php echo esc_attr($ttl); ?>" min="60" max="86400" class="small-text">
                        <p class="description">Срок жизни одноразовой ссылки. По умолчанию 900 (15 мин). Минимум 60 сек, максимум 24 часа.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Сохранить'); ?>
        </form>

        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('svl_bot_regen', 'svl_bot_regen_nonce'); ?>
            <button type="submit" name="svl_bot_regen" value="1" class="button" onclick="return confirm('Перегенерировать секрет? Старый перестанет работать у бота.')">🔄 Перегенерировать секрет</button>
        </form>

        <h2 style="margin-top:32px;">Эндпоинты</h2>
        <table class="form-table">
            <tr><th>Список статей</th><td><code>GET <?php echo esc_html(rest_url('vip/v1/lockers')); ?></code></td></tr>
            <tr><th>Выдать ссылку</th><td><code>POST <?php echo esc_html(rest_url('vip/v1/issue')); ?></code> { code, post_id, telegram_user_id }</td></tr>
            <tr><th>Активировать (публ.)</th><td><code><?php echo esc_html(home_url('/?vip_token=...')); ?></code> — обрабатывает svl-magic.php</td></tr>
        </table>

        <h2 style="margin-top:32px;">Статистика бот-токенов</h2>
        <p>
            Всего: <b><?php echo $total; ?></b> ·
            Активных: <b style="color:#166534;"><?php echo $active; ?></b> ·
            Использовано: <b style="color:#1e40af;"><?php echo $used; ?></b> ·
            Истёкших: <b style="color:#991b1b;"><?php echo $expired; ?></b>
        </p>
        <p class="description">Полный список и управление — на странице <a href="<?php echo esc_url(admin_url('admin.php?page=svl-magic')); ?>">🪄 Magic Links</a>.</p>
    </div>
    <?php
}
