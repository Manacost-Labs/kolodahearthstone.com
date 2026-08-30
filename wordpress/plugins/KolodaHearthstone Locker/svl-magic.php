<?php
/**
 * SVL Magic Links — одноразовые ссылки автоматической разблокировки.
 * URL формат: ?vip_token=XXXXXXXX (32 символа). Открытие = установка cookie + сжигание токена.
 */
if (!defined('ABSPATH')) exit;

// =====================================================
// 1. ХРАНИЛИЩЕ ТОКЕНОВ
// Структура: array(token => array('code'=>, 'created'=>, 'expires'=>, 'used_at'=>0, 'used_ip'=>'', 'note'=>''))
// =====================================================

function svl_magic_get_all() {
    $arr = get_option('svl_magic_tokens', array());
    return is_array($arr) ? $arr : array();
}

function svl_magic_save_all($arr) {
    update_option('svl_magic_tokens', $arr, false);
}

function svl_magic_generate_token() {
    return bin2hex(random_bytes(16)); // 32 hex chars
}

/**
 * Создаёт токен. $code обязателен, $expires_days — срок действия (0 = бессрочно).
 */
function svl_magic_create_token($code, $expires_days = 30, $note = '') {
    $code = trim($code);
    if ($code === '') return false;
    $arr = svl_magic_get_all();
    $token = svl_magic_generate_token();
    $arr[$token] = array(
        'code'    => $code,
        'created' => time(),
        'expires' => $expires_days > 0 ? time() + $expires_days * DAY_IN_SECONDS : 0,
        'used_at' => 0,
        'used_ip' => '',
        'note'    => mb_substr($note, 0, 200),
    );
    svl_magic_save_all($arr);
    return $token;
}

/**
 * Находит и валидирует токен. Возвращает данные либо false.
 */
function svl_magic_lookup_token($token) {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return false;
    $arr = svl_magic_get_all();
    if (empty($arr[$token])) return false;
    $t = $arr[$token];
    if (!empty($t['used_at'])) return false;
    if (!empty($t['expires']) && $t['expires'] < time()) return false;
    return $t;
}

/**
 * Сжигает токен (помечает использованным).
 */
function svl_magic_burn_token($token) {
    $arr = svl_magic_get_all();
    if (empty($arr[$token])) return;
    $arr[$token]['used_at'] = time();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $arr[$token]['used_ip'] = mb_substr($ip, 0, 64);
    svl_magic_save_all($arr);
}

// =====================================================
// 2. ОБРАБОТКА ?vip_token=XXX в URL
// Делается на early hook чтобы поставить cookie до отдачи страницы
// =====================================================

add_action('init', 'svl_magic_handle_url', 5);
function svl_magic_handle_url() {
    if (empty($_GET['vip_token'])) return;
    $token = sanitize_text_field(wp_unslash($_GET['vip_token']));
    $data  = svl_magic_lookup_token($token);
    if (!$data) {
        // Токен невалиден — устанавливаем флаг, чтобы показать сообщение
        if (!headers_sent()) setcookie('vip_magic_status', 'invalid', time() + 60, '/');
        return;
    }
    $code = $data['code'];
    $cookie_name = 'vip_access_' . preg_replace('/[^a-z0-9]/', '', strtolower($code));
    $days = (int) (svl_opt('svl_cookie_days') ?: 7);
    if (!headers_sent()) {
        setcookie($cookie_name, 'true', time() + $days * DAY_IN_SECONDS, '/');
        setcookie('kh_review_access_source', 'magic', time() + $days * DAY_IN_SECONDS, '/');
        setcookie('vip_magic_status', 'success', time() + 60, '/');
    }
    svl_magic_burn_token($token);

    // Логируем как успешную разблокировку
    if (function_exists('svl_pro_log')) {
        svl_pro_log(array(
            'code'    => $code,
            'post_id' => 0,
            'referer' => 'magic-link',
            'is_fail' => 0,
        ));
    }
    // Инкрементим основную статистику
    $stats = get_option('svl_locker_stats', array());
    $stats[$code] = isset($stats[$code]) ? $stats[$code] + 1 : 1;
    update_option('svl_locker_stats', $stats);

    // Чистим URL от token, чтобы пользователь не делился ссылкой
    if (!headers_sent()) {
        $clean = remove_query_arg('vip_token');
        wp_safe_redirect($clean);
        exit;
    }
}

// Показываем баннер «активирован/невалиден» в шорткоде через классы (рендерится в JS)
add_action('wp_footer', 'svl_magic_status_notice');
function svl_magic_status_notice() {
    if (empty($_COOKIE['vip_magic_status'])) return;
    $status = sanitize_text_field(wp_unslash($_COOKIE['vip_magic_status']));
    if (!in_array($status, array('success','invalid'), true)) return;
    // Очищаем cookie
    if (!headers_sent()) setcookie('vip_magic_status', '', time() - 3600, '/');
    ?>
    <script>
    (function(){
        var status = <?php echo wp_json_encode($status); ?>;
        document.querySelectorAll('.svl-wrapper').forEach(function(w){
            var msg = w.querySelector('.svl-body');
            if (!msg) return;
            var el = document.createElement('div');
            el.className = 'svl-magic-badge';
            if (status === 'success') {
                el.style.background = 'linear-gradient(135deg,#bbf7d0,#86efac)';
                el.style.color = '#166534';
                el.innerHTML = '✨ Доступ активирован по одноразовой ссылке';
            } else {
                el.style.background = 'linear-gradient(135deg,#fecaca,#fca5a5)';
                el.style.color = '#991b1b';
                el.innerHTML = '⚠️ Ссылка недействительна или уже использована';
            }
            msg.insertBefore(el, msg.firstChild);
        });
    })();
    </script>
    <?php
}

// =====================================================
// 3. АДМИН-СТРАНИЦА «Magic Links»
// =====================================================

add_action('admin_menu', 'svl_magic_admin_menu', 30);
function svl_magic_admin_menu() {
    add_submenu_page(
        'svl-stats', 'Magic Links', '🪄 Magic Links',
        'manage_options', 'svl-magic', 'svl_magic_admin_page'
    );
}

function svl_magic_admin_page() {
    if (!current_user_can('manage_options')) wp_die();

    // Создание токена
    if (isset($_POST['svl_magic_create']) && check_admin_referer('svl_magic', 'svl_magic_nonce')) {
        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $exp  = max(0, intval($_POST['expires_days'] ?? 30));
        $note = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));
        $count = max(1, min(100, intval($_POST['count'] ?? 1)));
        $tokens = array();
        for ($i = 0; $i < $count; $i++) {
            $t = svl_magic_create_token($code, $exp, $note);
            if ($t) $tokens[] = $t;
        }
        if ($tokens) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Создано ссылок: <strong>' . count($tokens) . '</strong></p></div>';
        }
    }

    // Удаление токена
    if (isset($_POST['svl_magic_revoke']) && check_admin_referer('svl_magic_revoke', 'svl_magic_revoke_nonce')) {
        $tk = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $arr = svl_magic_get_all();
        if (isset($arr[$tk])) { unset($arr[$tk]); svl_magic_save_all($arr); }
        echo '<div class="notice notice-success is-dismissible"><p>🗑 Ссылка удалена</p></div>';
    }

    // Чистка использованных
    if (isset($_POST['svl_magic_purge_used']) && check_admin_referer('svl_magic_purge', 'svl_magic_purge_nonce')) {
        $arr = svl_magic_get_all();
        $arr = array_filter($arr, function($t){ return empty($t['used_at']); });
        svl_magic_save_all($arr);
        echo '<div class="notice notice-success is-dismissible"><p>🧹 Использованные ссылки очищены</p></div>';
    }

    $tokens = svl_magic_get_all();
    uasort($tokens, function($a, $b){ return $b['created'] - $a['created']; });

    // Статистика
    $total = count($tokens);
    $active = 0; $used = 0; $expired = 0;
    foreach ($tokens as $t) {
        if (!empty($t['used_at'])) $used++;
        elseif (!empty($t['expires']) && $t['expires'] < time()) $expired++;
        else $active++;
    }
    ?>
    <div class="wrap">
        <div class="svl-hero">
            <h1>🪄 Magic Links — одноразовые ссылки</h1>
            <p>Сгенерируйте URL вида <code style="background:rgba(255,255,255,.15); padding:2px 8px; border-radius:4px; color:#fff;">site.com/?vip_token=XXXX</code>. При открытии — автоматическая разблокировка контента и «сжигание» ссылки.</p>
            <p style="margin-top:10px; opacity:.85;">💡 Идеально для раздачи в Telegram, тестового доступа стримерам, замены утечённых кодов.</p>
        </div>

        <div class="svl-cards">
            <div class="svl-card c-orange"><div class="svl-card-ico">🔗</div><div class="svl-card-label">Всего создано</div><div class="svl-card-value"><?php echo number_format_i18n($total); ?></div></div>
            <div class="svl-card c-green"><div class="svl-card-ico">✅</div><div class="svl-card-label">Активных</div><div class="svl-card-value"><?php echo number_format_i18n($active); ?></div></div>
            <div class="svl-card c-blue"><div class="svl-card-ico">👤</div><div class="svl-card-label">Использовано</div><div class="svl-card-value"><?php echo number_format_i18n($used); ?></div></div>
            <div class="svl-card c-purple"><div class="svl-card-ico">⏳</div><div class="svl-card-label">Истёкших</div><div class="svl-card-value"><?php echo number_format_i18n($expired); ?></div></div>
        </div>

        <div class="svl-panel">
            <h2>➕ Создать ссылки</h2>
            <p class="svl-panel-desc">Генерирует одну или несколько одноразовых ссылок для одного кода.</p>
            <form method="post" style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 14px; align-items: end;">
                <?php wp_nonce_field('svl_magic', 'svl_magic_nonce'); ?>
                <div class="svl-field" style="margin:0;">
                    <label class="svl-lbl" for="mlcode">Код для разблокировки</label>
                    <input type="text" id="mlcode" name="code" required placeholder="например: T6aHd">
                </div>
                <div class="svl-field" style="margin:0;">
                    <label class="svl-lbl" for="mlexp">Срок действия (дней)</label>
                    <input type="number" id="mlexp" name="expires_days" value="30" min="0" max="365">
                </div>
                <div class="svl-field" style="margin:0;">
                    <label class="svl-lbl" for="mlcount">Сколько ссылок создать</label>
                    <input type="number" id="mlcount" name="count" value="1" min="1" max="100">
                </div>
                <div class="svl-field" style="margin:0;">
                    <label class="svl-lbl" for="mlnote">Заметка</label>
                    <input type="text" id="mlnote" name="note" placeholder="Telegram-промо, стример Х">
                </div>
                <button type="submit" name="svl_magic_create" class="svl-btn svl-btn-primary">🎲 Создать</button>
            </form>
            <p class="svl-help" style="margin-top:12px; font-size:12px; color:var(--muted);">
                <strong>0 дней</strong> = бессрочно (использовать только пока не активирована).<br>
                <strong>Несколько ссылок</strong> на один код — каждая разблокирует только один раз.
            </p>
        </div>

        <div class="svl-panel">
            <h2>📋 Все ссылки (<?php echo $total; ?>)</h2>
            <?php if (!empty($tokens)): ?>
            <table class="svl-table">
                <thead>
                    <tr>
                        <th>Ссылка</th>
                        <th style="width:120px;">Код</th>
                        <th style="width:140px;">Создана</th>
                        <th style="width:120px;">Истекает</th>
                        <th style="width:120px;">Статус</th>
                        <th>Заметка</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $tk => $t):
                        $url = home_url('/?vip_token=' . $tk);
                        $is_used = !empty($t['used_at']);
                        $is_expired = !$is_used && !empty($t['expires']) && $t['expires'] < time();
                    ?>
                    <tr>
                        <td>
                            <input type="text" readonly value="<?php echo esc_attr($url); ?>" onclick="this.select(); document.execCommand('copy'); this.style.background='#dcfce7';" style="width:100%; max-width:380px; padding:6px 10px; border:1px solid var(--br); border-radius:6px; font-family:ui-monospace,monospace; font-size:11px; cursor:pointer;" title="Кликните чтобы скопировать">
                        </td>
                        <td><span class="svl-pill"><?php echo esc_html($t['code']); ?></span></td>
                        <td style="font-size:12px; color:var(--muted);"><?php echo esc_html(date_i18n('d.m.Y H:i', $t['created'])); ?></td>
                        <td style="font-size:12px; color:var(--muted);">
                            <?php if (empty($t['expires'])): ?><em>бессрочно</em>
                            <?php else: echo esc_html(date_i18n('d.m.Y', $t['expires'])); endif; ?>
                        </td>
                        <td>
                            <?php if ($is_used): ?>
                                <span style="background:#dbeafe; color:#1e40af; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:600;">👤 Использована</span>
                                <div style="font-size:10px; color:var(--muted); margin-top:2px;"><?php echo esc_html(date_i18n('d.m H:i', $t['used_at'])); ?></div>
                            <?php elseif ($is_expired): ?>
                                <span style="background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:600;">⏳ Истекла</span>
                            <?php else: ?>
                                <span style="background:#dcfce7; color:#166534; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:600;">✅ Активна</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;"><?php echo esc_html($t['note']); ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Удалить эту ссылку?');" style="margin:0;">
                                <?php wp_nonce_field('svl_magic_revoke', 'svl_magic_revoke_nonce'); ?>
                                <input type="hidden" name="token" value="<?php echo esc_attr($tk); ?>">
                                <button type="submit" name="svl_magic_revoke" class="svl-btn svl-btn-danger" style="font-size:11px; padding:4px 10px;">🗑</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($used > 0): ?>
            <form method="post" style="margin-top:18px;" onsubmit="return confirm('Удалить все использованные ссылки?');">
                <?php wp_nonce_field('svl_magic_purge', 'svl_magic_purge_nonce'); ?>
                <button type="submit" name="svl_magic_purge_used" class="svl-btn">🧹 Очистить использованные (<?php echo $used; ?>)</button>
            </form>
            <?php endif; ?>
            <?php else: ?>
                <div class="svl-empty">
                    <div class="svl-empty-ico">🔗</div>
                    <p>Ссылок пока нет. Создайте первую через форму выше.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
