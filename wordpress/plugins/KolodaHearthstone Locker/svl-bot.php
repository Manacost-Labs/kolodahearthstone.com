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
 *   GET  /wp-json/vip/v1/redeem    -> token redirect that sets the unlock cookie
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
    register_rest_route('vip/v1', '/redeem', array(
        'methods'             => 'GET',
        'callback'            => 'svl_bot_rest_redeem',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('vip/v1', '/redeem/(?P<kha>[A-Za-z0-9_-]+)/(?P<post_id>[0-9]+)', array(
        'methods'             => 'GET',
        'callback'            => 'svl_bot_rest_redeem',
        'permission_callback' => '__return_true',
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
        if (empty($codes)) continue;

        $image = svl_bot_pick_image($r);
        $excerpt = svl_bot_make_excerpt($r);

        foreach ($codes as $code) {
            $key = $r->ID . '|' . $code;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = array(
                'post_id' => (int) $r->ID,
                'code'    => $code,
                'title'   => html_entity_decode(get_the_title($r->ID), ENT_QUOTES, 'UTF-8'),
                'url'     => get_permalink($r->ID),
                'image'   => $image,
                'excerpt' => $excerpt,
                'date'    => $r->post_date,
                'type'    => $r->post_type,
            );
        }
    }
    return $out;
}

/**
 * Public image URL for the post. Cascade of fallbacks so every locker has
 * a cover, branded if the post itself has none.
 */
function svl_bot_pick_image($post) {
    // 1. Featured image (large)
    $url = get_the_post_thumbnail_url($post->ID, 'large');
    if ($url) return (string) $url;

    // 2. SEO modules' OG image (this plugin's own SEO module + AIOSEO + Yoast)
    foreach (array('_vip_seo_og_image', '_aioseo_og_image_custom_url', '_yoast_wpseo_opengraph-image') as $meta_key) {
        $val = get_post_meta($post->ID, $meta_key, true);
        if (!empty($val)) return (string) $val;
    }

    // 3. First <img> embedded in the post body
    if (preg_match('/<img[^>]+src=(["\'])([^"\']+)\1/i', (string) ($post->post_content ?? ''), $im)) {
        return (string) $im[2];
    }

    // 4. Site-wide brand fallback: wallpaper.* shipped with the plugin
    $dir  = plugin_dir_path(__FILE__);
    $base = plugin_dir_url(__FILE__);
    foreach (array('jpg', 'jpeg', 'png', 'webp', 'gif') as $ext) {
        if (file_exists($dir . 'wallpaper.' . $ext)) {
            return $base . 'wallpaper.' . $ext;
        }
    }

    // 5. Site icon as last resort
    if (function_exists('get_site_icon_url')) {
        $icon = get_site_icon_url(512);
        if ($icon) return (string) $icon;
    }

    return '';
}

/**
 * Builds a short plain-text excerpt: explicit post_excerpt -> stripped
 * shortcodes/HTML from post_content, capped at ~600 chars.
 */
function svl_bot_make_excerpt($post) {
    $raw = trim((string) ($post->post_excerpt ?? ''));
    if ($raw === '') {
        $raw = strip_shortcodes((string) ($post->post_content ?? ''));
        $raw = wp_strip_all_tags($raw);
    } else {
        $raw = wp_strip_all_tags($raw);
    }
    $raw = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $raw = preg_replace('/\s+/u', ' ', $raw);
    $raw = trim((string) $raw);
    if (function_exists('mb_strlen') && mb_strlen($raw) > 600) {
        $raw = rtrim(mb_substr($raw, 0, 599)) . '…';
    } elseif (strlen($raw) > 600) {
        $raw = rtrim(substr($raw, 0, 599)) . '…';
    }
    return $raw;
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

    $token = svl_bot_create_token($code, $ttl, 'tg-bot:' . $tg_user, $post_id);
    if (!$token) {
        return new WP_Error('svl_bot_token', 'Token creation failed', array('status' => 500));
    }

    $base = ($post_id > 0) ? get_permalink($post_id) : home_url('/');
    $url = rest_url('vip/v1/redeem/' . rawurlencode($token) . '/' . max(0, $post_id));

    return rest_ensure_response(array(
        'token'      => $token,
        'url'        => $url,
        'target'     => $base,
        'code'       => $code,
        'ttl'        => $ttl,
        'expires_at' => gmdate('c', time() + $ttl),
    ));
}

/**
 * Public token redemption endpoint.
 *
 * This intentionally does not rely on the front-end ?vip_token=... handler:
 * full-page caches / reverse proxies can serve the post before WordPress gets
 * a chance to run init hooks. REST requests bypass that page-cache path, so the
 * bot link can reliably set the cookie and then redirect to the article.
 */
function svl_bot_rest_redeem($request) {
    $token = svl_bot_read_redeem_token($request);
    $post_id = intval($request->get_param('post_id'));

    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        svl_bot_set_cookie('vip_magic_status', 'invalid', time() + 60);
        return svl_bot_redirect_response(
            $post_id > 0 ? get_permalink($post_id) : home_url('/'),
            'bad-format-len-' . strlen($token) . '-' . substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $token), 0, 16)
        );
    }

    $data = svl_bot_lookup_token($token);
    if (!$data || empty($data['code'])) {
        svl_bot_set_cookie('vip_magic_status', 'invalid', time() + 60);
        return svl_bot_redirect_response($post_id > 0 ? get_permalink($post_id) : home_url('/'), 'not-found');
    }

    $code = trim((string) $data['code']);
    if (!empty($data['post_id'])) {
        $post_id = intval($data['post_id']);
    }

    $cookie_name = 'vip_access_' . preg_replace('/[^a-z0-9]/', '', strtolower($code));
    $days = function_exists('svl_opt') ? intval(svl_opt('svl_cookie_days') ?: 7) : 7;
    $expires = time() + max(1, $days) * DAY_IN_SECONDS;
    $note = isset($data['note']) ? (string) $data['note'] : '';
    if (strpos($note, 'tg-bot:') === 0) {
        $access_source = 'telegram';
    } elseif (strpos($note, 'api:') === 0) {
        $access_source = 'arena';
    } else {
        $access_source = 'magic';
    }

    svl_bot_set_cookie($cookie_name, 'true', $expires);
    svl_bot_set_cookie('kh_review_access_source', $access_source, $expires);
    svl_bot_set_cookie('vip_magic_status', 'success', time() + 60);

    svl_bot_burn_token($token);

    if (function_exists('svl_pro_log')) {
        svl_pro_log(array(
            'code'    => $code,
            'post_id' => $post_id,
            'referer' => 'telegram-bot',
            'is_fail' => 0,
        ));
    }

    $stats = get_option('svl_locker_stats', array());
    if (!is_array($stats)) $stats = array();
    $stats[$code] = isset($stats[$code]) ? intval($stats[$code]) + 1 : 1;
    update_option('svl_locker_stats', $stats, false);

    $target = $post_id > 0 ? get_permalink($post_id) : home_url('/');
    return svl_bot_redirect_response($target, 'success');
}

function svl_bot_read_redeem_token($request) {
    $url_params = method_exists($request, 'get_url_params') ? $request->get_url_params() : array();
    foreach (array('kha', 'v', 'svl_token', 'vip_token', 'token') as $key) {
        if (isset($url_params[$key])) {
            $value = sanitize_text_field((string) $url_params[$key]);
            if ($value !== '') return $value;
        }
    }

    $query = method_exists($request, 'get_query_params') ? $request->get_query_params() : array();
    foreach (array('kha', 'v', 'svl_token', 'vip_token', 'token') as $key) {
        $value = null;
        if (isset($query[$key])) {
            $value = $query[$key];
        } elseif (isset($_GET[$key])) {
            $value = wp_unslash($_GET[$key]);
        }
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = sanitize_text_field((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function svl_bot_set_cookie($name, $value, $expires) {
    $args = array(
        'expires'  => intval($expires),
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => false,
        'samesite' => 'Lax',
    );
    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
        $args['domain'] = COOKIE_DOMAIN;
    }
    setcookie($name, $value, $args);
    $_COOKIE[$name] = $value;
}

function svl_bot_redirect_response($url, $status = '') {
    $fallback = home_url('/');
    $target = wp_validate_redirect((string) $url, $fallback);
    $response = new WP_REST_Response(null, 302);
    $response->header('Location', $target);
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('X-SVL-Bot-Version', 'redeem-direct-db-20260602');
    if ($status !== '') {
        $response->header('X-SVL-Redeem', $status);
    }
    return $response;
}

function svl_bot_get_magic_tokens_direct() {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'svl_magic_tokens'
    ));
    if ($raw === null) return array();
    $arr = maybe_unserialize($raw);
    return is_array($arr) ? $arr : array();
}

function svl_bot_save_magic_tokens_direct($arr) {
    if (!is_array($arr)) $arr = array();
    update_option('svl_magic_tokens', $arr, false);
    wp_cache_delete('svl_magic_tokens', 'options');
    wp_cache_delete('alloptions', 'options');
}

function svl_bot_lookup_token($token) {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return false;
    $arr = svl_bot_get_magic_tokens_direct();
    if (empty($arr[$token]) || !is_array($arr[$token])) return false;
    $t = $arr[$token];
    if (!empty($t['used_at'])) return false;
    if (!empty($t['expires']) && intval($t['expires']) < time()) return false;
    return $t;
}

function svl_bot_burn_token($token) {
    $arr = svl_bot_get_magic_tokens_direct();
    if (empty($arr[$token]) || !is_array($arr[$token])) return;
    $arr[$token]['used_at'] = time();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $arr[$token]['used_ip'] = mb_substr($ip, 0, 64);
    svl_bot_save_magic_tokens_direct($arr);
}

/**
 * Writes a short-lived (seconds-based) token directly into svl_magic_tokens.
 * The svl-magic.php redeem handler will pick it up on ?vip_token=... and burn it.
 */
function svl_bot_create_token($code, $ttl_seconds, $note = '', $post_id = 0) {
    $code = trim((string) $code);
    if ($code === '') return false;

    $arr   = svl_bot_get_magic_tokens_direct();
    $token = function_exists('svl_magic_generate_token') ? svl_magic_generate_token() : bin2hex(random_bytes(16));
    $arr[$token] = array(
        'code'    => $code,
        'created' => time(),
        'expires' => time() + max(60, intval($ttl_seconds)),
        'used_at' => 0,
        'used_ip' => '',
        'post_id' => max(0, intval($post_id)),
        'note'    => mb_substr((string) $note, 0, 200),
    );
    svl_bot_save_magic_tokens_direct($arr);
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
                        <input type="text" id="svl_bot_secret" name="<?php echo esc_attr(SVL_BOT_OPT_SECRET); ?>" value="<?php echo esc_attr($secret); ?>" class="large-text code" autocomplete="off" spellcheck="false" onclick="this.select()" placeholder="Вставьте секрет, который зашит в боте, или нажмите «Перегенерировать»">
                        <p class="description">Бот шлёт его в заголовке <code>Authorization: Bearer ...</code>. Без секрета REST-эндпоинты возвращают 503. После сохранения нажмите на поле — выделится для копирования.</p>
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
