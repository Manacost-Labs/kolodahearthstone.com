<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('KHSH_Polls')) {

class KHSH_Polls {

    private static $instance = null;
    private $plugin_dir;
    private $plugin_url;

    public static function get_instance() {
        return self::$instance;
    }

    public function __construct() {
        self::$instance = $this;
        $this->plugin_dir = KHSH_PLUGIN_DIR . 'modules/polls/';
        $this->plugin_url = KHSH_PLUGIN_URL . '/modules/polls/';
        
        add_shortcode('khs_poll', 'khs_poll_renderer');
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_khs_poll_vote', [$this, 'ajax_vote']);
        add_action('wp_ajax_nopriv_khs_poll_vote', [$this, 'ajax_vote']);
        add_action('wp_ajax_khs_poll_cancel_vote', [$this, 'ajax_cancel_vote']);
        add_action('wp_ajax_nopriv_khs_poll_cancel_vote', [$this, 'ajax_cancel_vote']);
        add_action('admin_init', [$this, 'add_tinymce_button']);
    }

    public function enqueue_scripts() {
        wp_enqueue_style(
            'khs-poll-style',
            $this->plugin_url . 'assets/css/poll-style.css',
            [],
            filemtime($this->plugin_dir . 'assets/css/poll-style.css')
        );
        
        wp_enqueue_script(
            'khs-poll-script',
            $this->plugin_url . 'assets/js/poll-script.js',
            ['jquery'],
            filemtime($this->plugin_dir . 'assets/js/poll-script.js'),
            true
        );
        
        wp_localize_script('khs-poll-script', 'khsPoll', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('khs_poll_nonce')
        ]);
    }

    public function add_tinymce_button() {
        if (current_user_can('edit_posts') || current_user_can('edit_pages')) {
            add_filter('mce_external_plugins', [$this, 'add_tinymce_plugin'], 10);
            add_filter('mce_buttons', [$this, 'register_tinymce_button'], 10);
            add_filter('mce_css', [$this, 'add_tinymce_css'], 10);
        }
    }

    public function add_tinymce_plugin($plugin_array) {
        $plugin_path = $this->plugin_url . 'assets/js/tinymce-button.js';
        if (file_exists($this->plugin_dir . 'assets/js/tinymce-button.js')) {
            $plugin_array['khs_poll'] = $plugin_path . '?v=' . filemtime($this->plugin_dir . 'assets/js/tinymce-button.js');
        }
        return $plugin_array;
    }

    public function add_tinymce_css($mce_css) {
        if (!empty($mce_css)) $mce_css .= ',';
        $mce_css .= $this->plugin_url . 'assets/css/tinymce-poll.css';
        return $mce_css;
    }

    public function register_tinymce_button($buttons) {
        array_push($buttons, 'khs_poll');
        return $buttons;
    }

    public function ajax_vote() {
        // Проверяем nonce
        if (!check_ajax_referer('khs_poll_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
            return;
        }
        
        $poll_id = sanitize_text_field($_POST['poll_id'] ?? '');
        $option = sanitize_text_field($_POST['option'] ?? '');
        
        if (empty($poll_id) || empty($option)) {
            wp_send_json_error(['message' => 'Неверные параметры']);
            return;
        }
        
        // Проверяем, голосовал ли уже пользователь (по IP и cookie)
        $user_voted = $this->check_user_voted($poll_id);
        if ($user_voted) {
            wp_send_json_error(['message' => 'Вы уже голосовали в этом опросе']);
            return;
        }
        
        // Получаем текущие результаты
        $option_name = 'khs_poll_' . $poll_id;
        $results = get_option($option_name, ['total' => 0]);
        
        // Проверяем, что опция существует (любое число после "option")
        if (preg_match('/^option\d+$/', $option)) {
            // Инициализируем счетчик опции, если его нет
            if (!isset($results[$option])) {
                $results[$option] = 0;
            }
            
            $results[$option]++;
            $results['total']++;
            
            // Используем autoload = false для оптимизации
            update_option($option_name, $results, false);
            
            // Сохраняем факт голосования с информацией о выборе
            $this->mark_user_voted($poll_id, $option);
            
            wp_send_json_success([
                'results' => $results,
                'message' => 'Ваш голос учтен!'
            ]);
            return;
        }
        
        wp_send_json_error(['message' => 'Неверный вариант ответа']);
    }

    public function check_user_voted($poll_id) {
        // Проверяем только cookie - это основной механизм для отслеживания на уровне устройства/браузера
        // IP не используется для блокировки, так как разные устройства могут иметь один внешний IP
        $cookie_name = 'khs_poll_voted_' . $poll_id;
        if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
            return true;
        }
        
        return false;
    }

    private function mark_user_voted($poll_id, $option = null) {
        // Устанавливаем cookie на 1 год с информацией о выборе
        // Cookie работает на уровне браузера/устройства, что позволяет голосовать с разных устройств отдельно
        $cookie_name = 'khs_poll_voted_' . $poll_id;
        $cookie_value = $option ? $option : '1';
        setcookie($cookie_name, $cookie_value, time() + YEAR_IN_SECONDS, '/');
        
        // IP больше не сохраняем - используем только cookie для проверки голосования
        // Это позволяет пользователям голосовать с разных устройств независимо
    }
    
    private function unmark_user_voted($poll_id) {
        // Получаем информацию о выборе из cookie
        $cookie_name = 'khs_poll_voted_' . $poll_id;
        $voted_option = isset($_COOKIE[$cookie_name]) ? $_COOKIE[$cookie_name] : null;
        
        // Если есть информация о выборе, уменьшаем соответствующий счетчик
        if ($voted_option && preg_match('/^option\d+$/', $voted_option)) {
            $option_name = 'khs_poll_' . $poll_id;
            $results = get_option($option_name, ['total' => 0]);
            
            // Инициализируем счетчик опции, если его нет
            if (!isset($results[$voted_option])) {
                $results[$voted_option] = 0;
            }
            
            if ($results[$voted_option] > 0) {
                $results[$voted_option]--;
            }
            if ($results['total'] > 0) {
                $results['total']--;
            }
            update_option($option_name, $results, false);
        }
        
        // Удаляем cookie
        setcookie($cookie_name, '', time() - 3600, '/');
    }
    
    public function ajax_cancel_vote() {
        // Проверяем nonce
        if (!check_ajax_referer('khs_poll_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
            return;
        }
        
        $poll_id = sanitize_text_field($_POST['poll_id'] ?? '');
        
        if (empty($poll_id)) {
            wp_send_json_error(['message' => 'Неверные параметры']);
            return;
        }
        
        // Проверяем, голосовал ли пользователь
        $user_voted = $this->check_user_voted($poll_id);
        if (!$user_voted) {
            wp_send_json_error(['message' => 'Вы еще не голосовали в этом опросе']);
            return;
        }
        
        // Удаляем факт голосования (метод сам уменьшит нужный счетчик)
        $this->unmark_user_voted($poll_id);
        
        // Получаем обновленные результаты
        $option_name = 'khs_poll_' . $poll_id;
        $results = get_option($option_name, ['total' => 0]);
        
        wp_send_json_success([
            'results' => $results,
            'message' => 'Ваш голос отменен'
        ]);
    }

    private function get_user_ip() {
        $ip = '0.0.0.0';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // HTTP_X_FORWARDED_FOR может содержать несколько IP через запятую
            // Берем первый IP (настоящий IP клиента)
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Очищаем IP от пробелов и проверяем валидность
        $ip = trim($ip);
        
        // Базовая валидация IPv4 и IPv6
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }
}

// Вспомогательная функция для проверки голосования
function khs_poll_check_user_voted($poll_id) {
    // Проверяем только cookie - это основной механизм для отслеживания на уровне устройства/браузера
    // IP не используется для блокировки, так как разные устройства могут иметь один внешний IP
    $cookie_name = 'khs_poll_voted_' . $poll_id;
    if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
        return true;
    }
    
    return false;
}

// Функция-рендер шорткода
function khs_poll_renderer($atts, $content = null) {
    // Собираем все опции из атрибутов
    $options = [];
    $options_hash = '';
    
    // Ищем все атрибуты, начинающиеся с "option" (option1, option2, option3 и т.д.)
    foreach ($atts as $key => $value) {
        if (preg_match('/^option\d+$/', $key) && !empty($value)) {
            $option_num = (int) str_replace('option', '', $key);
            $options[$option_num] = $value;
            $options_hash .= $value;
        }
    }
    
    // Если опций нет, используем дефолтные
    if (empty($options)) {
        $options[1] = isset($atts['option1']) ? $atts['option1'] : 'Вариант 1';
        $options[2] = isset($atts['option2']) ? $atts['option2'] : 'Вариант 2';
        $options_hash = $options[1] . $options[2];
    }
    
    // Сортируем по ключам
    ksort($options);
    
    $question = isset($atts['question']) ? $atts['question'] : 'Вопрос?';
    
    // Генерируем уникальный ID на основе хэша вопроса и всех опций
    $poll_id = md5($question . $options_hash);
    $unique_id = 'khs-poll-' . substr($poll_id, 0, 8);
    
    // Получаем текущие результаты
    $option_name = 'khs_poll_' . $poll_id;
    $results = get_option($option_name, ['total' => 0]);
    
    // Инициализируем счетчики для всех опций
    foreach ($options as $num => $option_text) {
        $option_key = 'option' . $num;
        if (!isset($results[$option_key])) {
            $results[$option_key] = 0;
        }
    }
    
    // Проверяем, голосовал ли пользователь
    $user_voted = khs_poll_check_user_voted($poll_id);
    
    // Вычисляем проценты для всех опций
    $percents = [];
    foreach ($options as $num => $option_text) {
        $option_key = 'option' . $num;
        $percents[$num] = $results['total'] > 0 ? round(($results[$option_key] / $results['total']) * 100) : 0;
    }
    
    $content_html = '';
    if (!empty($content)) {
        $content = do_shortcode($content);
        $content_html = '<div class="khs-poll-description">' . wpautop($content) . '</div>';
    }
    
    ob_start();
    ?>
    <div id="<?php echo esc_attr($unique_id); ?>" class="khs-poll-wrapper" data-poll-id="<?php echo esc_attr($poll_id); ?>">
        <div class="khs-poll-question">
            <?php echo esc_html($question); ?>
        </div>
        <?php echo $content_html; ?>
        <div class="khs-poll-options">
            <?php foreach ($options as $num => $option_text): 
                $option_key = 'option' . $num;
                $votes = $results[$option_key] ?? 0;
                $percent = $percents[$num] ?? 0;
            ?>
                <button type="button" class="khs-poll-option" data-option="<?php echo esc_attr($option_key); ?>" <?php echo $user_voted ? 'disabled' : ''; ?>>
                    <span class="khs-poll-option-text"><?php echo esc_html($option_text); ?></span>
                    <?php if ($results['total'] > 0): ?>
                        <span class="khs-poll-option-votes">
                            <?php echo esc_html($votes); ?> голосов (<?php echo esc_html($percent); ?>%)
                        </span>
                        <div class="khs-poll-bar-container">
                            <div class="khs-poll-bar" style="width: <?php echo esc_attr($percent); ?>%"></div>
                        </div>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php if ($user_voted): ?>
            <button type="button" class="khs-poll-cancel-vote" data-poll-id="<?php echo esc_attr($poll_id); ?>">
                Отменить голос
            </button>
        <?php endif; ?>
        <div class="khs-poll-message"></div>
    </div>
    <?php
    return ob_get_clean();
}

new KHSH_Polls();
}

