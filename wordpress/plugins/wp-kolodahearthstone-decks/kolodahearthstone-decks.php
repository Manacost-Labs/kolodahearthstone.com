<?php
/*
Plugin Name: KolodaHeartstone: Decks
Description: Управление колодами Hearthstone с шорткодами для отображения
Version: 2.8.2
Author: Manacost
*/

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-hs-inline-deck-link.php';

class KolodaHearthstone_Decks {

    private $head_buffer_started = false;
    private static $settings_cache = null;
    private $assets_needed = null;

    public function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
        add_filter('manage_hs_deck_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_hs_deck_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_shortcode('hs_deck', array($this, 'single_deck_shortcode'));
        add_shortcode('hs_decks', array($this, 'decks_list_shortcode'));
        add_action('wp_head', array($this, 'maybe_output_styles'));
        add_action('wp_footer', array($this, 'maybe_output_scripts'));
        add_action('update_option_hs_deck_settings', array(__CLASS__, 'flush_settings_cache'));
        add_action('add_option_hs_deck_settings', array(__CLASS__, 'flush_settings_cache'));
        add_action('admin_init', array($this, 'add_tinymce_button'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_hs_create_deck', array($this, 'ajax_create_deck'));
        add_action('wp_ajax_hs_search_decks', array($this, 'ajax_search_decks'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_header_image_meta_box'));
        add_action('save_post', array($this, 'save_header_image_meta_box'));
        add_action('template_redirect', array($this, 'maybe_swap_hero_thumbnail'));
        add_action('wp_head', array($this, 'output_og_tags'), 1);
        // Глушим встроенный OG-вывод Blocksy / Blocksy Companion, если он есть
        add_action('init', array($this, 'disable_theme_og_output'), 99);
        add_action('template_redirect', array($this, 'maybe_inject_mode_badge'));
        add_filter('the_content', array($this, 'prepend_post_stats'), 5);
        add_action('wp_head', array($this, 'output_post_stats_styles'), 20);
        add_action('wp_ajax_hs_count_view', array($this, 'ajax_count_view'));
        add_action('wp_ajax_nopriv_hs_count_view', array($this, 'ajax_count_view'));
        add_filter('manage_post_posts_columns', array($this, 'add_views_column'));
        add_action('manage_post_posts_custom_column', array($this, 'render_views_column'), 10, 2);
        add_filter('manage_edit-post_sortable_columns', array($this, 'make_views_column_sortable'));
        add_action('pre_get_posts', array($this, 'sort_by_views'));
    }

    /**
     * AJAX-инкремент счётчика просмотров.
     * Cache-safe: запрос идёт на admin-ajax.php (никогда не кешируется), даже если страница сама в кеше.
     * Защита от спама: throttle по IP+пост на 10 секунд через transient.
     */
    public function ajax_count_view() {
        check_ajax_referer('hs_count_view', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) wp_send_json_error('bad_id');

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') wp_send_json_error('not_found');
        if (!in_array($post->post_type, array('post', 'page', 'hs_deck'), true)) wp_send_json_error('bad_type');

        // Не считаем админов и редакторов
        if (current_user_can('edit_posts')) wp_send_json_success(array('views' => (int) get_post_meta($post_id, '_hs_post_views', true), 'counted' => false));

        // Боты по User-Agent
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|telegram|whatsapp|preview|scrape|headless|phantom/i', $ua)) {
            wp_send_json_success(array('views' => (int) get_post_meta($post_id, '_hs_post_views', true), 'counted' => false));
        }

        // Throttle: одна и та же связка IP+post не накручивает чаще чем раз в 10 секунд
        $ip = $this->get_client_ip();
        $throttle_key = 'hs_v_' . $post_id . '_' . md5($ip);
        if (get_transient($throttle_key)) {
            wp_send_json_success(array('views' => (int) get_post_meta($post_id, '_hs_post_views', true), 'counted' => false));
        }
        set_transient($throttle_key, 1, 10);

        $views = (int) get_post_meta($post_id, '_hs_post_views', true) + 1;
        update_post_meta($post_id, '_hs_post_views', $views);

        wp_send_json_success(array('views' => $views, 'counted' => true));
    }

    /**
     * Достаём реальный IP даже за CDN/прокси (Cloudflare, X-Forwarded-For)
     */
    private function get_client_ip() {
        $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) continue;
            $val = (string) $_SERVER[$key];
            // X-Forwarded-For может содержать несколько IP через запятую — берём первый
            if (strpos($val, ',') !== false) $val = trim(explode(',', $val)[0]);
            $val = filter_var($val, FILTER_VALIDATE_IP);
            if ($val) return $val;
        }
        return '0.0.0.0';
    }

    /**
     * Колонка «Просмотры» в списке записей админки
     */
    public function add_views_column($columns) {
        $new = array();
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') $new['hs_views'] = 'Просмотры';
        }
        return $new;
    }

    public function render_views_column($column, $post_id) {
        if ($column !== 'hs_views') return;
        $views = (int) get_post_meta($post_id, '_hs_post_views', true);
        echo '<strong>' . esc_html(number_format_i18n($views)) . '</strong>';
    }

    public function make_views_column_sortable($columns) {
        $columns['hs_views'] = 'hs_views';
        return $columns;
    }

    public function sort_by_views($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('orderby') === 'hs_views') {
            $query->set('meta_key', '_hs_post_views');
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Лёгкий inline-CSS для блока статистики — только на singular post/page
     */
    public function output_post_stats_styles() {
        if (!is_singular(array('post', 'page'))) return;
        ?>
<style id="hs-post-stats-styles">
.hs-post-stats-wrap{display:block;width:100%;margin:0 0 24px;text-align:center}
.hs-post-stats{display:inline-flex !important;flex:0 0 auto;align-self:flex-start;flex-wrap:wrap;align-items:center;gap:6px;margin:0;padding:8px 16px;width:fit-content !important;width:-moz-fit-content !important;max-width:100%;background:linear-gradient(135deg,rgba(139,90,60,0.10),rgba(139,117,95,0.16));border:1px solid rgba(139,117,95,0.22);border-radius:999px;font-size:13px;font-weight:600;color:#5a4632;font-family:inherit;line-height:1;box-shadow:0 1px 3px rgba(45,27,14,0.06);box-sizing:border-box;vertical-align:top}
.hs-post-stats .hs-stat{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;line-height:1;letter-spacing:0.01em;white-space:nowrap}
.hs-post-stats .hs-stat + .hs-stat{border-left:1px solid rgba(139,117,95,0.30)}
.hs-post-stats .hs-stat-ico{display:inline-flex;align-items:center;justify-content:center;color:#8b5a3c;flex:0 0 auto}
.hs-post-stats .hs-stat-ico svg{display:block}
.hs-post-stats .hs-stat-views{color:#8b5a3c}
.hs-post-stats .hs-stat-time{color:#6b4a2c}
.hs-post-stats .hs-stat-date{color:#5a4632}
@media (max-width:560px){
  .hs-post-stats{font-size:12px;padding:6px 12px;gap:4px}
  .hs-post-stats .hs-stat{padding:2px 8px}
  .hs-post-stats .hs-stat svg{width:14px;height:14px}
}
</style>
        <?php
    }

    /**
     * Подсчёт времени чтения в минутах (200 слов/мин — средний для рус/eng)
     */
    public static function calc_reading_time($content) {
        $text = wp_strip_all_tags(strip_shortcodes($content));
        $words = preg_match_all('/\S+/u', $text);
        $base = $words ? (int) ceil($words / 200) : 1;
        // Учитываем время на разбор скриншотов, кодов колод, ссылок и т.п.
        return max(1, $base + 8);
    }

    /**
     * Склонение русских числительных: 1 минута / 2 минуты / 5 минут
     */
    public static function ru_plural($n, $forms) {
        $n = abs((int) $n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 === 1) return $forms[0];
        return $forms[2];
    }

    /**
     * Вставка блока статистики в начало контента статьи
     */
    public function prepend_post_stats($content) {
        if (!is_singular(array('post', 'page')) || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_the_ID();
        if (!$post_id) return $content;

        $views = (int) get_post_meta($post_id, '_hs_post_views', true);
        $minutes = self::calc_reading_time($content);
        $date = get_the_date('j F Y', $post_id);

        $views_label = number_format_i18n($views) . ' ' . self::ru_plural($views, array('просмотр', 'просмотра', 'просмотров'));
        $time_label  = $minutes . ' ' . self::ru_plural($minutes, array('минута', 'минуты', 'минут')) . ' чтения';

        $eye = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        $clock = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        $cal = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';

        $stats  = '<div class="hs-post-stats-wrap">';
        $stats .= '<div class="hs-post-stats" role="group" aria-label="Информация о статье" data-hs-post-id="' . esc_attr($post_id) . '">';
        $stats .= '<span class="hs-stat hs-stat-date"><span class="hs-stat-ico">' . $cal . '</span><span>' . esc_html($date) . '</span></span>';
        $stats .= '<span class="hs-stat hs-stat-time"><span class="hs-stat-ico">' . $clock . '</span><span>' . esc_html($time_label) . '</span></span>';
        $stats .= '<span class="hs-stat hs-stat-views"><span class="hs-stat-ico">' . $eye . '</span><span class="hs-views-num" data-base="' . esc_attr($views) . '">' . esc_html($views_label) . '</span></span>';
        $stats .= '</div>';
        $stats .= '</div>';

        // Cache-safe AJAX-инкремент: даже если HTML взят из кеша, JS дёрнет некешируемый admin-ajax.php
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('hs_count_view');
        $cfg = wp_json_encode(array(
            'ajax'    => $ajax_url,
            'nonce'   => $nonce,
            'postId'  => (int) $post_id,
            'plural'  => array('просмотр', 'просмотра', 'просмотров'),
        ));
        $stats .= '<script>(function(){' .
            'var cfg=' . $cfg . ';' .
            'function plural(n,f){var a=Math.abs(n)%100,b=a%10;if(a>10&&a<20)return f[2];if(b>1&&b<5)return f[1];if(b===1)return f[0];return f[2];}' .
            'function fmt(n){return n.toLocaleString("ru-RU");}' .
            'function send(){' .
                'try{' .
                    'var fd=new FormData();fd.append("action","hs_count_view");fd.append("nonce",cfg.nonce);fd.append("post_id",cfg.postId);' .
                    'fetch(cfg.ajax,{method:"POST",credentials:"same-origin",body:fd})' .
                    '.then(function(r){return r.ok?r.json():null;})' .
                    '.then(function(j){' .
                        'if(!j||!j.success||!j.data)return;' .
                        'var el=document.querySelector(".hs-post-stats[data-hs-post-id=\\"" + cfg.postId + "\\"] .hs-views-num");' .
                        'if(!el)return;' .
                        'var v=parseInt(j.data.views,10)||0;' .
                        'el.textContent=fmt(v)+" "+plural(v,cfg.plural);' .
                    '}).catch(function(){});' .
                '}catch(e){}' .
            '}' .
            'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",send);}else{send();}' .
        '})();</script>';

        return $stats . $content;
    }

    /**
     * Бейдж режима под заголовком в hero-секции.
     * Через Blocksy-хуки, плюс JS-фолбэк для тем без этих хуков.
     */
    public function maybe_inject_mode_badge() {
        if (!is_singular()) return;
        $post_id = get_queried_object_id();
        if (!$post_id) return;
        $mode = (string) get_post_meta($post_id, '_hs_mode', true);
        if (!$mode) return;
        $modes = self::get_hs_modes();
        if (!isset($modes[$mode]) || $mode === '') return;

        $label = $modes[$mode];
        $html = '<div class="hs-mode-badge hs-mode-' . esc_attr($mode) . '">' . esc_html($label) . '</div>';

        $printed = false;
        foreach (array('blocksy:hero:title:after', 'blocksy:single:hero:title:after') as $hook) {
            add_action($hook, function() use ($html, &$printed) {
                echo $html;
                $printed = true;
            }, 20);
        }

        // Фолбэк: если Blocksy-хуки не сработали — вставляем через JS после заголовка hero
        add_action('wp_footer', function() use ($html) {
            ?>
            <script>
            (function() {
                if (document.querySelector('.hs-mode-badge')) return;
                var sel = '.ct-hero-section .entry-title, .hero-section .entry-title, .page-title h1, .entry-header .entry-title, h1.entry-title';
                var title = document.querySelector(sel);
                if (!title) return;
                var wrap = document.createElement('div');
                wrap.innerHTML = <?php echo wp_json_encode($html); ?>;
                title.parentNode.insertBefore(wrap.firstChild, title.nextSibling);
            })();
            </script>
            <?php
        }, 5);
    }

    /**
     * Отключаем встроенный OG-вывод Blocksy и Blocksy Companion, чтобы не было дублей и приоритет был у нас
     */
    public function disable_theme_og_output() {
        // Blocksy Companion: meta tags via various function names depending on version
        $candidates = array(
            'blocksy_open_graph_meta',
            'blc_render_open_graph_metas',
            'blocksy_render_open_graph_metas',
            'blocksy_companion_open_graph',
        );
        foreach ($candidates as $fn) {
            if (function_exists($fn)) {
                remove_action('wp_head', $fn, 1);
                remove_action('wp_head', $fn, 2);
                remove_action('wp_head', $fn, 5);
                remove_action('wp_head', $fn, 10);
            }
        }
    }

    /**
     * Open Graph / Twitter Card мета-теги для предпросмотра в соцсетях (Telegram, VK, FB и т.д.)
     */
    public function output_og_tags() {
        if (!is_singular()) return;
        if (defined('WPSEO_VERSION') || class_exists('RankMath') || defined('SEOPRESS_VERSION') || function_exists('aioseo')) {
            return;
        }

        $post = get_queried_object();
        if (!$post || empty($post->ID)) return;

        // Картинка: «шапка статьи» → обложка → первая <img> в контенте → custom logo → site icon
        $image_url = '';
        $image_w = 0;
        $image_h = 0;
        $image_mime = '';
        $image_alt = '';
        $image_source = '';
        $image_id = 0;

        // Только реальные картинки статьи: «Шапка статьи» и Featured Image.
        // Логотип/site_icon НЕ используем — это даёт 512×512 favicon вместо обложки в превью соцсетей.
        $candidates = array();
        $hdr_id = (int) get_post_meta($post->ID, '_hs_header_image_id', true);
        if ($hdr_id) $candidates[] = array('id' => $hdr_id, 'src' => 'header_image');
        $thumb_id = (int) get_post_thumbnail_id($post->ID);
        if ($thumb_id) $candidates[] = array('id' => $thumb_id, 'src' => 'featured');

        foreach ($candidates as $cand) {
            $img = wp_get_attachment_image_src($cand['id'], 'full');
            if (!$img) $img = wp_get_attachment_image_src($cand['id'], 'large');
            if ($img) {
                $image_url = $img[0];
                $image_w = (int) $img[1];
                $image_h = (int) $img[2];
                $image_mime = (string) get_post_mime_type($cand['id']);
                $image_alt = (string) get_post_meta($cand['id'], '_wp_attachment_image_alt', true);
                $image_id = (int) $cand['id'];
                $image_source = $cand['src'];
                break;
            }
        }

        // Фолбэк: первая <img> из контента
        if (!$image_url && !empty($post->post_content)) {
            if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $m)) {
                $image_url = $m[1];
                $image_source = 'content_first_img';
            }
        }

        // Принудительно HTTPS
        if (is_ssl() && $image_url && strpos($image_url, 'http://') === 0) {
            $image_url = 'https://' . substr($image_url, 7);
        }

        $title = wp_strip_all_tags(get_the_title($post));
        $url = get_permalink($post);
        $site_name = get_bloginfo('name');

        $excerpt = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 40, '…');
        $excerpt = wp_strip_all_tags($excerpt);

        $locale = get_locale();
        $published = mysql2date('c', $post->post_date_gmt, false);
        $modified  = mysql2date('c', $post->post_modified_gmt, false);

        $diag = 'image_source=' . ($image_source ?: 'NONE_NEED_FEATURED_IMAGE') . ' | image_id=' . (int) $image_id . ' | image_size=' . ($image_w ? ($image_w . 'x' . $image_h) : 'unknown') . ' | post_id=' . (int) $post->ID;
        echo "\n<!-- KolodaHS OG tags v2.7 | " . esc_html($diag) . " -->\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:locale" content="' . esc_attr(str_replace('-', '_', $locale)) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($excerpt) {
            echo '<meta property="og:description" content="' . esc_attr($excerpt) . '">' . "\n";
            echo '<meta name="description" content="' . esc_attr($excerpt) . '">' . "\n";
        }
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        if ($image_url) {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . "\n";
            echo '<meta property="og:image:secure_url" content="' . esc_url($image_url) . '">' . "\n";
            if ($image_mime) echo '<meta property="og:image:type" content="' . esc_attr($image_mime) . '">' . "\n";
            if ($image_w) echo '<meta property="og:image:width" content="' . (int) $image_w . '">' . "\n";
            if ($image_h) echo '<meta property="og:image:height" content="' . (int) $image_h . '">' . "\n";
            if (!$image_alt) $image_alt = $title;
            echo '<meta property="og:image:alt" content="' . esc_attr($image_alt) . '">' . "\n";
        }
        echo '<meta property="article:published_time" content="' . esc_attr($published) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr($modified) . '">' . "\n";

        echo '<meta name="twitter:card" content="' . ($image_url ? 'summary_large_image' : 'summary') . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        if ($excerpt) echo '<meta name="twitter:description" content="' . esc_attr($excerpt) . '">' . "\n";
        if ($image_url) echo '<meta name="twitter:image" content="' . esc_url($image_url) . '">' . "\n";
        echo "<!-- /KolodaHS OG tags -->\n";
    }

    /**
     * Мета-бокс «Шапка статьи» — отдельное изображение, не зависящее от обложки
     */
    public function add_header_image_meta_box() {
        $screens = array('post', 'page');
        $custom_types = get_post_types(array('public' => true, '_builtin' => false), 'names');
        foreach ($custom_types as $pt) {
            if ($pt === 'hs_deck') continue;
            $screens[] = $pt;
        }
        foreach ($screens as $screen) {
            add_meta_box(
                'hs_header_image',
                'Шапка статьи (header image)',
                array($this, 'render_header_image_meta_box'),
                $screen,
                'side',
                'low'
            );
        }
    }

    public static function get_hs_modes() {
        return array(
            ''              => '— Не указан —',
            'standard'      => 'Стандартный формат',
            'wild'          => 'Вольный формат',
            'battlegrounds' => 'Поля Сражений',
            'arena'         => 'Арена',
        );
    }

    public function render_header_image_meta_box($post) {
        wp_nonce_field('hs_header_image_meta_box', 'hs_header_image_meta_box_nonce');
        $image_id = (int) get_post_meta($post->ID, '_hs_header_image_id', true);
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        $mode = (string) get_post_meta($post->ID, '_hs_mode', true);
        $modes = self::get_hs_modes();
        ?>
        <div class="hs-header-image-wrap">
            <p class="description" style="margin:0 0 8px;">Это изображение будет показано как баннер вверху статьи. Не зависит от стандартной обложки.</p>
            <div class="hs-header-image-preview" style="margin-bottom:10px; <?php echo $image_url ? '' : 'display:none;'; ?>">
                <img src="<?php echo esc_url($image_url); ?>" style="max-width:100%; height:auto; border:1px solid #ddd; border-radius:4px;">
            </div>
            <input type="hidden" name="hs_header_image_id" id="hs_header_image_id" value="<?php echo esc_attr($image_id); ?>">
            <p>
                <button type="button" class="button hs-header-image-select"><?php echo $image_id ? 'Заменить' : 'Выбрать изображение'; ?></button>
                <button type="button" class="button hs-header-image-remove" style="<?php echo $image_id ? '' : 'display:none;'; ?>">Удалить</button>
            </p>
            <p style="margin-top:14px;">
                <label for="hs_mode" style="display:block; font-weight:600; margin-bottom:4px;">Режим</label>
                <select name="hs_mode" id="hs_mode" style="width:100%;">
                    <?php foreach ($modes as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($mode, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="description" style="display:block; margin-top:4px;">Бейдж режима будет показан под заголовком в шапке статьи.</span>
            </p>
        </div>
        <script>
        (function($) {
            $(function() {
                var $wrap = $('.hs-header-image-wrap');
                var $input = $wrap.find('#hs_header_image_id');
                var $preview = $wrap.find('.hs-header-image-preview');
                var $img = $preview.find('img');
                var $select = $wrap.find('.hs-header-image-select');
                var $remove = $wrap.find('.hs-header-image-remove');
                var frame;
                $select.on('click', function(e) {
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: 'Выберите изображение для шапки',
                        button: { text: 'Использовать это изображение' },
                        multiple: false,
                        library: { type: 'image' }
                    });
                    frame.on('select', function() {
                        var att = frame.state().get('selection').first().toJSON();
                        var url = att.sizes && att.sizes.large ? att.sizes.large.url : att.url;
                        $input.val(att.id);
                        $img.attr('src', url);
                        $preview.show();
                        $select.text('Заменить');
                        $remove.show();
                    });
                    frame.open();
                });
                $remove.on('click', function(e) {
                    e.preventDefault();
                    $input.val('');
                    $img.attr('src', '');
                    $preview.hide();
                    $select.text('Выбрать изображение');
                    $remove.hide();
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function save_header_image_meta_box($post_id) {
        if (!isset($_POST['hs_header_image_meta_box_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hs_header_image_meta_box_nonce'])), 'hs_header_image_meta_box')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $id = isset($_POST['hs_header_image_id']) ? intval($_POST['hs_header_image_id']) : 0;
        // Валидация: должен существовать как attachment
        if ($id > 0) {
            $att = get_post($id);
            if (!$att || $att->post_type !== 'attachment') $id = 0;
        }
        if ($id > 0) {
            update_post_meta($post_id, '_hs_header_image_id', $id);
        } else {
            delete_post_meta($post_id, '_hs_header_image_id');
        }
        $mode = isset($_POST['hs_mode']) ? sanitize_key(wp_unslash($_POST['hs_mode'])) : '';
        $allowed_modes = array_keys(self::get_hs_modes());
        if ($mode && in_array($mode, $allowed_modes, true)) {
            update_post_meta($post_id, '_hs_mode', $mode);
        } else {
            delete_post_meta($post_id, '_hs_mode');
        }
    }

    /**
     * Подмена миниатюры на «шапку статьи» только во время рендера hero-секции Blocksy.
     * Работает универсально через get_post_metadata: фильтр ставится перед hero и снимается после.
     */
    public function maybe_swap_hero_thumbnail() {
        if (!is_singular()) return;
        $post_id = get_queried_object_id();
        if (!$post_id) return;
        $header_id = (int) get_post_meta($post_id, '_hs_header_image_id', true);
        if (!$header_id) return;

        $filter = function($value, $object_id, $meta_key, $single) use ($post_id, $header_id) {
            if ($meta_key !== '_thumbnail_id' || (int) $object_id !== (int) $post_id) return $value;
            return $single ? $header_id : array($header_id);
        };

        // Один общий флаг — фильтр живёт максимум один раз: либо через Blocksy-хуки, либо через fallback
        $state = (object) array('active' => false, 'blocksy_handled' => false);

        $add = function() use ($filter, $state) {
            if ($state->active) return;
            add_filter('get_post_metadata', $filter, 10, 4);
            $state->active = true;
        };
        $remove = function() use ($filter, $state) {
            if (!$state->active) return;
            remove_filter('get_post_metadata', $filter, 10);
            $state->active = false;
        };

        // Известные Blocksy-хуки hero-секции
        $hero_before_hooks = array('blocksy:single:hero:before', 'blocksy:hero:section:before', 'blocksy:hero:title:before');
        $hero_after_hooks  = array('blocksy:single:hero:after',  'blocksy:hero:section:after');

        foreach ($hero_before_hooks as $h) {
            add_action($h, function() use ($add, $state) {
                $state->blocksy_handled = true;
                $add();
            }, 1);
        }
        foreach ($hero_after_hooks as $h) {
            add_action($h, $remove, 999);
        }

        // Fallback: если Blocksy-хуки не сработали — включаем фильтр на template_include и снимаем перед the_content
        add_filter('template_include', function($tpl) use ($add, $state) {
            if (!$state->blocksy_handled) $add();
            return $tpl;
        }, 999);
        add_filter('the_content', function($content) use ($remove) {
            $remove();
            return $content;
        }, 1);
    }

    /**
     * Получение настроек с дефолтами
     */
    public static function get_settings() {
        if (self::$settings_cache !== null) return self::$settings_cache;
        $defaults = array(
            'global_lightbox' => 0,
            'scope' => 'content',
            'exclude_classes' => 'no-lightbox, nolightbox, wp-smiley, avatar, emoji',
            'min_width' => 100,
        );
        $opts = get_option('hs_deck_settings', array());
        self::$settings_cache = wp_parse_args(is_array($opts) ? $opts : array(), $defaults);
        return self::$settings_cache;
    }

    /**
     * Сбросить кеш настроек после обновления опций
     */
    public static function flush_settings_cache() {
        self::$settings_cache = null;
    }

    /**
     * Помощник: нужно ли грузить CSS/JS на текущей странице.
     * Кешируется на запрос.
     */
    private function needs_assets() {
        if ($this->assets_needed !== null) return $this->assets_needed;
        $opts = self::get_settings();
        if (!empty($opts['global_lightbox'])) return $this->assets_needed = true;

        if (is_singular()) {
            $post = get_queried_object();
            if ($post && !empty($post->post_content)) {
                if (has_shortcode($post->post_content, 'hs_deck') ||
                    has_shortcode($post->post_content, 'hs_decks') ||
                    has_shortcode($post->post_content, 'hs_deck_link')) {
                    return $this->assets_needed = true;
                }
                if (get_post_meta($post->ID, '_hs_mode', true)) {
                    return $this->assets_needed = true;
                }
            }
            if (get_post_type() === 'hs_deck') return $this->assets_needed = true;
        }

        if (is_post_type_archive('hs_deck')) return $this->assets_needed = true;

        return $this->assets_needed = false;
    }

    /**
     * Регистрация страницы настроек
     */
    public function add_settings_page() {
        add_options_page(
            'KolodaHS: Lightbox',
            'KolodaHS Lightbox',
            'manage_options',
            'hs-deck-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('hs_deck_settings_group', 'hs_deck_settings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => array(),
        ));
    }

    public function sanitize_settings($input) {
        $out = array();
        $out['global_lightbox'] = !empty($input['global_lightbox']) ? 1 : 0;
        $scope = isset($input['scope']) ? $input['scope'] : 'content';
        $out['scope'] = in_array($scope, array('content', 'all'), true) ? $scope : 'content';
        $out['exclude_classes'] = isset($input['exclude_classes']) ? sanitize_text_field($input['exclude_classes']) : '';
        $out['min_width'] = isset($input['min_width']) ? max(0, intval($input['min_width'])) : 100;
        return $out;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $opts = self::get_settings();
        ?>
        <div class="wrap">
            <h1>KolodaHS: Lightbox</h1>
            <form method="post" action="options.php">
                <?php settings_fields('hs_deck_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Глобальный Lightbox</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hs_deck_settings[global_lightbox]" value="1" <?php checked(1, $opts['global_lightbox']); ?>>
                                Открывать все изображения на сайте в lightbox
                            </label>
                            <p class="description">Применяется к ссылкам на изображения и к самим картинкам.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Область применения</th>
                        <td>
                            <label><input type="radio" name="hs_deck_settings[scope]" value="content" <?php checked('content', $opts['scope']); ?>> Только контент записей и страниц</label><br>
                            <label><input type="radio" name="hs_deck_settings[scope]" value="all" <?php checked('all', $opts['scope']); ?>> Весь сайт (включая виджеты, сайдбары)</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Исключения по классам</th>
                        <td>
                            <input type="text" name="hs_deck_settings[exclude_classes]" value="<?php echo esc_attr($opts['exclude_classes']); ?>" class="regular-text" style="width:100%; max-width:600px;">
                            <p class="description">Через запятую. Картинки или их родители с этими классами игнорируются.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Минимальная ширина (px)</th>
                        <td>
                            <input type="number" name="hs_deck_settings[min_width]" value="<?php echo esc_attr($opts['min_width']); ?>" min="0" class="small-text">
                            <p class="description">Картинки уже этого размера не оборачиваются (иконки, аватары и т.п.).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Подключение скриптов для фронтенда
     */
    public function enqueue_frontend_scripts() {
        if ($this->needs_assets()) {
            wp_enqueue_script('jquery');
        }
    }
    
    /**
     * Подключение скриптов для админки
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_media();
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', 'var hsDeckAjax = ' . wp_json_encode(array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hs_deck_ajax_nonce'),
                'postId' => get_the_ID()
            )) . ';', 'before');
            
            // Подключаем стили для модальных окон TinyMCE из плагина custom-shortcodes
            $custom_shortcodes_path = WP_PLUGIN_DIR . '/wp-kolodahearthstone-shortcodes/tinymce-modals.css';
            if (file_exists($custom_shortcodes_path)) {
                wp_enqueue_style(
                    'mtp-tinymce-modals-decks',
                    plugins_url('wp-kolodahearthstone-shortcodes/tinymce-modals.css'),
                    [],
                    filemtime($custom_shortcodes_path)
                );
            }
        }
    }
    
    /**
     * AJAX обработчик для создания колоды
     */
    public function ajax_create_deck() {
        check_ajax_referer('hs_deck_ajax_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
            return;
        }
        
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $code = isset($_POST['code']) ? sanitize_textarea_field(wp_unslash($_POST['code'])) : '';
        $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

        // Защита от SSRF: разрешаем загрузку только http/https с публичных URL
        if ($image_url) {
            $parsed = wp_parse_url($image_url);
            if (empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
                $image_url = '';
            } elseif (empty($parsed['host'])) {
                $image_url = '';
            }
        }
        
        if (empty($title)) {
            wp_send_json_error(array('message' => 'Заголовок не может быть пустым'));
            return;
        }
        
        if (empty($code)) {
            wp_send_json_error(array('message' => 'Код колоды не может быть пустым'));
            return;
        }
        
        // Создаем пост колоды
        $post_data = array(
            'post_title' => $title,
            'post_type' => 'hs_deck',
            'post_status' => 'publish',
            'post_author' => get_current_user_id()
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Ошибка при создании колоды: ' . $post_id->get_error_message()));
            return;
        }
        
        // Сохраняем код колоды
        update_post_meta($post_id, '_hs_deck_code', $code);
        
        // Устанавливаем изображение
        if ($image_id > 0) {
            // Если передан ID изображения из медиа-библиотеки
            set_post_thumbnail($post_id, $image_id);
        } elseif (!empty($image_url)) {
            // Если передан URL изображения
            $this->set_featured_image_from_url($post_id, $image_url);
        }
        
        wp_send_json_success(array('deck_id' => $post_id));
    }
    
    /**
     * Установка изображения по URL
     */
    private function set_featured_image_from_url($post_id, $image_url) {
        // Дополнительная валидация против SSRF: только http/https, только публичные хосты, расширения изображений
        $parsed = wp_parse_url($image_url);
        if (empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) return false;
        if (empty($parsed['host'])) return false;

        // Блокируем приватные/локальные IP и localhost (SSRF)
        $host = strtolower($parsed['host']);
        if (in_array($host, array('localhost', '127.0.0.1', '0.0.0.0', '::1'), true)) return false;
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if (!$ip) {
            $resolved = @gethostbyname($host);
            if ($resolved && $resolved !== $host) $ip = $resolved;
        }
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Только разумные расширения
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        if (!preg_match('/\.(jpe?g|png|gif|webp|avif|bmp|svg)$/i', $path)) return false;

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) return false;

        $file_array = array(
            'name'     => sanitize_file_name(basename($path)),
            'tmp_name' => $tmp,
        );

        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        set_post_thumbnail($post_id, $id);
        return true;
    }
    
    /**
     * AJAX обработчик для поиска колод
     */
    public function ajax_search_decks() {
        check_ajax_referer('hs_deck_ajax_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
            return;
        }
        
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        
        if (empty($query) || strlen($query) < 2) {
            wp_send_json_success(array('decks' => array()));
            return;
        }
        
        $args = array(
            'post_type' => 'hs_deck',
            'posts_per_page' => 20,
            'post_status' => 'publish',
            's' => $query,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'ignore_sticky_posts' => true,
        );
        
        $search_query = new WP_Query($args);
        $decks = array();
        
        if ($search_query->have_posts()) {
            while ($search_query->have_posts()) {
                $search_query->the_post();
                $post_id = get_the_ID();
                $decks[] = array(
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'date' => get_the_date('c')
                );
            }
            wp_reset_postdata();
        }
        
        wp_send_json_success(array('decks' => $decks));
    }
    
    /**
     * Добавление кнопки в TinyMCE
     */
    public function add_tinymce_button() {
        if (current_user_can('edit_posts') || current_user_can('edit_pages')) {
            add_filter('mce_external_plugins', array($this, 'add_tinymce_plugin'));
            add_filter('mce_buttons_2', array($this, 'register_inline_deck_button'));
        }
    }

    public function register_inline_deck_button($buttons) {
        if (!in_array('hs_inline_deck_link', $buttons, true)) {
            $buttons[] = 'hs_inline_deck_link';
        }
        return $buttons;
    }

    public function add_tinymce_plugin($plugin_array) {
        if (file_exists(plugin_dir_path(__FILE__) . 'tinymce-deck.js')) {
            $plugin_array['hs_deck'] = add_query_arg(
                'ver',
                (string) filemtime(plugin_dir_path(__FILE__) . 'tinymce-deck.js'),
                plugins_url('tinymce-deck.js', __FILE__)
            );
        }
        return $plugin_array;
    }
    
    /**
     * Регистрация custom post type для колод
     */
    public function register_post_type() {
        $labels = array(
            'name' => 'Колоды',
            'singular_name' => 'Колода',
            'add_new' => 'Добавить колоду',
            'add_new_item' => 'Добавить новую колоду',
            'edit_item' => 'Редактировать колоду',
            'new_item' => 'Новая колода',
            'view_item' => 'Просмотреть колоду',
            'search_items' => 'Искать колоды',
            'not_found' => 'Колоды не найдены',
            'not_found_in_trash' => 'Колоды в корзине не найдены',
            'all_items' => 'Все колоды',
            'menu_name' => 'Колоды HS'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => true,
            'show_in_admin_bar' => true,
            'query_var' => true,
            'rewrite' => array(
                'slug' => 'hs_deck',
                'with_front' => false,
            ),
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'menu_icon' => 'dashicons-images-alt2',
            'supports' => array('title', 'thumbnail', 'editor'),
            'show_in_rest' => true,
        );
        
        register_post_type('hs_deck', $args);
    }
    
    /**
     * Добавление метабоксов
     */
    public function add_meta_boxes() {
        add_meta_box(
            'hs_deck_code',
            'Код колоды',
            array($this, 'render_deck_code_meta_box'),
            'hs_deck',
            'normal',
            'high'
        );
        
        add_meta_box(
            'hs_deck_shortcode',
            'Шорткоды',
            array($this, 'render_shortcode_meta_box'),
            'hs_deck',
            'side',
            'default'
        );
    }
    
    /**
     * Рендеринг метабокса с кодом колоды
     */
    public function render_deck_code_meta_box($post) {
        wp_nonce_field('hs_deck_meta_box', 'hs_deck_meta_box_nonce');
        
        $deck_code = get_post_meta($post->ID, '_hs_deck_code', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="hs_deck_code">Код колоды</label></th>
                <td>
                    <textarea id="hs_deck_code" name="hs_deck_code" rows="3" style="width:100%; font-family: monospace;"><?php echo esc_textarea($deck_code); ?></textarea>
                    <p class="description">Вставьте код колоды из Hearthstone</p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Рендеринг метабокса со шорткодами
     */
    public function render_shortcode_meta_box($post) {
        $post_id = $post->ID;
        ?>
        <div style="padding: 10px 0;">
            <p><strong>Шорткод для этой колоды:</strong></p>
            <input type="text" value='[hs_deck id="<?php echo $post_id; ?>"]' readonly 
                   onclick="this.select();" 
                   style="width:100%; padding:8px; background:#f0f0f0; border:1px solid #ddd; border-radius:4px; font-family:monospace; font-size:12px; cursor:pointer;">
            <p class="description" style="margin-top:10px;">Скопируйте этот шорткод для вставки колоды в статью или на страницу</p>
        </div>
        <?php
    }
    
    /**
     * Сохранение метабоксов
     */
    public function save_meta_boxes($post_id) {
        // Проверка nonce
        if (!isset($_POST['hs_deck_meta_box_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hs_deck_meta_box_nonce'])), 'hs_deck_meta_box')) return;

        // Проверка автосохранения и ревизий
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        // Проверка прав
        if (!current_user_can('edit_post', $post_id)) return;
        
        // Сохранение кода колоды
        if (isset($_POST['hs_deck_code'])) {
            update_post_meta($post_id, '_hs_deck_code', sanitize_textarea_field(wp_unslash($_POST['hs_deck_code'])));
        }
    }
    
    /**
     * Добавление кастомных колонок в список колод
     */
    public function add_custom_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['deck_code'] = 'Код колоды';
                $new_columns['shortcode'] = 'Шорткод';
            }
        }
        return $new_columns;
    }
    
    /**
     * Рендеринг кастомных колонок
     */
    public function render_custom_columns($column, $post_id) {
        if ($column === 'deck_code') {
            $deck_code = get_post_meta($post_id, '_hs_deck_code', true);
            if ($deck_code) {
                echo '<code style="font-size:11px;">' . esc_html(substr($deck_code, 0, 30)) . (strlen($deck_code) > 30 ? '...' : '') . '</code>';
            } else {
                echo '<span style="color:#999;">—</span>';
            }
        }
        
        if ($column === 'shortcode') {
            $shortcode = '[hs_deck id="' . $post_id . '"]';
            echo '<input type="text" value="' . esc_attr($shortcode) . '" readonly onclick="this.select();" style="width:100%; padding:4px; background:#f9f9f9; border:1px solid #ddd; border-radius:3px; font-family:monospace; font-size:11px; cursor:pointer;">';
        }
    }
    
    /**
     * Получить URL картинки колоды с фолбэком по размерам — единый метод вместо 4-7 вызовов
     */
    private function get_deck_image_url($post_id) {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if (!$thumb_id) return '';
        foreach (array('large', 'medium', 'full') as $size) {
            $url = wp_get_attachment_image_url($thumb_id, $size);
            if ($url) return $url;
        }
        return (string) wp_get_attachment_url($thumb_id);
    }

    /**
     * Шорткод для отображения одной колоды
     */
    public function single_deck_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts);
        
        $post_id = intval($atts['id']);
        
        if (!$post_id) {
            return '<p style="color:red;">Ошибка: не указан ID колоды</p>';
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'hs_deck') {
            return '<p style="color:red;">Ошибка: колода с ID ' . esc_html((string) $post_id) . ' не найдена</p>';
        }
        
        $deck_code = get_post_meta($post_id, '_hs_deck_code', true);
        $title = get_the_title($post_id);
        $deck_image = $this->get_deck_image_url($post_id);

        ob_start();
        ?>
        <div class="hs-deck-single-card">
            <h3 class="hs-deck-title"><?php echo esc_html($title); ?></h3>
            
            <?php if ($deck_image): ?>
            <div class="hs-deck-image">
                <a href="<?php echo esc_url($deck_image); ?>" class="hs-deck-lightbox-trigger slb_off no-lightbox" rel="nolightbox" data-lightbox="false" data-title="<?php echo esc_attr($title); ?>" data-code="<?php echo esc_attr($deck_code); ?>" aria-label="Открыть изображение колоды">
                    <img src="<?php echo esc_url($deck_image); ?>" alt="<?php echo esc_attr($title); ?>">
                    <span class="hs-deck-zoom-icon" aria-hidden="true">⤢</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($deck_code): ?>
            <div class="hs-deck-actions">
                <button type="button" class="hs-deck-copy-btn" data-code="<?php echo esc_attr($deck_code); ?>" onclick="return false;">
                    Скопировать код
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Шорткод для отображения списка колод
     */
    public function decks_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'count' => 12,
            'columns' => 2,
        ), $atts);
        
        $count = max(1, intval($atts['count']));
        $columns = max(1, min(4, intval($atts['columns'])));
        
        $args = array(
            'post_type' => 'hs_deck',
            'posts_per_page' => $count,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'publish',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'ignore_sticky_posts' => true,
        );

        $query = new WP_Query($args);
        
        // ОПТИМИЗАЦИЯ: Загружаем все мета-данные одним запросом для всех постов
        $all_meta = array();
        if ($query->have_posts()) {
            $post_ids = wp_list_pluck($query->posts, 'ID');
            if (!empty($post_ids)) {
                global $wpdb;
                $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
                // Используем call_user_func_array для совместимости со старыми версиями PHP
                $meta_query = call_user_func_array(
                    array($wpdb, 'prepare'),
                    array_merge(
                        array("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = '_hs_deck_code'"),
                        $post_ids
                    )
                );
                $meta_results = $wpdb->get_results($meta_query);
                
                // Группируем мета-данные по post_id
                foreach ($meta_results as $meta) {
                    $all_meta[$meta->post_id] = $meta->meta_value;
                }
            }
            $query->rewind_posts(); // Возвращаем указатель на начало
        }
        
        ob_start();
        ?>
        <div class="hs-decks-grid" style="grid-template-columns: repeat(<?php echo $columns; ?>, 1fr);">
            <?php
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $title = get_the_title($post_id);
                    // ОПТИМИЗАЦИЯ: Используем предзагруженные мета-данные
                    $deck_code = isset($all_meta[$post_id]) ? $all_meta[$post_id] : '';
                    $deck_image = $this->get_deck_image_url($post_id);
                    $permalink = get_permalink($post_id);
                    ?>
                    <div class="hs-deck-card">
                        <?php if ($deck_image): ?>
                        <div class="hs-deck-card-image">
                            <a href="<?php echo esc_url($permalink); ?>">
                                <img src="<?php echo esc_url($deck_image); ?>" alt="<?php echo esc_attr($title); ?>">
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <div class="hs-deck-card-body">
                            <h4 class="hs-deck-card-title">
                                <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
                            </h4>
                            
                            <?php if ($deck_code): ?>
                            <button type="button" class="hs-deck-card-copy-btn" data-code="<?php echo esc_attr($deck_code); ?>" data-post-id="<?php echo $post_id; ?>" onclick="return false;">
                                Скопировать код
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
                wp_reset_postdata();
            } else {
                echo '<p class="hs-decks-empty">Колоды не найдены</p>';
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function maybe_output_styles() {
        if (!$this->needs_assets()) return;
        $this->output_styles();
    }

    public function maybe_output_scripts() {
        // Скрипты нужны для глобального lightbox даже без шорткодов
        if (!$this->needs_assets()) return;
        $this->output_scripts();
    }

    /**
     * Вывод стилей - минималистичный дизайн под бумажный фон
     */
    public function output_styles() {
        $font_url = plugins_url('font/2318-font.otf', __FILE__);
        ?>
        <style id="hs-decks-styles">
        @font-face {
            font-family: 'HSDeckFont';
            src: url('<?php echo esc_url($font_url); ?>') format('opentype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        .hs-deck-inline-link {
            display: inline-flex;
            align-items: center;
            gap: 0.32em;
            color: inherit;
            text-decoration: none;
            cursor: zoom-in;
            vertical-align: baseline;
        }

        .hs-deck-inline-icon {
            display: inline-block;
            flex: 0 0 auto;
            width: 42px;
            height: auto;
            max-width: none;
            margin: 0;
            vertical-align: -0.14em;
        }

        .hs-deck-inline-label {
            font-weight: 700;
            font-style: italic;
            line-height: inherit;
        }

        .hs-deck-inline-link:hover,
        .hs-deck-inline-link:focus {
            color: #6b4a2c;
            text-decoration: none;
        }

        .hs-deck-inline-link:focus-visible {
            outline: 2px solid #8b5a3c;
            outline-offset: 3px;
            border-radius: 2px;
        }
        
        /* Одиночная колода - минималистично */
        .hs-deck-single-card {
            margin: 20px 0;
            padding: 0;
        }
        
        .hs-deck-title {
            font-family: 'HSDeckFont', sans-serif;
            font-size: 2rem;
            font-weight: 900;
            color: #2d1b0e;
            margin: 0 0 20px 0;
            text-align: center;
            letter-spacing: 0.02em;
        }
        
        .hs-deck-image {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .hs-deck-image img {
            width: 100%;
            height: auto;
            display: block;
        }

        /* Кликабельная картинка колоды (триггер lightbox) */
        .hs-deck-image .hs-deck-lightbox-trigger {
            position: relative;
            display: block;
            cursor: zoom-in;
            overflow: hidden;
            border-radius: 8px;
        }

        .hs-deck-image .hs-deck-lightbox-trigger img {
            transition: transform 0.3s ease;
        }

        .hs-deck-image .hs-deck-lightbox-trigger:hover img {
            transform: scale(1.02);
        }

        .hs-deck-zoom-icon {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 36px;
            height: 36px;
            line-height: 36px;
            text-align: center;
            background: rgba(45,27,14,0.7);
            color: #fff;
            border-radius: 50%;
            font-size: 18px;
            font-weight: 700;
            opacity: 0;
            transform: scale(0.8);
            transition: opacity 0.2s ease, transform 0.2s ease;
            pointer-events: none;
        }

        .hs-deck-image .hs-deck-lightbox-trigger:hover .hs-deck-zoom-icon,
        .hs-deck-image .hs-deck-lightbox-trigger:focus .hs-deck-zoom-icon {
            opacity: 1;
            transform: scale(1);
        }

        /* Lightbox overlay */
        .hs-deck-lightbox {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.88);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999999;
            padding: 40px 20px;
            box-sizing: border-box;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
            cursor: zoom-out;
        }

        .hs-deck-lightbox.is-open {
            opacity: 1;
            visibility: visible;
        }

        .hs-deck-lightbox-inner {
            position: relative;
            max-width: 100%;
            max-height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            cursor: default;
        }

        .hs-deck-lightbox-img {
            max-width: 100%;
            max-height: calc(100vh - 180px);
            width: auto;
            height: auto;
            display: block;
            border-radius: 6px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            transform: scale(0.95);
            transition: transform 0.25s ease;
        }

        .hs-deck-lightbox.is-open .hs-deck-lightbox-img {
            transform: scale(1);
        }

        .hs-deck-lightbox-caption {
            color: #fff;
            font-family: 'HSDeckFont', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            text-align: center;
            text-shadow: 0 1px 2px rgba(0,0,0,0.6);
            max-width: 90vw;
        }

        .hs-deck-lightbox-copy {
            display: none;
            min-height: 42px;
            padding: 10px 20px;
            border: 1px solid rgba(255,255,255,0.45);
            border-radius: 5px;
            background: #6b4a2c;
            color: #fff;
            font: 700 14px/1.2 inherit;
            cursor: pointer;
            transition: background-color 0.18s ease, border-color 0.18s ease;
        }

        .hs-deck-lightbox-copy:hover,
        .hs-deck-lightbox-copy:focus-visible {
            background: #8b5a3c;
            border-color: #fff;
            outline: none;
        }

        .hs-deck-lightbox-copy.copy-success {
            background: #277a45;
        }

        .hs-deck-lightbox-copy:disabled {
            cursor: wait;
            opacity: 0.75;
        }

        .hs-deck-lightbox-close {
            position: absolute;
            top: -44px;
            right: 0;
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            transition: background-color 0.2s ease;
            padding: 0;
        }

        .hs-deck-lightbox-close:hover,
        .hs-deck-lightbox-close:focus {
            background: rgba(255,255,255,0.3);
            outline: none;
        }

        body.hs-deck-lightbox-open {
            overflow: hidden;
        }

        /* Бейдж режима под заголовком в hero */
        .hs-mode-badge {
            display: inline-block;
            margin: 12px auto 0;
            padding: 6px 18px;
            border-radius: 999px;
            font-family: 'HSDeckFont', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            color: #fff;
            background: rgba(0,0,0,0.45);
            border: 1px solid rgba(255,255,255,0.35);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            text-transform: uppercase;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }

        /* Центрируем бейдж, если он попал в блок-обёртку рядом с заголовком */
        .ct-hero-section,
        .hero-section,
        .entry-header {
            text-align: inherit;
        }
        .ct-hero-section .hs-mode-badge,
        .hero-section .hs-mode-badge,
        .entry-header .hs-mode-badge {
            display: inline-block;
        }
        .ct-hero-section,
        .hero-section {
            position: relative;
        }

        /* Цветовая дифференциация по режимам */
        .hs-mode-standard      { background: linear-gradient(135deg, rgba(72,118,255,0.85), rgba(45,80,200,0.9)); border-color: rgba(150,180,255,0.6); }
        .hs-mode-wild          { background: linear-gradient(135deg, rgba(140,82,255,0.85), rgba(85,40,170,0.9)); border-color: rgba(200,170,255,0.6); }
        .hs-mode-battlegrounds { background: linear-gradient(135deg, rgba(220,150,40,0.9),  rgba(160,90,20,0.95)); border-color: rgba(255,210,140,0.7); }
        .hs-mode-arena         { background: linear-gradient(135deg, rgba(200,50,60,0.9),   rgba(140,25,30,0.95)); border-color: rgba(255,170,170,0.6); }

        .hs-deck-lightbox-nav {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 32px;
            line-height: 1;
            cursor: pointer;
            transition: background-color 0.2s ease;
            padding: 0;
            z-index: 1;
        }

        .hs-deck-lightbox-prev { left: 20px; }
        .hs-deck-lightbox-next { right: 20px; }

        .hs-deck-lightbox-nav:hover,
        .hs-deck-lightbox-nav:focus {
            background: rgba(255,255,255,0.3);
            outline: none;
        }

        @media (max-width: 600px) {
            .hs-deck-lightbox {
                padding: 60px 12px 20px;
            }
            .hs-deck-lightbox-close {
                top: -48px;
                right: 50%;
                transform: translateX(50%);
            }
            .hs-deck-zoom-icon {
                width: 32px;
                height: 32px;
                line-height: 32px;
                font-size: 16px;
                opacity: 1;
                transform: scale(1);
            }
            .hs-deck-lightbox-nav {
                width: 40px;
                height: 40px;
                font-size: 26px;
            }
            .hs-deck-lightbox-prev { left: 8px; }
            .hs-deck-lightbox-next { right: 8px; }
        }
        
        .hs-deck-actions {
            text-align: center;
            margin-top: 20px;
        }
        
        .hs-deck-copy-btn {
            background: rgba(139,117,95,0.9);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer !important;
            transition: background-color 0.2s ease;
            text-align: center;
            display: inline-block;
            min-width: 180px;
            white-space: nowrap;
            box-sizing: border-box;
            pointer-events: auto !important;
            user-select: none;
        }
        
        .hs-deck-copy-btn:hover {
            background: rgba(107,74,44,0.95);
        }
        
        .hs-deck-copy-btn.copy-success {
            background: rgba(56,161,105,0.9);
        }
        
        .hs-deck-copy-btn:disabled {
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        /* Список колод - минималистично */
        .hs-decks-grid {
            display: grid;
            gap: 20px;
            margin: 20px 0;
        }
        
        .hs-deck-card {
            border: 1px solid rgba(139,117,95,0.15);
            border-radius: 8px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        
        .hs-deck-card:hover {
            border-color: rgba(139,117,95,0.3);
        }
        
        .hs-deck-card-image {
            width: 100%;
            height: 200px;
            overflow: hidden;
            background: rgba(240,235,230,0.5);
        }
        
        .hs-deck-card-image a {
            display: block;
            width: 100%;
            height: 100%;
        }
        
        .hs-deck-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .hs-deck-card-body {
            padding: 15px;
        }
        
        .hs-deck-card-title {
            margin: 0 0 12px 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #2d1b0e;
        }
        
        .hs-deck-card-title a {
            color: inherit;
            text-decoration: none;
        }
        
        .hs-deck-card-title a:hover {
            color: #8b5a3c;
        }
        
        .hs-deck-card-copy-btn {
            width: 100%;
            background: rgba(139,117,95,0.8);
            color: #fff;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer !important;
            transition: background-color 0.2s ease;
            margin-top: 8px;
            white-space: nowrap;
            box-sizing: border-box;
            pointer-events: auto !important;
            user-select: none;
        }
        
        .hs-deck-card-copy-btn:hover {
            background: rgba(107,74,44,0.9);
        }
        
        .hs-deck-card-copy-btn.copy-success {
            background: rgba(56,161,105,0.8);
        }
        
        .hs-deck-card-copy-btn:disabled {
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .hs-decks-empty {
            text-align: center;
            padding: 30px 20px;
            color: #6b5d4a;
        }
        
        @media (max-width: 900px) {
            .hs-decks-grid {
                grid-template-columns: 1fr !important;
            }
            .hs-deck-card-image {
                height: 180px;
            }
        }
        
        @media (max-width: 600px) {
            .hs-deck-title {
                font-size: 1.5rem;
            }
            .hs-deck-card-image {
                height: 160px;
            }
            .hs-deck-card-body {
                padding: 12px;
            }
        }
        
        /* Стили для колод внутри спойлеров */
        .mtp-spoiler-content .hs-deck-single-card {
            margin: 15px 0;
        }
        
        .mtp-spoiler-content .hs-deck-title {
            font-size: 1.6rem;
            margin-bottom: 15px;
        }
        
        .mtp-spoiler-content .hs-deck-image {
            max-width: 100%;
        }
        
        .mtp-spoiler-content .hs-deck-actions {
            margin-top: 15px;
        }
        
        .mtp-spoiler-content .hs-decks-grid {
            margin: 15px 0;
            gap: 15px;
        }
        
        .mtp-spoiler-content .hs-deck-card {
            margin: 0;
        }
        
        /* Убеждаемся, что кнопки работают внутри спойлеров */
        .mtp-spoiler-content .hs-deck-copy-btn,
        .mtp-spoiler-content .hs-deck-card-copy-btn {
            position: relative;
            z-index: 1;
        }
        
        /* Улучшаем видимость кнопок внутри спойлеров */
        .mtp-spoiler-content.active .hs-deck-copy-btn,
        .mtp-spoiler-content.active .hs-deck-card-copy-btn {
            pointer-events: auto !important;
            cursor: pointer !important;
        }
        </style>
        <?php
    }
    
    /**
     * Вывод скриптов
     */
    public function output_scripts() {
        $opts = self::get_settings();
        $excludes = array_filter(array_map('trim', explode(',', $opts['exclude_classes'])));
        $cfg = array(
            'global'   => (int) $opts['global_lightbox'],
            'scope'    => $opts['scope'],
            'excludes' => array_values($excludes),
            'minWidth' => (int) $opts['min_width'],
        );
        ?>
        <script>
        var hsDeckLightboxCfg = <?php echo wp_json_encode($cfg); ?>;
        (function() {
            'use strict';
            
            // Ждем загрузки DOM и jQuery
            function initDeckCopyButtons() {
                // Проверяем наличие jQuery
                if (typeof jQuery === 'undefined') {
                    console.error('jQuery не загружен');
                    return;
                }

                if (window.hsDeckLightboxInitialized) {
                    return;
                }
                window.hsDeckLightboxInitialized = true;

                var $ = jQuery;
                
                // Функция для копирования кода колоды
                function copyDeckCode(btn, code) {
                    // Предотвращаем множественные клики
                    if (btn.prop('disabled') || btn.hasClass('copying')) {
                        return false;
                    }
                    
                    if (!code) {
                        alert('Код колоды не найден');
                        return false;
                    }
                    
                    // Блокируем кнопку
                    btn.prop('disabled', true).addClass('copying');
                    var originalText = btn.text().trim();
                    
                    // Функция для восстановления кнопки
                    function restoreButton() {
                        btn.removeClass('copy-success copying').prop('disabled', false);
                        btn.text(originalText);
                    }
                    
                    // Пытаемся скопировать через Clipboard API (работает для всех пользователей)
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(code).then(function() {
                            btn.addClass('copy-success');
                            btn.text('✓ Скопировано!');
                            setTimeout(restoreButton, 2000);
                        }).catch(function(err) {
                            console.error('Ошибка копирования:', err);
                            // Fallback для старых браузеров или если Clipboard API недоступен
                            fallbackCopy(code);
                        });
                    } else {
                        // Fallback для старых браузеров
                        fallbackCopy(code);
                    }
                    
                    function fallbackCopy(text) {
                        var textarea = $('<textarea>')
                            .val(text)
                            .css({
                                position: 'fixed',
                                left: '-9999px',
                                top: '0',
                                opacity: '0',
                                zIndex: '-1'
                            })
                            .appendTo('body');
                        
                        try {
                            textarea[0].select();
                            textarea[0].setSelectionRange(0, 99999);
                            
                            var successful = document.execCommand('copy');
                            if (successful) {
                                btn.addClass('copy-success');
                                btn.text('✓ Скопировано!');
                                setTimeout(restoreButton, 2000);
                            } else {
                                alert('Не удалось скопировать код. Попробуйте выделить и скопировать вручную.');
                                restoreButton();
                            }
                        } catch (err) {
                            console.error('Ошибка fallback копирования:', err);
                            alert('Не удалось скопировать код. Попробуйте выделить и скопировать вручную.');
                            restoreButton();
                        } finally {
                            textarea.remove();
                        }
                    }
                }
                
                // Копирование кода колоды - работает для всех пользователей и внутри спойлеров
                // Используем делегирование событий для работы с динамическим контентом (включая спойлеры)
                $(document).off('click', '.hs-deck-copy-btn, .hs-deck-card-copy-btn').on('click', '.hs-deck-copy-btn, .hs-deck-card-copy-btn', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var btn = $(this);
                    var code = btn.data('code');
                    
                    if (!code) {
                        code = btn.attr('data-code');
                    }
                    
                    copyDeckCode(btn, code);
                    
                    return false;
                });
                
                // Также добавляем обработчик через addEventListener для надежности
                document.addEventListener('click', function(e) {
                    var target = e.target;
                    if (target && (target.classList.contains('hs-deck-copy-btn') || target.classList.contains('hs-deck-card-copy-btn'))) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        var btn = $(target);
                        var code = btn.data('code') || btn.attr('data-code');
                        
                        if (code) {
                            copyDeckCode(btn, code);
                        }
                        
                        return false;
                    }
                }, true);
                
                // === Lightbox ===
                var lightbox = null;
                var lightboxImg = null;
                var lightboxCaption = null;
                var lightboxCopy = null;
                var lightboxPrev = null;
                var lightboxNext = null;
                var lastFocusedTrigger = null;
                var gallery = [];
                var galleryIndex = -1;
                var cfg = window.hsDeckLightboxCfg || { global: 0, scope: 'content', excludes: [], minWidth: 100 };
                var IMG_RE = /\.(jpe?g|png|gif|webp|bmp|avif|svg)(\?.*)?$/i;
                var deckImagePreloads = Object.create(null);

                function preloadDeckImage(src) {
                    if (!src) return null;
                    if (deckImagePreloads[src]) return deckImagePreloads[src];

                    var image = new Image();
                    image.decoding = 'async';
                    image.src = src;

                    var ready = typeof image.decode === 'function'
                        ? image.decode().catch(function() { return null; })
                        : null;
                    deckImagePreloads[src] = { image: image, ready: ready };
                    return deckImagePreloads[src];
                }

                function preloadDeckTrigger(trigger) {
                    if (!trigger || !trigger.matches || !trigger.matches('.hs-deck-lightbox-trigger')) return;
                    preloadDeckImage(trigger.getAttribute('href') || '');
                }

                document.addEventListener('pointerover', function(e) {
                    preloadDeckTrigger(e.target.closest ? e.target.closest('.hs-deck-lightbox-trigger') : null);
                }, true);
                document.addEventListener('focusin', function(e) {
                    preloadDeckTrigger(e.target.closest ? e.target.closest('.hs-deck-lightbox-trigger') : null);
                }, true);
                document.addEventListener('touchstart', function(e) {
                    preloadDeckTrigger(e.target.closest ? e.target.closest('.hs-deck-lightbox-trigger') : null);
                }, { capture: true, passive: true });

                function warmVisibleDeckImages() {
                    var triggers = document.querySelectorAll('.hs-deck-inline-link.hs-deck-lightbox-trigger');
                    if (!triggers.length) return;

                    if (!('IntersectionObserver' in window)) {
                        for (var i = 0; i < Math.min(triggers.length, 4); i++) preloadDeckTrigger(triggers[i]);
                        return;
                    }

                    var observer = new IntersectionObserver(function(entries) {
                        entries.forEach(function(entry) {
                            if (!entry.isIntersecting) return;
                            preloadDeckTrigger(entry.target);
                            observer.unobserve(entry.target);
                        });
                    }, { rootMargin: '300px 0px' });

                    for (var j = 0; j < triggers.length; j++) observer.observe(triggers[j]);
                }

                if (typeof window.requestIdleCallback === 'function') {
                    window.requestIdleCallback(warmVisibleDeckImages, { timeout: 1200 });
                } else {
                    window.setTimeout(warmVisibleDeckImages, 300);
                }

                function ensureLightbox() {
                    if (lightbox) return;
                    lightbox = document.createElement('div');
                    lightbox.className = 'hs-deck-lightbox';
                    lightbox.setAttribute('role', 'dialog');
                    lightbox.setAttribute('aria-modal', 'true');
                    lightbox.setAttribute('aria-hidden', 'true');
                    lightbox.innerHTML = '<div class="hs-deck-lightbox-inner">' +
                        '<button type="button" class="hs-deck-lightbox-close" aria-label="Закрыть">×</button>' +
                        '<button type="button" class="hs-deck-lightbox-nav hs-deck-lightbox-prev" aria-label="Предыдущее">‹</button>' +
                        '<button type="button" class="hs-deck-lightbox-nav hs-deck-lightbox-next" aria-label="Следующее">›</button>' +
                        '<img class="hs-deck-lightbox-img" src="" alt="">' +
                        '<div class="hs-deck-lightbox-caption"></div>' +
                        '<button type="button" class="hs-deck-lightbox-copy">Скопировать код колоды</button>' +
                        '</div>';
                    document.body.appendChild(lightbox);
                    lightboxImg = lightbox.querySelector('.hs-deck-lightbox-img');
                    lightboxCaption = lightbox.querySelector('.hs-deck-lightbox-caption');
                    lightboxCopy = lightbox.querySelector('.hs-deck-lightbox-copy');
                    lightboxPrev = lightbox.querySelector('.hs-deck-lightbox-prev');
                    lightboxNext = lightbox.querySelector('.hs-deck-lightbox-next');

                    lightbox.addEventListener('click', function(e) {
                        if (e.target === lightbox) closeLightbox();
                    });
                    lightbox.querySelector('.hs-deck-lightbox-close').addEventListener('click', closeLightbox);
                    lightboxPrev.addEventListener('click', function(e) { e.stopPropagation(); navigate(-1); });
                    lightboxNext.addEventListener('click', function(e) { e.stopPropagation(); navigate(1); });
                    lightboxCopy.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var item = gallery[galleryIndex] || {};
                        copyDeckCode($(lightboxCopy), item.code || '');
                    });
                }

                function setNavVisibility() {
                    if (!lightboxPrev) return;
                    var multi = gallery.length > 1;
                    lightboxPrev.style.display = multi ? '' : 'none';
                    lightboxNext.style.display = multi ? '' : 'none';
                }

                function showCurrent() {
                    if (galleryIndex < 0 || galleryIndex >= gallery.length) return;
                    var item = gallery[galleryIndex];
                    lightboxImg.src = item.src;
                    lightboxImg.alt = item.title || '';
                    if (item.title) {
                        lightboxCaption.textContent = item.title;
                        lightboxCaption.style.display = '';
                    } else {
                        lightboxCaption.textContent = '';
                        lightboxCaption.style.display = 'none';
                    }
                    if (item.code) {
                        lightboxCopy.style.display = 'inline-flex';
                        lightboxCopy.setAttribute('data-code', item.code);
                    } else {
                        lightboxCopy.style.display = 'none';
                        lightboxCopy.removeAttribute('data-code');
                    }
                }

                function navigate(delta) {
                    if (gallery.length < 2) return;
                    galleryIndex = (galleryIndex + delta + gallery.length) % gallery.length;
                    showCurrent();
                }

                function openSingle(src, title, code, trigger) {
                    gallery = [{ src: src, title: title || '', code: code || '' }];
                    galleryIndex = 0;
                    openLightbox(trigger);
                }

                function openGallery(items, index, trigger) {
                    gallery = items;
                    galleryIndex = index;
                    openLightbox(trigger);
                }

                function openLightbox(trigger) {
                    ensureLightbox();
                    lastFocusedTrigger = trigger || null;
                    setNavVisibility();
                    showCurrent();
                    document.body.classList.add('hs-deck-lightbox-open');
                    lightbox.setAttribute('aria-hidden', 'false');
                    void lightbox.offsetWidth;
                    lightbox.classList.add('is-open');
                    var closeBtn = lightbox.querySelector('.hs-deck-lightbox-close');
                    if (closeBtn) closeBtn.focus();
                }

                function closeLightbox() {
                    if (!lightbox || !lightbox.classList.contains('is-open')) return;
                    lightbox.classList.remove('is-open');
                    lightbox.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('hs-deck-lightbox-open');
                    setTimeout(function() {
                        if (lightboxImg) lightboxImg.src = '';
                    }, 250);
                    if (lastFocusedTrigger && typeof lastFocusedTrigger.focus === 'function') {
                        try { lastFocusedTrigger.focus(); } catch (e) {}
                    }
                }

                // Перехват клика на наших триггерах (capture, до Simple Lightbox)
                document.addEventListener('click', function(e) {
                    var trigger = e.target.closest ? e.target.closest('.hs-deck-lightbox-trigger') : null;
                    if (!trigger) return;
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                    var src = trigger.getAttribute('href');
                    var title = trigger.getAttribute('data-title') || '';
                    var code = trigger.getAttribute('data-code') || '';
                    preloadDeckImage(src);
                    openSingle(src, title, code, trigger);
                }, true);

                document.addEventListener('keydown', function(e) {
                    if (!lightbox || !lightbox.classList.contains('is-open')) return;
                    if (e.key === 'Escape') closeLightbox();
                    else if (e.key === 'ArrowLeft') navigate(-1);
                    else if (e.key === 'ArrowRight') navigate(1);
                });

                // === Глобальный lightbox для всех картинок ===
                function isExcluded(el) {
                    if (!el) return false;
                    if (el.closest && el.closest('.hs-deck-lightbox-trigger, .hs-deck-lightbox')) return true;
                    var excludes = cfg.excludes || [];
                    for (var i = 0; i < excludes.length; i++) {
                        var cls = excludes[i];
                        if (!cls) continue;
                        if (el.closest && el.closest('.' + cls)) return true;
                    }
                    return false;
                }

                function getScopeRoots() {
                    if (cfg.scope === 'all') return [document.body];
                    var roots = [];
                    var sels = ['.entry-content', '.post-content', '.single-content', 'article .content', '.page-content', '.wp-block-post-content'];
                    for (var i = 0; i < sels.length; i++) {
                        var nodes = document.querySelectorAll(sels[i]);
                        for (var j = 0; j < nodes.length; j++) roots.push(nodes[j]);
                    }
                    if (!roots.length) {
                        var fallback = document.querySelector('article, main, .entry, .post');
                        if (fallback) roots.push(fallback);
                    }
                    return roots;
                }

                function imageSrcFromAnchor(a) {
                    var href = a.getAttribute('href') || '';
                    return IMG_RE.test(href) ? href : '';
                }

                function imageSrcFromImg(img) {
                    return img.currentSrc || img.src || '';
                }

                function imgQualifies(img) {
                    if (isExcluded(img)) return false;
                    var w = img.naturalWidth || img.width || 0;
                    if (cfg.minWidth && w && w < cfg.minWidth) return false;
                    return !!imageSrcFromImg(img);
                }

                function buildGalleryFromRoots(roots) {
                    var items = [];
                    var seen = {};
                    for (var i = 0; i < roots.length; i++) {
                        var root = roots[i];
                        // Anchors-on-image
                        var anchors = root.querySelectorAll('a');
                        for (var a = 0; a < anchors.length; a++) {
                            var ah = anchors[a];
                            var src = imageSrcFromAnchor(ah);
                            if (!src || isExcluded(ah)) continue;
                            var im = ah.querySelector('img');
                            if (!im) continue;
                            if (seen[src]) continue;
                            seen[src] = true;
                            items.push({ src: src, title: im.getAttribute('alt') || im.getAttribute('title') || '', el: ah });
                        }
                        // Standalone images (no parent anchor leading to an image)
                        var imgs = root.querySelectorAll('img');
                        for (var k = 0; k < imgs.length; k++) {
                            var img = imgs[k];
                            if (img.closest('a') && imageSrcFromAnchor(img.closest('a'))) continue;
                            if (!imgQualifies(img)) continue;
                            var s = imageSrcFromImg(img);
                            if (!s || seen[s]) continue;
                            seen[s] = true;
                            items.push({ src: s, title: img.getAttribute('alt') || img.getAttribute('title') || '', el: img });
                        }
                    }
                    return items;
                }

                function initGlobalLightbox() {
                    if (!cfg.global) return;
                    var roots = getScopeRoots();
                    if (!roots.length) return;

                    document.addEventListener('click', function(e) {
                        var anchor = e.target.closest ? e.target.closest('a') : null;
                        var img = e.target.closest ? e.target.closest('img') : null;
                        var src = '';
                        var clickedEl = null;

                        if (anchor) {
                            if (isExcluded(anchor)) return;
                            // Только если ссылка ведёт на изображение и внутри нашей области
                            if (!roots.some(function(r) { return r.contains(anchor); })) return;
                            src = imageSrcFromAnchor(anchor);
                            if (!src) return;
                            clickedEl = anchor;
                        } else if (img) {
                            if (!roots.some(function(r) { return r.contains(img); })) return;
                            // Если img внутри ссылки на не-картинку — не вмешиваемся
                            var parentA = img.closest('a');
                            if (parentA && !imageSrcFromAnchor(parentA)) return;
                            if (!imgQualifies(img)) return;
                            src = parentA ? imageSrcFromAnchor(parentA) : imageSrcFromImg(img);
                            if (!src) return;
                            clickedEl = parentA || img;
                        } else {
                            return;
                        }

                        var items = buildGalleryFromRoots(roots);
                        var idx = -1;
                        for (var i = 0; i < items.length; i++) {
                            if (items[i].src === src) { idx = i; break; }
                        }
                        if (idx === -1) {
                            items = [{ src: src, title: (img && img.alt) || '' }];
                            idx = 0;
                        }

                        e.preventDefault();
                        e.stopPropagation();
                        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                        openGallery(items, idx, clickedEl);
                    }, true);

                    function applyZoomCursor(scope) {
                        var imgs = scope.querySelectorAll ? scope.querySelectorAll('img') : [];
                        for (var ii = 0; ii < imgs.length; ii++) {
                            var im2 = imgs[ii];
                            if (im2.dataset && im2.dataset.hsLightboxApplied) continue;
                            if (!imgQualifies(im2)) continue;
                            var pa = im2.closest('a');
                            if (pa && !imageSrcFromAnchor(pa)) continue;
                            im2.style.cursor = 'zoom-in';
                            if (im2.dataset) im2.dataset.hsLightboxApplied = '1';
                        }
                    }

                    // Первичный проход
                    for (var ri = 0; ri < roots.length; ri++) applyZoomCursor(roots[ri]);

                    // MutationObserver — для контента, добавленного динамически
                    // (например, VIP Content Locker раскодирует и вставляет HTML после клика)
                    if (typeof MutationObserver !== 'undefined') {
                        var observer = new MutationObserver(function(mutations) {
                            for (var i = 0; i < mutations.length; i++) {
                                var added = mutations[i].addedNodes;
                                for (var j = 0; j < added.length; j++) {
                                    var node = added[j];
                                    if (node.nodeType !== 1) continue;
                                    // Проверяем, попадает ли новый узел в одну из наших scope-областей
                                    var inScope = false;
                                    for (var k = 0; k < roots.length; k++) {
                                        if (roots[k].contains(node)) { inScope = true; break; }
                                    }
                                    if (!inScope) continue;
                                    applyZoomCursor(node);
                                    if (node.tagName === 'IMG') applyZoomCursor({ querySelectorAll: function() { return [node]; } });
                                }
                            }
                        });
                        for (var ri2 = 0; ri2 < roots.length; ri2++) {
                            observer.observe(roots[ri2], { childList: true, subtree: true });
                        }
                    }
                }

                initGlobalLightbox();

                // Убеждаемся, что кнопки работают даже когда спойлер открывается
                $(document).on('click', '.mtp-spoiler-toggle', function() {
                    // Небольшая задержка, чтобы спойлер успел открыться
                    setTimeout(function() {
                        $('.mtp-spoiler-content.active .hs-deck-copy-btn, .mtp-spoiler-content.active .hs-deck-card-copy-btn').each(function() {
                            var $btn = $(this);
                            // Убеждаемся, что кнопки кликабельны
                            $btn.css({
                                'pointer-events': 'auto',
                                'cursor': 'pointer'
                            });
                        });
                    }, 100);
                });
            }
            
            // Инициализация при загрузке DOM
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initDeckCopyButtons);
            } else {
                initDeckCopyButtons();
            }
            
            // Также инициализируем после полной загрузки страницы
            if (window.addEventListener) {
                window.addEventListener('load', initDeckCopyButtons);
            }
        })();
        </script>
        <?php
    }
}

// Инициализация плагина
new KolodaHearthstone_Decks();
new HS_Inline_Deck_Link(__FILE__);

