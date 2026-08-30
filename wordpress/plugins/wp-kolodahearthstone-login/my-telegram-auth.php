<?php
/*
Plugin Name: KolodaHearthstone: Core
Description: Основной плагин (Login 13.0: STABLE FIX)
Version: 13.0
Author: Manacost
*/

if (!defined('ABSPATH')) exit;

if (!class_exists('My_Telegram_Auth')) {

class My_Telegram_Auth {

    /**
     * Логирование действий (только в режиме отладки)
     */
    private function log($message, $type = 'info') {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;
        
        $log_file = WP_CONTENT_DIR . '/mtp-auth.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$type}] {$message}\n";
        
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
    }

    public function __construct() {
        // Инициализация сессии для сохранения return_url
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // --- Шорткоды ---
        add_shortcode('my_tg_login', [$this, 'render_login_box']);
        add_shortcode('my_tg_profile', [$this, 'render_profile']);
        add_shortcode('mtp_locker', [$this, 'render_locker_shortcode']);
        add_shortcode('sub_deck', [$this, 'render_deck_feed']);
        add_shortcode('deck_editor', [$this, 'render_deck_editor']);
        add_shortcode('mtp_fav_btn', [$this, 'render_fav_btn']);
        add_shortcode('sub_deck_favs', [$this, 'render_fav_feed']);

        // --- Инициализация ---
        add_action('init', [$this, 'handle_login'], 1);
        add_action('init', [$this, 'handle_profile_actions']);
        add_action('init', [$this, 'handle_deck_submission']);
        add_action('init', [$this, 'register_deck_post_type_core'], 0);
        add_action('init', [$this, 'create_access_codes_table'], 0);
        
        // Ранняя проверка сессии для восстановления куки (до wp_set_current_user и W3 Total Cache)
        add_action('plugins_loaded', [$this, 'early_session_check'], 1); // Приоритет 1 - раньше W3 Total Cache

        // --- Перехваты ---
        add_filter('get_edit_post_link', [$this, 'redirect_edit_link_to_frontend'], 10, 3);
        // СОВМЕСТИМОСТЬ С AIOSEO: Используем приоритет 20, чтобы наш фильтр применялся после SEO плагинов
        add_filter('the_content', [$this, 'auto_format_content_injector'], 20);
        
        // СОВМЕСТИМОСТЬ С MEMBERS: Добавляем фильтр для обхода проверок доступа для пользователей с кодом
        if (class_exists('Members_Load') || function_exists('members_plugin')) {
            add_filter('members_has_post_access', [$this, 'members_bypass_access_with_code'], 10, 3);
            add_filter('members_can_current_user_view_post', [$this, 'members_bypass_access_with_code'], 10, 3);
            // Добавляем фильтр для временного предоставления capabilities
            add_filter('user_has_cap', [$this, 'members_grant_capabilities_with_code'], 10, 4);
        }
        
        // --- Визуал и ссылки ---
        add_filter('author_link', [$this, 'replace_author_link_global'], 10, 3);
        add_filter('get_avatar', [$this, 'replace_wp_avatar'], 10, 5);
        add_filter('get_avatar_url', [$this, 'replace_wp_avatar_url'], 10, 3);
        add_filter('get_comment_author_url', [$this, 'replace_comment_author_url'], 10, 3);
        add_filter('wp_nav_menu_objects', [$this, 'dynamic_menu_item']);

        // --- AJAX ---
        add_action('wp_ajax_mtp_fav_action', [$this, 'handle_fav_action']);
        add_action('wp_ajax_mtp_save_avatar', [$this, 'ajax_save_avatar']);
        add_action('wp_ajax_mtp_delete_avatar', [$this, 'ajax_delete_avatar']);
        add_action('wp_ajax_mtp_select_avatar', [$this, 'ajax_select_avatar']);
        add_action('wp_ajax_mtp_check_subscription_batch', [$this, 'ajax_check_subscription_batch']);
        add_action('wp_ajax_mtp_check_single_user', [$this, 'ajax_check_single_user']);
        add_action('wp_ajax_mtp_get_statistics', [$this, 'ajax_get_statistics']);
        add_action('wp_ajax_mtp_check_auth_status', [$this, 'ajax_check_auth_status']);
        add_action('wp_ajax_nopriv_mtp_check_auth_status', [$this, 'ajax_check_auth_status']);
        add_action('wp_ajax_mtp_verify_access_code', [$this, 'ajax_verify_access_code']);
        add_action('wp_ajax_nopriv_mtp_verify_access_code', [$this, 'ajax_verify_access_code']);
        add_action('wp_ajax_mtp_create_access_code', [$this, 'ajax_create_access_code']);
        add_action('wp_ajax_mtp_delete_access_code', [$this, 'ajax_delete_access_code']);
        add_action('wp_ajax_mtp_get_code_statistics', [$this, 'ajax_get_code_statistics']);

        // --- Админка ---
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_manual_actions']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp', [$this, 'schedule_cron']);
        add_action('mta_daily_sub_check', [$this, 'check_all_users_subscriptions']);
        add_action('mta_check_single_user_sub', [$this, 'async_check_single_user_subscriptions']);
        
        // --- Защита от автоматического выхода ---
        add_filter('auth_cookie_expiration', [$this, 'extend_auth_cookie_expiration'], 10, 3);
        add_action('set_auth_cookie', [$this, 'ensure_auth_cookie_persistence'], 10, 5);
        add_action('init', [$this, 'verify_user_session'], 1); // Ранняя проверка сессии (приоритет 1)
        add_action('wp_loaded', [$this, 'verify_user_session_after_loaded'], 1); // Проверка после загрузки WordPress
        add_action('template_redirect', [$this, 'verify_user_session_on_page_load'], 1); // Проверка при загрузке любой страницы
        add_action('send_headers', [$this, 'prevent_cache_for_logged_in'], 1); // Раньше для отправки заголовков
        
        // --- Интеграция с W3 Total Cache ---
        add_filter('w3tc_can_cache', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        add_filter('w3tc_can_cache_redis', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        add_filter('w3tc_can_cache_memcached', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        add_filter('w3tc_can_cache_database', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        add_filter('w3tc_can_cache_apc', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        add_filter('w3tc_can_cache_opcache', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        add_filter('w3tc_can_cache_file', [$this, 'w3tc_prevent_cache_for_telegram_users'], 10, 1);
        
        // --- СОВМЕСТИМОСТЬ С WORDFENCE: Добавляем фильтры для whitelist наших запросов (если Wordfence активен)
        if (class_exists('wordfence') || defined('WORDFENCE_VERSION')) {
            add_filter('wordfence_ls_require_captcha', [$this, 'wordfence_whitelist_our_ajax'], 10, 1);
            // Используем правильный фильтр для Wordfence (может отличаться в разных версиях)
            if (function_exists('wordfence_firewall_whitelisted_urls')) {
                add_filter('wordfence_firewall_whitelisted_urls', [$this, 'wordfence_whitelist_urls'], 10, 1);
            }
        }
    }

    // 1. РЕГИСТРАЦИЯ ТИПА ЗАПИСИ
    public function register_deck_post_type_core() {
        register_post_type('user_deck', [
            'labels' => ['name' => 'Колоды Игроков', 'singular_name' => 'Колода', 'menu_name' => 'Колоды Игроков'],
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => ['slug' => 'user_deck', 'with_front' => false],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 5,
            'menu_icon' => 'dashicons-cards',
            'supports' => ['title', 'editor', 'author', 'thumbnail', 'comments', 'custom-fields']
        ]);
        if (is_admin()) flush_rewrite_rules(); // Авто-фикс 404
    }

    public function redirect_edit_link_to_frontend($link, $post_id, $context) {
        if (get_post_type($post_id) === 'user_deck' && !is_admin()) {
            return home_url('/dobavlenie-kolody/?edit_id=' . $post_id);
        }
        return $link;
    }

    // 2. ЛОГИН (FIX 500 ERROR)
    public function render_login_box() { 
        $token = get_option('mta_bot_token'); 
        if (!$token) return '<div style="color:red;font-size:10px;">Token not set</div>'; 
        
        $out = '<div class="mtp-widget-container" style="display:inline-block;">'; 
        if (is_user_logged_in()) { 
            $u = wp_get_current_user(); 
            $prof_link = home_url('/lichnyj-kabinet/'); 
            $photo_url = $this->get_user_photo_url_raw($u->ID); 
            if(!$photo_url) $photo_url = get_avatar_url($u->ID); 
            $out .= '<a href="'.$prof_link.'" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; transition:opacity 0.2s;" onmouseover="this.style.opacity=\'0.8\'" onmouseout="this.style.opacity=\'1\'">'; 
            $out .= '<img src="'.$photo_url.'" style="width:48px; height:48px; border-radius:50%; object-fit:cover; box-shadow:0 3px 6px rgba(0,0,0,0.1); border:2px solid #fff;">'; 
            $out .= '<div style="display:flex; flex-direction:column; line-height:1.3;"><span style="font-weight:700; font-size:1.05rem;">'.$u->display_name.'</span><span style="font-size:0.75rem; opacity:0.8; color:#718096;">Личный кабинет</span></div></a>'; 
        } else { 
            $bot_name = $this->get_bot_username($token); 
            if ($bot_name) {
                // Сохраняем текущий URL в сессии для редиректа после авторизации
                if (!session_id()) {
                    session_start();
                }
                $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $clean_url = remove_query_arg(['tg_auth', 'hash', 'id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'return_to', 'auth_success'], $current_url);
                $_SESSION['mtp_return_url'] = $clean_url;
                $auth_url = home_url('/?tg_auth=1');
                
                // Добавляем улучшенные стили и индикатор загрузки
                $out .= '<style>
                .mtp-login-wrapper {
                    position: relative;
                    display: inline-block;
                    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(250, 245, 240, 0.98) 100%);
                    border-radius: 16px;
                    padding: 24px;
                    box-shadow: 0 8px 32px rgba(139, 117, 95, 0.12), 0 0 0 1px rgba(139, 117, 95, 0.08);
                    transition: all 0.3s ease;
                    min-width: 280px;
                }
                .mtp-login-wrapper:hover {
                    box-shadow: 0 12px 40px rgba(139, 117, 95, 0.18), 0 0 0 1px rgba(139, 117, 95, 0.12);
                    transform: translateY(-2px);
                }
                .mtp-login-title {
                    font-size: 1.25rem;
                    font-weight: 800;
                    color: #2d1b0e;
                    margin-bottom: 16px;
                    text-align: center;
                    letter-spacing: -0.01em;
                }
                .mtp-login-subtitle {
                    font-size: 0.875rem;
                    color: #6b5d4a;
                    margin-bottom: 20px;
                    text-align: center;
                    line-height: 1.5;
                }
                .mtp-login-loading {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(255, 255, 255, 0.95);
                    display: none;
                    align-items: center;
                    justify-content: center;
                    flex-direction: column;
                    border-radius: 16px;
                    z-index: 1000;
                    backdrop-filter: blur(8px);
                    transition: opacity 0.3s ease;
                }
                .mtp-login-loading.active {
                    display: flex;
                    animation: mtp-fadeIn 0.3s ease;
                }
                @keyframes mtp-fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                .mtp-login-spinner {
                    width: 48px;
                    height: 48px;
                    border: 4px solid rgba(139, 117, 95, 0.1);
                    border-top: 4px solid #2271b1;
                    border-right: 4px solid #2271b1;
                    border-radius: 50%;
                    animation: mtp-spin 0.8s linear infinite;
                }
                @keyframes mtp-spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .mtp-login-message {
                    margin-top: 16px;
                    font-size: 15px;
                    color: #2271b1;
                    font-weight: 700;
                    text-align: center;
                    letter-spacing: 0.3px;
                }
                .mtp-login-wrapper iframe {
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
                    transition: transform 0.2s ease;
                }
                .mtp-login-wrapper iframe:hover {
                    transform: scale(1.02);
                }
                @media (max-width: 600px) {
                    .mtp-login-wrapper {
                        padding: 20px;
                        min-width: auto;
                        width: 100%;
                    }
                    .mtp-login-title {
                        font-size: 1.1rem;
                    }
                }
                </style>';
                
                $out .= '<div class="mtp-login-wrapper">';
                $out .= '<div class="mtp-login-loading" id="mtp-login-loading"><div><div class="mtp-login-spinner"></div><div class="mtp-login-message">Авторизация...</div></div></div>';
                $out .= '<div class="mtp-login-title">🔐 Вход через Telegram</div>';
                $out .= '<div class="mtp-login-subtitle">Быстрая и безопасная авторизация</div>';
                $out .= '<script async src="https://telegram.org/js/telegram-widget.js?22" data-telegram-login="'.$bot_name.'" data-size="large" data-radius="12" data-userpic="false" data-auth-url="'.esc_url($auth_url).'" data-request-access="write"></script>';
                $out .= '</div>';
                
                // JavaScript для автоматического обновления после авторизации
                $out .= '<script>
                (function() {
                    var ajaxUrl = "'.admin_url('admin-ajax.php').'";
                    var container = document.querySelector(".mtp-widget-container");
                    var loadingEl = document.getElementById("mtp-login-loading");
                    
                    // Функция для проверки статуса авторизации через AJAX
                    function checkAuthStatus(callback, errorCallback) {
                        var requestData = {
                            action: "mtp_check_auth_status",
                            _ajax_nonce: "'.wp_create_nonce('mtp_check_auth').'"
                        };
                        
                        if (typeof jQuery !== "undefined") {
                            jQuery.post(ajaxUrl, requestData, function(response) {
                                if (response.success && response.data.logged_in) {
                                    if (callback) callback(response.data);
                                } else if (errorCallback) {
                                    errorCallback();
                                }
                            }).fail(function() {
                                if (errorCallback) errorCallback();
                            });
                        } else {
                            var formData = new URLSearchParams();
                            for (var key in requestData) {
                                formData.append(key, requestData[key]);
                            }
                            
                            fetch(ajaxUrl, {
                                method: "POST",
                                headers: {
                                    "Content-Type": "application/x-www-form-urlencoded",
                                },
                                body: formData.toString()
                            })
                            .then(function(res) { return res.json(); })
                            .then(function(response) {
                                if (response.success && response.data.logged_in) {
                                    if (callback) callback(response.data);
                                } else if (errorCallback) {
                                    errorCallback();
                                }
                            })
                            .catch(function() {
                                if (errorCallback) errorCallback();
                            });
                        }
                    }
                    
                    // Функция для обновления виджета на странице
                    function updateLoginWidget(userData) {
                        if (!container) return;
                        
                        var newHtml = \'<a href="\' + userData.profile_url + \'" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; transition:opacity 0.2s;" onmouseover="this.style.opacity=\\\'0.8\\\'" onmouseout="this.style.opacity=\\\'1\\\'">\' +
                            \'<img src="\' + userData.photo + \'" style="width:48px; height:48px; border-radius:50%; object-fit:cover; box-shadow:0 3px 6px rgba(0,0,0,0.1); border:2px solid #fff;">\' +
                            \'<div style="display:flex; flex-direction:column; line-height:1.3;"><span style="font-weight:700; font-size:1.05rem;">\' + userData.name + \'</span><span style="font-size:0.75rem; opacity:0.8; color:#718096;">Личный кабинет</span></div></a>\';
                        
                        container.innerHTML = newHtml;
                        if (loadingEl) {
                            loadingEl.classList.remove("active");
                        }
                    }
                    
                    // Проверяем успешную авторизацию
                    if (window.location.search.includes("auth_success=1")) {
                        // Убираем параметр из URL
                        var newUrl = window.location.pathname + window.location.search.replace(/[?&]auth_success=1/, "").replace(/^&/, "?");
                        if (newUrl === window.location.pathname) newUrl = window.location.pathname;
                        window.history.replaceState({}, "", newUrl);
                        
                        // Показываем индикатор загрузки
                        if (loadingEl) {
                            loadingEl.classList.add("active");
                        }
                        
                        // Проверяем статус авторизации с задержкой (даем время кукам установиться)
                        var attempts = 0;
                        var maxAttempts = 20; // Увеличиваем количество попыток
                        var checkInterval = setInterval(function() {
                            attempts++;
                            checkAuthStatus(
                                function(userData) {
                                    // Успешная авторизация
                                    clearInterval(checkInterval);
                                    updateLoginWidget(userData);
                                },
                                function() {
                                    // Ошибка или не авторизован
                                    if (attempts >= maxAttempts) {
                                        clearInterval(checkInterval);
                                        // Если после всех попыток не авторизован, делаем полную перезагрузку
                                        if (loadingEl) {
                                            loadingEl.classList.remove("active");
                                        }
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 500);
                                    }
                                }
                            );
                        }, 300); // Проверяем каждые 300ms (быстрее)
                        
                        return;
                    }
                    
                    // Проверяем статус авторизации при загрузке страницы (на случай, если пользователь уже авторизован)
                    // Это важно для случаев, когда страница была закеширована
                    setTimeout(function() {
                        checkAuthStatus(function(userData) {
                            // Если пользователь авторизован, но виджет показывает кнопку входа - обновляем
                            var widget = document.querySelector("iframe[src*=\'telegram-widget\']");
                            if (widget && container) {
                                updateLoginWidget(userData);
                            }
                        });
                    }, 1000);
                    
                    // Показываем индикатор загрузки при клике на виджет
                    document.addEventListener("DOMContentLoaded", function() {
                        var widget = document.querySelector("iframe[src*=\'telegram-widget\']");
                        if (widget) {
                            // Отслеживаем изменения в URL (когда Telegram возвращает пользователя)
                            var checkAuth = setInterval(function() {
                                if (window.location.search.includes("tg_auth=1")) {
                                    if (loadingEl) {
                                        loadingEl.classList.add("active");
                                    }
                                    clearInterval(checkAuth);
                                }
                            }, 500);
                            
                            // Останавливаем проверку через 30 секунд
                            setTimeout(function() {
                                clearInterval(checkAuth);
                            }, 30000);
                        }
                    });
                })();
                </script>';
            } else {
                $out .= 'Error loading bot';
            }
        } 
        return $out.'</div>'; 
    }

    // 3. ПРОФИЛЬ
    public function render_profile($atts = [], $content = null) {
        if (!is_user_logged_in()) return '<div style="text-align:center; padding:60px 20px;">Вход в систему... ' . $this->render_login_box() . '</div>';
        if (!is_array($atts)) $atts = [];
        $atts = shortcode_atts(['color' => 'default'], $atts);
        
        // Цветовая палитра (как у баннеров и спойлеров)
        $color_map = [
            'default' => ['bg' => 'rgba(139,117,95,0.15)', 'border' => 'rgba(139,117,95,0.3)', 'text' => '#2d1b0e', 'header_bg' => 'rgba(139,117,95,0.2)'],
            'blue' => ['bg' => 'rgba(49,130,206,0.15)', 'border' => 'rgba(49,130,206,0.3)', 'text' => '#1e3a5f', 'header_bg' => 'rgba(49,130,206,0.2)'],
            'green' => ['bg' => 'rgba(56,161,105,0.15)', 'border' => 'rgba(56,161,105,0.3)', 'text' => '#22543d', 'header_bg' => 'rgba(56,161,105,0.2)'],
            'red' => ['bg' => 'rgba(229,62,62,0.15)', 'border' => 'rgba(229,62,62,0.3)', 'text' => '#742a2a', 'header_bg' => 'rgba(229,62,62,0.2)'],
            'purple' => ['bg' => 'rgba(128,90,213,0.15)', 'border' => 'rgba(128,90,213,0.3)', 'text' => '#553c9a', 'header_bg' => 'rgba(128,90,213,0.2)'],
            'orange' => ['bg' => 'rgba(237,137,54,0.15)', 'border' => 'rgba(237,137,54,0.3)', 'text' => '#7c2d12', 'header_bg' => 'rgba(237,137,54,0.2)'],
            'yellow' => ['bg' => 'rgba(255,193,7,0.15)', 'border' => 'rgba(255,193,7,0.3)', 'text' => '#78350f', 'header_bg' => 'rgba(255,193,7,0.2)'],
            'brown' => ['bg' => 'rgba(139,90,60,0.15)', 'border' => 'rgba(139,90,60,0.3)', 'text' => '#3d2817', 'header_bg' => 'rgba(139,90,60,0.2)']
        ];
        
        $colors = isset($color_map[$atts['color']]) ? $color_map[$atts['color']] : $color_map['default'];
        $unique_id = 'profile-' . uniqid();
        
        $user = wp_get_current_user();
        $target_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : $user->ID;
        $target_user = get_userdata($target_id);
        if (!$target_user) return 'Пользователь не найден';
        $is_me = ($user->ID === $target_id);
        $photo = $this->get_user_photo_url_raw($target_id) ?: get_avatar_url($target_id, ['size'=>300]);
        $badges = $this->get_badges_html($target_id, false);
        $reg = date_i18n('j F Y', strtotime($target_user->user_registered));

        // Получаем статистику для использования в стилях
        $k = (int)get_user_meta($target_id, 'mcp_total_views', true);
        $d = count_user_posts($target_id, 'user_deck');
        $c = get_comments(['user_id' => $target_id, 'count' => true]);
        $s = count((array)get_user_meta($target_id, 'mcp_liked_comments', true));
        
        $output = '<style>
        /* ОСНОВНАЯ СТРУКТУРА */
        #'.$unique_id.'.mtp-dashboard {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 40px;
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 20px 40px 20px;
        }
        
        /* САЙДБАР - КАРТОЧКА ПРОФИЛЯ */
        #'.$unique_id.' .mtp-sidebar {
            background: #fdfbf7;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            height: fit-content;
            box-shadow: 0 10px 30px rgba(60, 40, 20, 0.08);
            border: 1px solid rgba(139, 117, 95, 0.15);
        }
        
        /* АВАТАР С МАГИЧЕСКОЙ ОБВОДКОЙ */
        #'.$unique_id.' .mtp-avatar-img {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            border: 4px solid rgba(59, 130, 246, 0.3);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.2), 0 6px 20px rgba(139, 117, 95, 0.15);
            display: block;
        }
        
        /* ИМЯ */
        #'.$unique_id.' .mtp-name {
            font-size: 1.75rem;
            font-weight: 900;
            margin: 15px 0;
            color: '.$colors['text'].';
            letter-spacing: -0.01em;
        }
        
        /* БЕЙДЖИ */
        #'.$unique_id.' .mtp-badges {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
        }
        
        #'.$unique_id.' .mtp-badge-item {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.15), inset 0 1px 0 rgba(255,255,255,0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        #'.$unique_id.' .mtp-badge-item::before {
            content: \'\';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        #'.$unique_id.' .mtp-badge-item:hover::before {
            left: 100%;
        }
        
        #'.$unique_id.' .mtp-badge-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.4);
        }
        
        /* МЕТА-ИНФОРМАЦИЯ */
        #'.$unique_id.' .mtp-meta {
            color: #6b5d4a;
            font-size: 0.85rem;
            margin: 15px 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(240, 235, 230, 0.9);
            padding: 10px 18px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid rgba(139, 117, 95, 0.15);
        }
        
        /* СОЦСЕТИ */
        #'.$unique_id.' .mtp-socials-box {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 25px 0;
        }
        
        /* КНОПКА ДОБАВИТЬ КОЛОДУ */
        #'.$unique_id.' .mtp-btn-add-deck {
            display: block;
            text-align: center;
            background: linear-gradient(135deg, '.$colors['text'].' 0%, #1a0f0a 100%);
            color: #fff;
            padding: 14px;
            border-radius: 12px;
            font-weight: 800;
            text-decoration: none;
            margin-bottom: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(60, 40, 20, 0.2);
            letter-spacing: 0.3px;
        }
        
        #'.$unique_id.' .mtp-btn-add-deck:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(60, 40, 20, 0.3);
            color: #fff;
            text-decoration: none;
        }
        
        /* КНОПКА НАСТРОЙКИ */
        #'.$unique_id.' .mtp-btn-settings {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            cursor: pointer;
            background: rgba(240, 235, 230, 0.9);
            border: 1px solid rgba(139, 117, 95, 0.25);
            border-radius: 10px;
            color: #6b5d4a;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        #'.$unique_id.' .mtp-btn-settings:hover {
            background: rgba(250, 245, 240, 0.95);
            border-color: rgba(139, 117, 95, 0.35);
        }
        
        /* ТАБЫ */
        #'.$unique_id.' .mtp-tabs {
            display: flex;
            gap: 25px;
            border-bottom: 2px solid '.$colors['border'].';
            margin: 0 0 30px 0;
            flex-wrap: wrap;
        }
        
        #'.$unique_id.' .mtp-tab-btn {
            background: transparent;
            border: none;
            padding: 15px 0;
            font-weight: 800;
            color: #8b755f;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.25s ease;
            margin-bottom: -2px;
            letter-spacing: -0.01em;
            text-transform: uppercase;
            font-size: 0.95rem;
            white-space: nowrap;
        }
        
        #'.$unique_id.' .mtp-tab-btn:hover {
            color: '.$colors['text'].';
            transform: translateY(-1px);
        }
        
        #'.$unique_id.' .mtp-tab-btn.active {
            color: '.$colors['text'].';
            border-bottom-color: '.$colors['border'].';
        }
        
        #'.$unique_id.' .mtp-tab-content {
            display: none;
            animation: fadeIn 0.3s;
        }
        
        #'.$unique_id.' .mtp-tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* АДАПТИВНОСТЬ */
        @media (max-width: 1200px) {
            #'.$unique_id.'.mtp-dashboard {
                grid-template-columns: 280px 1fr;
                gap: 30px;
                padding: 0 15px 30px 15px;
            }
        }
        
        @media (max-width: 900px) {
            #'.$unique_id.'.mtp-dashboard {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 25px;
                padding: 0 15px 30px 15px;
            }
            
            #'.$unique_id.' .mtp-sidebar {
                width: 100%;
                max-width: none;
                padding: 25px 20px;
            }
            
            #'.$unique_id.' .mtp-avatar-img {
                width: 120px;
                height: 120px;
            }
            
            #'.$unique_id.' .mtp-name {
                font-size: 1.5rem;
            }
            
            #'.$unique_id.' .mtp-tabs {
                gap: 15px;
                overflow-x: auto;
                padding-bottom: 5px;
            }
            
            #'.$unique_id.' .mtp-tab-btn {
                padding: 12px 8px;
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 600px) {
            #'.$unique_id.'.mtp-dashboard {
                padding: 0 10px 20px 10px;
            }
            
            #'.$unique_id.' .mtp-sidebar {
                padding: 20px 15px;
            }
            
            #'.$unique_id.' .mtp-avatar-img {
                width: 100px;
                height: 100px;
            }
            
            #'.$unique_id.' .mtp-name {
                font-size: 1.3rem;
            }
            
            #'.$unique_id.' .mtp-badges {
                gap: 6px;
            }
            
            #'.$unique_id.' .mtp-badge-item {
                padding: 6px 12px;
                font-size: 0.7rem;
            }
            
            #'.$unique_id.' .mtp-tabs {
                gap: 10px;
            }
            
            #'.$unique_id.' .mtp-tab-btn {
                padding: 10px 6px;
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 400px) {
            #'.$unique_id.' .mtp-sidebar {
                padding: 15px 10px;
            }
            
            #'.$unique_id.' .mtp-avatar-img {
                width: 80px;
                height: 80px;
            }
            
            #'.$unique_id.' .mtp-name {
                font-size: 1.1rem;
            }
        }
        </style>';
        $avatars_list = $is_me ? get_option('mtp_avatars', []) : [];
        $output .= '<div id="' . $unique_id . '" class="mtp-dashboard"><aside class="mtp-sidebar">';
        if ($is_me && !empty($avatars_list)) {
            $output .= '<div style="position:relative; display:inline-block; cursor:pointer;" onclick="mtpOpenAvatarModal()"><img src="' . esc_url($photo) . '" class="mtp-avatar-img" style="cursor:pointer;"><div style="position:absolute; bottom:5px; right:5px; background:rgba(139,90,60,0.9); color:#fff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; box-shadow:0 2px 8px rgba(0,0,0,0.3);">✏️</div></div>';
        } else {
            $output .= '<img src="' . esc_url($photo) . '" class="mtp-avatar-img">';
        }
        $output .= '<div class="mtp-name">' . esc_html($target_user->display_name) . '</div><div class="mtp-badges">' . $badges . '</div><div class="mtp-meta">📅 В клубе с ' . $reg . '</div><div class="mtp-socials-box">'; ob_start(); do_action('mtp_profile_socials', $target_id); $output .= ob_get_clean(); $output .= '</div>';
        if ($is_me) { 
            $output .= '<a href="' . home_url('/dobavlenie-kolody/') . '" class="mtp-btn-add-deck">+ Добавить колоду</a>';
            $output .= '<button onclick="document.getElementById(\'mtp-set-div-' . $unique_id . '\').style.display=\'block\'" class="mtp-btn-settings">⚙️ Настройки</button>';
            $output .= '<div id="mtp-set-div-' . $unique_id . '" style="display:none;text-align:left;margin-top:15px;background:rgba(250,245,240,0.95);padding:20px;border:1px solid rgba(139,117,95,0.2);border-radius:12px;box-shadow:0 6px 20px rgba(139,117,95,0.15);"><form method="post" enctype="multipart/form-data">'.wp_nonce_field('mtp_data_act','mtp_n_data',true,false).'<label style="display:block;margin-bottom:8px;font-weight:700;color:#2d1b0e;font-size:0.9rem;letter-spacing:0.2px;">Сменить аватар:</label><input type="file" name="mtp_file" accept="image/*" style="margin-bottom:15px;width:100%;padding:8px;font-size:0.85rem;border:1px solid rgba(139,117,95,0.25);border-radius:6px;background:rgba(255,255,255,0.9);">'; 
            ob_start(); 
            do_action('mtp_profile_form_fields', $target_id); 
            $output .= ob_get_clean(); 
            $output .= '<button type="submit" style="width:100%;padding:10px;background:linear-gradient(135deg, ' . $colors['text'] . ' 0%, #1a0f0a 100%);color:#fff;border:none;border-radius:8px;font-weight:800;cursor:pointer;margin-top:12px;box-shadow:0 3px 10px rgba(60,40,20,0.2);transition:all 0.2s ease;" onmouseover="this.style.transform=\'translateY(-1px)\'; this.style.boxShadow=\'0 4px 12px rgba(60,40,20,0.3)\'" onmouseout="this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'0 3px 10px rgba(60,40,20,0.2)\'">Сохранить</button></form></div>';
            $output .= '<a href="' . wp_logout_url(home_url()) . '" style="color:#b85c5c;display:block;margin-top:15px;font-size:0.9rem;font-weight:700;text-decoration:none;transition:color 0.2s ease;" onmouseover="this.style.color=\'#a04a4a\'" onmouseout="this.style.color=\'#b85c5c\'">Выйти из аккаунта</a>'; 
        }
        $output .= '</aside><main class="mtp-content">';
        
        // Модальное окно для выбора аватарки
        if ($is_me && !empty($avatars_list)) {
            $output .= '<div id="mtp-avatar-modal">
            <div style="background:linear-gradient(135deg, #3d2817 0%, #2d1b0e 100%);border-radius:20px;padding:30px;max-width:600px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.5);border:2px solid rgba(139,90,60,0.3);">
                <h2 style="color:#d4a574;font-size:1.8rem;font-weight:900;text-align:center;margin:0 0 25px 0;text-shadow:0 2px 4px rgba(0,0,0,0.3);font-family:serif;">Выберите героя</h2>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:25px;">';
            foreach($avatars_list as $id => $url) {
                $selected_class = (get_user_meta($target_id, 'mtp_selected_avatar', true) === $url) ? 'selected' : '';
                $output .= '<div class="mtp-avatar-option" data-avatar-id="'.esc_attr($id).'" style="position:relative;cursor:pointer;border-radius:12px;overflow:hidden;border:3px solid transparent;transition:all 0.3s ease;'.$selected_class.'" onclick="mtpSelectAvatar(\''.esc_js($id).'\')">
                    <img src="'.esc_url($url).'" style="width:100%;height:auto;display:block;border-radius:8px;">
                    <div class="mtp-avatar-check" style="position:absolute;top:5px;right:5px;background:rgba(56,161,105,0.9);color:#fff;width:28px;height:28px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:16px;box-shadow:0 2px 8px rgba(0,0,0,0.3);">✓</div>
                </div>';
            }
            $output .= '</div>
                <button onclick="mtpCloseAvatarModal()" style="width:100%;padding:14px;background:rgba(139,90,60,0.8);color:#fff;border:none;border-radius:10px;font-weight:800;font-size:1rem;cursor:pointer;transition:all 0.3s ease;box-shadow:0 4px 12px rgba(0,0,0,0.3);" onmouseover="this.style.background=\'rgba(139,90,60,1)\'" onmouseout="this.style.background=\'rgba(139,90,60,0.8)\'">Отмена</button>
            </div>
        </div>
        <style>
        #mtp-avatar-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;backdrop-filter:blur(5px);align-items:center;justify-content:center;}
        #mtp-avatar-modal.show{display:flex !important;}
        .mtp-avatar-option.selected{border-color:#38a169 !important;box-shadow:0 0 0 3px rgba(56,161,105,0.3) !important;}
        .mtp-avatar-option:hover{transform:scale(1.05);border-color:rgba(212,165,116,0.5) !important;}
        .mtp-avatar-option.selected .mtp-avatar-check{display:flex !important;}
        </style>
        <script>
        function mtpOpenAvatarModal(){document.getElementById("mtp-avatar-modal").classList.add("show");}
        function mtpCloseAvatarModal(){document.getElementById("mtp-avatar-modal").classList.remove("show");}
        function mtpSelectAvatar(id){
            jQuery.post("'.admin_url('admin-ajax.php').'",{action:"mtp_select_avatar",avatar_id:id},function(r){
                if(r.success){location.reload();}else{alert("Ошибка выбора аватарки");}
            });
        }
        document.addEventListener("click",function(e){if(e.target.id==="mtp-avatar-modal")mtpCloseAvatarModal();});
        </script>';
        }
        ob_start(); do_action('mtp_profile_stats', $target_id); $output .= ob_get_clean(); 
        $output .= "<script>function openMtpTab(evt, tabName) { var i, x, tablinks; x = document.getElementsByClassName('mtp-tab-content'); for (i = 0; i < x.length; i++) { x[i].className = x[i].className.replace(' active', ''); } tablinks = document.getElementsByClassName('mtp-tab-btn'); for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(' active', ''); } document.getElementById(tabName).className += ' active'; evt.currentTarget.className += ' active'; }</script>";
        $output .= '<div class="mtp-tabs"><button class="mtp-tab-btn active" onclick="openMtpTab(event, \'tab-decks\')">Колоды</button><button class="mtp-tab-btn" onclick="openMtpTab(event, \'tab-articles\')">Статьи</button><button class="mtp-tab-btn" onclick="openMtpTab(event, \'tab-favs\')">Избранное</button></div>';
        $output .= '<div id="tab-decks" class="mtp-tab-content active">' . do_shortcode('[sub_deck type="user_deck" count="9" author_id="'.$target_id.'"]') . '</div><div id="tab-articles" class="mtp-tab-content">' . do_shortcode('[sub_deck type="post" count="9" author_id="'.$target_id.'"]') . '</div><div id="tab-favs" class="mtp-tab-content">';
        if ($is_me) { $output .= do_shortcode('[sub_deck_favs count="9"]'); } else { $output .= '<p style="color:#718096;padding:20px;background:#f7fafc;border-radius:8px;text-align:center;">Вы можете видеть только свое избранное.</p>'; }
        $output .= '</div></main></div>'; return $output;
    }

    private function get_badges_html($user_id, $mini = false) {
        $user = get_userdata($user_id); if (!$user) return ''; $roles = (array) $user->roles; $html = '';
        $badges_map = [
            'administrator'=>['bg'=>'linear-gradient(135deg, #FFC107 0%, #FFA000 100%)','l'=>'ГЛАВ. АДМИН','c'=>'#000','shadow'=>'rgba(255,193,7,0.4)'],
            'editor'=>['bg'=>'linear-gradient(135deg, #3182ce 0%, #2c5282 100%)','l'=>'РЕДАКТОР','c'=>'#fff','shadow'=>'rgba(49,130,206,0.4)'],
            'vip'=>['bg'=>'linear-gradient(135deg, #1a1a1a 0%, #000000 100%)','l'=>'VIP','c'=>'#FFD700','shadow'=>'rgba(0,0,0,0.5)'],
            'streamer'=>['bg'=>'linear-gradient(135deg, #805ad5 0%, #6b46c1 100%)','l'=>'СТРИМЕР','c'=>'#fff','shadow'=>'rgba(128,90,213,0.4)'],
            'youtuber'=>['bg'=>'linear-gradient(135deg, #e53e3e 0%, #c53030 100%)','l'=>'ЮТУБЕР','c'=>'#fff','shadow'=>'rgba(229,62,62,0.4)'],
            'moderator'=>['bg'=>'linear-gradient(135deg, #38a169 0%, #2f855a 100%)','l'=>'МОДЕРАТОР','c'=>'#fff','shadow'=>'rgba(56,161,105,0.4)'],
            'author'=>['bg'=>'linear-gradient(135deg, #718096 0%, #4a5568 100%)','l'=>'АВТОР','c'=>'#fff','shadow'=>'rgba(113,128,150,0.4)']
        ];
        foreach ($roles as $role) { 
            if (isset($badges_map[$role])) { 
                $b = $badges_map[$role]; 
                if ($mini) {
                    $style = "background:{$b['bg']};color:{$b['c']};";
                } else {
                    $style = "display:inline-block;background:{$b['bg']};color:{$b['c']};padding:8px 16px;border-radius:10px;font-size:0.75rem;font-weight:900;text-transform:uppercase;letter-spacing:0.8px;box-shadow:0 3px 8px {$b['shadow']},inset 0 1px 0 rgba(255,255,255,0.3);transition:all 0.3s ease;position:relative;overflow:hidden;";
                }
                $cls = $mini ? 'mtp-mini-badge' : 'mtp-badge-item'; 
                $html .= "<span class='$cls' style='$style'>{$b['l']}</span>"; 
            } 
        }
        if (!$mini) {
            $titles = [get_user_meta($user_id,'mcp_custom_title',true), get_user_meta($user_id,'mcp_title_s1',true), get_user_meta($user_id,'mcp_title_s2',true), get_user_meta($user_id,'mcp_title_s3',true)];
            foreach ($titles as $t) { 
                if ($t) { 
                    $bg = 'linear-gradient(135deg, #718096 0%, #4a5568 100%)';
                    $shadow = 'rgba(113,128,150,0.4)';
                    if(mb_stripos($t,'Ютуб')!==false || mb_stripos($t,'Ютубер')!==false) {
                        $bg = 'linear-gradient(135deg, #e53e3e 0%, #c53030 100%)';
                        $shadow = 'rgba(229,62,62,0.4)';
                    } elseif(mb_stripos($t,'Стрим')!==false || mb_stripos($t,'Стример')!==false) {
                        $bg = 'linear-gradient(135deg, #805ad5 0%, #6b46c1 100%)';
                        $shadow = 'rgba(128,90,213,0.4)';
                    } elseif(mb_stripos($t,'VIP')!==false) {
                        $bg = 'linear-gradient(135deg, #1a1a1a 0%, #000000 100%)';
                        $shadow = 'rgba(0,0,0,0.5)';
                    } elseif(mb_stripos($t,'Легенда')!==false || mb_stripos($t,'Легенд')!==false) {
                        $bg = 'linear-gradient(135deg, #4a90e2 0%, #357abd 100%)';
                        $shadow = 'rgba(74,144,226,0.4)';
                    }
                    $html .= "<span class='mtp-badge-item' style='display:inline-block;background:$bg;color:#fff;padding:8px 16px;border-radius:10px;font-size:0.75rem;font-weight:900;text-transform:uppercase;letter-spacing:0.8px;box-shadow:0 3px 8px $shadow,inset 0 1px 0 rgba(255,255,255,0.3);transition:all 0.3s ease;position:relative;overflow:hidden;'>".esc_html($t)."</span>"; 
                } 
            }
        }
        return $html;
    }

    // 4. РЕДАКТОР КОЛОД
    public function render_deck_editor() { 
        if (!is_user_logged_in()) return $this->render_login_box(); 
        $deck_id = 0; $val_title = ''; $val_code = ''; $val_content = ''; $val_guide = 0; $btn_text = 'Опубликовать колоду'; $header_text = 'Создать новую колоду';
        $error_msg = '';
        $success_msg = '';
        
        // Проверяем сообщения об ошибках/успехе
        if (isset($_GET['err'])) {
            if ($_GET['err'] === 'title') {
                $error_msg = 'Ошибка: Название колоды обязательно для заполнения.';
            }
        }
        if (isset($_GET['success'])) {
            $success_msg = 'Колода успешно сохранена!';
        }
        
        if (isset($_GET['edit_id'])) {
            $deck_id = intval($_GET['edit_id']);
            $post = get_post($deck_id);
            if ($post && $post->post_author == get_current_user_id() && $post->post_type == 'user_deck') {
                $val_title = $post->post_title; $val_content = $post->post_content;
                $val_code = get_post_meta($deck_id, 'mtp_deck_code', true); $val_guide = get_post_meta($deck_id, 'mtp_has_guide', true);
                $btn_text = 'Сохранить изменения'; $header_text = 'Редактирование колоды';
            } else { 
                $deck_id = 0; 
                $error_msg = 'Ошибка: Вы не можете редактировать эту колоду.'; 
            }
        }
        ob_start(); 
        ?> 
        <style>
        .mtp-deck-editor-wrapper{max-width:1200px;margin:0 auto;padding:0 20px 40px 20px}
        .mtp-deck-editor-form{background:rgba(250,245,240,0.85);border-radius:20px;padding:40px;box-shadow:0 8px 32px rgba(139,117,95,0.12),0 0 0 1px rgba(139,117,95,0.12);backdrop-filter:blur(10px);border:none}
        .mtp-deck-editor-title{font-size:2.5rem;font-weight:900;color:#2d1b0e;margin:0 0 35px 0;letter-spacing:-0.02em;text-shadow:0 2px 4px rgba(0,0,0,0.05);padding-bottom:20px;border-bottom:2px solid rgba(139,117,95,0.15)}
        .mtp-deck-editor-field{margin-bottom:28px}
        .mtp-deck-editor-label{display:block;font-weight:800;color:#2d1b0e;font-size:1rem;margin-bottom:10px;letter-spacing:0.3px}
        .mtp-deck-editor-label--required::after{content:' *';color:#e53e3e;font-weight:900}
        .mtp-deck-editor-input,.mtp-deck-editor-textarea{width:100%;padding:14px 18px;background:rgba(255,255,255,0.95);border:2px solid rgba(139,117,95,0.2);border-radius:12px;font-size:1rem;color:#2d1b0e;transition:all 0.3s ease;box-shadow:inset 0 2px 4px rgba(0,0,0,0.03);font-family:inherit}
        .mtp-deck-editor-input:focus,.mtp-deck-editor-textarea:focus{outline:none;border-color:rgba(139,90,60,0.4);background:rgba(255,255,255,1);box-shadow:0 0 0 3px rgba(139,90,60,0.1),inset 0 2px 4px rgba(0,0,0,0.03)}
        .mtp-deck-editor-textarea{min-height:120px;resize:vertical;font-family:'Consolas','Monaco',monospace;font-size:0.95rem;line-height:1.6}
        .mtp-deck-editor-row{display:grid;grid-template-columns:1fr 1fr;gap:25px;margin-bottom:28px}
        .mtp-deck-editor-message{padding:16px 20px;border-radius:12px;margin-bottom:25px;font-weight:700;font-size:0.95rem;border:2px solid}
        .mtp-deck-editor-error{background:rgba(254,226,226,0.9);color:#c53030;border-color:#fc8181}
        .mtp-deck-editor-success{background:rgba(198,246,213,0.9);color:#22543d;border-color:#68d391}
        .mtp-deck-editor-checkbox-wrapper{background:rgba(240,235,230,0.9);padding:20px 25px;border:2px solid rgba(139,117,95,0.2);border-radius:14px;margin:35px 0;display:flex;align-items:center;cursor:pointer;transition:all 0.3s ease}
        .mtp-deck-editor-checkbox-wrapper:hover{background:rgba(250,245,240,0.95);border-color:rgba(139,117,95,0.3);transform:translateX(4px)}
        .mtp-deck-editor-checkbox{width:22px;height:22px;margin-right:15px;cursor:pointer;accent-color:#8b5a3c}
        .mtp-deck-editor-checkbox-label{font-weight:800;color:#2d1b0e;font-size:1.05rem;cursor:pointer;margin:0}
        .mtp-deck-editor-btn-wrapper{margin-top:40px}
        .mtp-deck-editor-btn{width:100%;padding:18px 30px;background:linear-gradient(135deg, #8b5a3c 0%, #6b4a2c 100%);color:#fff;border:none;border-radius:12px;font-weight:900;font-size:1.1rem;cursor:pointer;transition:all 0.3s ease;box-shadow:0 4px 16px rgba(139,90,60,0.25);letter-spacing:0.5px;text-transform:uppercase}
        .mtp-deck-editor-btn:hover{background:linear-gradient(135deg, #9b6a4c 0%, #7b5a3c 100%);transform:translateY(-2px);box-shadow:0 6px 20px rgba(139,90,60,0.35)}
        .mtp-deck-editor-btn:active{transform:translateY(0)}
        .mtp-deck-editor-btn-secondary{background:rgba(240,235,230,0.9);color:#6b5d4a;border:2px solid rgba(139,117,95,0.25);padding:12px 20px;font-size:0.9rem;text-transform:none;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(139,117,95,0.1)}
        .mtp-deck-editor-btn-secondary:hover{background:rgba(250,245,240,0.95);border-color:rgba(139,117,95,0.35);color:#2d1b0e;transform:translateY(-1px);box-shadow:0 3px 12px rgba(139,117,95,0.15)}
        .mtp-deck-editor-file-input{width:100%;padding:14px 18px;background:rgba(255,255,255,0.95);border:2px dashed rgba(139,117,95,0.3);border-radius:12px;font-size:0.95rem;color:#6b5d4a;transition:all 0.3s ease;cursor:pointer}
        .mtp-deck-editor-file-input:hover{border-color:rgba(139,117,95,0.5);background:rgba(250,245,240,0.95)}
        .mtp-deck-editor-file-input:focus{outline:none;border-color:rgba(139,90,60,0.4);border-style:solid;box-shadow:0 0 0 3px rgba(139,90,60,0.1)}
        .wp-editor-container{border:2px solid rgba(139,117,95,0.2);border-radius:12px;overflow:hidden;background:rgba(255,255,255,0.95);box-shadow:inset 0 2px 4px rgba(0,0,0,0.03)}
        .wp-editor-container:focus-within{border-color:rgba(139,90,60,0.4);box-shadow:0 0 0 3px rgba(139,90,60,0.1),inset 0 2px 4px rgba(0,0,0,0.03)}
        @media (max-width:900px){.mtp-deck-editor-wrapper{padding:0 15px 30px 15px}.mtp-deck-editor-form{padding:30px 25px}.mtp-deck-editor-title{font-size:2rem;margin-bottom:25px}.mtp-deck-editor-row{grid-template-columns:1fr;gap:20px}.mtp-deck-editor-field{margin-bottom:22px}}
        @media (max-width:600px){.mtp-deck-editor-wrapper{padding:0 10px 20px 10px}.mtp-deck-editor-form{padding:25px 20px}.mtp-deck-editor-title{font-size:1.75rem;margin-bottom:20px;padding-bottom:15px}.mtp-deck-editor-input,.mtp-deck-editor-textarea{padding:12px 15px;font-size:0.95rem}.mtp-deck-editor-btn{padding:16px 25px;font-size:1rem}}
        </style>
        <div class="mtp-deck-editor-wrapper">
        <form method="post" enctype="multipart/form-data" class="mtp-deck-editor-form"> 
                    <?php wp_nonce_field('mtp_save_deck', 'mtp_deck_nonce'); ?> 
                    <input type="hidden" name="editing_post_id" value="<?php echo $deck_id; ?>">
                    
                    <?php if ($error_msg): ?>
                        <div class="mtp-deck-editor-message mtp-deck-editor-error">
                            <?php echo esc_html($error_msg); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success_msg): ?>
                        <div class="mtp-deck-editor-message mtp-deck-editor-success">
                            <?php echo esc_html($success_msg); ?>
                        </div>
                    <?php endif; ?>
                    
                    <h1 class="mtp-deck-editor-title">
                        <?php echo esc_html($header_text); ?>
                    </h1>
                    
                    <div class="mtp-deck-editor-row">
                        <div class="mtp-deck-editor-field">
                            <label class="mtp-deck-editor-label mtp-deck-editor-label--required" for="deck_title">
                                Название колоды
                            </label>
                            <input 
                                type="text" 
                                id="deck_title"
                                name="deck_title" 
                                value="<?php echo esc_attr($val_title); ?>" 
                                required 
                                class="mtp-deck-editor-input"
                                placeholder="Введите название колоды"
                            >
                        </div>
                        
                        <div class="mtp-deck-editor-field">
                            <label class="mtp-deck-editor-label" for="deck_image">
                                Скриншот
                            </label>
                            <input 
                                type="file" 
                                id="deck_image"
                                name="deck_image" 
                                accept="image/*" 
                                class="mtp-deck-editor-file-input"
                            >
                        </div>
                    </div>
                    
                    <div class="mtp-deck-editor-field">
                        <label class="mtp-deck-editor-label" for="deck_code">
                            Код колоды
                        </label>
                        <textarea 
                            id="deck_code"
                            name="deck_code" 
                            class="mtp-deck-editor-textarea"
                            rows="4"
                            placeholder="Вставьте код колоды здесь"
                        ><?php echo esc_textarea($val_code); ?></textarea>
                    </div>
                    
                    <div class="mtp-deck-editor-field">
                        <label class="mtp-deck-editor-label" style="font-size:1.25rem;margin-bottom:15px;">
                            Описание
                        </label>
                        
                        <div style="margin-bottom:15px;">
                            <button type="button" onclick="insertLocker()" class="mtp-deck-editor-btn mtp-deck-editor-btn-secondary">
                                🔒 Скрыть выделенное (VIP)
                            </button>
                        </div>
                        
                        <?php wp_editor($val_content, 'deck_content', [
                            'media_buttons' => true, 
                            'textarea_rows' => 15, 
                            'teeny' => false, 
                            'editor_class' => 'mtp-editor-area'
                        ]); ?> 
                    </div>
                    
                    <script>
                    function insertLocker(){
                        if(typeof tinyMCE!=='undefined'&&tinyMCE.activeEditor&&!tinyMCE.activeEditor.isHidden()){
                            var s=tinyMCE.activeEditor.selection.getContent();
                            tinyMCE.activeEditor.selection.setContent('[mtp_locker role="vip"]'+(s||'СКРЫТЫЙ ТЕКСТ')+'[/mtp_locker]')
                        }else{
                            var t=document.getElementById('deck_content'),s=t.selectionStart,e=t.selectionEnd;
                            t.value=t.value.substring(0,s)+'[mtp_locker role="vip"]'+(t.value.substring(s,e)||'СКРЫТЫЙ ТЕКСТ')+'[/mtp_locker]'+t.value.substring(e)
                        }
                    }
                    </script>

                    <div class="mtp-deck-editor-checkbox-wrapper">
                        <input 
                            type="checkbox" 
                            id="has_guide"
                            name="has_guide" 
                            value="1" 
                            <?php checked($val_guide, 1); ?> 
                            class="mtp-deck-editor-checkbox"
                        >
                        <label for="has_guide" class="mtp-deck-editor-checkbox-label">
                            Это подробный гайд
                        </label>
                    </div>
                    
                    <div class="mtp-deck-editor-btn-wrapper">
                        <button type="submit" name="submit_deck" class="mtp-deck-editor-btn">
                            <?php echo esc_html($btn_text); ?>
                        </button>
                    </div>
                </form>
        </div>
        <?php return ob_get_clean(); 
    }

    public function handle_deck_submission() { 
        if (!isset($_POST['submit_deck'])) return;
        if (!isset($_POST['mtp_deck_nonce']) || !wp_verify_nonce($_POST['mtp_deck_nonce'], 'mtp_save_deck')) wp_die('Security Error');
        $user_id = get_current_user_id(); $title = sanitize_text_field($_POST['deck_title']); 
        if (empty($title)) { wp_redirect(add_query_arg('err', 'title', wp_get_referer())); exit; }
        $code = sanitize_textarea_field($_POST['deck_code']); $content = wp_kses_post($_POST['deck_content']); $has_guide = isset($_POST['has_guide']) ? 1 : 0; 
        $editing_id = isset($_POST['editing_post_id']) ? intval($_POST['editing_post_id']) : 0; $post_id = 0;
        if ($editing_id > 0) { $existing_post = get_post($editing_id); if ($existing_post && $existing_post->post_author == $user_id) { $post_id = wp_update_post(['ID' => $editing_id, 'post_title' => $title, 'post_content' => $content]); } } else { $post_id = wp_insert_post(['post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'user_deck', 'post_author' => $user_id]); }
        if (is_wp_error($post_id)) wp_die($post_id->get_error_message());
        if ($post_id) { 
            update_post_meta($post_id, 'mtp_deck_code', $code); update_post_meta($post_id, 'mtp_has_guide', $has_guide); 
            // СОВМЕСТИМОСТЬ С SMUSH: Обрабатываем изображения через стандартный WordPress API
            if (!empty($_FILES['deck_image']['name'])) { 
                require_once(ABSPATH . 'wp-admin/includes/image.php'); 
                require_once(ABSPATH . 'wp-admin/includes/file.php'); 
                require_once(ABSPATH . 'wp-admin/includes/media.php'); 
                $aid = media_handle_upload('deck_image', $post_id); 
                if (!is_wp_error($aid)) {
                    set_post_thumbnail($post_id, $aid);
                    // СОВМЕСТИМОСТЬ С SMUSH: Даем время на обработку изображения (если Smush активен)
                    if (class_exists('WP_Smush')) {
                        // Smush обработает изображение автоматически через стандартные хуки WordPress
                        // Не требуется дополнительных действий
                    }
                }
            } 
            if ($editing_id > 0) {
                wp_redirect(add_query_arg('success', '1', home_url('/dobavlenie-kolody/?edit_id=' . $post_id))); 
            } else {
                wp_redirect(add_query_arg('success', '1', get_permalink($post_id))); 
            }
            exit; 
        } 
    }

    // 5. LOCKER SHORTCODE
    public function render_locker_shortcode($atts, $content = null) { 
        $a = shortcode_atts(['role' => 'vip', 'code' => ''], $atts); 
        if ($a['role'] === 'all') return do_shortcode($content); 
        if (current_user_can('administrator') || current_user_can('editor') || is_author()) return '<div style="border:1px dashed #ccc; padding:10px; position:relative;"><span style="position:absolute; top:-10px; right:0; background:#f0f0f1; font-size:10px; padding:2px 5px;">Admin View</span>' . do_shortcode($content) . '</div>'; 
        $allowed = [$a['role']]; if($a['role']=='vip'){ $r=get_option('mta_rules_map',''); foreach(explode("\n",$r) as $l){$p=explode('|',$l);if(count($p)==2)$allowed[]=trim($p[1]);} }
        $user = wp_get_current_user(); $access = false; 
        if (is_user_logged_in()) { foreach($allowed as $r){ if(in_array($r,(array)$user->roles)){$access=true;break;} } }
        
        // Получаем ID текущей статьи
        $post_id = get_the_ID();
        if (!$post_id || !is_numeric($post_id)) {
            $post_id = null;
        } else {
            $post_id = intval($post_id);
            // Проверяем, что пост существует
            if (!get_post($post_id)) {
                $post_id = null;
            }
        }
        
        // Если указан код в шорткоде, сохраняем его в мета-поле поста
        $shortcode_code = !empty($a['code']) ? sanitize_text_field($a['code']) : '';
        if ($shortcode_code && $post_id) {
            $meta_key = '_mtp_access_code_' . sanitize_key($a['role']);
            $existing_code = get_post_meta($post_id, $meta_key, true);
            if ($existing_code !== $shortcode_code) {
                update_post_meta($post_id, $meta_key, $shortcode_code);
            }
        }
        
        // Проверяем доступ по коду (если не авторизован или нет доступа)
        if (!$access) {
            $code_access = $this->check_code_access($a['role'], $post_id);
            if ($code_access) {
                $access = true;
            }
        }
        
        if ($access) return do_shortcode($content); // ВАЖНО: do_shortcode внутри!
        
        // Показываем форму с возможностью ввода кода
        if (!is_user_logged_in()) {
            return '<div style="background:#fff3cd; border:1px solid #ffeeba; padding:20px; border-radius:10px; text-align:center; margin:20px 0;"><div style="font-size:24px;">🔒</div><h4>Контент закрыт</h4><p>Только для подписчиков.</p>'.$this->render_login_box().$this->render_code_access_form($a['role'], $post_id).'</div>';
        }
        return '<div style="background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; padding:20px; border-radius:10px; text-align:center; margin:20px 0;"><div style="font-size:24px;">⛔</div><h4>Доступ ограничен</h4><p>Нужна подписка.</p><a href="'.home_url('/lichnyj-kabinet/').'" style="color:#721c24;">Проверить статус</a>'.$this->render_code_access_form($a['role'], $post_id).'</div>';
    }
    
    /**
     * Создание таблицы для кодов доступа
     */
    public function create_access_codes_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtp_access_codes';
        $usage_table = $wpdb->prefix . 'mtp_access_code_usage';
        
        // Проверяем существование таблиц для оптимизации
        $codes_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $usage_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$usage_table'") === $usage_table;
        
        if ($codes_table_exists && $usage_table_exists) {
            return; // Таблицы уже существуют
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        if (!$codes_table_exists) {
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                code varchar(100) NOT NULL,
                role varchar(50) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                created_by bigint(20) UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY code (code),
                KEY role (role)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        if (!$usage_table_exists) {
            // Создаем таблицу для использования кодов
            $sql_usage = "CREATE TABLE IF NOT EXISTS $usage_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                code_id bigint(20) UNSIGNED NOT NULL,
                ip_address varchar(45) NOT NULL,
                user_agent text,
                accessed_at datetime DEFAULT CURRENT_TIMESTAMP,
                expires_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY code_id (code_id),
                KEY ip_address (ip_address),
                KEY expires_at (expires_at)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_usage);
        }
    }
    
    /**
     * СОВМЕСТИМОСТЬ С MEMBERS: Обход проверки доступа для пользователей с кодом
     */
    public function members_bypass_access_with_code($has_access, $post_id = null, $user_id = null) {
        // Если доступ уже есть, не меняем
        if ($has_access) {
            return $has_access;
        }
        
        // Если post_id не передан, пытаемся получить из контекста
        if (!$post_id) {
            $post_id = get_the_ID();
        }
        
        if (!$post_id) {
            return $has_access;
        }
        
        // Проверяем все возможные роли - сначала из Members, затем стандартные (vip и т.д.)
        $roles_to_check = [];
        
        // Получаем роли доступа из Members
        $post_access_roles = get_post_meta($post_id, '_members_access_role', false);
        if (!empty($post_access_roles)) {
            $roles_to_check = array_merge($roles_to_check, $post_access_roles);
        }
        
        // Также проверяем стандартные роли (vip, и другие из правил)
        $rules_raw = get_option('mta_rules_map', '');
        if (!empty($rules_raw)) {
            foreach (explode("\n", $rules_raw) as $l) {
                $p = explode('|', $l);
                if (count($p) == 2) {
                    $role = trim($p[1]);
                    if (!in_array($role, $roles_to_check)) {
                        $roles_to_check[] = $role;
                    }
                }
            }
        }
        
        // Добавляем стандартную роль vip
        if (!in_array('vip', $roles_to_check)) {
            $roles_to_check[] = 'vip';
        }
        
        // Проверяем каждую роль на наличие валидного кода доступа
        foreach ($roles_to_check as $role) {
            if ($this->check_code_access($role, $post_id)) {
                return true; // Пользователь имеет доступ через код
            }
        }
        
        return $has_access;
    }
    
    /**
     * СОВМЕСТИМОСТЬ С MEMBERS: Временное предоставление capabilities для пользователей с кодом
     */
    public function members_grant_capabilities_with_code($allcaps, $caps, $args, $user) {
        // Работаем только для неавторизованных пользователей
        if (!$user || $user->ID == 0) {
            // Для неавторизованных пользователей проверяем код доступа
            if (!empty($caps)) {
                $post_id = get_the_ID();
                if ($post_id > 0) {
                    // Проверяем все возможные роли
                    $roles_to_check = [];
                    
                    // Получаем роли доступа из Members
                    $post_access_roles = get_post_meta($post_id, '_members_access_role', false);
                    if (!empty($post_access_roles)) {
                        $roles_to_check = array_merge($roles_to_check, $post_access_roles);
                    }
                    
                    // Также проверяем стандартные роли
                    $rules_raw = get_option('mta_rules_map', '');
                    if (!empty($rules_raw)) {
                        foreach (explode("\n", $rules_raw) as $l) {
                            $p = explode('|', $l);
                            if (count($p) == 2) {
                                $role = trim($p[1]);
                                if (!in_array($role, $roles_to_check)) {
                                    $roles_to_check[] = $role;
                                }
                            }
                        }
                    }
                    
                    // Добавляем стандартную роль vip
                    if (!in_array('vip', $roles_to_check)) {
                        $roles_to_check[] = 'vip';
                    }
                    
                    // Проверяем код доступа для любой из ролей
                    foreach ($roles_to_check as $role) {
                        if ($this->check_code_access($role, $post_id)) {
                            // Предоставляем все запрошенные capabilities
                            foreach ($caps as $cap) {
                                $allcaps[$cap] = true;
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        return $allcaps;
    }
    
    /**
     * Проверка доступа по коду
     */
    private function check_code_access($role, $post_id = null) {
        // Используем cookies вместо сессии для более надежной работы
        if ($post_id && is_numeric($post_id)) {
            $cookie_name = 'mtp_code_access_' . sanitize_key($role) . '_' . intval($post_id);
        } else {
            $cookie_name = 'mtp_code_access_' . sanitize_key($role);
        }
        
        // Проверяем cookie - пробуем несколько способов
        $cookie_value = null;
        
        // Способ 1: через $_COOKIE
        if (isset($_COOKIE[$cookie_name])) {
            $cookie_value = $_COOKIE[$cookie_name];
        }
        
        // Способ 2: если не найдено, пробуем через filter_input (может быть полезно в некоторых конфигурациях)
        if ($cookie_value === null) {
            $cookie_value = filter_input(INPUT_COOKIE, $cookie_name);
            if ($cookie_value === false || $cookie_value === null) {
                $cookie_value = null;
            }
        }
        
        // Способ 3: проверяем через $_SERVER['HTTP_COOKIE'] как запасной вариант
        if ($cookie_value === null && isset($_SERVER['HTTP_COOKIE'])) {
            $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
            foreach ($cookies as $cookie) {
                $cookie = trim($cookie);
                if (strpos($cookie, $cookie_name . '=') === 0) {
                    $cookie_value = substr($cookie, strlen($cookie_name) + 1);
                    break;
                }
            }
        }
        
        if ($cookie_value !== null && $cookie_value !== '') {
            // Конвертируем значение cookie в число (может быть строка с timestamp)
            $expires_at = is_numeric($cookie_value) ? intval($cookie_value) : 0;
            $current_time = time();
            
            // Проверяем, что значение валидное и время не истекло
            // Дополнительно проверяем, что expires_at разумное значение (не меньше текущего времени минус 1 год и не больше текущего + 10 лет)
            if ($expires_at > 0 && $expires_at > ($current_time - 31536000) && $expires_at < ($current_time + 315360000) && $current_time < $expires_at) {
                return true;
            } else {
                // Время истекло или значение невалидное, удаляем cookie
                if (!headers_sent()) {
                    setcookie($cookie_name, '', time() - 3600, '/', '', is_ssl(), false);
                }
                // Также очищаем из $_COOKIE
                if (isset($_COOKIE[$cookie_name])) {
                    unset($_COOKIE[$cookie_name]);
                }
            }
        }
        
        return false;
    }
    
    /**
     * Форма для ввода кода доступа
     */
    private function render_code_access_form($role, $post_id = null) {
        $unique_id = 'code-form-' . uniqid();
        ob_start();
        ?>
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(139,117,95,0.2);">
            <p style="margin-bottom: 15px; font-weight: 700; color: #2d1b0e;">Или введите код доступа:</p>
            <form id="<?php echo esc_attr($unique_id); ?>" class="mtp-code-access-form" data-role="<?php echo esc_attr($role); ?>"<?php if ($post_id) { echo ' data-post-id="' . esc_attr($post_id) . '"'; } ?> style="max-width: 400px; margin: 0 auto;">
                <input type="text" name="access_code" placeholder="Введите код доступа" required 
                       style="width: 100%; padding: 12px 16px; border: 2px solid rgba(139,117,95,0.3); border-radius: 8px; font-size: 1rem; margin-bottom: 12px; box-sizing: border-box;"
                       autocomplete="off">
                <button type="submit" 
                        style="width: 100%; padding: 12px 24px; background: linear-gradient(135deg, #8b5a3c 0%, #6b4a2c 100%); color: #fff; border: none; border-radius: 8px; font-weight: 800; font-size: 1rem; cursor: pointer; transition: all 0.3s ease;">
                    Получить доступ
                </button>
                <div class="mtp-code-message" style="margin-top: 12px; padding: 10px; border-radius: 6px; display: none;"></div>
            </form>
        </div>
        <script>
        (function() {
            var form = document.getElementById('<?php echo esc_js($unique_id); ?>');
            if (!form) return;
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var formData = new FormData(form);
                formData.append('action', 'mtp_verify_access_code');
                formData.append('role', form.dataset.role);
                <?php if ($post_id): ?>
                if (form.dataset.postId) {
                    formData.append('post_id', form.dataset.postId);
                }
                <?php endif; ?>
                
                var submitBtn = form.querySelector('button[type="submit"]');
                var messageDiv = form.querySelector('.mtp-code-message');
                var originalText = submitBtn.textContent;
                
                submitBtn.disabled = true;
                submitBtn.textContent = 'Проверка...';
                messageDiv.style.display = 'none';
                
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    // Проверяем статус ответа
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    // Пытаемся получить текст ответа для отладки
                    return response.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Ошибка парсинга JSON:', text);
                            throw new Error('Некорректный ответ от сервера: ' + text.substring(0, 100));
                        }
                    });
                })
                .then(function(data) {
                    if (data && data.success) {
                        // Устанавливаем cookie через JavaScript с правильными параметрами
                        if (data.data && data.data.cookie_name && data.data.cookie_value) {
                            var expiresDate = new Date(data.data.expires * 1000);
                            var cookieString = data.data.cookie_name + '=' + data.data.cookie_value + '; expires=' + expiresDate.toUTCString() + '; path=/';
                            // Если сайт на HTTPS, добавляем Secure
                            if (window.location.protocol === 'https:') {
                                cookieString += '; Secure';
                            }
                            document.cookie = cookieString;
                        }
                        
                        messageDiv.style.cssText = 'margin-top: 12px; padding: 12px; border-radius: 6px; display: block; background: rgba(198,246,213,0.9); color: #22543d; border: 2px solid #68d391; font-weight: 700;';
                        messageDiv.textContent = '✓ Доступ предоставлен! Страница обновится через 2 секунды...';
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        messageDiv.style.cssText = 'margin-top: 12px; padding: 12px; border-radius: 6px; display: block; background: rgba(254,226,226,0.9); color: #c53030; border: 2px solid #fc8181; font-weight: 700;';
                        messageDiv.textContent = (data && data.data && data.data.message) ? data.data.message : 'Неверный код доступа';
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                })
                .catch(function(error) {
                    console.error('Ошибка при проверке кода:', error);
                    messageDiv.style.cssText = 'margin-top: 12px; padding: 12px; border-radius: 6px; display: block; background: rgba(254,226,226,0.9); color: #c53030; border: 2px solid #fc8181; font-weight: 700;';
                    messageDiv.textContent = 'Ошибка при проверке кода: ' + (error.message || 'Неизвестная ошибка');
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX обработчик для проверки кода доступа
     */
    public function ajax_verify_access_code() {
        try {
            // Логируем входные данные для отладки (только если включен WP_DEBUG)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MTP Access Code Verification - POST data: ' . print_r($_POST, true));
            }
            
            $code = isset($_POST['access_code']) ? trim(sanitize_text_field($_POST['access_code'])) : '';
            $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : 'vip';
            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;
            
            if (empty($code)) {
                wp_send_json_error(array('message' => 'Код не может быть пустым'));
                return;
            }
            
            $code_valid = false;
            $code_id = null;
            
            // Если указан post_id, проверяем код из мета-поля поста (из шорткода)
            if ($post_id && is_numeric($post_id)) {
                $post_id = intval($post_id);
                $meta_key = '_mtp_access_code_' . sanitize_key($role);
                $post_code = get_post_meta($post_id, $meta_key, true);
                
                // Убираем пробелы для сравнения
                $post_code = trim($post_code);
                $code = trim($code);
                
                if ($post_code === $code && !empty($post_code)) {
                    $code_valid = true;
                    // Для статистики используем специальный ID для кодов из шорткода
                    $code_id = -$post_id; // Отрицательный ID для отличия от БД кодов
                }
            }
            
            // Если код не найден в мета-полях поста, проверяем в таблице БД (старые коды)
            if (!$code_valid) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'mtp_access_codes';
                
                // Проверяем существование таблицы
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
                
                if ($table_exists) {
                    $code_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, role FROM $table_name WHERE code = %s AND role = %s",
                        $code,
                        $role
                    ));
                    
                    if ($code_data) {
                        $code_valid = true;
                        $code_id = $code_data->id;
                    }
                }
            }
            
            if (!$code_valid) {
                wp_send_json_error(array('message' => 'Неверный код доступа. Проверьте правильность ввода.'));
                return;
            }
            
            // Сохраняем доступ в cookie на 7 дней (более надежно, чем сессия)
            $expires_timestamp = time() + (7 * 24 * 60 * 60); // 7 дней
            
            // Если код привязан к посту, сохраняем доступ для конкретного поста
            if ($post_id && is_numeric($post_id)) {
                $cookie_name = 'mtp_code_access_' . sanitize_key($role) . '_' . intval($post_id);
            } else {
                $cookie_name = 'mtp_code_access_' . sanitize_key($role);
            }
            
            // Устанавливаем cookie с правильными параметрами
            $cookie_domain = '';
            $cookie_path = '/';
            $cookie_secure = is_ssl();
            $cookie_httponly = false;
            
            // Устанавливаем cookie (если заголовки еще не отправлены)
            if (!headers_sent()) {
                setcookie($cookie_name, (string)$expires_timestamp, $expires_timestamp, $cookie_path, $cookie_domain, $cookie_secure, $cookie_httponly);
            }
            
            // Также устанавливаем в $_COOKIE для текущего запроса
            $_COOKIE[$cookie_name] = $expires_timestamp;
            
            // Сохраняем статистику использования (только для кодов из БД, не из шорткода)
            if ($code_id && $code_id > 0) {
                global $wpdb;
                $usage_table = $wpdb->prefix . 'mtp_access_code_usage';
                $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
                $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
                
                $wpdb->insert(
                    $usage_table,
                    array(
                        'code_id' => $code_id,
                        'ip_address' => $ip_address,
                        'user_agent' => $user_agent,
                        'expires_at' => date('Y-m-d H:i:s', $expires_timestamp)
                    ),
                    array('%d', '%s', '%s', '%s')
                );
            }
            
            // Возвращаем данные для установки cookie через JavaScript (на случай, если серверная установка не сработала)
            wp_send_json_success(array(
                'message' => 'Доступ предоставлен на 7 дней',
                'cookie_name' => $cookie_name,
                'cookie_value' => $expires_timestamp,
                'expires' => $expires_timestamp
            ));
        } catch (Exception $e) {
            // Логируем ошибку
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MTP Access Code Verification Error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
            }
            wp_send_json_error(array('message' => 'Ошибка сервера при проверке кода: ' . $e->getMessage()));
        } catch (Error $e) {
            // Логируем фатальную ошибку
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MTP Access Code Verification Fatal Error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
            }
            wp_send_json_error(array('message' => 'Критическая ошибка сервера. Обратитесь к администратору.'));
        }
    }

    // --- FAVORITES, FEED, INJECTOR ---
    public function handle_fav_action() {
        if (!is_user_logged_in()) wp_send_json_error('Need login');
        $post_id = intval($_POST['post_id']); if (!$post_id) wp_send_json_error('Invalid');
        $user_id = get_current_user_id(); $favs = get_user_meta($user_id, 'mtp_user_favs', true) ?: [];
        if (in_array($post_id, $favs)) { $favs = array_diff($favs, [$post_id]); $status = 'removed'; } else { $favs[] = $post_id; $status = 'added'; }
        update_user_meta($user_id, 'mtp_user_favs', array_values($favs)); wp_send_json_success(['status' => $status]);
    }
    public function render_fav_btn() {
        if (!is_user_logged_in()) return '';
        $post_id = get_the_ID(); 
        $user_id = get_current_user_id(); 
        $favs = get_user_meta($user_id, 'mtp_user_favs', true) ?: []; 
        $is_fav = in_array($post_id, $favs);
        $text = $is_fav ? 'В избранном' : 'В избранное'; 
        $class = $is_fav ? 'action-btn btn-favorite active' : 'action-btn btn-favorite';
        $current_url = esc_url(get_permalink($post_id));
        $encoded_url = urlencode($current_url);
        
        $out = '<style>
        .deck-actions-container {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }
        
        .action-btn {
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-decoration: none;
        }
        
        .btn-favorite {
            background: #3182ce;
            color: #fff;
        }
        
        .btn-favorite:hover {
            background: #2c5282;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(49, 130, 206, 0.3);
        }
        
        .btn-favorite:not(.active) {
            background: #3182ce;
        }
        
        .btn-share {
            background: #fdfbf7;
            color: #3d2f1f;
            border: 1px solid rgba(139, 117, 95, 0.2);
        }
        
        .btn-share:hover {
            background: #f5f1e8;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.12);
        }
        
        .icon-heart:before {
            content: "\f487";
            font-family: "dashicons";
            font-size: 18px;
            line-height: 1;
        }
        
        .btn-favorite.active .icon-heart:before {
            content: "\f488";
        }
        
        .icon-share:before {
            content: "\f237";
            font-family: "dashicons";
            font-size: 18px;
            line-height: 1;
        }
        
        .icon-link:before {
            content: "\f103";
            font-family: "dashicons";
            font-size: 18px;
            line-height: 1;
            margin-right: 8px;
        }
        
        .icon-telegram:before {
            content: "✈";
            font-size: 18px;
            line-height: 1;
            margin-right: 8px;
            display: inline-block;
        }
        
        .icon-vk:before {
            content: "ВК";
            font-size: 14px;
            line-height: 1;
            margin-right: 8px;
            display: inline-block;
            font-weight: 700;
        }
        
        .share-wrapper {
            position: relative;
        }
        
        .share-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            background: #fdfbf7;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            min-width: 220px;
            z-index: 1000;
            overflow: hidden;
            border: 1px solid rgba(139, 117, 95, 0.15);
        }
        
        .share-dropdown.show {
            display: block;
        }
        
        .share-item {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            color: #3d2f1f;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: background 0.2s ease;
            border-bottom: 1px solid rgba(139, 117, 95, 0.1);
        }
        
        .share-item:last-child {
            border-bottom: none;
        }
        
        .share-item:hover {
            background: rgba(139, 117, 95, 0.08);
            color: #2d1b0e;
        }
        
        .share-item.copied {
            background: rgba(56, 161, 105, 0.1);
            color: #22543d;
        }
        </style>';
        
        $out .= '<div class="deck-actions-container">';
        $out .= '<button class="'.$class.'" data-post-id="'.$post_id.'">';
        $out .= '<span class="icon-heart"></span>';
        $out .= '<span class="mtp-fav-txt">'.$text.'</span>';
        $out .= '</button>';
        
        $out .= '<div class="share-wrapper">';
        $out .= '<button class="action-btn btn-share" id="shareBtn-'.$post_id.'">';
        $out .= '<span class="icon-share"></span>';
        $out .= '<span>Поделиться</span>';
        $out .= '</button>';
        
        $out .= '<div class="share-dropdown" id="shareDropdown-'.$post_id.'">';
        $out .= '<a href="#" class="share-item" id="copyLinkBtn-'.$post_id.'">';
        $out .= '<span class="icon-link"></span> Скопировать ссылку';
        $out .= '</a>';
        $out .= '<a href="https://t.me/share/url?url='.$encoded_url.'" target="_blank" class="share-item">';
        $out .= '<span class="icon-telegram"></span> Telegram';
        $out .= '</a>';
        $out .= '<a href="https://vk.com/share.php?url='.$encoded_url.'" target="_blank" class="share-item">';
        $out .= '<span class="icon-vk"></span> ВКонтакте';
        $out .= '</a>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</div>';
        
        $out .= "<script>
        (function() {
            var shareBtn = document.getElementById('shareBtn-".$post_id."');
            var shareDropdown = document.getElementById('shareDropdown-".$post_id."');
            var copyLinkBtn = document.getElementById('copyLinkBtn-".$post_id."');
            var shareWrapper = shareBtn ? shareBtn.closest('.share-wrapper') : null;
            var currentUrl = '".$current_url."';
            
            if (shareBtn && shareDropdown && shareWrapper) {
                shareBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    shareDropdown.classList.toggle('show');
                });
                
                copyLinkBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(currentUrl).then(function() {
                            copyLinkBtn.classList.add('copied');
                            var originalText = copyLinkBtn.innerHTML;
                            copyLinkBtn.innerHTML = '<span class=\"icon-link\"></span> Скопировано!';
                            setTimeout(function() {
                                copyLinkBtn.classList.remove('copied');
                                copyLinkBtn.innerHTML = originalText;
                            }, 2000);
                        });
                    } else {
                        var textArea = document.createElement('textarea');
                        textArea.value = currentUrl;
                        textArea.style.position = 'fixed';
                        textArea.style.opacity = '0';
                        document.body.appendChild(textArea);
                        textArea.select();
                        try {
                            document.execCommand('copy');
                            copyLinkBtn.classList.add('copied');
                            var originalText = copyLinkBtn.innerHTML;
                            copyLinkBtn.innerHTML = '<span class=\"icon-link\"></span> Скопировано!';
                            setTimeout(function() {
                                copyLinkBtn.classList.remove('copied');
                                copyLinkBtn.innerHTML = originalText;
                            }, 2000);
                        } catch (err) {
                            alert('Не удалось скопировать ссылку');
                        }
                        document.body.removeChild(textArea);
                    }
                });
                
                document.addEventListener('click', function(e) {
                    if (shareWrapper && !shareWrapper.contains(e.target)) {
                        shareDropdown.classList.remove('show');
                    }
                });
            }
            
            jQuery(document).ready(function($) {
                $('.btn-favorite[data-post-id=\"".$post_id."\"]').off('click.fav').on('click.fav', function() {
                    var btn = $(this);
                    var txt = btn.find('.mtp-fav-txt');
                    btn.prop('disabled', true);
                    $.post('".admin_url('admin-ajax.php')."', {
                        action: 'mtp_fav_action',
                        post_id: btn.data('post-id')
                    }, function(res) {
                        btn.prop('disabled', false);
                        if (res.success) {
                            if (res.data.status === 'added') {
                                btn.addClass('active');
                                txt.text('В избранном');
                            } else {
                                btn.removeClass('active');
                                txt.text('В избранное');
                            }
                        }
                    });
                });
            });
        })();
        </script>";
        
        return $out;
    }
    public function render_fav_feed($atts) {
        if (!is_user_logged_in()) return '<p style="text-align:center; padding:20px;">Авторизуйтесь, чтобы видеть избранное.</p>';
        $user_id = get_current_user_id(); $favs = get_user_meta($user_id, 'mtp_user_favs', true); if (empty($favs)) return '<p style="text-align:center; padding:30px; color:#a0aec0; background:#f7fafc; border-radius:8px; font-weight:500;">Список избранного пуст.</p>';
        $a = shortcode_atts(['count' => 9], $atts); $args = ['post_type' => ['user_deck', 'post'], 'posts_per_page' => $a['count'], 'post__in' => $favs, 'orderby' => 'post__in', 'post_status' => 'publish'];
        return $this->render_feed_from_args($args);
    }
    public function auto_format_content_injector($content) {
        if (is_admin()) return $content;
        if (is_singular('user_deck')) {
            $post_id = get_the_ID(); $deck_code = get_post_meta($post_id, 'mtp_deck_code', true); $img_url = get_the_post_thumbnail_url($post_id, 'large');
            $style = '<style>.mtp-copy-btn-single { margin-top:15px; background:linear-gradient(135deg, #8b5a3c 0%, #6b4a2c 100%); color:#fff; border:none; padding:14px 32px; border-radius:10px; cursor:pointer; font-size:16px; font-weight:800; transition:all 0.3s ease; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(139,90,60,0.25); letter-spacing:0.3px; } .mtp-copy-btn-single:hover { background:linear-gradient(135deg, #9b6a4c 0%, #7b5a3c 100%); transform:translateY(-2px); box-shadow:0 6px 16px rgba(139,90,60,0.35); } .mtp-copy-btn-single.copied { background:linear-gradient(135deg, #6b8a5c 0%, #5a7a4c 100%); box-shadow:0 4px 12px rgba(107,138,92,0.25); }</style>';
            $code_html = $deck_code ? '<div style="background:rgba(250,245,240,0.8); border:1px solid rgba(139,117,95,0.15); border-radius:12px; padding:28px; margin-bottom:30px; box-shadow:0 4px 12px rgba(139,117,95,0.08);"><p style="margin:0 0 12px 0; font-weight:800; color:#2d1b0e; font-size:0.95rem; text-transform:uppercase; letter-spacing:0.8px;">Код колоды</p><textarea readonly style="width:100%; height:70px; font-family:monospace; font-size:13px; padding:12px; border:1px solid rgba(139,117,95,0.2); border-radius:8px; resize:none; background:#fff; color:#2d3748; box-shadow:inset 0 2px 4px rgba(0,0,0,0.03);" onclick="this.select()">' . esc_textarea($deck_code) . '</textarea><button onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.querySelector(\'span\').innerText=\'Скопировано!\'; this.classList.add(\'copied\'); setTimeout(()=>{this.querySelector(\'span\').innerText=\'Копировать код\'; this.classList.remove(\'copied\');}, 2000);" class="mtp-copy-btn-single"><span class="dashicons dashicons-clipboard" style="line-height:1.3"></span> <span>Копировать код</span></button></div>' : '';
            $img_html = $img_url ? '<div style="margin-bottom:30px;"><img src="'.esc_url($img_url).'" style="width:100%; height:auto; border-radius:12px; border:1px solid rgba(139,117,95,0.15); display:block; box-shadow:0 6px 20px rgba(139,117,95,0.12);"></div>' : '';
            $header_html = '<div style="clear:both; margin-top:40px; margin-bottom:25px; border-bottom:2px solid rgba(139,117,95,0.15); padding-bottom:12px;"><h3 style="margin:0; padding:0; font-size:1.85rem; color:#2d1b0e; font-weight:900; letter-spacing:-0.02em; text-shadow:0 1px 2px rgba(0,0,0,0.05);">Описание колоды</h3></div>';
            $content = $style . $code_html . $img_html . $header_html . '<div style="font-size:1.05rem; line-height:1.75; color:#3d2f1f;">' . $content . '</div>';
        }
        if ((is_singular('user_deck') || is_singular('post')) && is_user_logged_in()) { 
            $fav_btn = $this->render_fav_btn(); 
            $content .= $fav_btn; 
        }
        return $content;
    }
    public function render_deck_feed($atts) { $a = shortcode_atts(['count' => 9, 'type' => 'user_deck', 'author_id' => ''], $atts); $args = ['post_type' => $a['type'], 'posts_per_page' => $a['count'], 'post_status' => 'publish']; if (!empty($a['author_id'])) $args['author'] = $a['author_id']; return $this->render_feed_from_args($args); }
    private function render_feed_from_args($args) {
        $query = new WP_Query($args); 
        if (!$query->have_posts()) {
            return '<div style="max-width:1600px;margin:0 auto;padding:0 20px 40px 20px"><div style="text-align:center; padding:30px; color:#6b5d4a; background:rgba(250,245,240,0.8); border-radius:12px; font-weight:600; border:1px solid rgba(139,117,95,0.12);">Записей не найдено.</div></div>';
        }
        
        // ОПТИМИЗАЦИЯ: Загружаем все мета-данные одним запросом для всех постов
        $post_ids = wp_list_pluck($query->posts, 'ID');
        $all_meta = [];
        if (!empty($post_ids)) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
            $meta_query = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} 
                WHERE post_id IN ($placeholders) 
                AND meta_key IN ('mtp_has_guide', 'mtp_deck_code')",
                ...$post_ids
            );
            $meta_results = $wpdb->get_results($meta_query);
            
            // Группируем мета-данные по post_id
            foreach ($meta_results as $meta) {
                if (!isset($all_meta[$meta->post_id])) {
                    $all_meta[$meta->post_id] = [];
                }
                $all_meta[$meta->post_id][$meta->meta_key] = $meta->meta_value;
            }
        }
        
        // ОПТИМИЗАЦИЯ: Загружаем все аватары пользователей одним запросом
        $author_ids = array_unique(wp_list_pluck($query->posts, 'post_author'));
        $author_avatars = [];
        if (!empty($author_ids)) {
            foreach ($author_ids as $author_id) {
                $author_avatars[$author_id] = $this->get_user_photo_url_raw($author_id) ?: get_avatar_url($author_id);
            }
        }
        
        $out = '<style>
        .mtp-deck-container{max-width:1600px !important;margin:0 auto !important;padding:0 20px 40px 20px !important;width:100% !important;box-sizing:border-box !important}
        .mtp-deck-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:25px;margin-bottom:40px}
        .mtp-deck-card{background:rgba(250,245,240,0.85);border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(139,117,95,0.12),0 0 0 1px rgba(139,117,95,0.12);transition:all .3s cubic-bezier(0.4, 0, 0.2, 1);border:none;display:flex;flex-direction:column;backdrop-filter:blur(10px);position:relative;min-width:0}
        .mtp-deck-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(139,117,95,0.18),0 0 0 1px rgba(139,117,95,0.15)}
        .mtp-deck-img-wrap{height:160px;overflow:hidden;display:block;position:relative;background:rgba(240,235,230,0.6)}
        .mtp-deck-img{width:100%;height:100%;object-fit:cover;transition:transform .6s cubic-bezier(0.4, 0, 0.2, 1)}
        .mtp-deck-card:hover .mtp-deck-img{transform:scale(1.08)}
        .mtp-deck-body{padding:20px;flex-grow:1;display:flex;flex-direction:column;min-height:0}
        .mtp-deck-date{font-size:.7rem;color:#6b5d4a;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;font-weight:800;text-transform:uppercase;letter-spacing:.8px}
        .mtp-guide-badge{background:linear-gradient(135deg, #8b5a3c 0%, #6b4a2c 100%);color:#fff;font-size:9px;padding:4px 8px;border-radius:6px;font-weight:900;text-transform:uppercase;letter-spacing:0.6px;box-shadow:0 2px 6px rgba(139,90,60,0.2)}
        .mtp-deck-title{font-weight:900;font-size:1.1rem;line-height:1.35;margin-bottom:12px;color:#2d1b0e;text-decoration:none!important;display:block;transition:color .25s ease;letter-spacing:-0.01em;text-shadow:0 1px 1px rgba(0,0,0,0.02);min-height:2.7em}
        .mtp-deck-title:hover{color:#8b5a3c}
        .mtp-deck-author{display:flex;align-items:center;gap:10px;margin-top:auto;padding-top:12px;border-top:1px solid rgba(139,117,95,0.15);margin-bottom:12px}
        .mtp-auth-ava{width:30px;height:30px;border-radius:50%;object-fit:cover;border:2px solid rgba(250,245,240,0.9);box-shadow:0 2px 4px rgba(139,117,95,0.1)}
        .mtp-btn-container{margin-top:auto;display:grid;grid-template-columns:1fr 1fr;gap:10px;row-gap:10px;width:100%}
        .mtp-btn-full{grid-column:span 2}
        .mtp-btn{display:flex;align-items:center;justify-content:center;text-align:center;padding:11px 12px;border-radius:8px;font-weight:800;text-decoration:none;font-size:.82rem;transition:all .25s cubic-bezier(0.4, 0, 0.2, 1);border:1px solid transparent;cursor:pointer;width:100%;box-sizing:border-box;background:rgba(240,235,230,0.9);color:#6b5d4a;height:42px;letter-spacing:0.05px;white-space:nowrap;overflow:visible;min-width:0;word-break:keep-all}
        .mtp-btn-std{border-color:rgba(139,117,95,0.25);background:rgba(240,235,230,0.8)}
        .mtp-btn-std:hover{border-color:rgba(139,117,95,0.35);background:rgba(250,245,240,0.95);color:#2d1b0e;transform:translateY(-1px);box-shadow:0 3px 8px rgba(139,117,95,0.15)}
        .mtp-btn-copy{background:linear-gradient(135deg, #8b5a3c 0%, #6b4a2c 100%);color:#fff;border-color:transparent;box-shadow:0 3px 10px rgba(139,90,60,0.2)}
        .mtp-btn-copy:hover{background:linear-gradient(135deg, #9b6a4c 0%, #7b5a3c 100%);transform:translateY(-1px);box-shadow:0 4px 12px rgba(139,90,60,0.3)}
        .mtp-btn-copy.copied{background:linear-gradient(135deg, #6b8a5c 0%, #5a7a4c 100%);box-shadow:0 3px 10px rgba(107,138,92,0.25)}
        @media (max-width:1200px){.mtp-deck-container{padding:0 15px 30px 15px}.mtp-deck-grid{grid-template-columns:repeat(2,1fr);gap:22px}.mtp-deck-body{padding:18px}}
        @media (max-width:900px){.mtp-deck-container{padding:0 15px 30px 15px}.mtp-deck-grid{grid-template-columns:repeat(2,1fr);gap:18px;margin-bottom:30px}.mtp-deck-card{padding:0}.mtp-deck-body{padding:16px}.mtp-deck-img-wrap{height:140px}.mtp-btn{font-size:0.78rem;padding:10px 10px}}
        @media (max-width:600px){.mtp-deck-container{padding:0 10px 20px 10px}.mtp-deck-grid{grid-template-columns:1fr;gap:12px;margin-bottom:25px}.mtp-deck-body{padding:14px}.mtp-deck-img-wrap{height:120px}.mtp-deck-title{font-size:1rem;min-height:2.4em}.mtp-btn-container{grid-template-columns:1fr;gap:8px}.mtp-btn-full{grid-column:span 1}.mtp-btn{height:38px;padding:10px 8px;font-size:0.8rem}}
        @media (max-width:400px){.mtp-deck-container{padding:0 10px 20px 10px}.mtp-deck-img-wrap{height:100px}.mtp-deck-body{padding:12px}.mtp-deck-title{font-size:0.95rem}.mtp-btn{font-size:0.75rem;padding:9px 6px}}
        </style><div class="mtp-deck-container"><div class="mtp-deck-grid">';
        while ($query->have_posts()) {
            $query->the_post(); 
            $pid = get_the_ID(); 
            $auth_id = get_the_author_meta('ID'); 
            $img_url = get_the_post_thumbnail_url($pid, 'medium') ?: 'https://via.placeholder.com/400x250?text=Deck'; 
            // ОПТИМИЗАЦИЯ: Используем предзагруженные аватары
            $ava = isset($author_avatars[$auth_id]) ? $author_avatars[$auth_id] : get_avatar_url($auth_id); 
            $link = esc_url(get_permalink()); 
            // ОПТИМИЗАЦИЯ: Используем предзагруженные мета-данные
            $has_guide = isset($all_meta[$pid]['mtp_has_guide']) ? $all_meta[$pid]['mtp_has_guide'] : false; 
            $deck_code = isset($all_meta[$pid]['mtp_deck_code']) ? $all_meta[$pid]['mtp_deck_code'] : ''; 
            $guide_html = $has_guide ? '<span class="mtp-guide-badge">Гайд</span>' : ''; 
            $can_edit = (is_user_logged_in() && get_current_user_id() == $auth_id);
            $title = esc_html(get_the_title());
            $author_name = esc_html(get_the_author_meta('display_name'));
            $date = esc_html(get_the_date('j M Y'));
            
            $out .= '<div class="mtp-deck-card"><a href="'.$link.'" class="mtp-deck-img-wrap"><img src="'.esc_url($img_url).'" class="mtp-deck-img" loading="lazy" alt="'.esc_attr($title).'"></a><div class="mtp-deck-body"><div class="mtp-deck-date"><span>'.$date.'</span> '.$guide_html.'</div><a href="'.$link.'" class="mtp-deck-title">'.$title.'</a><div class="mtp-deck-author"><img src="'.esc_url($ava).'" class="mtp-auth-ava" alt="'.esc_attr($author_name).'"><span style="font-size:0.9rem; font-weight:700; color:#4a5568;">'.$author_name.'</span></div><div class="mtp-btn-container"><a href="'.$link.'" class="mtp-btn mtp-btn-std">Подробнее</a>';
            if ($can_edit) { 
                $edit_url = esc_url(home_url('/dobavlenie-kolody/?edit_id=' . $pid)); 
                $out .= '<a href="'.$edit_url.'" class="mtp-btn mtp-btn-std">Редактировать</a>'; 
            } else { 
                $out = str_replace('mtp-btn-std">Подробнее</a>', 'mtp-btn-std mtp-btn-full">Подробнее</a>', $out); 
            }
            if ($deck_code) { 
                $out .= '<button class="mtp-btn mtp-btn-copy mtp-btn-full" onclick="navigator.clipboard.writeText(\''.esc_js($deck_code).'\').then(() => { this.innerText = \'Скопировано!\'; this.classList.add(\'copied\'); setTimeout(() => { this.innerText = \'Копировать код\'; this.classList.remove(\'copied\'); }, 2000); });">Копировать код</button>'; 
            }
            $out .= '</div></div></div>'; 
        }
        $out .= '</div></div>'; 
        wp_reset_postdata(); 
        return $out;
    }

    // --- OTHER ---
    public function handle_login() { 
        if (!isset($_GET['tg_auth'])) return; 
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); 
        
        // Отправляем заголовки без кеша, но не используем nocache_headers(), чтобы не сбросить куки
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        
        // Запускаем сессию для получения return_url
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        $token = get_option('mta_bot_token'); 
        if (!$token) {
            wp_die('Bot token not configured');
        }
        
        $a = $_GET; 
        
        // Проверяем наличие обязательных параметров
        if (!isset($a['hash']) || !isset($a['id'])) {
            wp_die('Auth Error: Missing required parameters');
        }
        
        $check = $a['hash']; 
        
        // Удаляем только служебные параметры, но не return_to (его нет в GET)
        unset($a['hash'], $a['tg_auth']); 
        $arr = []; 
        foreach ($a as $k => $v) $arr[] = $k.'='.$v; 
        sort($arr); 
        $sec = hash('sha256', $token, true); 
        $hash = hash_hmac('sha256', implode("\n", $arr), $sec); 
        if (strcmp($hash, $check) !== 0) {
            wp_die('Auth Error: Invalid hash');
        }
        
        // Валидируем и санитизируем Telegram ID
        $uid = isset($a['id']) ? sanitize_text_field($a['id']) : '';
        if (empty($uid) || !is_numeric($uid)) {
            wp_die('Auth Error: Invalid user ID');
        } 
        
        // Получаем return_url из сессии
        $return_url = isset($_SESSION['mtp_return_url']) ? $_SESSION['mtp_return_url'] : '';
        unset($_SESSION['mtp_return_url']);
        
        if (is_user_logged_in()) { 
            $curr = wp_get_current_user(); 
            if ($curr->user_login === 'tg_'.$uid) { 
                // Если уже авторизован, редиректим на сохраненную страницу
                $redirect_url = !empty($return_url) ? $return_url : (wp_get_referer() ?: home_url());
                wp_safe_redirect($redirect_url); 
                exit; 
            } 
        } 
        
        $u = get_user_by('login', 'tg_'.$uid); 
        if (!$u) { 
            // Санитизируем данные перед созданием пользователя
            $first_name = isset($a['first_name']) ? sanitize_text_field($a['first_name']) : '';
            $last_name = isset($a['last_name']) ? sanitize_text_field($a['last_name']) : '';
            $username = sanitize_user('tg_'.$uid, true);
            $email = sanitize_email('tg_'.$uid.'@loc.al');
            
            // Создаем пользователя с обработкой ошибок
            $id = wp_create_user($username, wp_generate_password(), $email);
            if (is_wp_error($id)) {
                $this->log("Failed to create user: " . $id->get_error_message(), 'error');
                wp_die('Auth Error: Failed to create user account');
            }
            
            $u = new WP_User($id); 
            $u->set_role('subscriber'); 
            
            // Обновляем данные пользователя с санитизированными значениями
            $display_name = trim($first_name . ' ' . $last_name);
            if (empty($display_name)) {
                $display_name = $first_name ?: 'User ' . $uid;
            }
            
            $update_result = wp_update_user([
                'ID' => $u->ID, 
                'display_name' => $display_name, 
                'nickname' => $first_name ?: $username
            ]);
            
            if (is_wp_error($update_result)) {
                $this->log("Failed to update user data: " . $update_result->get_error_message(), 'error');
            }
        } 
        
        // Обновляем фото пользователя с валидацией URL
        if (isset($a['photo_url']) && !empty($a['photo_url'])) {
            $photo_url = esc_url_raw($a['photo_url']);
            if (filter_var($photo_url, FILTER_VALIDATE_URL)) {
                update_user_meta($u->ID, 'tg_photo_url', $photo_url);
                // Очищаем кеш фото пользователя
                wp_cache_delete('mtp_user_photo_' . $u->ID, 'mtp_user_photos');
            } else {
                $this->log("Invalid photo URL for user {$u->ID}: " . $a['photo_url'], 'warning');
            }
        } 
        
        // Проверяем подписки асинхронно, чтобы не блокировать авторизацию
        // Запускаем проверку через 2 секунды после авторизации
        wp_schedule_single_event(time() + 2, 'mta_check_single_user_sub', [$u->ID]);
        $this->log("User {$u->ID} ({$u->user_login}) authorized, subscription check scheduled");
        
        // Очищаем старые куки и устанавливаем новые с правильными параметрами
        wp_clear_auth_cookie(); 
        wp_set_current_user($u->ID); 
        
        // Устанавливаем куки с remember=true для долгосрочной сессии
        // Используем правильные параметры для надежной авторизации
        $secure = is_ssl();
        
        // Устанавливаем куки через WordPress функцию
        // Фильтр extend_auth_cookie_expiration автоматически увеличит время жизни для Telegram пользователей
        // wp_set_auth_cookie автоматически устанавливает текущего пользователя через wp_set_current_user
        wp_set_auth_cookie($u->ID, true, $secure);
        
        do_action('wp_login', $u->user_login, $u); 
        
        // Редиректим на сохраненную страницу или referer, а не в личный кабинет
        if (!empty($return_url)) {
            $redirect_url = $return_url;
        } else {
            // Пытаемся получить referer
            $redirect_url = wp_get_referer();
            if (!$redirect_url || strpos($redirect_url, home_url()) === false) {
                // Если referer не найден или не с нашего сайта, используем главную
                $redirect_url = home_url();
            }
        }
        
        // Добавляем параметр успешной авторизации для автоматического обновления страницы
        $redirect_url = add_query_arg('auth_success', '1', $redirect_url);
        
        wp_safe_redirect($redirect_url); 
        exit; 
    }
    public function admin_scripts($hook) {
        if (strpos($hook, 'mtp-avatars') !== false) {
            wp_enqueue_media();
        }
    }

    public function add_admin_menu() { 
        // Создаем отдельное меню верхнего уровня
        add_menu_page(
            'TG Auth',
            'TG Auth',
            'manage_options',
            'mtp-auth',
            [$this, 'render_admin_page'],
            'dashicons-admin-users',
            30
        );
        
        // Подменю "Настройки" (главная страница)
        add_submenu_page('mtp-auth', 'Настройки', 'Настройки', 'manage_options', 'mtp-auth', [$this, 'render_admin_page']);
        
        // Подменю "Статистика"
        add_submenu_page('mtp-auth', 'Статистика', 'Статистика', 'manage_options', 'mtp-statistics', [$this, 'page_statistics']);
        
        // Подменю "Аватарки" (скрытое, доступно по прямой ссылке)
        add_submenu_page('mtp-auth', 'Аватарки', 'Аватарки', 'manage_options', 'mtp-avatars', [$this, 'page_avatars']);
        
        // Подменю "Коды доступа"
        add_submenu_page('mtp-auth', 'Коды доступа', 'Коды доступа', 'manage_options', 'mtp-access-codes', [$this, 'page_access_codes']);
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Настройки Auth</h1>
            <form method="post" action="options.php">
                <?php settings_fields('mtp_grp'); do_settings_sections('mtp_grp'); submit_button(); ?>
            </form>
            
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid #ccc;">
                <h2>Проверка подписок</h2>
                <p>Проверка подписок всех пользователей может занять время. Используйте пакетную проверку или проверку отдельного пользователя.</p>
                
                <div style="margin:20px 0;">
                    <button type="button" id="mta-check-all-btn" class="button button-secondary">
                        🔄 Проверить все подписки (пакетно)
                    </button>
                    <div id="mta-check-all-progress" style="margin-top:15px; display:none;">
                        <div style="background:#f0f0f1; border-radius:4px; height:24px; position:relative; overflow:hidden;">
                            <div id="mta-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
                        </div>
                        <p id="mta-progress-text" style="margin-top:10px; font-weight:600;"></p>
                        <div id="mta-check-logs" style="margin-top:15px; max-height:300px; overflow-y:auto; padding:10px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px; font-family:monospace; font-size:12px; display:none;"></div>
                    </div>
                </div>
                
                <div style="margin:20px 0; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
                    <h3>Проверить отдельного пользователя</h3>
                    <p>Введите Telegram ID пользователя (число без префикса tg_)</p>
                    <input type="text" id="mta-single-user-id" placeholder="123456789" style="width:200px; padding:6px; margin-right:10px;">
                    <button type="button" id="mta-check-single-btn" class="button button-primary">
                        Проверить
                    </button>
                    <div id="mta-single-result" style="margin-top:15px; padding:10px; background:#fff; border:1px solid #ddd; border-radius:4px; display:none;"></div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var checking = false;
            var totalUsers = 0;
            var processedUsers = 0;
            
            $('#mta-check-all-btn').on('click', function() {
                if (checking) return;
                checking = true;
                processedUsers = 0;
                $('#mta-check-all-progress').show();
                $('#mta-progress-bar').css('width', '0%');
                $('#mta-progress-text').text('Инициализация...');
                $('#mta-check-logs').hide().empty();
                $('#mta-check-all-btn').prop('disabled', true);
                
                checkBatch(0);
            });
            
            function checkBatch(offset) {
                $.post(ajaxurl, {
                    action: 'mtp_check_subscription_batch',
                    offset: offset,
                    nonce: '<?php echo wp_create_nonce("mta_check_batch"); ?>'
                }, function(response) {
                    if (response.success) {
                        if (totalUsers === 0) {
                            totalUsers = response.data.total;
                        }
                        
                        processedUsers += response.data.processed;
                        var percent = totalUsers > 0 ? Math.round((processedUsers / totalUsers) * 100) : 0;
                        $('#mta-progress-bar').css('width', percent + '%');
                        $('#mta-progress-text').text('Обработано: ' + processedUsers + ' из ' + totalUsers + ' (' + percent + '%)');
                        
                        if (response.data.logs && response.data.logs.length > 0) {
                            var $logs = $('#mta-check-logs');
                            $logs.show();
                            response.data.logs.forEach(function(log) {
                                $logs.append('<div>' + log + '</div>');
                            });
                            $logs.scrollTop($logs[0].scrollHeight);
                        }
                        
                        if (response.data.has_more) {
                            setTimeout(function() {
                                checkBatch(offset + response.data.processed);
                            }, 100);
                        } else {
                            checking = false;
                            $('#mta-check-all-btn').prop('disabled', false);
                            $('#mta-progress-text').text('Готово! Обработано ' + processedUsers + ' пользователей.');
                        }
                    } else {
                        checking = false;
                        $('#mta-check-all-btn').prop('disabled', false);
                        $('#mta-progress-text').text('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                    }
                }).fail(function() {
                    checking = false;
                    $('#mta-check-all-btn').prop('disabled', false);
                    $('#mta-progress-text').text('Ошибка соединения с сервером');
                });
            }
            
            $('#mta-check-single-btn').on('click', function() {
                var userId = $('#mta-single-user-id').val().trim();
                if (!userId || !/^\d+$/.test(userId)) {
                    alert('Введите корректный Telegram ID (только цифры)');
                    return;
                }
                
                var $btn = $(this);
                var $result = $('#mta-single-result');
                $btn.prop('disabled', true);
                $result.hide().html('<p>Проверка...</p>').show();
                
                $.post(ajaxurl, {
                    action: 'mtp_check_single_user',
                    user_id: userId,
                    nonce: '<?php echo wp_create_nonce("mta_check_single"); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        var html = '<h4>Результат проверки для пользователя ' + userId + '</h4>';
                        html += '<ul style="list-style:disc; margin-left:20px;">';
                        response.data.results.forEach(function(item) {
                            html += '<li><strong>' + item.chat_id + ':</strong> ' + item.status;
                            if (item.role) {
                                html += ' → Роль: ' + item.role;
                            }
                            html += '</li>';
                        });
                        html += '</ul>';
                        if (response.data.user_found) {
                            html += '<p style="color:green; font-weight:600;">✓ Пользователь найден в системе</p>';
                        } else {
                            html += '<p style="color:orange; font-weight:600;">⚠ Пользователь не найден в системе (проверка только подписок)</p>';
                        }
                        $result.html(html);
                    } else {
                        $result.html('<p style="color:red;">Ошибка: ' + (response.data || 'Неизвестная ошибка') + '</p>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.html('<p style="color:red;">Ошибка соединения с сервером</p>');
                });
            });
        });
        </script>
        <?php
    }

    public function page_statistics() {
        ?>
        <div class="wrap">
            <h1>📊 Статистика Telegram Авторизации</h1>
            <p>Статистика пользователей, регистраций и ролей в реальном времени.</p>
            
            <div id="mtp-statistics-container" style="margin-top:20px;">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:20px; margin-bottom:30px;">
                    <div class="mtp-stat-card" style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <div style="font-size:14px; color:#666; margin-bottom:8px;">Всего пользователей</div>
                        <div id="stat-total-users" style="font-size:32px; font-weight:700; color:#2271b1;">-</div>
                    </div>
                    <div class="mtp-stat-card" style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <div style="font-size:14px; color:#666; margin-bottom:8px;">Активных (30 дней)</div>
                        <div id="stat-active-users" style="font-size:32px; font-weight:700; color:#38a169;">-</div>
                    </div>
                    <div class="mtp-stat-card" style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <div style="font-size:14px; color:#666; margin-bottom:8px;">Новых сегодня</div>
                        <div id="stat-new-today" style="font-size:32px; font-weight:700; color:#ed8936;">-</div>
                    </div>
                    <div class="mtp-stat-card" style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <div style="font-size:14px; color:#666; margin-bottom:8px;">Новых за неделю</div>
                        <div id="stat-new-week" style="font-size:32px; font-weight:700; color:#805ad5;">-</div>
                    </div>
                </div>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:30px;">
                    <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:15px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <h2 style="margin-top:0; font-size:18px; margin-bottom:10px;">Распределение по ролям</h2>
                        <div id="stat-roles-chart" style="min-height:220px;">
                            <canvas id="rolesChart"></canvas>
                        </div>
                    </div>
                    <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:15px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <h2 style="margin-top:0; font-size:18px; margin-bottom:10px;">Регистрации по дням (последние 30 дней)</h2>
                        <div id="stat-registrations-chart" style="min-height:220px;">
                            <canvas id="registrationsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <h2 style="margin-top:0; font-size:18px;">Детальная статистика по ролям</h2>
                    <div id="stat-roles-table" style="overflow-x:auto;">
                        <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                            <thead>
                                <tr>
                                    <th>Роль</th>
                                    <th>Количество</th>
                                    <th>Процент</th>
                                </tr>
                            </thead>
                            <tbody id="stat-roles-tbody">
                                <tr><td colspan="3" style="text-align:center; padding:20px;">Загрузка...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div style="margin-top:20px; text-align:right;">
                    <button type="button" id="mtp-refresh-stats" class="button button-secondary">
                        🔄 Обновить статистику
                    </button>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
        <script>
        jQuery(document).ready(function($) {
            var rolesChart = null;
            var registrationsChart = null;
            
            function loadStatistics() {
                $.post(ajaxurl, {
                    action: 'mtp_get_statistics',
                    nonce: '<?php echo wp_create_nonce("mta_get_stats"); ?>'
                }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Обновляем карточки
                        $('#stat-total-users').text(data.total_users || 0);
                        $('#stat-active-users').text(data.active_users || 0);
                        $('#stat-new-today').text(data.new_today || 0);
                        $('#stat-new-week').text(data.new_week || 0);
                        
                        // Обновляем график ролей
                        if (rolesChart) {
                            rolesChart.destroy();
                        }
                        
                        var rolesCtx = document.getElementById('rolesChart');
                        if (rolesCtx) {
                            var rolesData = data.roles || {};
                            var labels = Object.keys(rolesData);
                            var values = Object.values(rolesData);
                            
                            rolesChart = new Chart(rolesCtx, {
                                type: 'doughnut',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        data: values,
                                        backgroundColor: [
                                            '#2271b1',
                                            '#38a169',
                                            '#ed8936',
                                            '#805ad5',
                                            '#e53e3e',
                                            '#718096',
                                            '#d69e2e'
                                        ]
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: true,
                                    plugins: {
                                        legend: {
                                            position: 'bottom'
                                        }
                                    }
                                }
                            });
                        }
                        
                        // Обновляем график регистраций
                        if (registrationsChart) {
                            registrationsChart.destroy();
                        }
                        
                        var regCtx = document.getElementById('registrationsChart');
                        if (regCtx) {
                            var regData = data.registrations || [];
                            var regLabels = regData.map(function(item) { return item.date; });
                            var regValues = regData.map(function(item) { return item.count; });
                            
                            registrationsChart = new Chart(regCtx, {
                                type: 'line',
                                data: {
                                    labels: regLabels,
                                    datasets: [{
                                        label: 'Регистраций',
                                        data: regValues,
                                        borderColor: '#2271b1',
                                        backgroundColor: 'rgba(34, 113, 177, 0.1)',
                                        tension: 0.4,
                                        fill: true
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: true,
                                    plugins: {
                                        legend: {
                                            display: false
                                        }
                                    },
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            ticks: {
                                                stepSize: 1
                                            }
                                        }
                                    }
                                }
                            });
                        }
                        
                        // Обновляем таблицу ролей
                        var tbody = $('#stat-roles-tbody');
                        tbody.empty();
                        
                        if (data.roles && Object.keys(data.roles).length > 0) {
                            var total = data.total_users || 1;
                            Object.keys(data.roles).sort().forEach(function(role) {
                                var count = data.roles[role];
                                var percent = ((count / total) * 100).toFixed(1);
                                tbody.append(
                                    '<tr>' +
                                    '<td><strong>' + role + '</strong></td>' +
                                    '<td>' + count + '</td>' +
                                    '<td>' + percent + '%</td>' +
                                    '</tr>'
                                );
                            });
                        } else {
                            tbody.append('<tr><td colspan="3" style="text-align:center; padding:20px;">Нет данных</td></tr>');
                        }
                    } else {
                        alert('Ошибка загрузки статистики: ' + (response.data || 'Неизвестная ошибка'));
                    }
                }).fail(function() {
                    alert('Ошибка соединения с сервером');
                });
            }
            
            $('#mtp-refresh-stats').on('click', function() {
                loadStatistics();
            });
            
            // Загружаем статистику при загрузке страницы
            loadStatistics();
            
            // Автообновление каждые 5 минут
            setInterval(loadStatistics, 300000);
        });
        </script>
        <?php
    }

    public function page_avatars() {
        $avatars = get_option('mtp_avatars', []);
        ?>
        <div class="wrap">
            <h1>🖼️ Управление Аватарками</h1>
            <p>Загружайте картинки для выбора пользователями в качестве аватарок (рекомендуется 256x256 px или больше).</p>
            
            <details style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width:600px;">
                <summary style="cursor:pointer; font-weight:600; font-size:1.1em; outline:none;">▶ Добавить новую аватарку</summary>
                
                <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
                    <div style="margin-top:15px;">
                        <button class="button" id="upload-avatar-btn">📷 Выбрать картинку</button>
                    </div>
                    <input type="hidden" id="new-avatar-url">
                    <div id="preview-avatar" style="margin-top:10px; min-height:100px;"></div>
                    
                    <button class="button button-primary" onclick="mtpSaveAvatar()" style="margin-top:15px;">Сохранить Аватарку</button>
                </div>
            </details>

            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:20px; margin-top:30px;">
                <?php if(empty($avatars)): ?>
                    <div style="grid-column:1/-1; text-align:center; padding:40px; color:#666;">Аватарки не добавлены. Добавьте первую!</div>
                <?php else: ?>
                    <?php foreach($avatars as $id => $url): ?>
                        <div style="position:relative; background:#fff; border:2px solid #ddd; border-radius:8px; padding:10px; text-align:center;">
                            <img src="<?php echo esc_url($url); ?>" style="width:100%; height:auto; border-radius:6px; display:block; margin-bottom:10px;">
                            <button class="button button-link-delete" onclick="mtpDelAvatar('<?php echo esc_js($id); ?>')" style="width:100%;">Удалить</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($){
                var frame;
                $('#upload-avatar-btn').click(function(e) {
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Выберите аватарку', button: { text: 'Использовать' }, multiple: false });
                    frame.on('select', function() {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#new-avatar-url').val(attachment.url);
                        $('#preview-avatar').html('<img src="'+attachment.url+'" style="width:150px; height:150px; object-fit:cover; border:2px solid #ccc; border-radius:8px; padding:5px;">');
                    });
                    frame.open();
                });
            });
            function mtpSaveAvatar(){
                var u = jQuery('#new-avatar-url').val();
                if(!u) return alert('Выберите картинку');
                jQuery.post(ajaxurl, {action:'mtp_save_avatar', url:u}, function(r){ 
                    if(r.success) location.reload(); 
                    else alert('Ошибка сохранения');
                });
            }
            function mtpDelAvatar(id){
                if(confirm('Удалить эту аватарку?')) 
                    jQuery.post(ajaxurl, {action:'mtp_delete_avatar', id:id}, function(r){ 
                        if(r.success) location.reload(); 
                    });
            }
        </script>
        <?php
    }

    public function ajax_save_avatar() {
        if(!current_user_can('manage_options')) wp_send_json_error();
        $url = esc_url_raw($_POST['url']);
        $avatars = get_option('mtp_avatars', []);
        $id = uniqid('av_');
        $avatars[$id] = $url;
        update_option('mtp_avatars', $avatars);
        wp_send_json_success(['id' => $id]);
    }

    public function ajax_delete_avatar() {
        if(!current_user_can('manage_options')) wp_send_json_error();
        $id = sanitize_text_field($_POST['id']);
        $avatars = get_option('mtp_avatars', []);
        if(isset($avatars[$id])) unset($avatars[$id]);
        update_option('mtp_avatars', $avatars);
        wp_send_json_success();
    }

    public function ajax_select_avatar() {
        if(!is_user_logged_in()) wp_send_json_error('Need login');
        $avatar_id = sanitize_text_field($_POST['avatar_id']);
        $avatars = get_option('mtp_avatars', []);
        if(isset($avatars[$avatar_id])) {
            $uid = get_current_user_id();
            update_user_meta($uid, 'mtp_selected_avatar', $avatars[$avatar_id]);
            // ОПТИМИЗАЦИЯ: Очищаем кеш фото пользователя при обновлении
            wp_cache_delete('mtp_user_photo_' . $uid, 'mtp_user_photos');
            wp_send_json_success(['url' => $avatars[$avatar_id]]);
        }
        wp_send_json_error('Avatar not found');
    }
    
    /**
     * Страница управления кодами доступа
     */
    public function page_access_codes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtp_access_codes';
        $usage_table = $wpdb->prefix . 'mtp_access_code_usage';
        
        // Получаем все коды
        $codes = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        
        // Получаем статистику для каждого кода
        $stats = array();
        foreach ($codes as $code) {
            $usage_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $usage_table WHERE code_id = %d",
                $code->id
            ));
            $recent_usage = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $usage_table WHERE code_id = %d AND accessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $code->id
            ));
            $stats[$code->id] = array(
                'total' => $usage_count,
                'recent' => $recent_usage
            );
        }
        
        // Получаем список доступных ролей
        $available_roles = array('vip', 'subscriber');
        $rules_raw = get_option('mta_rules_map', '');
        if (!empty($rules_raw)) {
            foreach (explode("\n", $rules_raw) as $l) {
                $p = explode('|', $l);
                if (count($p) == 2) {
                    $role = trim($p[1]);
                    if (!in_array($role, $available_roles)) {
                        $available_roles[] = $role;
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>🔑 Управление кодами доступа</h1>
            <p>Создавайте коды доступа для пользователей, которые не могут войти через Telegram. Доступ по коду действует 7 дней.</p>
            
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width:600px;">
                <h2 style="margin-top:0;">Создать новый код</h2>
                <form id="mtp-create-code-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="new-code">Код доступа</label></th>
                            <td>
                                <input type="text" id="new-code" name="code" required 
                                       style="width:100%; padding:8px; font-family:monospace; font-size:14px;"
                                       placeholder="Введите код или оставьте пустым для автогенерации">
                                <p class="description">Оставьте пустым для автоматической генерации кода</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="new-role">Роль</label></th>
                            <td>
                                <select id="new-role" name="role" required style="width:100%; padding:8px;">
                                    <?php foreach ($available_roles as $role): ?>
                                        <option value="<?php echo esc_attr($role); ?>" <?php selected($role, 'vip'); ?>>
                                            <?php echo esc_html(ucfirst($role)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Создать код</button>
                    </p>
                </form>
            </div>
            
            <h2>Существующие коды</h2>
            <?php if (empty($codes)): ?>
                <p>Коды доступа еще не созданы.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:200px;">Код</th>
                            <th style="width:100px;">Роль</th>
                            <th style="width:150px;">Дата создания</th>
                            <th style="width:100px;">Всего использований</th>
                            <th style="width:150px;">За последние 7 дней</th>
                            <th style="width:100px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($codes as $code): ?>
                            <tr data-code-id="<?php echo $code->id; ?>">
                                <td><code style="font-size:14px; background:#f0f0f1; padding:4px 8px; border-radius:4px;"><?php echo esc_html($code->code); ?></code></td>
                                <td><?php echo esc_html(ucfirst($code->role)); ?></td>
                                <td><?php echo esc_html(date('d.m.Y H:i', strtotime($code->created_at))); ?></td>
                                <td><strong><?php echo isset($stats[$code->id]) ? $stats[$code->id]['total'] : 0; ?></strong></td>
                                <td><strong><?php echo isset($stats[$code->id]) ? $stats[$code->id]['recent'] : 0; ?></strong></td>
                                <td>
                                    <button class="button button-link-delete mtp-delete-code" data-code-id="<?php echo $code->id; ?>">Удалить</button>
                                    <button class="button mtp-view-stats" data-code-id="<?php echo $code->id; ?>">Статистика</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <!-- Модальное окно для статистики -->
            <div id="mtp-code-stats-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:100000; align-items:center; justify-content:center;">
                <div style="background:#fff; padding:30px; border-radius:8px; max-width:800px; width:90%; max-height:80vh; overflow-y:auto; position:relative;">
                    <button id="mtp-close-stats-modal" style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:24px; cursor:pointer; color:#666;">×</button>
                    <h2>Статистика использования кода</h2>
                    <div id="mtp-stats-content"></div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Создание нового кода
            $('#mtp-create-code-form').on('submit', function(e) {
                e.preventDefault();
                
                var code = $('#new-code').val().trim();
                var role = $('#new-role').val();
                
                // Если код не указан, генерируем случайный
                if (!code) {
                    code = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
                }
                
                $.post(ajaxurl, {
                    action: 'mtp_create_access_code',
                    code: code,
                    role: role,
                    nonce: '<?php echo wp_create_nonce("mtp_create_code"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data && response.data.message ? response.data.message : 'Ошибка при создании кода');
                    }
                });
            });
            
            // Удаление кода
            $('.mtp-delete-code').on('click', function() {
                var codeId = $(this).data('code-id');
                if (!confirm('Вы уверены, что хотите удалить этот код? Статистика использования также будет удалена.')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'mtp_delete_access_code',
                    code_id: codeId,
                    nonce: '<?php echo wp_create_nonce("mtp_delete_code"); ?>'
                }, function(response) {
                    if (response.success) {
                        $('tr[data-code-id="' + codeId + '"]').fadeOut(function() {
                            $(this).remove();
                            if ($('tbody tr').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        alert(response.data && response.data.message ? response.data.message : 'Ошибка при удалении кода');
                    }
                });
            });
            
            // Просмотр статистики
            $('.mtp-view-stats').on('click', function() {
                var codeId = $(this).data('code-id');
                $('#mtp-code-stats-modal').show();
                $('#mtp-stats-content').html('<p>Загрузка...</p>');
                
                $.post(ajaxurl, {
                    action: 'mtp_get_code_statistics',
                    code_id: codeId,
                    nonce: '<?php echo wp_create_nonce("mtp_get_stats"); ?>'
                }, function(response) {
                    if (response.success && response.data) {
                        var html = '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr><th>IP адрес</th><th>User Agent</th><th>Дата использования</th><th>Истекает</th></tr></thead>';
                        html += '<tbody>';
                        
                        if (response.data.length > 0) {
                            response.data.forEach(function(item) {
                                html += '<tr>';
                                html += '<td>' + item.ip_address + '</td>';
                                html += '<td><small>' + (item.user_agent || '-') + '</small></td>';
                                html += '<td>' + item.accessed_at + '</td>';
                                html += '<td>' + item.expires_at + '</td>';
                                html += '</tr>';
                            });
                        } else {
                            html += '<tr><td colspan="4">Нет использований</td></tr>';
                        }
                        
                        html += '</tbody></table>';
                        $('#mtp-stats-content').html(html);
                    } else {
                        $('#mtp-stats-content').html('<p>Ошибка загрузки статистики</p>');
                    }
                });
            });
            
            // Закрытие модального окна
            $('#mtp-close-stats-modal, #mtp-code-stats-modal').on('click', function(e) {
                if (e.target === this || e.target.id === 'mtp-close-stats-modal') {
                    $('#mtp-code-stats-modal').hide();
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Создание кода доступа
     */
    public function ajax_create_access_code() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mtp_create_code')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности'));
            return;
        }
        
        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
        $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : 'vip';
        
        if (empty($code)) {
            // Генерируем случайный код
            $code = wp_generate_password(12, false);
        }
        
        // Проверяем уникальность кода
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtp_access_codes';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE code = %s",
            $code
        ));
        
        if ($existing) {
            wp_send_json_error(array('message' => 'Код уже существует'));
            return;
        }
        
        // Создаем код
        $wpdb->insert(
            $table_name,
            array(
                'code' => $code,
                'role' => $role,
                'created_by' => get_current_user_id()
            ),
            array('%s', '%s', '%d')
        );
        
        if ($wpdb->last_error) {
            wp_send_json_error(array('message' => 'Ошибка при создании кода: ' . $wpdb->last_error));
            return;
        }
        
        wp_send_json_success(array('message' => 'Код успешно создан'));
    }
    
    /**
     * AJAX: Удаление кода доступа
     */
    public function ajax_delete_access_code() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mtp_delete_code')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности'));
            return;
        }
        
        $code_id = isset($_POST['code_id']) ? intval($_POST['code_id']) : 0;
        
        if (!$code_id) {
            wp_send_json_error(array('message' => 'Некорректный ID кода'));
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtp_access_codes';
        $usage_table = $wpdb->prefix . 'mtp_access_code_usage';
        
        // Удаляем статистику использования
        $wpdb->delete($usage_table, array('code_id' => $code_id), array('%d'));
        
        // Удаляем сам код
        $wpdb->delete($table_name, array('id' => $code_id), array('%d'));
        
        wp_send_json_success(array('message' => 'Код удален'));
    }
    
    /**
     * AJAX: Получение статистики по коду
     */
    public function ajax_get_code_statistics() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mtp_get_stats')) {
            wp_send_json_error(array('message' => 'Ошибка безопасности'));
            return;
        }
        
        $code_id = isset($_POST['code_id']) ? intval($_POST['code_id']) : 0;
        
        if (!$code_id) {
            wp_send_json_error(array('message' => 'Некорректный ID кода'));
            return;
        }
        
        global $wpdb;
        $usage_table = $wpdb->prefix . 'mtp_access_code_usage';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, user_agent, accessed_at, expires_at 
             FROM $usage_table 
             WHERE code_id = %d 
             ORDER BY accessed_at DESC 
             LIMIT 100",
            $code_id
        ));
        
        $data = array();
        foreach ($results as $row) {
            $data[] = array(
                'ip_address' => esc_html($row->ip_address),
                'user_agent' => esc_html(substr($row->user_agent, 0, 100)),
                'accessed_at' => esc_html(date('d.m.Y H:i', strtotime($row->accessed_at))),
                'expires_at' => esc_html(date('d.m.Y H:i', strtotime($row->expires_at)))
            );
        }
        
        wp_send_json_success($data);
    }
    public function handle_manual_actions() { 
        // Старый способ проверки (оставляем для обратной совместимости, но не используем)
        if (isset($_POST['mta_manual_trigger']) && current_user_can('manage_options')) { 
            // Перенаправляем на новую страницу с AJAX проверкой
            wp_redirect(admin_url('options-general.php?page=mtp-auth'));
            exit;
        } 
    }
    
    public function ajax_check_subscription_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mta_check_batch')) {
            wp_send_json_error('Ошибка безопасности');
        }
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10; // Проверяем по 10 пользователей за раз
        
        $token = get_option('mta_bot_token');
        $rules_raw = get_option('mta_rules_map', '');
        
        if (!$token || empty($rules_raw)) {
            wp_send_json_error('Ошибка: Нет токена или правил');
        }
        
        $rules = [];
        foreach (explode("\n", $rules_raw) as $l) {
            $p = explode('|', $l);
            if (count($p) == 2) {
                $rules[trim($p[0])] = trim($p[1]);
            }
        }
        
        $managed_roles = array_unique(array_values($rules));
        $users = get_users([
            'search' => 'tg_*',
            'search_columns' => ['user_login'],
            'number' => $batch_size,
            'offset' => $offset
        ]);
        
        // Получаем общее количество пользователей только один раз
        static $total_users = null;
        if ($total_users === null) {
            $total_users_query = get_users([
                'search' => 'tg_*',
                'search_columns' => ['user_login'],
                'number' => -1,
                'fields' => 'ID'
            ]);
            $total_users = count($total_users_query);
        }
        
        $logs = [];
        $processed = 0;
        
        foreach ($users as $u) {
            $tg_id = str_replace('tg_', '', $u->user_login);
            if (!is_numeric($tg_id)) continue;
            
            $user_roles = (array) $u->roles;
            $roles_should_have = [];
            
            foreach ($rules as $chat_id => $role_slug) {
                $status = $this->get_telegram_chat_status($token, $chat_id, $tg_id);
                $logs[] = "Пользователь $tg_id @ $chat_id = $status";
                
                if ($status && in_array($status, ['creator', 'administrator', 'member'])) {
                    $roles_should_have[] = $role_slug;
                }
                
                // Уменьшаем задержку для ускорения
                usleep(30000); // 30ms вместо 50ms
            }
            
            // Обновляем роли пользователя
            // СОВМЕСТИМОСТЬ С MEMBERS: Проверяем существование роли перед назначением
            foreach ($managed_roles as $role) {
                // Проверяем, что роль существует в системе (важно для совместимости с Members)
                $role_obj = get_role($role);
                if (!$role_obj) {
                    // Роль не существует, пропускаем (может быть удалена через Members)
                    continue;
                }
                
                if (in_array($role, $roles_should_have)) {
                    if (!in_array($role, $user_roles)) {
                        $u->add_role($role);
                    }
                } else {
                    if (in_array($role, $user_roles)) {
                        $u->remove_role($role);
                    }
                }
            }
            
            $processed++;
        }
        
        wp_send_json_success([
            'processed' => $processed,
            'total' => $total_users,
            'has_more' => ($offset + $processed) < $total_users,
            'logs' => $logs
        ]);
    }
    
    public function ajax_check_single_user() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mta_check_single')) {
            wp_send_json_error('Ошибка безопасности');
        }
        
        $user_id = isset($_POST['user_id']) ? sanitize_text_field($_POST['user_id']) : '';
        if (empty($user_id) || !is_numeric($user_id)) {
            wp_send_json_error('Некорректный Telegram ID');
        }
        
        $token = get_option('mta_bot_token');
        $rules_raw = get_option('mta_rules_map', '');
        
        if (!$token || empty($rules_raw)) {
            wp_send_json_error('Ошибка: Нет токена или правил');
        }
        
        $rules = [];
        foreach (explode("\n", $rules_raw) as $l) {
            $p = explode('|', $l);
            if (count($p) == 2) {
                $rules[trim($p[0])] = trim($p[1]);
            }
        }
        
        // Ищем пользователя в системе
        $user = get_user_by('login', 'tg_' . $user_id);
        $user_found = false;
        $results = [];
        
        foreach ($rules as $chat_id => $role_slug) {
            $status = $this->get_telegram_chat_status($token, $chat_id, $user_id);
            $results[] = [
                'chat_id' => $chat_id,
                'status' => $status ? $status : 'не найден',
                'role' => ($status && in_array($status, ['creator', 'administrator', 'member'])) ? $role_slug : null
            ];
        }
        
        // Если пользователь найден, обновляем его роли
        if ($user) {
            $user_found = true;
            $user_roles = (array) $user->roles;
            $managed_roles = array_unique(array_values($rules));
            $roles_should_have = [];
            
            foreach ($results as $result) {
                if ($result['role']) {
                    $roles_should_have[] = $result['role'];
                }
            }
            
            // СОВМЕСТИМОСТЬ С MEMBERS: Проверяем существование роли перед назначением
            foreach ($managed_roles as $role) {
                // Проверяем, что роль существует в системе (важно для совместимости с Members)
                $role_obj = get_role($role);
                if (!$role_obj) {
                    // Роль не существует, пропускаем (может быть удалена через Members)
                    continue;
                }
                
                if (in_array($role, $roles_should_have)) {
                    if (!in_array($role, $user_roles)) {
                        $user->add_role($role);
                    }
                } else {
                    if (in_array($role, $user_roles)) {
                        $user->remove_role($role);
                    }
                }
            }
        }
        
        wp_send_json_success([
            'results' => $results,
            'user_found' => $user_found
        ]);
    }
    
    public function ajax_get_statistics() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'mta_get_stats')) {
            wp_send_json_error('Ошибка безопасности');
        }
        
        // Получаем всех Telegram пользователей
        $all_users = get_users([
            'search' => 'tg_*',
            'search_columns' => ['user_login'],
            'number' => -1,
            'fields' => ['ID', 'user_registered', 'user_login']
        ]);
        
        $total_users = count($all_users);
        
        // ОПТИМИЗАЦИЯ: Загружаем все роли пользователей одним запросом
        $user_roles_map = [];
        if (!empty($all_users)) {
            $user_ids = wp_list_pluck($all_users, 'ID');
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            
            // Получаем все роли пользователей одним запросом
            $roles_query = $wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta} 
                WHERE user_id IN ($placeholders) 
                AND meta_key = '{$wpdb->prefix}capabilities'",
                ...$user_ids
            );
            $roles_results = $wpdb->get_results($roles_query);
            
            foreach ($roles_results as $row) {
                $caps = maybe_unserialize($row->meta_value);
                if (is_array($caps)) {
                    $user_roles_map[$row->user_id] = array_keys($caps);
                } else {
                    $user_roles_map[$row->user_id] = [];
                }
            }
        }
        
        // Активные пользователи (за последние 30 дней)
        $active_threshold = date('Y-m-d H:i:s', strtotime('-30 days'));
        $active_users = 0;
        $new_today = 0;
        $new_week = 0;
        
        $today_start = date('Y-m-d 00:00:00');
        $week_start = date('Y-m-d 00:00:00', strtotime('-7 days'));
        
        // Статистика по ролям
        $roles_stats = [];
        
        // Данные для графика регистраций (последние 30 дней)
        $registrations_data = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $registrations_data[] = [
                'date' => date('d.m', strtotime("-$i days")),
                'count' => 0
            ];
        }
        
        foreach ($all_users as $user) {
            // ОПТИМИЗАЦИЯ: Используем предзагруженные роли вместо get_userdata()
            $user_roles = isset($user_roles_map[$user->ID]) ? $user_roles_map[$user->ID] : [];
            $registered = $user->user_registered;
            
            // Активные пользователи
            if ($registered >= $active_threshold) {
                $active_users++;
            }
            
            // Новые сегодня
            if ($registered >= $today_start) {
                $new_today++;
            }
            
            // Новые за неделю
            if ($registered >= $week_start) {
                $new_week++;
            }
            
            // Статистика по ролям (используем предзагруженные роли)
            foreach ($user_roles as $role) {
                if (!isset($roles_stats[$role])) {
                    $roles_stats[$role] = 0;
                }
                $roles_stats[$role]++;
            }
            
            // Данные для графика регистраций
            $reg_date = date('d.m', strtotime($registered));
            foreach ($registrations_data as &$day_data) {
                if ($reg_date === $day_data['date']) {
                    $day_data['count']++;
                    break;
                }
            }
            unset($day_data);
        }
        
        // Сортируем роли по количеству (по убыванию)
        arsort($roles_stats);
        
        wp_send_json_success([
            'total_users' => $total_users,
            'active_users' => $active_users,
            'new_today' => $new_today,
            'new_week' => $new_week,
            'roles' => $roles_stats,
            'registrations' => $registrations_data
        ]);
    }
    /**
     * AJAX endpoint для проверки статуса авторизации
     */
    /**
     * AJAX обработчик для проверки статуса авторизации
     * Используется для динамического обновления виджета входа после успешной авторизации
     * ВАЖНО: Восстанавливает сессию, если куки есть, но пользователь не определен
     */
    public function ajax_check_auth_status() {
        // Проверяем nonce для безопасности (опционально для публичных запросов)
        if (isset($_POST['_ajax_nonce']) && !wp_verify_nonce($_POST['_ajax_nonce'], 'mtp_check_auth')) {
            // Если nonce неверный, продолжаем проверку (для совместимости)
        }
        
        $is_logged_in = is_user_logged_in();
        
        // Если пользователь не авторизован, но есть куки - пытаемся восстановить сессию
        if (!$is_logged_in) {
            $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
            $logged_in_cookie_name = LOGGED_IN_COOKIE;
            
            $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
            $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
            
            if ($has_auth_cookie || $has_logged_in_cookie) {
                $cookie_user_id = false;
                
                // Пытаемся валидировать logged_in cookie
                if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                    $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
                }
                
                // Если не получилось, пытаемся через auth cookie
                if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                    $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
                }
                
                if ($cookie_user_id) {
                    // Восстанавливаем сессию
                    wp_set_current_user($cookie_user_id);
                    $user = get_userdata($cookie_user_id);
                    
                    if ($user && strpos($user->user_login, 'tg_') === 0) {
                        // Устанавливаем куки заново
                        $secure = is_ssl();
                        wp_set_auth_cookie($cookie_user_id, true, $secure);
                        $is_logged_in = true;
                        $this->log("Session restored in ajax_check_auth_status for user {$cookie_user_id}");
                    }
                }
            }
        }
        
        $response = [
            'logged_in' => $is_logged_in
        ];
        
        if ($is_logged_in) {
            $user = wp_get_current_user();
            $photo_url = $this->get_user_photo_url_raw($user->ID);
            if (!$photo_url) {
                $photo_url = get_avatar_url($user->ID);
            }
            $response['user'] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'photo' => $photo_url,
                'profile_url' => home_url('/lichnyj-kabinet/')
            ];
        }
        
        wp_send_json_success($response);
    }
    
    public function schedule_cron() { if (!wp_next_scheduled('mta_daily_sub_check')) wp_schedule_event(time(), 'daily', 'mta_daily_sub_check'); }
    /**
     * Проверка подписок всех пользователей (синхронная, используется для обратной совместимости)
     * СОВМЕСТИМОСТЬ С MEMBERS: Добавлена проверка существования ролей
     */
    public function check_all_users_subscriptions($is_manual = false) { 
        $token = get_option('mta_bot_token'); 
        $rules_raw = get_option('mta_rules_map', ''); 
        $log = []; 
        if (!$token || empty($rules_raw)) return ['Ошибка: Нет токена']; 
        $rules = []; 
        foreach (explode("\n", $rules_raw) as $l) { 
            $p = explode('|', $l); 
            if(count($p)==2) $rules[trim($p[0])] = trim($p[1]); 
        } 
        $managed_roles = array_unique(array_values($rules)); 
        $users = get_users(['search' => 'tg_*', 'search_columns' => ['user_login'], 'number' => -1]); 
        foreach ($users as $u) { 
            $tg_id = str_replace('tg_', '', $u->user_login); 
            if (!is_numeric($tg_id)) continue; 
            $user_roles = (array) $u->roles; 
            $roles_should_have = []; 
            foreach ($rules as $chat_id => $role_slug) { 
                $status = $this->get_telegram_chat_status($token, $chat_id, $tg_id); 
                if ($is_manual) $log[] = "$tg_id @ $chat_id = $status"; 
                if ($status && in_array($status, ['creator', 'administrator', 'member'])) $roles_should_have[] = $role_slug; 
                usleep(50000); 
            } 
            // СОВМЕСТИМОСТЬ С MEMBERS: Проверяем существование роли перед назначением
            foreach ($managed_roles as $role) {
                // Проверяем, что роль существует в системе (важно для совместимости с Members)
                $role_obj = get_role($role);
                if (!$role_obj) {
                    // Роль не существует, пропускаем (может быть удалена через Members)
                    continue;
                }
                
                if (in_array($role, $roles_should_have)) { 
                    if (!in_array($role, $user_roles)) $u->add_role($role); 
                } else { 
                    if (in_array($role, $user_roles)) $u->remove_role($role); 
                } 
            }
        } 
        return $log; 
    }
    /**
     * Асинхронная проверка подписок пользователя (вызывается через WP Cron)
     */
    public function async_check_single_user_subscriptions($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log("User {$user_id} not found for subscription check", 'error');
            return;
        }
        $this->log("Starting async subscription check for user {$user_id}");
        $this->check_single_user_subscriptions($user);
        $this->log("Completed async subscription check for user {$user_id}");
    }
    
    /**
     * Проверка подписок пользователя (синхронная, используется для обратной совместимости)
     */
    private function check_single_user_subscriptions($u) { 
        $token = get_option('mta_bot_token'); 
        $rules_raw = get_option('mta_rules_map', ''); 
        if (!$token || empty($rules_raw)) return; 
        $tg_id = str_replace('tg_', '', $u->user_login); 
        if (!is_numeric($tg_id)) return; 
        $rules = []; 
        foreach (explode("\n", $rules_raw) as $l) { 
            $p = explode('|', $l); 
            if(count($p)==2) $rules[trim($p[0])] = trim($p[1]); 
        } 
        
        $user_roles = (array) $u->roles;
        $managed_roles = array_unique(array_values($rules));
        $roles_should_have = [];
        
        foreach ($rules as $chat_id => $role_slug) { 
            // Используем кеширование для ускорения (кеш на 5 минут)
            $cache_key = 'mta_sub_' . md5($token . $chat_id . $tg_id);
            $cached_status = get_transient($cache_key);
            
            if ($cached_status !== false) {
                $status = $cached_status;
            } else {
                $status = $this->get_telegram_chat_status($token, $chat_id, $tg_id);
                // Кешируем результат на 5 минут
                set_transient($cache_key, $status, 300);
            }
            
            if ($status && in_array($status, ['creator', 'administrator', 'member'])) {
                $roles_should_have[] = $role_slug;
            }
        }
        
        // Обновляем роли пользователя
        // СОВМЕСТИМОСТЬ С MEMBERS: Проверяем существование роли перед назначением
        foreach ($managed_roles as $role) {
            // Проверяем, что роль существует в системе (важно для совместимости с Members)
            $role_obj = get_role($role);
            if (!$role_obj) {
                // Роль не существует, пропускаем (может быть удалена через Members)
                continue;
            }
            
            if (in_array($role, $roles_should_have)) {
                if (!in_array($role, $user_roles)) {
                    $u->add_role($role);
                }
            } else {
                if (in_array($role, $user_roles)) {
                    $u->remove_role($role);
                }
            }
        }
    }
    /**
     * Получает статус пользователя в Telegram чате/канале с обработкой ошибок и retry-логикой
     */
    private function get_telegram_chat_status($token, $chat_id, $user_id, $retry = 0) { 
        $url = "https://api.telegram.org/bot{$token}/getChatMember?chat_id={$chat_id}&user_id={$user_id}"; 
        $res = wp_remote_get($url, [
            'timeout' => 8,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]); 
        
        // Обработка ошибок сети
        if (is_wp_error($res)) {
            $error_code = $res->get_error_code();
            $error_message = $res->get_error_message();
            $this->log("Telegram API error for chat {$chat_id}, user {$user_id}: {$error_code} - {$error_message}", 'error');
            
            // Если это временная ошибка и есть попытки, повторяем запрос
            if ($retry < 2 && in_array($error_code, ['http_request_failed', 'timeout'])) {
                sleep(1); // Небольшая задержка перед повтором
                return $this->get_telegram_chat_status($token, $chat_id, $user_id, $retry + 1);
            }
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        
        // Обработка ошибок API Telegram
        if ($response_code !== 200) {
            // Если это rate limit (429) и есть попытки, ждем и повторяем
            if ($response_code === 429 && $retry < 2) {
                $retry_after = isset($body['parameters']['retry_after']) ? $body['parameters']['retry_after'] : 2;
                sleep($retry_after);
                return $this->get_telegram_chat_status($token, $chat_id, $user_id, $retry + 1);
            }
            return false;
        }
        
        // Проверяем успешность ответа
        if (isset($body['ok']) && $body['ok'] === true && isset($body['result']['status'])) {
            return $body['result']['status'];
        }
        
        return false;
    }
    public function dynamic_menu_item($items) { foreach ($items as $item) { if ($item->url == '#mtp_login') { if (is_user_logged_in()) { $u = wp_get_current_user(); $item->url = home_url('/lichnyj-kabinet/'); $photo = $this->get_user_photo_url_raw($u->ID); if(!$photo) $photo = get_avatar_url($u->ID); $style = "display:inline-block; vertical-align:middle; width:28px; height:28px; border-radius:50%; margin-right:8px; border:2px solid #fff; box-shadow:0 1px 3px rgba(0,0,0,0.2);"; $item->title = "<img src='$photo' style='$style'> " . esc_html($u->display_name); } else { $item->url = home_url('/lichnyj-kabinet/'); $item->title = 'Войти / Кабинет'; } } } return $items; }
    public function replace_comment_author_url($url, $id, $comment) { if ( is_object($comment) && isset($comment->user_id) && $comment->user_id > 0 ) return home_url('/lichnyj-kabinet/?user_id=' . $comment->user_id); return $url; }
    public function handle_profile_actions() { 
        if (!is_user_logged_in()) return; 
        $uid = get_current_user_id(); 
        if (isset($_POST['mtp_n_data']) && wp_verify_nonce($_POST['mtp_n_data'], 'mtp_data_act')) { 
            if (!empty($_FILES['mtp_file']['name'])) { 
                require_once(ABSPATH . 'wp-admin/includes/image.php'); 
                require_once(ABSPATH . 'wp-admin/includes/file.php'); 
                require_once(ABSPATH . 'wp-admin/includes/media.php'); 
                $aid = media_handle_upload('mtp_file', 0); 
                if (!is_wp_error($aid)) {
                    update_user_meta($uid, 'mtp_custom_avatar', wp_get_attachment_url($aid));
                    // ОПТИМИЗАЦИЯ: Очищаем кеш фото пользователя при обновлении
                    wp_cache_delete('mtp_user_photo_' . $uid, 'mtp_user_photos');
                }
            } 
            do_action('mtp_profile_save_data', $uid); 
            wp_redirect(remove_query_arg(['mtp_n_data'], wp_get_referer())); 
            exit; 
        } 
    }
    /**
     * Получает URL фото пользователя с кешированием для оптимизации производительности
     */
    public function get_user_photo_url_raw($uid) {
        if (!$uid) return false;
        
        // ОПТИМИЗАЦИЯ: Используем кеш для уменьшения запросов к БД
        $cache_key = 'mtp_user_photo_' . $uid;
        $cached = wp_cache_get($cache_key, 'mtp_user_photos');
        
        if ($cached !== false) {
            return $cached ?: false;
        }
        
        // Загружаем все мета-данные одним запросом
        $meta_keys = ['mtp_selected_avatar', 'mtp_custom_avatar', 'tg_photo_url'];
        $meta_values = [];
        foreach ($meta_keys as $key) {
            $value = get_user_meta($uid, $key, true);
            if ($value) {
                $meta_values[$key] = $value;
            }
        }
        
        $result = false;
        if (!empty($meta_values['mtp_selected_avatar'])) {
            $result = $meta_values['mtp_selected_avatar'];
        } elseif (!empty($meta_values['mtp_custom_avatar'])) {
            $result = $meta_values['mtp_custom_avatar'];
        } elseif (!empty($meta_values['tg_photo_url'])) {
            $result = $meta_values['tg_photo_url'];
        }
        
        // Кешируем результат на 1 час
        wp_cache_set($cache_key, $result ?: '', 'mtp_user_photos', 3600);
        
        return $result ?: false;
    }
    public function replace_wp_avatar($av, $id, $s=96, $d='', $alt='') { $uid=0; if(is_numeric($id)) $uid=(int)$id; elseif(is_object($id) && !empty($id->user_id)) $uid=(int)$id->user_id; if($uid) { $url=$this->get_user_photo_url_raw($uid); if($url) return "<img alt='{$alt}' src='{$url}' class='avatar avatar-{$s} photo' height='{$s}' width='{$s}' style='border-radius:50%; object-fit:cover;' />"; } return $av; }
    public function replace_wp_avatar_url($url, $id, $args) { $uid=0; if(is_numeric($id)) $uid=(int)$id; elseif(is_object($id) && !empty($id->user_id)) $uid=(int)$id->user_id; if($uid) { $c=$this->get_user_photo_url_raw($uid); if($c) return $c; } return $url; }
    public function replace_author_link_global($link, $author_id, $author_nicename) { return home_url('/lichnyj-kabinet/?user_id=' . $author_id); }
    public function register_settings() { register_setting('mtp_grp', 'mta_bot_token'); register_setting('mtp_grp', 'mta_rules_map'); add_settings_section('main','Основные',null,'mtp_grp'); add_settings_field('bt','Token',function(){echo '<input name="mta_bot_token" value="'.esc_attr(get_option('mta_bot_token')).'" style="width:300px;">';},'mtp_grp','main'); add_settings_field('rl','Rules',function(){echo '<textarea name="mta_rules_map" style="width:400px; height:100px;">'.esc_textarea(get_option('mta_rules_map')).'</textarea>';},'mtp_grp','main'); }
    private function get_bot_username($token) { $c = get_transient('mta_bun_'.md5($token)); if($c) return $c; $r = wp_remote_get("https://api.telegram.org/bot{$token}/getMe"); if(!is_wp_error($r)) { $b = json_decode(wp_remote_retrieve_body($r),true); if(isset($b['result']['username'])) { set_transient('mta_bun_'.md5($token),$b['result']['username'],86400); return $b['result']['username']; }} return false; }
    
    /**
     * Увеличивает время жизни куки авторизации для Telegram пользователей
     */
    public function extend_auth_cookie_expiration($expiration, $user_id, $remember) {
        // Для Telegram пользователей устанавливаем долгосрочную сессию (14 дней)
        $user = get_userdata($user_id);
        if ($user && strpos($user->user_login, 'tg_') === 0) {
            if ($remember) {
                return 14 * DAY_IN_SECONDS; // 14 дней
            }
            return 2 * DAY_IN_SECONDS; // 2 дня даже без remember
        }
        return $expiration;
    }
    
    /**
     * Обеспечивает сохранение куки авторизации
     */
    public function ensure_auth_cookie_persistence($auth_cookie, $expire, $expiration, $user_id, $scheme) {
        // Дополнительная проверка для Telegram пользователей
        $user = get_userdata($user_id);
        if ($user && strpos($user->user_login, 'tg_') === 0) {
            // WordPress уже установил куки через wp_set_auth_cookie
            // Здесь мы просто проверяем, что все работает правильно
            // Не устанавливаем куки повторно, чтобы избежать конфликтов
        }
    }
    
    /**
     * Предотвращает кеширование страниц для авторизованных пользователей
     * ВАЖНО: Проверяет куки даже если is_user_logged_in() = false (для W3 Total Cache)
     */
    public function prevent_cache_for_logged_in() {
        // КРИТИЧНО: Не обрабатываем кеш на странице выхода
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        
        // Проверяем наличие куки авторизации
        $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $logged_in_cookie_name = LOGGED_IN_COOKIE;
        
        $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
        $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
        
        $should_prevent_cache = false;
        $user = null;
        
        // Если пользователь авторизован - проверяем
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user && strpos($user->user_login, 'tg_') === 0) {
                $should_prevent_cache = true;
            }
        } 
        // Если куки есть, но пользователь не определен - пытаемся валидировать
        // Это важно для W3 Total Cache - нужно исключить из кеша ДО того, как кеш отдаст страницу
        else if ($has_auth_cookie || $has_logged_in_cookie) {
            $cookie_user_id = false;
            
            // Пытаемся валидировать logged_in cookie
            if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
            }
            
            // Если не получилось, пытаемся через auth cookie
            if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
            }
            
            if ($cookie_user_id) {
                // Восстанавливаем сессию
                wp_set_current_user($cookie_user_id);
                $user = get_userdata($cookie_user_id);
                
                if ($user && strpos($user->user_login, 'tg_') === 0) {
                    $should_prevent_cache = true;
                    // Устанавливаем куки заново
                    $secure = is_ssl();
                    wp_set_auth_cookie($cookie_user_id, true, $secure);
                    $this->log("Session restored in prevent_cache_for_logged_in for user {$cookie_user_id}");
                }
            }
        }
        
        // Если нужно исключить из кеша - устанавливаем заголовки и константу
        if ($should_prevent_cache) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            // Отправляем заголовки без кеша, но не сбрасываем куки
            if (!headers_sent()) {
                header('Cache-Control: no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            }
        }
    }
    
    /**
     * Интеграция с W3 Total Cache - исключает из кеша страницы для Telegram пользователей
     * Проверяет куки даже если is_user_logged_in() = false (для ранней проверки)
     */
    public function w3tc_prevent_cache_for_telegram_users($can_cache) {
        // КРИТИЧНО: Не обрабатываем кеш на странице выхода
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return $can_cache; // Разрешаем кеширование страницы выхода
        }
        
        // Если уже нельзя кешировать - возвращаем как есть
        if (!$can_cache) {
            return $can_cache;
        }
        
        // Проверяем наличие куки авторизации
        $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $logged_in_cookie_name = LOGGED_IN_COOKIE;
        
        $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
        $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
        
        // Если пользователь авторизован - проверяем
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user && strpos($user->user_login, 'tg_') === 0) {
                return false; // Исключаем из кеша
            }
        } 
        // Если куки есть, но пользователь не определен - пытаемся валидировать
        // Это важно для W3 Total Cache - нужно исключить из кеша ДО того, как кеш отдаст страницу
        else if ($has_auth_cookie || $has_logged_in_cookie) {
            $cookie_user_id = false;
            
            // Пытаемся валидировать logged_in cookie
            if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
            }
            
            // Если не получилось, пытаемся через auth cookie
            if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
            }
            
            if ($cookie_user_id) {
                // Восстанавливаем сессию
                wp_set_current_user($cookie_user_id);
                $user = get_userdata($cookie_user_id);
                
                if ($user && strpos($user->user_login, 'tg_') === 0) {
                    // Устанавливаем куки заново
                    $secure = is_ssl();
                    wp_set_auth_cookie($cookie_user_id, true, $secure);
                    $this->log("Session restored in w3tc_prevent_cache_for_telegram_users for user {$cookie_user_id}");
                    return false; // Исключаем из кеша
                }
            }
        }
        
        // Если ничего не найдено - разрешаем кеширование
        return $can_cache;
    }
    
    /**
     * Ранняя проверка сессии (до wp_set_current_user и W3 Total Cache)
     * КРИТИЧНО: Выполняется с приоритетом 1 на plugins_loaded, чтобы восстановить сессию ДО кеширования
     */
    public function early_session_check() {
        // КРИТИЧНО: Не восстанавливаем сессию на странице выхода
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        
        // Защита от повторных вызовов в рамках одного запроса
        static $session_checked = false;
        if ($session_checked) {
            return;
        }
        $session_checked = true;
        
        // Проверяем только если заголовки еще не отправлены
        if (headers_sent()) {
            return;
        }
        
        // КРИТИЧНО: На странице профиля не восстанавливаем сессию, если пользователь уже авторизован
        // Проверяем, что home_url() доступна (может быть недоступна на ранних хуках)
        if (!isset($_SERVER['HTTP_HOST']) || !isset($_SERVER['REQUEST_URI'])) {
            return; // Недостаточно данных для проверки
        }
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        if (function_exists('home_url') && function_exists('is_user_logged_in')) {
            $profile_url = home_url('/lichnyj-kabinet/');
            $is_profile_page = (strpos($current_url, $profile_url) !== false);
            
            if ($is_profile_page && is_user_logged_in()) {
                return;
            }
        }
        
        // Проверяем наличие куки авторизации
        $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $logged_in_cookie_name = LOGGED_IN_COOKIE;
        
        $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
        $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
        
        // Определяем, главная ли это страница (для более агрессивной проверки)
        // Проверяем, что home_url() доступна (может быть недоступна на ранних хуках)
        $is_home_page = false;
        if (function_exists('home_url')) {
            $home_url = home_url('/');
            $is_home_page = (str_replace(['http://', 'https://'], '', $current_url) === str_replace(['http://', 'https://'], '', $home_url) ||
                            str_replace(['http://', 'https://'], '', rtrim($current_url, '/')) === str_replace(['http://', 'https://'], '', rtrim($home_url, '/')));
        }
        
        // Если куки есть, но пользователь еще не определен - принудительно валидируем и восстанавливаем
        // Это критично для W3 Total Cache - сессия должна быть восстановлена ДО проверки кеша
        if ($has_auth_cookie || $has_logged_in_cookie) {
            // Проверяем, авторизован ли пользователь (может быть еще не определен WordPress)
            $is_logged_in = is_user_logged_in();
            
            if (!$is_logged_in) {
                $cookie_user_id = false;
                
                // Пытаемся валидировать logged_in cookie
                if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                    $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
                }
                
                // Если не получилось, пытаемся через auth cookie
                if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                    $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
                }
                
                if ($cookie_user_id) {
                    // Устанавливаем пользователя раньше, чем WordPress это сделает
                    // Это важно для W3 Total Cache - он проверит is_user_logged_in() и не будет кешировать
                    wp_set_current_user($cookie_user_id);
                    
                    // Проверяем, что это Telegram пользователь
                    $user = get_userdata($cookie_user_id);
                    if ($user && strpos($user->user_login, 'tg_') === 0) {
                        // Устанавливаем куки заново для надежности (один раз)
                        $secure = is_ssl();
                        wp_set_auth_cookie($cookie_user_id, true, $secure);
                        
                        if ($is_home_page) {
                            $this->log("Early session restored for user {$cookie_user_id} on homepage (before cache check)");
                        } else {
                            $this->log("Early session restored for user {$cookie_user_id} (before cache check)");
                        }
                    }
                }
            } else {
                // Пользователь уже авторизован - убеждаемся, что куки установлены правильно
                $user = wp_get_current_user();
                if ($user && strpos($user->user_login, 'tg_') === 0) {
                    // Если отсутствует хотя бы одна кука - восстанавливаем
                    if (!$has_auth_cookie || !$has_logged_in_cookie) {
                        $secure = is_ssl();
                        wp_set_auth_cookie($user->ID, true, $secure);
                        $this->log("Auth cookies restored for user {$user->ID}");
                    }
                    // На главной странице обновляем куки только если они отсутствуют
                    elseif ($is_home_page && (!$has_auth_cookie || !$has_logged_in_cookie)) {
                        $secure = is_ssl();
                        wp_set_auth_cookie($user->ID, true, $secure);
                    }
                }
            }
        }
    }
    
    /**
     * Проверяет и восстанавливает сессию пользователя при необходимости
     */
    public function verify_user_session() {
        // КРИТИЧНО: Не восстанавливаем сессию на странице выхода
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        
        // КРИТИЧНО: На странице профиля не восстанавливаем сессию, если пользователь уже авторизован
        // Проверяем, что home_url() доступна (может быть недоступна на ранних хуках)
        if (function_exists('home_url') && function_exists('is_user_logged_in')) {
            $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $profile_url = home_url('/lichnyj-kabinet/');
            $is_profile_page = (strpos($current_url, $profile_url) !== false);
            
            if ($is_profile_page && is_user_logged_in()) {
                return;
            }
        }
        
        // Проверяем наличие куки авторизации перед проверкой is_user_logged_in()
        $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $logged_in_cookie_name = LOGGED_IN_COOKIE;
        
        $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
        $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
        
        // Если куки есть, но пользователь не авторизован - принудительно валидируем
        if (($has_auth_cookie || $has_logged_in_cookie) && !is_user_logged_in()) {
            // Принудительно валидируем куки - используем реальное значение куки
            $cookie_user_id = false;
            
            // Пытаемся валидировать logged_in cookie
            if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
            }
            
            // Если не получилось, пытаемся через auth cookie
            if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
            }
            
            if ($cookie_user_id) {
                // Устанавливаем пользователя
                wp_set_current_user($cookie_user_id);
                
                // Устанавливаем куки заново для надежности
                if (!headers_sent()) {
                    $secure = is_ssl();
                    wp_set_auth_cookie($cookie_user_id, true, $secure);
                }
            }
        }
        
        // Проверяем только для авторизованных пользователей
        if (!is_user_logged_in()) {
            return;
        }
        
        $user = wp_get_current_user();
        if (!$user || strpos($user->user_login, 'tg_') !== 0) {
            return;
        }
        
        // Если куки отсутствуют, но пользователь авторизован - восстанавливаем куки
        // Это может произойти, если куки были удалены кешем или другим плагином
        if (!$has_auth_cookie || !$has_logged_in_cookie) {
            // Проверяем, что заголовки еще не отправлены
            if (!headers_sent()) {
                // Восстанавливаем куки авторизации
                $secure = is_ssl();
                wp_set_auth_cookie($user->ID, true, $secure);
            }
        }
    }
    
    /**
     * Проверка сессии после полной загрузки WordPress (wp_loaded)
     * Это важно для главной страницы, где может быть кеширование
     */
    public function verify_user_session_after_loaded() {
        // КРИТИЧНО: Не восстанавливаем сессию на странице выхода
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        
        // Проверяем, что заголовки еще не отправлены
        if (headers_sent()) {
            return;
        }
        
        // КРИТИЧНО: На странице профиля не восстанавливаем сессию, если пользователь уже авторизован
        // Проверяем, что home_url() доступна (может быть недоступна на ранних хуках)
        if (function_exists('home_url') && function_exists('is_user_logged_in')) {
            $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $profile_url = home_url('/lichnyj-kabinet/');
            $is_profile_page = (strpos($current_url, $profile_url) !== false);
            
            if ($is_profile_page && is_user_logged_in()) {
                return;
            }
        }
        
        // Проверяем наличие куки авторизации
        $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $logged_in_cookie_name = LOGGED_IN_COOKIE;
        
        $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
        $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
        
        // Если куки есть, но пользователь не авторизован - принудительно валидируем
        if (($has_auth_cookie || $has_logged_in_cookie) && !is_user_logged_in()) {
            $cookie_user_id = false;
            
            // Пытаемся валидировать logged_in cookie
            if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
            }
            
            // Если не получилось, пытаемся через auth cookie
            if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
            }
            
            if ($cookie_user_id) {
                // Устанавливаем пользователя
                wp_set_current_user($cookie_user_id);
                
                // Проверяем, что это Telegram пользователь
                $user = get_userdata($cookie_user_id);
                if ($user && strpos($user->user_login, 'tg_') === 0) {
                    // Устанавливаем куки заново для надежности
                    $secure = is_ssl();
                    wp_set_auth_cookie($cookie_user_id, true, $secure);
                }
            }
        }
        
        // Если пользователь авторизован, убеждаемся, что куки установлены правильно
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user && strpos($user->user_login, 'tg_') === 0) {
                // Если отсутствует хотя бы одна кука - восстанавливаем
        if (!$has_auth_cookie || !$has_logged_in_cookie) {
                $secure = is_ssl();
                wp_set_auth_cookie($user->ID, true, $secure);
                }
            }
        }
    }
    
    /**
     * Дополнительная проверка сессии при загрузке страницы (включая главную)
     */
    public function verify_user_session_on_page_load() {
        // КРИТИЧНО: Не восстанавливаем сессию на странице выхода
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        
        // Проверяем, что заголовки еще не отправлены
        if (headers_sent()) {
            return;
        }
        
        // КРИТИЧНО: На странице профиля не восстанавливаем сессию, если пользователь уже авторизован
        // Проверяем, что home_url() доступна (может быть недоступна на ранних хуках)
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        if (function_exists('home_url') && function_exists('is_user_logged_in')) {
            $profile_url = home_url('/lichnyj-kabinet/');
            $is_profile_page = (strpos($current_url, $profile_url) !== false);
            
            if ($is_profile_page && is_user_logged_in()) {
                return;
            }
        }
        
        // Проверяем наличие куки авторизации
        $auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        $logged_in_cookie_name = LOGGED_IN_COOKIE;
        
        $has_auth_cookie = isset($_COOKIE[$auth_cookie_name]);
        $has_logged_in_cookie = isset($_COOKIE[$logged_in_cookie_name]);
        
        // Специальная обработка для главной страницы - более агрессивная проверка
        // Используем несколько способов определения главной страницы для надежности
        $home_url = home_url('/');
        $is_home_page = is_front_page() || is_home() || 
                       ($current_url === $home_url || $current_url === rtrim($home_url, '/') || 
                        str_replace(['http://', 'https://'], '', $current_url) === str_replace(['http://', 'https://'], '', $home_url));
        
        // Если есть хотя бы одна кука, пытаемся проверить авторизацию
        if ($has_auth_cookie || $has_logged_in_cookie) {
            // Если пользователь не авторизован, но куки есть - принудительно валидируем куки
            if (!is_user_logged_in()) {
                // Принудительно валидируем куки через WordPress API - используем реальное значение куки
                $cookie_user_id = false;
                
                // Пытаемся валидировать logged_in cookie
                if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                    $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
                }
                
                // Если не получилось, пытаемся через auth cookie
                if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                    $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
                }
                
                if ($cookie_user_id) {
                    // Куки валидны - устанавливаем пользователя
                    wp_set_current_user($cookie_user_id);
                    
                    // Проверяем, что это Telegram пользователь
                    $user = get_userdata($cookie_user_id);
                    if ($user && strpos($user->user_login, 'tg_') === 0) {
                        // Устанавливаем куки заново для надежности
                        $secure = is_ssl();
                        wp_set_auth_cookie($cookie_user_id, true, $secure);
                    }
                }
            }
        }
        
        // Специальная обработка для главной страницы
        // На главной странице всегда проверяем и обновляем куки для надежности
        if ($is_home_page) {
            // Если пользователь не авторизован на главной странице - пытаемся восстановить сессию
            if (!is_user_logged_in()) {
                // Пытаемся валидировать куки, если они есть
                if ($has_auth_cookie || $has_logged_in_cookie) {
                    $cookie_user_id = false;
                    
                    if ($has_logged_in_cookie && isset($_COOKIE[$logged_in_cookie_name])) {
                        $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$logged_in_cookie_name], 'logged_in');
                    }
                    
                    if (!$cookie_user_id && $has_auth_cookie && isset($_COOKIE[$auth_cookie_name])) {
                        $cookie_user_id = wp_validate_auth_cookie($_COOKIE[$auth_cookie_name], is_ssl() ? 'secure_auth' : 'auth');
                    }
                    
                    if ($cookie_user_id) {
                        wp_set_current_user($cookie_user_id);
                        $user = get_userdata($cookie_user_id);
                        if ($user && strpos($user->user_login, 'tg_') === 0) {
                            $secure = is_ssl();
                            wp_set_auth_cookie($cookie_user_id, true, $secure);
                            $this->log("Restored session for user {$cookie_user_id} on home page");
                        }
                    } else {
                        $this->log("Home page: cookies found but validation failed. Auth: " . ($has_auth_cookie ? 'yes' : 'no') . ", Logged_in: " . ($has_logged_in_cookie ? 'yes' : 'no'), 'warning');
                    }
                } else {
                    $this->log("Home page: user not logged in and no cookies found", 'warning');
                }
            }
            
            // Если пользователь авторизован, убеждаемся, что куки установлены правильно
            if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user && strpos($user->user_login, 'tg_') === 0) {
                    // На главной странице всегда обновляем куки для надежности
                    // Это гарантирует, что куки будут актуальными даже при кешировании
                    $secure = is_ssl();
                    wp_set_auth_cookie($user->ID, true, $secure);
                }
            }
        }
    }
    
    /**
     * СОВМЕСТИМОСТЬ С WORDFENCE: Исключаем наши AJAX-запросы из проверки капчи
     */
    public function wordfence_whitelist_our_ajax($require_captcha) {
        // Проверяем, является ли это нашим AJAX-запросом
        if (isset($_POST['action'])) {
            $our_actions = [
                'mtp_check_auth_status',
                'mtp_fav_action',
                'mtp_check_subscription_batch',
                'mtp_check_single_user',
                'mtp_get_statistics',
                'mcp_track_view',
                'mcp_like'
            ];
            if (in_array($_POST['action'], $our_actions)) {
                return false; // Не требуем капчу для наших запросов
            }
        }
        return $require_captcha;
    }
    
    /**
     * СОВМЕСТИМОСТЬ С WORDFENCE: Добавляем наши URL в whitelist
     */
    public function wordfence_whitelist_urls($whitelisted_urls) {
        // Добавляем Telegram API в whitelist
        if (!is_array($whitelisted_urls)) {
            $whitelisted_urls = [];
        }
        
        // Whitelist для Telegram API
        $whitelisted_urls[] = 'api.telegram.org';
        
        return $whitelisted_urls;
    }
}
new My_Telegram_Auth();
}