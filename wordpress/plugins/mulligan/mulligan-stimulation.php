<?php
/*
Plugin Name: Mulligan Stimulation
Description: Тренажёр муллигана для Hearthstone. Шорткод-симулятор начальной руки с проверкой правильности выбора.
Version: 1.11.1
Author: Manacost
*/

if (!defined('ABSPATH')) exit;

define('HS_MULLIGAN_VERSION', '1.11.1');
define('HS_MULLIGAN_FILE', __FILE__);
define('HS_MULLIGAN_DIR', plugin_dir_path(__FILE__));
define('HS_MULLIGAN_URL', plugin_dir_url(__FILE__));

require_once HS_MULLIGAN_DIR . 'includes/class-deckstring.php';
require_once HS_MULLIGAN_DIR . 'includes/class-card-data.php';

class HS_Mulligan_Plugin {

    public function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_hs_mulligan', array($this, 'save_meta_boxes'));
        add_action('save_post_hs_matchups', array($this, 'save_matchups_meta_box'));

        add_shortcode('hs_mulligan', array($this, 'shortcode'));
        add_shortcode('hs_matchups', array($this, 'shortcode_matchups'));
        add_shortcode('hs_matchup',  array($this, 'shortcode_matchup_single'));
        add_shortcode('hs_decklist', array($this, 'shortcode_decklist'));

        add_action('admin_init', array($this, 'add_tinymce_button'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        add_action('wp_ajax_hs_mulligan_decode', array($this, 'ajax_decode_deck'));
        add_action('wp_ajax_hs_mulligan_create', array($this, 'ajax_create_mulligan'));
        add_action('wp_ajax_hs_mulligan_load',   array($this, 'ajax_load_mulligan'));
        add_action('wp_ajax_hs_mulligan_search', array($this, 'ajax_search_mulligans'));
        add_action('wp_ajax_hs_matchups_create', array($this, 'ajax_create_matchups'));

        // Аналитика — без авторизации, с защитой по nonce + троттлингу.
        add_action('wp_ajax_hs_mulligan_track',        array($this, 'ajax_track'));
        add_action('wp_ajax_nopriv_hs_mulligan_track', array($this, 'ajax_track'));
        add_action('wp_ajax_hs_decklist_rate',         array($this, 'ajax_decklist_rate'));
        add_action('wp_ajax_nopriv_hs_decklist_rate',  array($this, 'ajax_decklist_rate'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // Прогрев индекса карт.
        add_action('hs_mulligan_refresh_cards', array('HS_Mulligan_Card_Data', 'refresh'));
        add_action('hs_mulligan_daily_refresh', array('HS_Mulligan_Card_Data', 'refresh'));
    }

    /**
     * Полный список 11 классов Hearthstone (slug => русское имя).
     */
    public static function class_list() {
        return array(
            'deathknight' => 'Рыцарь смерти',
            'demonhunter' => 'Охотник на демонов',
            'druid'       => 'Друид',
            'hunter'      => 'Охотник',
            'mage'        => 'Маг',
            'paladin'     => 'Паладин',
            'priest'      => 'Жрец',
            'rogue'       => 'Разбойник',
            'shaman'      => 'Шаман',
            'warlock'     => 'Чернокнижник',
            'warrior'     => 'Воин',
        );
    }

    public static function activate() {
        // Запланировать ежедневный прогрев индекса карт в фоне.
        if (!wp_next_scheduled('hs_mulligan_daily_refresh')) {
            wp_schedule_event(time() + 60, 'daily', 'hs_mulligan_daily_refresh');
        }
        // Прогрев — в фоне через cron, чтобы активация не висла на сети.
        if (!wp_next_scheduled('hs_mulligan_refresh_cards')) {
            wp_schedule_single_event(time() + 5, 'hs_mulligan_refresh_cards');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('hs_mulligan_daily_refresh');
        wp_clear_scheduled_hook('hs_mulligan_refresh_cards');
    }

    public function register_post_type() {
        register_post_type('hs_mulligan', array(
            'labels' => array(
                'name'          => 'Муллиганы',
                'singular_name' => 'Муллиган',
                'add_new'       => 'Добавить муллиган',
                'add_new_item'  => 'Новый муллиган',
                'edit_item'     => 'Редактировать муллиган',
                'menu_name'     => 'Муллиганы',
            ),
            'public'       => false,
            'show_ui'      => true,
            'menu_icon'    => 'dashicons-image-rotate',
            'supports'     => array('title', 'author'),
            'capability_type' => 'post',
        ));
        register_post_type('hs_matchups', array(
            'labels' => array(
                'name'          => 'Матчапы',
                'singular_name' => 'Матчап',
                'add_new'       => 'Добавить матчапы',
                'add_new_item'  => 'Новые матчапы',
                'edit_item'     => 'Редактировать матчапы',
                'menu_name'     => 'Матчапы',
            ),
            'public'       => false,
            'show_ui'      => true,
            'menu_icon'    => 'dashicons-chart-bar',
            'supports'     => array('title', 'author'),
            'capability_type' => 'post',
        ));
    }

    public function add_meta_boxes() {
        add_meta_box(
            'hs_mulligan_settings',
            'Настройки муллигана',
            array($this, 'render_meta_box'),
            'hs_mulligan',
            'normal',
            'high'
        );
        add_meta_box(
            'hs_matchups_settings',
            'Матчапы',
            array($this, 'render_matchups_meta_box'),
            'hs_matchups',
            'normal',
            'high'
        );
    }

    public function render_matchups_meta_box($post) {
        wp_nonce_field('hs_matchups_save', 'hs_matchups_nonce');
        $rows = self::load_matchups($post->ID);
        ?>
        <p>Записей матчапов: <strong><?php echo (int) count($rows); ?></strong>. Используйте кнопку «Матчапы» в редакторе записи (TinyMCE), чтобы настроить инфографику с винрейтами.</p>
        <?php
    }

    public function save_matchups_meta_box($post_id) {
        if (!isset($_POST['hs_matchups_nonce']) || !wp_verify_nonce($_POST['hs_matchups_nonce'], 'hs_matchups_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        // данные приходят через AJAX, тут только nonce-проверка
    }

    /**
     * Загрузка/нормализация матчапов.
     */
    public static function load_matchups($post_id) {
        $rows = get_post_meta($post_id, '_hs_matchups_data', true);
        if (!is_array($rows)) return array();
        return self::normalize_matchups($rows);
    }

    public static function normalize_matchups($rows) {
        $classes = self::class_list();
        $out = array();
        foreach ((array) $rows as $row) {
            if (!is_array($row)) continue;
            $type = isset($row['type']) && $row['type'] === 'custom' ? 'custom' : 'class';
            $class = isset($row['class']) ? sanitize_key($row['class']) : '';
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';

            if ($type === 'class') {
                if (!isset($classes[$class])) continue;
                if ($label === '') $label = $classes[$class];
            } else {
                if ($label === '') continue;
                // у кастомного матчапа можно оставить пустой class — иконки не будет
                if ($class !== '' && !isset($classes[$class])) $class = '';
            }

            $winrate = isset($row['winrate']) ? (int) $row['winrate'] : -1;
            if ($winrate < 0) continue; // пустые строки пропускаем
            $winrate = max(0, min(100, $winrate));
            $comment = isset($row['comment']) ? sanitize_textarea_field((string) $row['comment']) : '';

            $out[] = array(
                'type'    => $type,
                'class'   => $class,
                'label'   => $label,
                'winrate' => $winrate,
                'comment' => $comment,
            );
        }
        return $out;
    }

    public function render_meta_box($post) {
        wp_nonce_field('hs_mulligan_save', 'hs_mulligan_nonce');
        $deck_code = get_post_meta($post->ID, '_hs_mulligan_deck_code', true);
        $profiles  = self::load_profiles($post->ID);
        $count     = count($profiles);
        ?>
        <p>
            <label for="hs_mulligan_deck_code"><strong>Код колоды:</strong></label><br>
            <textarea id="hs_mulligan_deck_code" name="hs_mulligan_deck_code" rows="3" style="width:100%;font-family:monospace;"><?php echo esc_textarea($deck_code); ?></textarea>
        </p>
        <p>Профилей муллигана: <strong><?php echo (int) $count; ?></strong>. Настраивайте через кнопку «Муллиган» в редакторе записи (TinyMCE).</p>
        <?php
    }

    public function save_meta_boxes($post_id) {
        if (!isset($_POST['hs_mulligan_nonce']) || !wp_verify_nonce($_POST['hs_mulligan_nonce'], 'hs_mulligan_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['hs_mulligan_deck_code'])) {
            $code = sanitize_textarea_field(wp_unslash($_POST['hs_mulligan_deck_code']));
            update_post_meta($post_id, '_hs_mulligan_deck_code', $code);
        }
    }

    /**
     * Загрузка профилей с миграцией старого формата (_hs_mulligan_keep_ids → один профиль).
     */
    public static function load_profiles($post_id) {
        $profiles = get_post_meta($post_id, '_hs_mulligan_profiles', true);
        if (is_array($profiles) && !empty($profiles)) return self::normalize_profiles($profiles);

        $legacy = get_post_meta($post_id, '_hs_mulligan_keep_ids', true);
        if (is_array($legacy) && !empty($legacy)) {
            $cards = array();
            foreach ($legacy as $dbf) $cards[(int) $dbf] = array('keep' => true, 'note' => '');
            return array(array('id' => 'default', 'label' => 'По умолчанию', 'cards' => $cards));
        }
        return array();
    }

    /**
     * Нормализация и санитизация массива профилей.
     */
    public static function normalize_profiles($profiles) {
        $out = array();
        $used_ids = array();
        foreach ((array) $profiles as $p) {
            if (!is_array($p)) continue;
            $id = isset($p['id']) ? sanitize_key($p['id']) : '';
            if ($id === '') $id = 'profile-' . (count($used_ids) + 1);
            while (in_array($id, $used_ids, true)) $id .= '-x';
            $used_ids[] = $id;

            $label = isset($p['label']) ? sanitize_text_field($p['label']) : $id;
            $cards = array();
            if (isset($p['cards']) && is_array($p['cards'])) {
                foreach ($p['cards'] as $dbf => $entry) {
                    $dbf_id = (int) $dbf;
                    if ($dbf_id <= 0 || !is_array($entry)) continue;
                    // keep: int 0/1/2 (0 = не оставлять, 1 = одну копию, 2 = обе копии).
                    // Backward compat: если пришёл bool — конвертируем в 0/1.
                    $keep_raw = isset($entry['keep']) ? $entry['keep'] : 0;
                    if (is_bool($keep_raw)) $keep = $keep_raw ? 1 : 0;
                    else                    $keep = (int) $keep_raw;
                    $keep = max(0, min(2, $keep));
                    $note = isset($entry['note']) ? sanitize_textarea_field((string) $entry['note']) : '';
                    if ($note === '' && $keep === 0) continue;
                    $cards[$dbf_id] = array('keep' => $keep, 'note' => $note);
                }
            }
            $out[] = array('id' => $id, 'label' => $label, 'cards' => $cards);
        }
        return $out;
    }

    public function add_tinymce_button() {
        if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) return;
        add_filter('mce_external_plugins', array($this, 'add_tinymce_plugin'));
        add_filter('mce_buttons', array($this, 'register_button'));
    }

    public function add_tinymce_plugin($plugin_array) {
        $plugin_array['hs_mulligan'] = HS_MULLIGAN_URL . 'assets/tinymce-mulligan.js?v=' . HS_MULLIGAN_VERSION;
        $plugin_array['hs_matchups'] = HS_MULLIGAN_URL . 'assets/tinymce-matchups.js?v=' . HS_MULLIGAN_VERSION;
        $plugin_array['hs_decklist'] = HS_MULLIGAN_URL . 'assets/tinymce-decklist.js?v=' . HS_MULLIGAN_VERSION;
        return $plugin_array;
    }

    public function register_button($buttons) {
        $buttons[] = 'hs_mulligan';
        $buttons[] = 'hs_matchups';
        $buttons[] = 'hs_decklist';
        return $buttons;
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
        wp_enqueue_script('jquery');
        $classes = array();
        foreach (self::class_list() as $slug => $label) {
            $classes[] = array(
                'slug'  => $slug,
                'label' => $label,
                'icon'  => HS_MULLIGAN_URL . 'assets/class-icons/' . $slug . '.png',
            );
        }
        wp_add_inline_script('jquery', 'var hsMulliganAjax = ' . wp_json_encode(array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hs_mulligan_ajax'),
            'classes' => $classes,
            'fontUrl' => HS_MULLIGAN_URL . 'assets/font/2318-font.otf',
        )) . ';', 'before');
    }

    public function enqueue_frontend_scripts() {
        wp_register_script(
            'hs-mulligan-frontend',
            HS_MULLIGAN_URL . 'assets/mulligan-frontend.js',
            array(),
            HS_MULLIGAN_VERSION,
            true
        );
        wp_localize_script('hs-mulligan-frontend', 'hsMulliganFront', array(
            'ajaxurl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('hs_mulligan_track'),
            'rateNonce'  => wp_create_nonce('hs_decklist_rate'),
        ));
        wp_register_style(
            'hs-mulligan-frontend',
            HS_MULLIGAN_URL . 'assets/mulligan-frontend.css',
            array(),
            HS_MULLIGAN_VERSION
        );
        wp_register_style(
            'hs-matchups-frontend',
            HS_MULLIGAN_URL . 'assets/matchups-frontend.css',
            array(),
            HS_MULLIGAN_VERSION
        );
        wp_register_style(
            'hs-decklist-frontend',
            HS_MULLIGAN_URL . 'assets/decklist-frontend.css',
            array(),
            HS_MULLIGAN_VERSION
        );
        wp_register_script(
            'hs-decklist-frontend',
            HS_MULLIGAN_URL . 'assets/decklist-frontend.js',
            array(),
            HS_MULLIGAN_VERSION,
            true
        );
        // ВАЖНО: wp_localize_script должна вызываться ПОСЛЕ wp_register_script.
        wp_localize_script('hs-decklist-frontend', 'hsDecklistFront', array(
            'ajaxurl'   => admin_url('admin-ajax.php'),
            'rateNonce' => wp_create_nonce('hs_decklist_rate'),
        ));
    }

    /**
     * Хардкодная карта базовых героев → класс.
     * Базовые герои НЕ входят в cards.collectible.json HearthstoneJSON,
     * поэтому их класс приходится определять напрямую по dbfId.
     */
    public static function basic_hero_classes() {
        return array(
            // Базовые герои
            7     => array('class' => 'WARRIOR',    'name' => 'Воин'),
            274   => array('class' => 'DRUID',      'name' => 'Друид'),
            31    => array('class' => 'HUNTER',     'name' => 'Охотник'),
            637   => array('class' => 'MAGE',       'name' => 'Маг'),
            671   => array('class' => 'PALADIN',    'name' => 'Паладин'),
            813   => array('class' => 'PRIEST',     'name' => 'Жрец'),
            930   => array('class' => 'ROGUE',      'name' => 'Разбойник'),
            1066  => array('class' => 'SHAMAN',     'name' => 'Шаман'),
            893   => array('class' => 'WARLOCK',    'name' => 'Чернокнижник'),
            56550 => array('class' => 'DEMONHUNTER','name' => 'Охотник на демонов'),
            78065 => array('class' => 'DEATHKNIGHT','name' => 'Рыцарь смерти'),
            // Альтернативные скины (популярные)
            2826  => array('class' => 'WARRIOR',    'name' => 'Воин'),
            2827  => array('class' => 'HUNTER',     'name' => 'Охотник'),
            2828  => array('class' => 'MAGE',       'name' => 'Маг'),
            2829  => array('class' => 'SHAMAN',     'name' => 'Шаман'),
            40183 => array('class' => 'PALADIN',    'name' => 'Паладин'),
            40195 => array('class' => 'PRIEST',     'name' => 'Жрец'),
            40323 => array('class' => 'WARLOCK',    'name' => 'Чернокнижник'),
            57761 => array('class' => 'ROGUE',      'name' => 'Разбойник'),
            60224 => array('class' => 'DRUID',      'name' => 'Друид'),
            74481 => array('class' => 'DEMONHUNTER','name' => 'Охотник на демонов'),
        );
    }

    /**
     * Возвращает {class, name} для героя по dbfId — используя сначала
     * портреты-героев из коллекции, затем хардкоднутую карту базовых.
     */
    public static function resolve_hero($hero_dbf, $cards_db) {
        $hero_dbf = (int) $hero_dbf;
        if ($hero_dbf <= 0) return null;
        if (isset($cards_db[$hero_dbf]) && !empty($cards_db[$hero_dbf]['class'])) {
            return array(
                'class' => $cards_db[$hero_dbf]['class'],
                'name'  => $cards_db[$hero_dbf]['name'] ?: '',
            );
        }
        $basic = self::basic_hero_classes();
        if (isset($basic[$hero_dbf])) return $basic[$hero_dbf];
        return null;
    }

    public function ajax_decode_deck() {
        check_ajax_referer('hs_mulligan_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'Недостаточно прав'));

        $code = isset($_POST['code']) ? sanitize_textarea_field(wp_unslash($_POST['code'])) : '';
        if ($code === '') wp_send_json_error(array('message' => 'Пустой код колоды'));

        try {
            $deck = HS_Mulligan_Deckstring::decode($code);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Не удалось декодировать код: ' . $e->getMessage()));
        }

        $cards_db  = HS_Mulligan_Card_Data::get_index();
        $hero_dbf  = isset($deck['heroes'][0]) ? $deck['heroes'][0] : 0;
        $hero_info = self::resolve_hero($hero_dbf, $cards_db);

        $expand = function ($rows) use ($cards_db) {
            $list = array();
            foreach ($rows as $row) {
                list($dbf_id, $count) = $row;
                $info = isset($cards_db[$dbf_id]) ? $cards_db[$dbf_id] : null;
                $list[] = array(
                    'dbfId'    => $dbf_id,
                    'count'    => $count,
                    'name'     => $info ? $info['name']     : ('dbfId ' . $dbf_id),
                    'cost'     => $info ? $info['cost']     : null,
                    'image'    => $info ? $info['image']    : '',
                    'imageBig' => $info ? $info['imageBig'] : '',
                    'text'     => $info ? $info['text']     : '',
                    'type'     => $info ? $info['type']     : '',
                    'rarity'   => $info ? $info['rarity']   : '',
                );
            }
            usort($list, function($a, $b) {
                $ca = is_null($a['cost']) ? 99 : (int)$a['cost'];
                $cb = is_null($b['cost']) ? 99 : (int)$b['cost'];
                if ($ca !== $cb) return $ca - $cb;
                return strcmp((string)$a['name'], (string)$b['name']);
            });
            return $list;
        };

        // Sideboards: [ownerDbf => [[dbf,count], ...]]. Раскрываем с инфо о владельце.
        $sideboards = array();
        if (!empty($deck['sideboards'])) {
            foreach ($deck['sideboards'] as $owner_dbf => $rows) {
                $owner_info = isset($cards_db[$owner_dbf]) ? $cards_db[$owner_dbf] : null;
                $sideboards[] = array(
                    'ownerDbfId' => (int) $owner_dbf,
                    'ownerName'  => $owner_info ? $owner_info['name'] : ('dbfId ' . $owner_dbf),
                    'ownerImage' => $owner_info ? $owner_info['image'] : '',
                    'cards'      => $expand($rows),
                );
            }
        }

        wp_send_json_success(array(
            'format'     => $deck['format'],
            'hero'       => $hero_info ? $hero_info['name'] : 'Неизвестный класс',
            'cards'      => $expand($deck['cards']),
            'sideboards' => $sideboards,
        ));
    }

    /**
     * Загрузка существующего муллигана для редактирования через ту же модалку.
     */
    public function ajax_load_mulligan() {
        check_ajax_referer('hs_mulligan_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'Недостаточно прав'));

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) wp_send_json_error(array('message' => 'Не указан ID'));
        $post = get_post($id);
        if (!$post || $post->post_type !== 'hs_mulligan') wp_send_json_error(array('message' => 'Не найден'));

        $code     = (string) get_post_meta($id, '_hs_mulligan_deck_code', true);
        $profiles = self::load_profiles($id);

        wp_send_json_success(array(
            'id'       => $id,
            'title'    => get_the_title($id),
            'code'     => $code,
            'profiles' => $profiles,
        ));
    }

    /**
     * Поиск муллиганов по названию (для модалки «Редактировать существующий»).
     */
    public function ajax_search_mulligans() {
        check_ajax_referer('hs_mulligan_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'Недостаточно прав'));

        $q = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $args = array(
            'post_type'      => 'hs_mulligan',
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );
        if (strlen($q) >= 2) $args['s'] = $q;
        $items = array();
        $query = new WP_Query($args);
        while ($query->have_posts()) {
            $query->the_post();
            $items[] = array(
                'id'    => get_the_ID(),
                'title' => get_the_title(),
                'date'  => get_the_date('c'),
            );
        }
        wp_reset_postdata();
        wp_send_json_success(array('items' => $items));
    }

    public function ajax_create_mulligan() {
        check_ajax_referer('hs_mulligan_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'Недостаточно прав'));

        $title    = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $code     = isset($_POST['code']) ? sanitize_textarea_field(wp_unslash($_POST['code'])) : '';
        $existing = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

        $profiles_raw = isset($_POST['profiles']) ? wp_unslash($_POST['profiles']) : '';
        $profiles = json_decode((string) $profiles_raw, true);
        if (!is_array($profiles)) $profiles = array();
        $profiles = self::normalize_profiles($profiles);

        if ($title === '') wp_send_json_error(array('message' => 'Введите название'));
        if ($code === '')  wp_send_json_error(array('message' => 'Введите код колоды'));

        try {
            HS_Mulligan_Deckstring::decode($code);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Код колоды некорректен'));
        }

        if ($existing > 0) {
            $post = get_post($existing);
            if (!$post || $post->post_type !== 'hs_mulligan') {
                wp_send_json_error(array('message' => 'Запись для обновления не найдена'));
            }
            if (!current_user_can('edit_post', $existing)) {
                wp_send_json_error(array('message' => 'Нет прав на правку этой записи'));
            }
            wp_update_post(array('ID' => $existing, 'post_title' => $title));
            $post_id = $existing;
        } else {
            $post_id = wp_insert_post(array(
                'post_title'  => $title,
                'post_type'   => 'hs_mulligan',
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ), true);
            if (is_wp_error($post_id)) {
                wp_send_json_error(array('message' => 'Ошибка создания записи: ' . $post_id->get_error_message()));
            }
        }

        update_post_meta($post_id, '_hs_mulligan_deck_code', $code);
        update_post_meta($post_id, '_hs_mulligan_profiles', $profiles);
        delete_post_meta($post_id, '_hs_mulligan_keep_ids');

        wp_send_json_success(array('id' => $post_id, 'updated' => $existing > 0));
    }

    /**
     * AJAX: счётчики просмотров и попыток. Дроссель — 10 секунд на IP+post.
     */
    public function ajax_track() {
        check_ajax_referer('hs_mulligan_track', 'nonce');
        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $action = isset($_POST['t'])  ? sanitize_key(wp_unslash($_POST['t'])) : '';
        $score  = isset($_POST['score']) ? max(0, min(100, (int) $_POST['score'])) : -1;
        if (!$id || !in_array($action, array('view', 'check'), true)) {
            wp_send_json_error(array('message' => 'bad'));
        }

        // Не считаем редакторов и админов.
        if (current_user_can('edit_posts')) wp_send_json_success(array('counted' => false));

        // Боты по UA.
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|telegram|whatsapp|preview|scrape|headless|phantom/i', $ua)) {
            wp_send_json_success(array('counted' => false));
        }

        $ip  = self::get_client_ip();
        $key = 'hsm_t_' . $action . '_' . $id . '_' . md5($ip);
        if (get_transient($key)) wp_send_json_success(array('counted' => false));
        set_transient($key, 1, 10);

        $opt = (array) get_option('hs_mulligan_stats_' . $id, array(
            'views' => 0, 'checks' => 0, 'score_sum' => 0, 'score_n' => 0,
        ));
        if ($action === 'view')   $opt['views'] = (int) $opt['views'] + 1;
        if ($action === 'check') {
            $opt['checks'] = (int) $opt['checks'] + 1;
            if ($score >= 0) {
                $opt['score_sum'] = (int) $opt['score_sum'] + $score;
                $opt['score_n']   = (int) $opt['score_n'] + 1;
            }
        }
        update_option('hs_mulligan_stats_' . $id, $opt, false);
        wp_send_json_success(array('counted' => true, 'stats' => $opt));
    }

    /**
     * AJAX: оценка колоды от 1 до 5. Один голос на IP+колоду в день.
     */
    public function ajax_decklist_rate() {
        check_ajax_referer('hs_decklist_rate', 'nonce');
        $hash   = isset($_POST['hash']) ? sanitize_key(wp_unslash($_POST['hash'])) : '';
        $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
        if (!$hash || $rating < 1 || $rating > 5) {
            wp_send_json_error(array('message' => 'bad'));
        }

        $ip = self::get_client_ip();
        $key = 'hsd_rated_' . $hash . '_' . md5($ip);
        if (get_transient($key)) {
            $opt = (array) get_option('hs_decklist_rating_' . $hash, array('sum' => 0, 'count' => 0));
            $avg = !empty($opt['count']) ? round($opt['sum'] / $opt['count'], 1) : 0;
            wp_send_json_error(array(
                'message' => 'Вы уже оценивали эту колоду',
                'avg'     => $avg,
                'count'   => (int) $opt['count'],
            ));
        }
        set_transient($key, $rating, DAY_IN_SECONDS);

        $opt = (array) get_option('hs_decklist_rating_' . $hash, array('sum' => 0, 'count' => 0));
        $opt['sum']   = (int) $opt['sum'] + $rating;
        $opt['count'] = (int) $opt['count'] + 1;
        update_option('hs_decklist_rating_' . $hash, $opt, false);

        $avg = round($opt['sum'] / $opt['count'], 1);
        wp_send_json_success(array('avg' => $avg, 'count' => (int) $opt['count'], 'my' => $rating));
    }

    private static function get_client_ip() {
        $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) continue;
            $val = (string) $_SERVER[$key];
            if (strpos($val, ',') !== false) $val = trim(explode(',', $val)[0]);
            if (filter_var($val, FILTER_VALIDATE_IP)) return $val;
        }
        return '0.0.0.0';
    }

    public function ajax_create_matchups() {
        check_ajax_referer('hs_mulligan_ajax', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'Недостаточно прав'));

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $rows_raw = isset($_POST['rows']) ? wp_unslash($_POST['rows']) : '';
        $rows = json_decode((string) $rows_raw, true);
        if (!is_array($rows)) $rows = array();
        $rows = self::normalize_matchups($rows);

        if ($title === '') wp_send_json_error(array('message' => 'Введите название'));
        if (empty($rows))  wp_send_json_error(array('message' => 'Заполните винрейт хотя бы для одного матчапа'));

        $post_id = wp_insert_post(array(
            'post_title'  => $title,
            'post_type'   => 'hs_matchups',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ), true);
        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Ошибка создания: ' . $post_id->get_error_message()));
        }
        update_post_meta($post_id, '_hs_matchups_data', $rows);
        wp_send_json_success(array('id' => $post_id));
    }

    public function shortcode_matchups($atts, $content = null) {
        $atts = shortcode_atts(array(
            'id'         => 0,
            'show_title' => '0',
            'order'      => 'as-is', // as-is | winrate-desc | winrate-asc | alphabetical
        ), $atts, 'hs_matchups');

        // Новый инлайн-формат: парсим вложенные [hs_matchup ...] прямо из контента
        // — автор может редактировать всё в редакторе статьи.
        if ($content && strpos($content, '[hs_matchup') !== false) {
            $rows = self::parse_inline_matchups($content);
            if (empty($rows)) return '';
            $rows = self::apply_order($rows, $atts['order']);
            $sum = 0; $n = 0;
            foreach ($rows as $r) { $sum += (int) $r['winrate']; $n++; }
            $avg = $n ? (int) round($sum / $n) : 0;
            wp_enqueue_style('hs-matchups-frontend');
            $show_title = !empty($atts['show_title']) && $atts['show_title'] !== '0';
            $show_title_value = '';
            return self::render_matchups_html($rows, $avg, $show_title, $show_title_value);
        }

        // Legacy: рендер из CPT по id
        $id = intval($atts['id']);
        if (!$id) return '';
        $post = get_post($id);
        if (!$post || $post->post_type !== 'hs_matchups' || $post->post_status !== 'publish') return '';

        $modified  = get_post_modified_time('U', true, $id);
        $cache_key = 'hsm_matchups_' . $id . '_' . $modified . '_' . HS_MULLIGAN_VERSION;
        $cached    = wp_cache_get($cache_key, 'hs_mulligan');
        if (is_array($cached) && isset($cached['rows'], $cached['avg'])) {
            $rows = $cached['rows'];
            $avg  = $cached['avg'];
        } else {
            $rows = self::load_matchups($id);
            if (empty($rows)) return '';
            $sum = 0; $n = 0;
            foreach ($rows as $r) { $sum += (int) $r['winrate']; $n++; }
            $avg = $n ? (int) round($sum / $n) : 0;
            wp_cache_set($cache_key, array('rows' => $rows, 'avg' => $avg), 'hs_mulligan', HOUR_IN_SECONDS);
        }

        wp_enqueue_style('hs-matchups-frontend');

        $rows = self::apply_order($rows, $atts['order']);
        $show_title = !empty($atts['show_title']) && $atts['show_title'] !== '0';
        $show_title_value = get_the_title($id);
        return self::render_matchups_html($rows, $avg, $show_title, $show_title_value);
    }

    public static function apply_order($rows, $order) {
        switch ($order) {
            case 'winrate-desc':
                usort($rows, function($a,$b){ return (int)$b['winrate'] - (int)$a['winrate']; });
                break;
            case 'winrate-asc':
                usort($rows, function($a,$b){ return (int)$a['winrate'] - (int)$b['winrate']; });
                break;
            case 'alphabetical':
                usort($rows, function($a,$b){ return strcmp((string)$a['label'], (string)$b['label']); });
                break;
        }
        return $rows;
    }

    /**
     * Парсит [hs_matchup class="..." wr="65"]Комментарий[/hs_matchup] из контента.
     */
    public static function parse_inline_matchups($content) {
        $pattern = '/\[hs_matchup(\s[^\]]*)?\](.*?)\[\/hs_matchup\]/s';
        if (!preg_match_all($pattern, $content, $m, PREG_SET_ORDER)) return array();
        $rows = array();
        foreach ($m as $match) {
            $atts_str = isset($match[1]) ? $match[1] : '';
            $inner    = isset($match[2]) ? $match[2] : '';
            $atts = shortcode_parse_atts($atts_str);
            if (!is_array($atts)) $atts = array();
            $type = (isset($atts['type']) && $atts['type'] === 'custom') ? 'custom' : 'class';
            $rows[] = array(
                'type'    => $type,
                'class'   => isset($atts['class']) ? $atts['class'] : '',
                'label'   => isset($atts['label']) ? $atts['label'] : '',
                'winrate' => isset($atts['wr']) ? (int) $atts['wr'] : (isset($atts['winrate']) ? (int) $atts['winrate'] : -1),
                'comment' => trim($inner),
            );
        }
        return self::normalize_matchups($rows);
    }

    /**
     * Одиночный [hs_matchup] — рендерится только внутри обёртки [hs_matchups]
     * (там его регэкспом разбирает parse_inline_matchups()).
     * Если автор оставил его вне обёртки — сохраняем хотя бы текст комментария,
     * чтобы данные не пропали из статьи.
     */
    public function shortcode_matchup_single($atts, $content = '') {
        if (!is_string($content) || $content === '') return '';
        return '<p class="hs-matchup-orphan">' . wp_kses_post(do_shortcode(trim($content))) . '</p>';
    }

    /**
     * Общий рендер инфографики из массива rows.
     */
    public static function render_matchups_html($rows, $avg, $show_title, $title_text) {
        ob_start();
        ?>
        <div class="hs-matchups">
            <div class="hs-matchups-head<?php echo $show_title ? '' : ' is-titleless'; ?>">
                <?php if ($show_title): ?>
                    <h3 class="hs-matchups-title"><?php echo esc_html($title_text); ?></h3>
                <?php endif; ?>
                <div class="hs-matchups-avg">
                    <span class="hs-matchups-avg-label">Средний винрейт колоды</span>
                    <span class="hs-matchups-avg-value <?php echo esc_attr(self::wr_class($avg)); ?>"><?php echo (int) $avg; ?>%</span>
                    <span class="hs-matchups-avg-verdict"><?php echo esc_html(self::wr_verdict($avg)); ?></span>
                </div>
            </div>
            <div class="hs-matchups-grid">
                <?php foreach ($rows as $r):
                    $wr  = (int) $r['winrate'];
                    $cls = self::wr_class($wr);
                    $icon_url = $r['class'] ? HS_MULLIGAN_URL . 'assets/class-icons/' . $r['class'] . '.png' : '';
                ?>
                    <div class="hs-matchup hs-matchup-<?php echo esc_attr($cls); ?>">
                        <div class="hs-matchup-head">
                            <?php if ($icon_url): ?>
                                <img class="hs-matchup-icon no-lightbox nolightbox" data-no-lightbox="1" data-fancybox="false" src="<?php echo esc_url($icon_url); ?>" alt="">
                            <?php else: ?>
                                <span class="hs-matchup-icon hs-matchup-icon-empty">★</span>
                            <?php endif; ?>
                            <span class="hs-matchup-label"><?php echo esc_html($r['label']); ?></span>
                        </div>
                        <div class="hs-matchup-wr-row">
                            <span class="hs-matchup-wr"><?php echo (int) $wr; ?>%</span>
                            <span class="hs-matchup-verdict"><?php echo esc_html(self::wr_verdict($wr)); ?></span>
                        </div>
                        <div class="hs-matchup-bar">
                            <div class="hs-matchup-bar-fill" style="width:<?php echo (int) $wr; ?>%;"></div>
                        </div>
                        <?php if ($r['comment']): ?>
                            <p class="hs-matchup-comment"><?php echo wp_kses_post(do_shortcode($r['comment'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        $html = trim(ob_get_clean());
        return preg_replace('/>\s+</', '><', $html);
    }

    public static function wr_class($wr) {
        $wr = (int) $wr;
        if ($wr >= 60) return 'good';
        if ($wr >= 50) return 'even';
        if ($wr >= 40) return 'tough';
        return 'bad';
    }

    public static function wr_verdict($wr) {
        $wr = (int) $wr;
        if ($wr >= 65) return 'Очень выгодный';
        if ($wr >= 55) return 'Выгодный';
        if ($wr >= 45) return 'Равный';
        if ($wr >= 35) return 'Сложный';
        return 'Очень сложный';
    }

    /**
     * [hs_decklist code="..." date="..." dust="..." title="..."]
     * Рендерит «карточку колоды»: список карт в две колонки (классовые/общие),
     * стоимость маны, миниатюра, кол-во копий, цветная полоса по редкости,
     * кнопка «Копировать».
     */
    public function shortcode_decklist($atts) {
        $atts = shortcode_atts(array(
            'code'  => '',
            'date'  => '',
            'dust'  => '',
            'title' => '',
        ), $atts, 'hs_decklist');

        $code = trim((string) $atts['code']);
        if ($code === '') return '';

        try {
            $deck = HS_Mulligan_Deckstring::decode($code);
        } catch (Exception $e) {
            return '<div class="hs-decklist-error">Ошибка декодирования колоды.</div>';
        }

        $cards_db   = HS_Mulligan_Card_Data::get_index();
        $hero_dbf   = isset($deck['heroes'][0]) ? (int) $deck['heroes'][0] : 0;
        $hero_info  = self::resolve_hero($hero_dbf, $cards_db);
        $hero_class = $hero_info && !empty($hero_info['class']) ? strtoupper($hero_info['class']) : '';
        $class_slug = strtolower($hero_class);
        $class_icon = (in_array($class_slug, array_keys(self::class_list()), true))
            ? HS_MULLIGAN_URL . 'assets/class-icons/' . $class_slug . '.png'
            : '';

        // Стоимость в пыли по редкости (стандартная крафт-цена).
        $dust_by_rarity = array(
            'common'    => 40,
            'free'      => 0,
            'rare'      => 100,
            'epic'      => 400,
            'legendary' => 1600,
        );

        $class_cards = array();
        $neutral_cards = array();
        $auto_dust = 0;
        foreach ($deck['cards'] as $row) {
            list($dbf, $count) = $row;
            $info = isset($cards_db[$dbf]) ? $cards_db[$dbf] : null;
            $card_class = $info && !empty($info['class']) ? strtoupper($info['class']) : 'NEUTRAL';
            $rarity = $info && !empty($info['rarity']) ? strtolower($info['rarity']) : 'common';
            $cid = $info && !empty($info['cardId']) ? $info['cardId'] : '';
            // Полноразмерный портрет 512×512 (без рамки, JPG, чёткий) — для арт-стрипа.
            $art = $cid ? 'https://art.hearthstonejson.com/v1/512x/' . $cid . '.jpg' : '';
            // Рендер карты целиком — для тултипа на ховере.
            $big = $info && !empty($info['imageBig'])
                ? $info['imageBig']
                : ($cid ? 'https://art.hearthstonejson.com/v1/render/latest/ruRU/512x/' . $cid . '.png' : '');

            $entry = array(
                'name'    => $info ? $info['name'] : ('dbfId ' . $dbf),
                'cost'    => $info ? (int) $info['cost'] : 0,
                'tile'    => $art,
                'imageBig'=> $big,
                'rarity'  => $rarity,
                'count'   => (int) $count,
            );
            if (isset($dust_by_rarity[$rarity])) {
                $auto_dust += $dust_by_rarity[$rarity] * (int) $count;
            }
            if ($card_class === 'NEUTRAL') {
                $neutral_cards[] = $entry;
            } else {
                $class_cards[] = $entry;
            }
        }

        $sort = function ($a, $b) {
            if ($a['cost'] !== $b['cost']) return $a['cost'] - $b['cost'];
            return strcmp((string) $a['name'], (string) $b['name']);
        };
        usort($class_cards, $sort);
        usort($neutral_cards, $sort);

        $sum = function ($list) {
            $n = 0; foreach ($list as $c) $n += $c['count']; return $n;
        };
        $class_total   = $sum($class_cards);
        $neutral_total = $sum($neutral_cards);
        $deck_total    = $class_total + $neutral_total;

        wp_enqueue_style('hs-decklist-frontend');
        wp_enqueue_script('hs-decklist-frontend');

        $render_card = function ($c) {
            $art = !empty($c['tile']) ? $c['tile'] : '';
            $big = !empty($c['imageBig']) ? $c['imageBig'] : $art;
            ob_start(); ?>
            <div class="hs-decklist-card hs-decklist-rarity-<?php echo esc_attr($c['rarity']); ?>"
                 data-img-big="<?php echo esc_attr($big); ?>"
                 data-name="<?php echo esc_attr($c['name']); ?>">
                <?php if ($art): ?>
                    <img class="hs-decklist-art no-lightbox nolightbox skip-lightbox"
                         data-no-lightbox="1" data-fancybox="false" data-amp-lightbox="false"
                         loading="lazy" alt="" src="<?php echo esc_url($art); ?>">
                <?php endif; ?>
                <div class="hs-decklist-cost"><?php echo (int) $c['cost']; ?></div>
                <div class="hs-decklist-name"><?php echo esc_html($c['name']); ?></div>
                <div class="hs-decklist-count">×<?php echo (int) $c['count']; ?></div>
            </div>
            <?php
            return ob_get_clean();
        };

        // Автоматическая пыль если автор не указал свою.
        $dust_display = trim((string) $atts['dust']);
        if ($dust_display === '' && $auto_dust > 0) $dust_display = number_format($auto_dust, 0, '.', ' ');

        ob_start();
        ?>
        <div class="hs-decklist hs-decklist-class-<?php echo esc_attr($class_slug); ?>">
            <?php if (!empty($atts['title']) || !empty($atts['date'])): ?>
                <div class="hs-decklist-head">
                    <span class="hs-decklist-title"><?php echo esc_html(!empty($atts['title']) ? $atts['title'] : $atts['date']); ?></span>
                </div>
            <?php endif; ?>

            <div class="hs-decklist-cols">
                <?php if (!empty($class_cards)): ?>
                    <div class="hs-decklist-col">
                        <div class="hs-decklist-col-head">Классовые (<?php echo (int) $class_total; ?>)</div>
                        <div class="hs-decklist-cards">
                            <?php foreach ($class_cards as $c) echo $render_card($c); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($neutral_cards)): ?>
                    <div class="hs-decklist-col">
                        <div class="hs-decklist-col-head">Общие (<?php echo (int) $neutral_total; ?>)</div>
                        <div class="hs-decklist-cards">
                            <?php foreach ($neutral_cards as $c) echo $render_card($c); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="hs-decklist-bottom">
                <button type="button" class="hs-decklist-copy" data-code="<?php echo esc_attr($code); ?>">
                    <span class="hs-decklist-copy-text">Копировать код колоды</span>
                </button>

                <?php
                $hash = substr(md5($code), 0, 12);
                $rating = (array) get_option('hs_decklist_rating_' . $hash, array('sum' => 0, 'count' => 0));
                $r_count = (int) (isset($rating['count']) ? $rating['count'] : 0);
                $r_avg   = $r_count > 0 ? round($rating['sum'] / $r_count, 1) : 0;
                ?>
                <div class="hs-decklist-rating" data-hash="<?php echo esc_attr($hash); ?>"
                     data-avg="<?php echo esc_attr($r_avg); ?>"
                     data-count="<?php echo esc_attr($r_count); ?>">
                    <span class="hs-decklist-rate-label">Оцените колоду:</span>
                    <span class="hs-decklist-stars" role="radiogroup" aria-label="Оценка колоды">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" class="hs-decklist-star<?php echo ($i <= round($r_avg)) ? ' is-on' : ''; ?>"
                                    data-value="<?php echo (int) $i; ?>"
                                    aria-label="<?php echo (int) $i; ?> из 5">★</button>
                        <?php endfor; ?>
                    </span>
                    <span class="hs-decklist-rate-summary">
                        <strong class="hs-decklist-rate-avg"><?php echo $r_count > 0 ? esc_html(number_format($r_avg, 1, '.', '')) : '—'; ?></strong>
                        <span class="hs-decklist-rate-count">(<span class="hs-decklist-rate-n"><?php echo (int) $r_count; ?></span> оценок)</span>
                    </span>
                </div>
            </div>
        </div>
        <?php
        // wpautop оборачивает любой текстовый узел между тегами (включая просто \n)
        // в <p>, что ломает flex-сетку внутри [spoiler]/[tabs]/[accordion]. Минифицируем
        // межтеговые пробелы и переносы — единая «строка» HTML wpautop не трогает.
        $html = trim(ob_get_clean());
        $html = preg_replace('/>\s+</', '><', $html);
        return $html;
    }

    public function shortcode($atts) {
        $atts = shortcode_atts(array('id' => 0), $atts, 'hs_mulligan');
        $id = intval($atts['id']);
        if (!$id) return '';
        $post = get_post($id);
        if (!$post || $post->post_type !== 'hs_mulligan' || $post->post_status !== 'publish') return '';

        // Object cache: ключ зависит от поста и версии плагина.
        // post_modified пересчитывается, когда автор сохранил изменения.
        $modified  = get_post_modified_time('U', true, $id);
        $cache_key = 'hsm_payload_' . $id . '_' . $modified . '_' . HS_MULLIGAN_VERSION;
        $cached    = wp_cache_get($cache_key, 'hs_mulligan');

        if (is_array($cached)) {
            $payload        = $cached['payload'];
            $class_icon_url = $cached['icon'];
        } else {
            $code     = (string) get_post_meta($id, '_hs_mulligan_deck_code', true);
            $profiles = self::load_profiles($id);

            try {
                $deck = HS_Mulligan_Deckstring::decode($code);
            } catch (Exception $e) {
                return '<div class="hs-mulligan-error">Ошибка декодирования колоды.</div>';
            }

            $cards_db    = HS_Mulligan_Card_Data::get_index();
            $cards_dict  = array();
            $pool_pairs  = array();
            foreach ($deck['cards'] as $row) {
                list($dbf_id, $count) = $row;
                $dbf_id = (int) $dbf_id;
                $count  = (int) $count;
                if (!isset($cards_dict[$dbf_id])) {
                    $info = isset($cards_db[$dbf_id]) ? $cards_db[$dbf_id] : null;
                    $cards_dict[$dbf_id] = array(
                        'n' => $info ? $info['name'] : ('dbfId ' . $dbf_id),
                        'c' => $info ? (int) $info['cost'] : 0,
                        'i' => $info ? (string) $info['image'] : '',
                        'b' => $info ? (string) $info['imageBig'] : '',  // 512x для тултипа
                        't' => $info ? (string) $info['text'] : '',
                        'r' => $info ? (string) $info['rarity'] : '',
                        'y' => $info ? (string) $info['type'] : '',
                    );
                }
                $pool_pairs[] = array($dbf_id, $count);
            }

            // Иконка класса по герою из deckstring.
            $hero_dbf  = isset($deck['heroes'][0]) ? (int) $deck['heroes'][0] : 0;
            $hero_info = self::resolve_hero($hero_dbf, $cards_db);
            $class_key = $hero_info && !empty($hero_info['class']) ? strtolower($hero_info['class']) : '';
            $class_slug = in_array($class_key, array_keys(self::class_list()), true) ? $class_key : '';
            $class_icon_url = $class_slug ? HS_MULLIGAN_URL . 'assets/class-icons/' . $class_slug . '.png' : '';

            // Компактные профили: ['keep'=>0|1|2,'note'=>'…'] → [0|1|2, '…'].
            $profiles_compact = array();
            foreach ($profiles as $p) {
                $cards_compact = array();
                foreach ($p['cards'] as $dbf => $entry) {
                    $cards_compact[(int) $dbf] = array(
                        isset($entry['keep']) ? (int) $entry['keep'] : 0,
                        isset($entry['note']) ? (string) $entry['note'] : '',
                    );
                }
                $profiles_compact[] = array(
                    'id'    => $p['id'],
                    'label' => $p['label'],
                    'cards' => $cards_compact,
                );
            }

            $payload = array(
                'title'    => get_the_title($id),
                'cards'    => $cards_dict,
                'pool'     => $pool_pairs,
                'profiles' => $profiles_compact,
            );

            wp_cache_set($cache_key, array(
                'payload' => $payload,
                'icon'    => $class_icon_url,
            ), 'hs_mulligan', HOUR_IN_SECONDS);
        }

        wp_enqueue_script('hs-mulligan-frontend');
        wp_enqueue_style('hs-mulligan-frontend');

        $uid       = 'hsm-' . $id . '-' . wp_generate_password(6, false, false);
        $board_url = HS_MULLIGAN_URL . 'assets/board.jpg';

        // Статистика для отображения «прошли N раз, средний балл X%».
        $stats = (array) get_option('hs_mulligan_stats_' . $id, array());
        $stats_views  = isset($stats['views']) ? (int) $stats['views'] : 0;
        $stats_checks = isset($stats['checks']) ? (int) $stats['checks'] : 0;
        $stats_avg    = (!empty($stats['score_n']) && $stats['score_n'] > 0)
            ? (int) round($stats['score_sum'] / $stats['score_n'])
            : 0;

        ob_start();
        ?>
        <div class="hs-mulligan-app" id="<?php echo esc_attr($uid); ?>"
             data-id="<?php echo (int) $id; ?>"
             data-payload="<?php echo esc_attr(wp_json_encode($payload)); ?>"
             data-cross="<?php echo esc_attr(HS_MULLIGAN_URL . 'assets/krest.png'); ?>"
             style="--hsm-board:url('<?php echo esc_url($board_url); ?>');">
            <div class="hs-mulligan-header">
                <h3>
                    <?php if ($class_icon_url): ?>
                        <img class="hs-mulligan-class-icon no-lightbox nolightbox" data-no-lightbox="1" data-fancybox="false" src="<?php echo esc_url($class_icon_url); ?>" alt="">
                    <?php endif; ?>
                    <span class="hs-mulligan-title-text"><?php echo esc_html($payload['title']); ?></span>
                </h3>
                <p class="hs-mulligan-hint">Кликните по картам, которые вы хотите <em>заменить</em> при муллигане. Невыбранные останутся в руке. Затем нажмите «Проверить».</p>
            </div>
            <div class="hs-mulligan-opponent-bar">
                <div class="hs-mulligan-opponent-row">
                    <span class="hs-mulligan-opponent-label">Оппонент</span>
                    <div class="hs-mulligan-opponent-chips" role="group"></div>
                </div>
                <div class="hs-mulligan-coin-row">
                    <span class="hs-mulligan-coin-label">Ход</span>
                    <div class="hs-mulligan-coin-chips" role="group">
                        <button type="button" class="hs-mulligan-coin-chip is-active" data-coin="random">🎲 Случайно</button>
                        <button type="button" class="hs-mulligan-coin-chip" data-coin="first">Иду первым (3 карты)</button>
                        <button type="button" class="hs-mulligan-coin-chip" data-coin="second">Иду вторым (4 карты + монета)</button>
                    </div>
                </div>
                <div class="hs-mulligan-opponent-current">
                    <span class="hs-mulligan-vs-label">Играем</span>
                    <span class="hs-mulligan-vs-name"></span>
                    <span class="hs-mulligan-coin-now"></span>
                </div>
            </div>
            <div class="hs-mulligan-cards"></div>
            <div class="hs-mulligan-actions">
                <button type="button" class="hs-mulligan-check">Проверить</button>
                <button type="button" class="hs-mulligan-reset">Новая раздача</button>
            </div>
            <div class="hs-mulligan-result" role="status" aria-live="polite" hidden></div>
            <?php if ($stats_views > 0 || $stats_checks > 0): ?>
                <div class="hs-mulligan-stats">
                    Этот тренажёр прошли <strong><?php echo (int) $stats_checks; ?></strong>
                    раз<?php if ($stats_avg > 0): ?>, средний балл <strong><?php echo (int) $stats_avg; ?>%</strong><?php endif; ?>.
                </div>
            <?php endif; ?>
        </div>
        <?php
        $html = trim(ob_get_clean());
        return preg_replace('/>\s+</', '><', $html);
    }
}

new HS_Mulligan_Plugin();

register_activation_hook(__FILE__, array('HS_Mulligan_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('HS_Mulligan_Plugin', 'deactivate'));
