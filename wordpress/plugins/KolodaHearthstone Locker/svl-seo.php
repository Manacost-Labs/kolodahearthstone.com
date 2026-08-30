<?php
/**
 * SVL SEO Module
 * Полноценный SEO-модуль для Simple VIP Locker.
 * - Контент под замком кодируется (Base64+ROT13) и не индексируется.
 * - Публичный тизер виден краулерам и пользователям.
 * - Schema.org Article помечается isAccessibleForFree:false (paywalled).
 */

if (!defined('ABSPATH')) { exit; }

// ==========================================
// 1. ОПЦИИ ПО УМОЛЧАНИЮ + REGISTER_SETTING
// ==========================================

function svl_seo_default_options() {
    return array(
        'svl_seo_enabled'           => 1,
        'svl_seo_apply_all'         => 1,
        'svl_seo_override_plugin'   => 0,
        'svl_seo_default_teaser'    => '',
        'svl_seo_default_og_image'  => '',
        'svl_seo_org_name'          => get_bloginfo('name'),
        'svl_seo_org_logo'          => '',
        'svl_seo_twitter_site'      => '',
        'svl_seo_title_sep'         => '—',
        'svl_seo_title_format'      => '%title% %sep% %sitename%',
        'svl_seo_verify_google'     => '',
        'svl_seo_verify_yandex'     => '',
        'svl_seo_verify_bing'       => '',
        'svl_seo_verify_pinterest'  => '',
        'svl_seo_breadcrumbs'       => 1,
        'svl_seo_noindex_paged'     => 1,
        'svl_seo_archive_desc'      => '%name%, %sitename%',
    );
}

function svl_seo_activate() {
    foreach (svl_seo_default_options() as $k => $v) {
        add_option($k, $v);
    }
}

add_action('admin_init', 'svl_seo_register_settings');
function svl_seo_register_settings() {
    foreach (array_keys(svl_seo_default_options()) as $k) {
        register_setting('svl_seo_opts', $k);
    }
}

function svl_seo_opt($key, $default = '') {
    $defs = svl_seo_default_options();
    if (!array_key_exists($key, $defs)) return $default;
    $v = get_option($key, isset($defs[$key]) ? $defs[$key] : $default);
    return $v;
}

// ==========================================
// 2. ПАНЕЛЬ ВИП ДАННЫЕ (для рендера контекста)
// ==========================================

function svl_seo_panel_data() {
    static $panels = array();
    return $panels;
}

function svl_seo_register_panel($data) {
    static $panels = array();
    $panels[] = $data;
    // Сохраняем в глобальный кеш контекста
    $GLOBALS['_svl_seo_panels'] = $panels;
}

function svl_seo_first_panel() {
    return !empty($GLOBALS['_svl_seo_panels']) ? $GLOBALS['_svl_seo_panels'][0] : null;
}

function svl_seo_has_panel() {
    return !empty($GLOBALS['_svl_seo_panels']);
}

// ==========================================
// 3. КОДИРОВАНИЕ ЗАЩИЩЁННОГО КОНТЕНТА
// ==========================================

/**
 * Кодирует контент (Base64+ROT13). UTF-8 байты >127 проходят через str_rot13 без изменений.
 */
function svl_seo_encode_locked($content) {
    return base64_encode(str_rot13($content));
}

// ==========================================
// 4. КОНТЕКСТНЫЙ ОПРЕДЕЛИТЕЛЬ
// ==========================================

function svl_seo_context() {
    $ctx = array('type' => 'unknown', 'object' => null);
    if (is_404()) {
        $ctx['type'] = '404';
    } elseif (is_search()) {
        $ctx['type'] = 'search';
    } elseif (is_singular()) {
        $ctx['type'] = 'singular';
        $ctx['object'] = get_queried_object();
    } elseif (is_front_page() || is_home()) {
        $ctx['type'] = 'home';
    } elseif (is_tax() || is_category() || is_tag()) {
        $ctx['type'] = 'term';
        $ctx['object'] = get_queried_object();
    } elseif (is_post_type_archive()) {
        $ctx['type'] = 'pt_archive';
        $ctx['object'] = get_queried_object();
    } elseif (is_author()) {
        $ctx['type'] = 'author';
        $ctx['object'] = get_queried_object();
    } elseif (is_date()) {
        $ctx['type'] = 'date';
    }
    return $ctx;
}

// ==========================================
// 5. РЕЗОЛВЕР SEO-ДАННЫХ
// ==========================================

function svl_seo_resolve_context() {
    $ctx = svl_seo_context();
    $sitename = get_bloginfo('name');
    $tagline  = get_bloginfo('description');
    $data = array(
        'title'     => $sitename,
        'desc'      => $tagline,
        'kw'        => '',
        'robots'    => 'index,follow',
        'canonical' => '',
        'og_image'  => svl_seo_opt('svl_seo_default_og_image'),
        'type'      => $ctx['type'],
    );

    switch ($ctx['type']) {
        case 'singular':
            $post = $ctx['object'];
            if (!$post) break;
            $pid = $post->ID;
            $panel = svl_seo_first_panel();

            $title = get_post_meta($pid, '_svl_seo_title', true);
            if (!$title && $panel && !empty($panel['seo_title'])) $title = $panel['seo_title'];
            if (!$title) $title = get_the_title($pid);

            $desc = get_post_meta($pid, '_svl_seo_desc', true);
            if (!$desc && $panel && !empty($panel['seo_desc']))   $desc = $panel['seo_desc'];
            if (!$desc && $panel && !empty($panel['teaser']))     $desc = $panel['teaser'];
            if (!$desc) $desc = svl_seo_opt('svl_seo_default_teaser');
            if (!$desc) {
                $excerpt = has_excerpt($pid) ? get_the_excerpt($pid) : wp_trim_words(strip_shortcodes(strip_tags($post->post_content)), 30, '…');
                $desc = $excerpt;
            }

            $kw = get_post_meta($pid, '_svl_seo_keywords', true);
            if (!$kw && $panel && !empty($panel['keywords'])) $kw = $panel['keywords'];

            $robots = get_post_meta($pid, '_svl_seo_robots', true);
            if (!$robots) $robots = 'index,follow';

            $canon = get_post_meta($pid, '_svl_seo_canonical', true);
            if (!$canon) $canon = get_permalink($pid);

            $og = get_post_meta($pid, '_svl_seo_og_image', true);
            if (!$og) {
                $thumb = get_the_post_thumbnail_url($pid, 'full');
                if ($thumb) $og = $thumb;
            }
            if (!$og) $og = svl_seo_opt('svl_seo_default_og_image');

            $data['title'] = $title;
            $data['desc']  = $desc;
            $data['kw']    = $kw;
            $data['robots']= $robots;
            $data['canonical'] = $canon;
            $data['og_image'] = $og;
            break;

        case 'home':
            $front_id = (int) get_option('page_on_front');
            if ($front_id) {
                $t = get_post_meta($front_id, '_svl_seo_title', true);
                if (!$t) $t = get_the_title($front_id);
                $data['title'] = $t;
                $d = get_post_meta($front_id, '_svl_seo_desc', true);
                if (!$d) $d = $tagline;
                $data['desc'] = $d;
            } else {
                $data['title'] = $sitename;
                $data['desc']  = $tagline;
            }
            $data['canonical'] = home_url('/');
            break;

        case 'term':
            $term = $ctx['object'];
            if ($term) {
                $data['title'] = $term->name;
                $td = term_description($term->term_id, $term->taxonomy);
                if (!$td) {
                    $tpl = svl_seo_opt('svl_seo_archive_desc');
                    $td = str_replace(array('%name%', '%sitename%'), array($term->name, $sitename), $tpl);
                }
                $data['desc'] = wp_strip_all_tags($td);
                $data['canonical'] = get_term_link($term);
            }
            break;

        case 'pt_archive':
            $pto = $ctx['object'];
            if ($pto) {
                $data['title'] = $pto->labels->name;
                $tpl = svl_seo_opt('svl_seo_archive_desc');
                $data['desc'] = str_replace(array('%name%', '%sitename%'), array($pto->labels->name, $sitename), $tpl);
                $data['canonical'] = get_post_type_archive_link($pto->name);
            }
            break;

        case 'author':
            $au = $ctx['object'];
            if ($au) {
                $data['title'] = $au->display_name;
                $bio = get_user_meta($au->ID, 'description', true);
                $data['desc'] = $bio ? wp_strip_all_tags($bio) : str_replace(array('%name%', '%sitename%'), array($au->display_name, $sitename), svl_seo_opt('svl_seo_archive_desc'));
                $data['canonical'] = get_author_posts_url($au->ID);
            }
            break;

        case 'date':
            $data['title'] = single_month_title(' ', false);
            $data['desc']  = str_replace(array('%name%', '%sitename%'), array(single_month_title(' ', false), $sitename), svl_seo_opt('svl_seo_archive_desc'));
            break;

        case 'search':
            $data['title']  = sprintf(__('Поиск: %s', 'svl'), get_search_query());
            $data['desc']   = '';
            $data['robots'] = 'noindex,follow';
            break;

        case '404':
            $data['title']  = __('Страница не найдена', 'svl');
            $data['robots'] = 'noindex,follow';
            break;
    }

    // Пагинация
    if (svl_seo_opt('svl_seo_noindex_paged') && (int) get_query_var('paged') > 1) {
        // Снимаем index, ставим noindex (сохраняя follow)
        $tokens = array_map('trim', explode(',', $data['robots']));
        $tokens = array_diff($tokens, array('index'));
        if (!in_array('noindex', $tokens, true)) $tokens[] = 'noindex';
        if (!in_array('follow', $tokens, true) && !in_array('nofollow', $tokens, true)) $tokens[] = 'follow';
        $data['robots'] = implode(',', $tokens);
    }

    // Нормализуем og_image
    if (!empty($data['og_image'])) $data['og_image'] = svl_seo_normalize_url($data['og_image']);
    if (!empty($data['canonical'])) $data['canonical'] = svl_seo_normalize_url($data['canonical']);

    return apply_filters('svl_seo_resolved', $data, $ctx);
}

// ==========================================
// 6. УТИЛИТЫ
// ==========================================

function svl_seo_normalize_url($url) {
    if (!$url) return $url;
    $home = wp_parse_url(home_url());
    $u    = wp_parse_url($url);
    if (!empty($u['host']) && !empty($home['host']) && $u['host'] === $home['host'] && is_ssl()) {
        if (!empty($u['scheme']) && $u['scheme'] === 'http') {
            $url = 'https://' . preg_replace('#^https?://#i', '', $url);
        }
    }
    return $url;
}

function svl_seo_image_dims($url) {
    if (!$url) return array(0, 0);
    $key = 'svl_imgd_' . md5($url);
    $cached = get_transient($key);
    if ($cached !== false) return $cached;

    $dims = array(0, 0);
    $aid = attachment_url_to_postid($url);
    if ($aid) {
        $meta = wp_get_attachment_metadata($aid);
        if (!empty($meta['width']) && !empty($meta['height'])) {
            $dims = array((int) $meta['width'], (int) $meta['height']);
        }
    }
    set_transient($key, $dims, 12 * HOUR_IN_SECONDS);
    return $dims;
}

// Регистрируем кастомный размер 1200×630 для OG-картинок (стандарт Facebook/Telegram/VK).
add_action('after_setup_theme', 'svl_seo_register_image_sizes');
function svl_seo_register_image_sizes() {
    add_image_size('svl_seo_og', 1200, 630, true); // crop=true
}

/**
 * Возвращает URL картинки в размере 1200x630 если возможно.
 * Если переданный URL — аттачмент медиабиблиотеки, попробует взять размер svl_seo_og.
 * Иначе вернёт оригинал.
 *
 * @return array{0:string,1:int,2:int} [url, width, height]
 */
function svl_seo_og_image_data($url) {
    if (!$url) return array('', 0, 0);
    $key = 'svl_og_' . md5($url);
    $cached = get_transient($key);
    if (is_array($cached) && count($cached) === 3) return $cached;

    $out = array($url, 0, 0);
    $aid = attachment_url_to_postid($url);
    if ($aid) {
        $img = wp_get_attachment_image_src($aid, 'svl_seo_og');
        if (is_array($img) && !empty($img[0])) {
            $out = array($img[0], (int) $img[1], (int) $img[2]);
        } else {
            // fallback на оригинал
            $meta = wp_get_attachment_metadata($aid);
            if (!empty($meta['width']) && !empty($meta['height'])) {
                $out = array($url, (int) $meta['width'], (int) $meta['height']);
            }
        }
    }
    set_transient($key, $out, 12 * HOUR_IN_SECONDS);
    return $out;
}

function svl_seo_format_title($title) {
    $sep      = svl_seo_opt('svl_seo_title_sep');
    $sitename = get_bloginfo('name');
    $tagline  = get_bloginfo('description');
    $format   = svl_seo_opt('svl_seo_title_format');
    $out = str_replace(
        array('%title%', '%sitename%', '%sep%', '%tagline%'),
        array($title, $sitename, $sep, $tagline),
        $format
    );
    return trim(preg_replace('/\s+/', ' ', $out));
}

// ==========================================
// 7. ФИЛЬТРЫ
// ==========================================

add_filter('pre_get_document_title', 'svl_seo_filter_title', 99);
function svl_seo_filter_title($title) {
    if (!svl_seo_opt('svl_seo_enabled')) return $title;
    if (!svl_seo_opt('svl_seo_apply_all') && !svl_seo_has_panel()) return $title;
    $r = svl_seo_resolve_context();
    return svl_seo_format_title($r['title']);
}

add_filter('wp_robots', 'svl_seo_filter_robots', 99);
function svl_seo_filter_robots($robots) {
    if (!svl_seo_opt('svl_seo_enabled')) return $robots;
    if (!svl_seo_opt('svl_seo_apply_all') && !svl_seo_has_panel()) return $robots;
    $r = svl_seo_resolve_context();
    $tokens = array_map('trim', explode(',', $r['robots']));
    foreach ($tokens as $t) {
        if ($t === 'noindex')   { unset($robots['index']); $robots['noindex']   = true; }
        if ($t === 'index')     { unset($robots['noindex']); $robots['index']   = true; }
        if ($t === 'nofollow')  { unset($robots['follow']); $robots['nofollow'] = true; }
        if ($t === 'follow')    { unset($robots['nofollow']); $robots['follow'] = true; }
        if ($t === 'noarchive') { $robots['noarchive'] = true; }
        if ($t === 'nosnippet') { $robots['nosnippet'] = true; }
    }
    return $robots;
}

add_filter('get_canonical_url', 'svl_seo_filter_canonical', 99, 2);
function svl_seo_filter_canonical($url, $post) {
    if (!svl_seo_opt('svl_seo_enabled')) return $url;
    $c = get_post_meta($post->ID, '_svl_seo_canonical', true);
    return $c ? $c : $url;
}

// Совместимость с Yoast/Rank Math (страховка для не-override режима)
add_filter('wpseo_metadesc', 'svl_seo_compat_desc', 10, 1);
add_filter('rank_math/frontend/description', 'svl_seo_compat_desc', 10, 1);
function svl_seo_compat_desc($desc) {
    if ($desc) return $desc;
    $r = svl_seo_resolve_context();
    return $r['desc'];
}

add_filter('user_contactmethods', 'svl_seo_user_fields');
function svl_seo_user_fields($fields) {
    $fields['twitter'] = __('Twitter / X (без @)', 'svl');
    return $fields;
}

// ==========================================
// 8. ЭМИТТЕР WP_HEAD
// ==========================================

add_action('wp_head', 'svl_seo_emit_verification', 1);
function svl_seo_emit_verification() {
    if (!svl_seo_opt('svl_seo_enabled')) return;
    $g = svl_seo_opt('svl_seo_verify_google');
    $y = svl_seo_opt('svl_seo_verify_yandex');
    $b = svl_seo_opt('svl_seo_verify_bing');
    $p = svl_seo_opt('svl_seo_verify_pinterest');
    if ($g) echo '<meta name="google-site-verification" content="' . esc_attr($g) . '">' . "\n";
    if ($y) echo '<meta name="yandex-verification" content="' . esc_attr($y) . '">' . "\n";
    if ($b) echo '<meta name="msvalidate.01" content="' . esc_attr($b) . '">' . "\n";
    if ($p) echo '<meta name="p:domain_verify" content="' . esc_attr($p) . '">' . "\n";
}

add_action('wp_head', 'svl_seo_emit_main', 5);
function svl_seo_emit_main() {
    if (!svl_seo_opt('svl_seo_enabled')) return;
    if (!svl_seo_opt('svl_seo_apply_all') && !svl_seo_has_panel()) return;

    $r = svl_seo_resolve_context();
    $ctx = svl_seo_context();
    $sitename = get_bloginfo('name');
    $locale   = get_locale();
    $is_post  = ($ctx['type'] === 'singular' && isset($ctx['object']->post_type) && $ctx['object']->post_type === 'post');
    $is_singular = ($ctx['type'] === 'singular');
    $is_panel = svl_seo_has_panel();
    $override = (bool) svl_seo_opt('svl_seo_override_plugin');

    echo "\n<!-- SVL SEO -->\n";

    if (!empty($r['desc'])) {
        echo '<meta name="description" content="' . esc_attr($r['desc']) . '">' . "\n";
    }
    if (!empty($r['kw'])) {
        echo '<meta name="keywords" content="' . esc_attr($r['kw']) . '">' . "\n";
    }

    // Canonical: для не-singular ядро не эмитит автоматически
    if (!$is_singular && !empty($r['canonical'])) {
        echo '<link rel="canonical" href="' . esc_url($r['canonical']) . '">' . "\n";
    }

    // Open Graph
    $og_type = $is_post ? 'article' : 'website';
    $og_url  = !empty($r['canonical']) ? $r['canonical'] : home_url(add_query_arg(null, null));
    $og_title = svl_seo_format_title($r['title']);
    echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
    if (!empty($r['desc'])) echo '<meta property="og:description" content="' . esc_attr($r['desc']) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr($sitename) . '">' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr($locale) . '">' . "\n";

    if (!empty($r['og_image'])) {
        list($og_img_url, $og_w, $og_h) = svl_seo_og_image_data($r['og_image']);
        $og_img_url = svl_seo_normalize_url($og_img_url);
        echo '<meta property="og:image" content="' . esc_url($og_img_url) . '">' . "\n";
        echo '<meta property="og:image:secure_url" content="' . esc_url($og_img_url) . '">' . "\n";
        if ($og_w && $og_h) {
            echo '<meta property="og:image:width" content="' . intval($og_w) . '">' . "\n";
            echo '<meta property="og:image:height" content="' . intval($og_h) . '">' . "\n";
        }
        echo '<meta property="og:image:alt" content="' . esc_attr($og_title) . '">' . "\n";
    }

    if ($is_post && $ctx['object']) {
        $post = $ctx['object'];
        echo '<meta property="article:published_time" content="' . esc_attr(mysql2date('c', $post->post_date_gmt, false)) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr(mysql2date('c', $post->post_modified_gmt, false)) . '">' . "\n";
        $author = get_userdata($post->post_author);
        if ($author) echo '<meta property="article:author" content="' . esc_attr($author->display_name) . '">' . "\n";
        $cats = get_the_category($post->ID);
        if (!empty($cats)) echo '<meta property="article:section" content="' . esc_attr($cats[0]->name) . '">' . "\n";
        $tags = get_the_tags($post->ID);
        if (!empty($tags) && is_array($tags)) {
            foreach ($tags as $tag) echo '<meta property="article:tag" content="' . esc_attr($tag->name) . '">' . "\n";
        }
    }

    // Twitter
    $twitter_site = svl_seo_opt('svl_seo_twitter_site');
    echo '<meta name="twitter:card" content="' . (!empty($r['og_image']) ? 'summary_large_image' : 'summary') . '">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($og_title) . '">' . "\n";
    if (!empty($r['desc']))     echo '<meta name="twitter:description" content="' . esc_attr($r['desc']) . '">' . "\n";
    if (!empty($r['og_image'])) {
        list($tw_img_url) = svl_seo_og_image_data($r['og_image']);
        echo '<meta name="twitter:image" content="' . esc_url(svl_seo_normalize_url($tw_img_url)) . '">' . "\n";
    }
    if ($twitter_site)          echo '<meta name="twitter:site" content="' . esc_attr($twitter_site) . '">' . "\n";
    if ($is_post && $ctx['object']) {
        $tw = get_user_meta($ctx['object']->post_author, 'twitter', true);
        if ($tw) echo '<meta name="twitter:creator" content="@' . esc_attr(ltrim($tw, '@')) . '">' . "\n";
    }

    // Schema.org
    svl_seo_emit_schema($r, $ctx, $is_post, $is_panel);

    echo "<!-- /SVL SEO -->\n\n";
}

function svl_seo_emit_schema($r, $ctx, $is_post, $is_panel) {
    $sitename = get_bloginfo('name');
    $home     = home_url('/');
    $org_name = svl_seo_opt('svl_seo_org_name'); if (!$org_name) $org_name = $sitename;
    $org_logo = svl_seo_opt('svl_seo_org_logo');
    $org_id   = $home . '#organization';
    $site_id  = $home . '#website';

    $graph = array();

    $org = array(
        '@type' => 'Organization',
        '@id'   => $org_id,
        'name'  => $org_name,
        'url'   => $home,
    );
    if ($org_logo) {
        $org['logo'] = array(
            '@type' => 'ImageObject',
            'url'   => svl_seo_normalize_url($org_logo),
        );
    }
    $graph[] = $org;

    $graph[] = array(
        '@type'    => 'WebSite',
        '@id'      => $site_id,
        'url'      => $home,
        'name'     => $sitename,
        'publisher'=> array('@id' => $org_id),
        'potentialAction' => array(
            '@type'       => 'SearchAction',
            'target'      => array(
                '@type'       => 'EntryPoint',
                'urlTemplate' => $home . '?s={search_term_string}',
            ),
            'query-input' => 'required name=search_term_string',
        ),
    );

    if ($is_post && $ctx['object']) {
        $post  = $ctx['object'];
        $word  = str_word_count(strip_tags(strip_shortcodes($post->post_content)));
        $author = get_userdata($post->post_author);
        $article = array(
            '@type'    => 'Article',
            'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => get_permalink($post)),
            'headline' => get_the_title($post),
            'datePublished' => mysql2date('c', $post->post_date_gmt, false),
            'dateModified'  => mysql2date('c', $post->post_modified_gmt, false),
            'author'   => $author ? array('@type' => 'Person', 'name' => $author->display_name) : null,
            'publisher'=> array('@id' => $org_id),
            'description' => $r['desc'],
            'wordCount'   => $word,
        );
        if (!empty($r['og_image'])) {
            list($sc_url, $sc_w, $sc_h) = svl_seo_og_image_data($r['og_image']);
            $article['image'] = array(
                '@type'  => 'ImageObject',
                'url'    => svl_seo_normalize_url($sc_url),
                'width'  => $sc_w ?: 1200,
                'height' => $sc_h ?: 630,
            );
        }
        if ($is_panel) {
            $article['isAccessibleForFree'] = false;
            $article['hasPart'] = array(
                '@type' => 'WebPageElement',
                'isAccessibleForFree' => false,
                'cssSelector' => '.svl-content-protector',
            );
        }
        $article = array_filter($article, function ($v) { return $v !== null; });
        $graph[] = $article;
    }

    if (svl_seo_opt('svl_seo_breadcrumbs')) {
        $bc = svl_seo_breadcrumbs($ctx);
        if (!empty($bc)) {
            $graph[] = array(
                '@type'           => 'BreadcrumbList',
                'itemListElement' => $bc,
            );
        }
    }

    $payload = array(
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    );
    echo '<script type="application/ld+json">' . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}

// ==========================================
// 9. ХЛЕБНЫЕ КРОШКИ
// ==========================================

function svl_seo_breadcrumbs($ctx) {
    $home = array(
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => __('Главная', 'svl'),
        'item'     => home_url('/'),
    );
    $items = array($home);
    $pos = 2;

    if ($ctx['type'] === 'singular' && $ctx['object']) {
        $post = $ctx['object'];
        if ($post->post_type === 'post') {
            $cats = get_the_category($post->ID);
            if (!empty($cats)) {
                $cat = $cats[0];
                $ancestors = array_reverse(get_ancestors($cat->term_id, 'category'));
                foreach ($ancestors as $aid) {
                    $a = get_term($aid, 'category');
                    $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $a->name, 'item' => get_term_link($a));
                }
                $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $cat->name, 'item' => get_term_link($cat));
            }
        } else {
            $pto = get_post_type_object($post->post_type);
            if ($pto && $pto->has_archive) {
                $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $pto->labels->name, 'item' => get_post_type_archive_link($post->post_type));
            }
        }
        $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => get_the_title($post), 'item' => get_permalink($post));
    } elseif ($ctx['type'] === 'term' && $ctx['object']) {
        $term = $ctx['object'];
        $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy));
        foreach ($ancestors as $aid) {
            $a = get_term($aid, $term->taxonomy);
            $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $a->name, 'item' => get_term_link($a));
        }
        $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $term->name, 'item' => get_term_link($term));
    } elseif ($ctx['type'] === 'pt_archive' && $ctx['object']) {
        $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $ctx['object']->labels->name, 'item' => get_post_type_archive_link($ctx['object']->name));
    } elseif ($ctx['type'] === 'author' && $ctx['object']) {
        $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $ctx['object']->display_name, 'item' => get_author_posts_url($ctx['object']->ID));
    } elseif ($ctx['type'] === 'date') {
        $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => single_month_title(' ', false), 'item' => '');
    }

    return count($items) > 1 ? $items : array();
}

// ==========================================
// 10. МЕТАБОКС "VIP SEO" + СЕРВЕРНЫЙ СЧЁТЧИК
// ==========================================

add_action('add_meta_boxes', 'svl_seo_add_metabox');
function svl_seo_add_metabox() {
    foreach (get_post_types(array('public' => true), 'names') as $pt) {
        add_meta_box('svl_seo_metabox', __('VIP SEO', 'svl'), 'svl_seo_metabox_html', $pt, 'normal', 'high');
    }
}

// Подключаем wp.media для медиа-пикеров (метабокс редактора + страница настроек)
add_action('admin_enqueue_scripts', 'svl_seo_admin_enqueue');
function svl_seo_admin_enqueue($hook) {
    if (in_array($hook, array('post.php', 'post-new.php'), true)) {
        wp_enqueue_media();
    }
    if (isset($_GET['page']) && $_GET['page'] === 'svl-seo') {
        wp_enqueue_media();
    }
}

function svl_seo_metabox_html($post) {
    wp_nonce_field('svl_seo_save', 'svl_seo_nonce');
    $title = get_post_meta($post->ID, '_svl_seo_title', true);
    $desc  = get_post_meta($post->ID, '_svl_seo_desc', true);
    $kw    = get_post_meta($post->ID, '_svl_seo_keywords', true);
    $focus = get_post_meta($post->ID, '_svl_seo_focus', true);
    $robots= get_post_meta($post->ID, '_svl_seo_robots', true);
    $canon = get_post_meta($post->ID, '_svl_seo_canonical', true);
    $ogimg = get_post_meta($post->ID, '_svl_seo_og_image', true);
    $score = svl_seo_score($post->ID);
    $color = svl_seo_score_color($score['score']);
    $perm  = get_permalink($post->ID);
    if (!$perm) $perm = home_url('/');
    $preview_title = svl_seo_format_title($title ? $title : $post->post_title);
    ?>
    <style>
        .svl-seo-mb { font-size: 13px; }
        .svl-seo-mb .svl-progress { height: 12px; background: #f0f0f0; border-radius: 6px; overflow: hidden; margin: 6px 0; }
        .svl-seo-mb .svl-progress > div { height: 100%; transition: width .3s, background .3s; }
        .svl-seo-mb .svl-checks { list-style:none; padding:0; margin:8px 0 16px; columns: 2; }
        .svl-seo-mb .svl-checks li { padding: 2px 0; break-inside: avoid; }
        .svl-seo-mb .svl-checks li.ok::before  { content: "✅ "; }
        .svl-seo-mb .svl-checks li.warn::before{ content: "⚠️ "; }
        .svl-seo-mb .svl-checks li.fail::before{ content: "❌ "; }
        .svl-seo-mb .svl-snippet { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:12px; margin:10px 0 18px; max-width: 600px; }
        .svl-seo-mb .svl-snippet .url   { color: #006621; font-size: 13px; }
        .svl-seo-mb .svl-snippet .ttl   { color: #1a0dab; font-size: 18px; line-height:1.3; margin: 4px 0; }
        .svl-seo-mb .svl-snippet .dsc   { color: #545454; font-size: 13px; line-height:1.5; }
        .svl-seo-mb table.form-table th { width: 180px; }
        .svl-seo-mb .counter { font-size: 12px; color: #666; margin-top: 4px; }
        .svl-seo-mb .counter.ok { color: #00a32a; }
        .svl-seo-mb .counter.bad { color: #d63638; }
        .svl-seo-mb .description { font-size: 12px; color: #646970; margin: 4px 0 0; font-style: italic; }
        .svl-seo-mb .description strong { color: #2c3338; font-style: normal; }
        /* Chip input для keywords */
        .svl-chips {
            display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
            background: #fff; border: 1px solid #8c8f94; border-radius: 4px;
            padding: 6px 8px; min-height: 36px; cursor: text;
            box-shadow: 0 0 0 transparent;
            transition: border-color .15s, box-shadow .15s;
        }
        .svl-chips.focused { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
        .svl-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: #f0f6fc; color: #0a4b78;
            border: 1px solid #c5d9ed; border-radius: 14px;
            padding: 3px 6px 3px 10px; font-size: 12px; line-height: 1.4;
            max-width: 100%;
        }
        .svl-chip-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }
        .svl-chip-x {
            border: 0; background: transparent; cursor: pointer;
            color: #0a4b78; font-size: 14px; line-height: 1; padding: 0 2px;
            border-radius: 50%;
        }
        .svl-chip-x:hover { background: #c5d9ed; color: #d63638; }
        .svl-chips-input {
            border: 0; outline: 0; background: transparent;
            flex: 1 1 120px; min-width: 120px; padding: 4px;
            font-size: 13px;
        }
        /* OG image picker */
        .svl-og-row { display: flex; gap: 10px; align-items: flex-start; }
        .svl-og-row input[type=url] { flex: 1; }
        .svl-og-preview { margin-top: 8px; }
        .svl-og-preview img { max-width: 200px; height: auto; border: 1px solid #dcdcde; border-radius: 4px; display: block; }
    </style>
    <div class="svl-seo-mb">
        <strong><?php _e('Качество SEO', 'svl'); ?>: <span id="svl-score"><?php echo intval($score['score']); ?></span>%</strong>
        <div class="svl-progress"><div id="svl-bar" style="width: <?php echo intval($score['score']); ?>%; background: <?php echo esc_attr($color); ?>;"></div></div>
        <ul class="svl-checks" id="svl-checks">
            <?php foreach ($score['checks'] as $key => $c) : ?>
                <li class="<?php echo esc_attr($c['status']); ?>" data-check="<?php echo esc_attr($key); ?>"><?php echo esc_html($c['label']); ?></li>
            <?php endforeach; ?>
        </ul>

        <strong><?php _e('Превью в Google', 'svl'); ?>:</strong>
        <div class="svl-snippet">
            <div class="url" id="svl-snip-url"><?php echo esc_html($perm); ?></div>
            <div class="ttl" id="svl-snip-title"><?php echo esc_html($preview_title); ?></div>
            <div class="dsc" id="svl-snip-desc"><?php echo esc_html($desc); ?></div>
        </div>

        <table class="form-table">
            <tr>
                <th><label for="svl_seo_focus"><?php _e('Фокус-ключ', 'svl'); ?></label></th>
                <td>
                    <input type="text" id="svl_seo_focus" name="svl_seo_focus" value="<?php echo esc_attr($focus); ?>" class="regular-text" placeholder="например: рецепт борща">
                    <p class="description"><strong>Главное</strong> ключевое слово/фраза статьи. Должно встречаться в заголовке, описании и тексте — это главный сигнал релевантности для поиска.</p>
                </td>
            </tr>
            <tr>
                <th><label for="svl_seo_title"><?php _e('SEO Title', 'svl'); ?></label></th>
                <td>
                    <input type="text" id="svl_seo_title" name="svl_seo_title" value="<?php echo esc_attr($title); ?>" class="large-text" placeholder="Оставьте пустым — будет использован заголовок записи">
                    <div class="counter" id="svl-cnt-title"></div>
                    <p class="description">Заголовок страницы для поисковиков и вкладки браузера. Включите фокус-ключ ближе к началу. <strong>Длина 30–60 символов</strong>.</p>
                </td>
            </tr>
            <tr>
                <th><label for="svl_seo_desc"><?php _e('Meta Description', 'svl'); ?></label></th>
                <td>
                    <textarea id="svl_seo_desc" name="svl_seo_desc" rows="3" class="large-text" placeholder="Кратко: о чём статья, что получит читатель, призыв к действию"><?php echo esc_textarea($desc); ?></textarea>
                    <div class="counter" id="svl-cnt-desc"></div>
                    <p class="description">Описание под заголовком в поисковой выдаче. Влияет на CTR. Включите фокус-ключ. <strong>Длина 120–160 символов</strong>.</p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Keywords', 'svl'); ?></label></th>
                <td>
                    <div class="svl-chips" id="svl-kw-chips">
                        <input type="text" class="svl-chips-input" id="svl-kw-input" placeholder="Введите слово и нажмите Enter">
                    </div>
                    <input type="hidden" id="svl_seo_keywords" name="svl_seo_keywords" value="<?php echo esc_attr($kw); ?>">
                    <p class="description">Введите ключевое слово и нажмите <strong>Enter</strong> или <strong>запятую</strong> — оно превратится в тег. Удаление: × на теге или Backspace в пустом поле. Рекомендуется <strong>5–10</strong> ключей.</p>
                </td>
            </tr>
            <tr>
                <th><label for="svl_seo_robots"><?php _e('Robots', 'svl'); ?></label></th>
                <td>
                    <select id="svl_seo_robots" name="svl_seo_robots">
                        <?php $opts = array('index,follow','noindex,follow','index,nofollow','noindex,nofollow');
                        foreach ($opts as $o) printf('<option value="%s" %s>%s</option>', esc_attr($o), selected($robots, $o, false), esc_html($o)); ?>
                    </select>
                    <p class="description">Команды поисковикам. <strong>index,follow</strong> — обычное значение. <strong>noindex</strong> — скрыть страницу из выдачи (черновики, служебные).</p>
                </td>
            </tr>
            <tr>
                <th><label for="svl_seo_canonical"><?php _e('Canonical URL', 'svl'); ?></label></th>
                <td>
                    <input type="url" id="svl_seo_canonical" name="svl_seo_canonical" value="<?php echo esc_attr($canon); ?>" class="large-text" placeholder="<?php echo esc_attr($perm); ?>">
                    <p class="description">Каноничный URL — основной адрес страницы при дублях. <strong>Оставьте пустым</strong>, если страница уникальна — будет использован обычный permalink.</p>
                </td>
            </tr>
            <tr>
                <th><label for="svl_seo_og_image"><?php _e('OG-картинка', 'svl'); ?></label></th>
                <td>
                    <div class="svl-og-row">
                        <input type="url" id="svl_seo_og_image" name="svl_seo_og_image" value="<?php echo esc_attr($ogimg); ?>" class="large-text" placeholder="https://...">
                        <button type="button" class="button" id="svl-og-pick"><?php _e('Выбрать из библиотеки', 'svl'); ?></button>
                        <button type="button" class="button-link" id="svl-og-clear" style="color:#d63638;"><?php _e('Очистить', 'svl'); ?></button>
                    </div>
                    <div class="svl-og-preview" id="svl-og-preview" <?php if (!$ogimg) echo 'style="display:none;"'; ?>>
                        <?php if ($ogimg): ?><img src="<?php echo esc_url($ogimg); ?>" alt=""><?php endif; ?>
                    </div>
                    <p class="description">Картинка для соцсетей (Facebook, Telegram, ВК, Twitter). <strong>Рекомендуемый размер: 1200×630</strong>. Если пусто — будет использовано миниатюра записи или дефолтная.</p>
                </td>
            </tr>
        </table>
    </div>
    <script>
    (function(){
        var sep = <?php echo wp_json_encode(svl_seo_opt('svl_seo_title_sep')); ?>;
        var sitename = <?php echo wp_json_encode(get_bloginfo('name')); ?>;
        var format = <?php echo wp_json_encode(svl_seo_opt('svl_seo_title_format')); ?>;
        var defTitle = <?php echo wp_json_encode($post->post_title); ?>;

        // ---------- KEYWORDS chip-input ----------
        var hidden = document.getElementById('svl_seo_keywords');
        var box    = document.getElementById('svl-kw-chips');
        var inp    = document.getElementById('svl-kw-input');
        var tags   = (hidden.value || '').split(',').map(function(s){return s.trim();}).filter(Boolean);

        function escHtml(s){ return String(s).replace(/[&<>"']/g, function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
        // Дебаунс recompute — иначе ввод в keywords/полях лагает (Gutenberg.getEditedPostContent медленный)
        var _rt;
        function recomputeDebounced(){ clearTimeout(_rt); _rt = setTimeout(recompute, 200); }
        function syncHidden(){ hidden.value = tags.join(', '); recomputeDebounced(); }
        function renderChips(){
            // удаляем все старые чипы (input оставляем)
            Array.prototype.slice.call(box.querySelectorAll('.svl-chip')).forEach(function(c){ c.remove(); });
            tags.forEach(function(tag, i){
                var chip = document.createElement('span');
                chip.className = 'svl-chip';
                chip.innerHTML = '<span class="svl-chip-text">' + escHtml(tag) + '</span><button type="button" class="svl-chip-x" aria-label="Удалить" data-i="' + i + '">×</button>';
                box.insertBefore(chip, inp);
            });
        }
        function addTag(raw){
            var t = (raw || '').trim().replace(/,+$/, '').trim();
            if (!t) return;
            if (tags.indexOf(t) === -1) tags.push(t);
            renderChips(); syncHidden();
        }
        function removeTag(i){ tags.splice(i, 1); renderChips(); syncHidden(); }
        renderChips();

        box.addEventListener('click', function(e){
            if (e.target === box) inp.focus();
            if (e.target.classList && e.target.classList.contains('svl-chip-x')) {
                removeTag(parseInt(e.target.getAttribute('data-i'), 10));
            }
        });
        inp.addEventListener('focus', function(){ box.classList.add('focused'); });
        inp.addEventListener('blur',  function(){ box.classList.remove('focused'); if (inp.value.trim()) { addTag(inp.value); inp.value=''; } });
        inp.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ',' || e.keyCode === 13 || e.keyCode === 188) {
                e.preventDefault();
                if (inp.value.trim()) { addTag(inp.value); inp.value = ''; }
            } else if (e.key === 'Backspace' && !inp.value && tags.length) {
                removeTag(tags.length - 1);
            }
        });
        // Защита: при сабмите формы добавить недопечатанное
        var formEl = document.getElementById('post');
        if (formEl) formEl.addEventListener('submit', function(){ if (inp.value.trim()) addTag(inp.value); });

        // ---------- OG IMAGE — media library ----------
        var ogInp     = document.getElementById('svl_seo_og_image');
        var ogPick    = document.getElementById('svl-og-pick');
        var ogClear   = document.getElementById('svl-og-clear');
        var ogPreview = document.getElementById('svl-og-preview');
        var mediaFrame;

        function setOgPreview(url){
            if (url) {
                ogPreview.innerHTML = '<img src="' + escHtml(url) + '" alt="">';
                ogPreview.style.display = '';
            } else {
                ogPreview.innerHTML = '';
                ogPreview.style.display = 'none';
            }
        }
        ogPick.addEventListener('click', function(e){
            e.preventDefault();
            if (!window.wp || !wp.media) { alert('Медиабиблиотека недоступна. Обновите страницу.'); return; }
            if (mediaFrame) { mediaFrame.open(); return; }
            mediaFrame = wp.media({
                title: 'Выбрать OG-картинку',
                button: { text: 'Использовать' },
                library: { type: 'image' },
                multiple: false
            });
            mediaFrame.on('select', function(){
                var att = mediaFrame.state().get('selection').first().toJSON();
                // Приоритет: размер svl_seo_og (1200×630) → large → оригинал
                var url = (att.sizes && att.sizes.svl_seo_og && att.sizes.svl_seo_og.url)
                    ? att.sizes.svl_seo_og.url
                    : (att.sizes && att.sizes.large ? att.sizes.large.url : att.url);
                ogInp.value = url;
                setOgPreview(url);
                if (!att.sizes || !att.sizes.svl_seo_og) {
                    var msg = document.getElementById('svl-og-warn');
                    if (!msg) {
                        msg = document.createElement('p');
                        msg.id = 'svl-og-warn';
                        msg.className = 'description';
                        msg.style.color = '#dba617';
                        msg.innerHTML = '⚠️ Картинка ещё не пересохранена в размере 1200×630. Зайдите в <strong>Инструменты → Regenerate Thumbnails</strong> или загрузите новый файл — иначе соцсети покажут оригинал.';
                        document.getElementById('svl-og-preview').after(msg);
                    }
                } else {
                    var w = document.getElementById('svl-og-warn'); if (w) w.remove();
                }
                recompute();
            });
            mediaFrame.open();
        });
        ogClear.addEventListener('click', function(e){
            e.preventDefault();
            ogInp.value = '';
            setOgPreview('');
            recompute();
        });
        ogInp.addEventListener('change', function(){ setOgPreview(ogInp.value.trim()); });

        // ---------- Score / preview ----------
        function fmtTitle(t) {
            return format.replace(/%title%/g, t || defTitle).replace(/%sitename%/g, sitename).replace(/%sep%/g, sep).replace(/%tagline%/g, '').replace(/\s+/g, ' ').trim();
        }
        function getContent() {
            try { if (window.wp && wp.data && wp.data.select('core/editor')) return wp.data.select('core/editor').getEditedPostContent() || ''; } catch(e){}
            try { if (window.tinymce && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) return tinymce.activeEditor.getContent({format:'text'}) || ''; } catch(e){}
            var ta = document.getElementById('content'); return ta ? ta.value : '';
        }
        function plainText(html) {
            var d = document.createElement('div'); d.innerHTML = html; return (d.textContent || '').toLowerCase();
        }
        function setCounter(el, len, min, max) {
            el.textContent = len + ' символов (рекомендуется ' + min + '–' + max + ')';
            el.className = 'counter ' + (len >= min && len <= max ? 'ok' : 'bad');
        }
        function recompute() {
            var T = document.getElementById('svl_seo_title').value.trim();
            var D = document.getElementById('svl_seo_desc').value.trim();
            var K = hidden.value.trim();
            var F = document.getElementById('svl_seo_focus').value.trim().toLowerCase();
            var content = plainText(getContent());

            var checks = {
                title: T.length > 0,
                title_len: T.length >= 30 && T.length <= 60,
                desc: D.length > 0,
                desc_len: D.length >= 120 && D.length <= 160,
                kw: K.length > 0,
                focus: F.length > 0,
                focus_title: F && T.toLowerCase().indexOf(F) !== -1,
                focus_desc: F && D.toLowerCase().indexOf(F) !== -1,
                focus_content: F && content.indexOf(F) !== -1,
                image: !!ogInp.value.trim()
            };
            var weights = {title:15,title_len:10,desc:15,desc_len:15,kw:10,focus:5,focus_title:10,focus_desc:10,focus_content:5,image:5};
            var score = 0;
            Object.keys(weights).forEach(function(k){ if (checks[k]) score += weights[k]; });
            var list = document.getElementById('svl-checks');
            list.querySelectorAll('li').forEach(function(li){
                var k = li.getAttribute('data-check');
                li.className = checks[k] ? 'ok' : (k.indexOf('focus_') === 0 || k === 'kw' ? 'warn' : 'fail');
            });
            var bar = document.getElementById('svl-bar');
            var c = score < 40 ? '#d63638' : score < 70 ? '#dba617' : score < 90 ? '#7ad03a' : '#00a32a';
            bar.style.width = score + '%'; bar.style.background = c;
            document.getElementById('svl-score').textContent = score;
            setCounter(document.getElementById('svl-cnt-title'), T.length, 30, 60);
            setCounter(document.getElementById('svl-cnt-desc'),  D.length, 120, 160);
            document.getElementById('svl-snip-title').textContent = fmtTitle(T);
            document.getElementById('svl-snip-desc').textContent  = D || '';
        }
        ['svl_seo_title','svl_seo_desc','svl_seo_focus','svl_seo_og_image'].forEach(function(id){
            var el = document.getElementById(id);
            if (!el) return;
            ['input','change','keyup'].forEach(function(ev){ el.addEventListener(ev, recomputeDebounced); });
        });
        recompute();
    })();
    </script>
    <?php
}

add_action('save_post', 'svl_seo_save_post', 10, 2);
function svl_seo_save_post($post_id, $post) {
    if (!isset($_POST['svl_seo_nonce']) || !wp_verify_nonce($_POST['svl_seo_nonce'], 'svl_seo_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $map = array(
        '_svl_seo_title'    => 'sanitize_text_field',
        '_svl_seo_desc'     => 'sanitize_textarea_field',
        '_svl_seo_keywords' => 'sanitize_text_field',
        '_svl_seo_focus'    => 'sanitize_text_field',
        '_svl_seo_robots'   => 'sanitize_text_field',
        '_svl_seo_canonical'=> 'esc_url_raw',
        '_svl_seo_og_image' => 'esc_url_raw',
    );
    foreach ($map as $meta => $fn) {
        $field = 'svl_seo_' . str_replace('_svl_seo_', '', $meta);
        $val = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
        $val = call_user_func($fn, $val);
        if ($val === '' || $val === null) {
            delete_post_meta($post_id, $meta);
        } else {
            update_post_meta($post_id, $meta, $val);
        }
    }
}

// ==========================================
// 11. СЕРВЕРНЫЙ СЧЁТЧИК
// ==========================================

function svl_seo_score($post_id) {
    $post = get_post($post_id);
    $title = get_post_meta($post_id, '_svl_seo_title', true);
    $desc  = get_post_meta($post_id, '_svl_seo_desc', true);
    $kw    = get_post_meta($post_id, '_svl_seo_keywords', true);
    $focus = mb_strtolower(get_post_meta($post_id, '_svl_seo_focus', true));
    $ogimg = get_post_meta($post_id, '_svl_seo_og_image', true);
    if (!$ogimg && $post) $ogimg = get_the_post_thumbnail_url($post_id, 'full');
    if (!$ogimg) $ogimg = svl_seo_opt('svl_seo_default_og_image');
    $content_raw = $post ? $post->post_content : '';
    $content = mb_strtolower(wp_strip_all_tags(strip_shortcodes($content_raw)));
    $title_l = mb_strtolower($title);
    $desc_l  = mb_strtolower($desc);

    $weights = array(
        'title' => 15, 'title_len' => 10, 'desc' => 15, 'desc_len' => 15,
        'kw' => 10, 'focus' => 5, 'focus_title' => 10, 'focus_desc' => 10,
        'focus_content' => 5, 'image' => 5,
    );
    $checks = array(
        'title'         => array('label' => 'SEO Title заполнен',                'status' => $title ? 'ok' : 'fail'),
        'title_len'     => array('label' => 'Title 30–60 символов',              'status' => (mb_strlen($title) >= 30 && mb_strlen($title) <= 60) ? 'ok' : 'warn'),
        'desc'          => array('label' => 'Meta Description заполнен',         'status' => $desc ? 'ok' : 'fail'),
        'desc_len'      => array('label' => 'Description 120–160 символов',      'status' => (mb_strlen($desc) >= 120 && mb_strlen($desc) <= 160) ? 'ok' : 'warn'),
        'kw'            => array('label' => 'Keywords заданы',                   'status' => $kw ? 'ok' : 'warn'),
        'focus'         => array('label' => 'Фокус-ключ задан',                  'status' => $focus ? 'ok' : 'warn'),
        'focus_title'   => array('label' => 'Фокус в title',                     'status' => ($focus && mb_strpos($title_l, $focus) !== false) ? 'ok' : 'warn'),
        'focus_desc'    => array('label' => 'Фокус в description',               'status' => ($focus && mb_strpos($desc_l, $focus) !== false) ? 'ok' : 'warn'),
        'focus_content' => array('label' => 'Фокус в контенте',                  'status' => ($focus && mb_strpos($content, $focus) !== false) ? 'ok' : 'warn'),
        'image'         => array('label' => 'OG-картинка установлена',           'status' => $ogimg ? 'ok' : 'warn'),
    );
    $score = 0;
    foreach ($weights as $k => $w) if ($checks[$k]['status'] === 'ok') $score += $w;
    return array('score' => $score, 'checks' => $checks);
}

function svl_seo_score_color($score) {
    if ($score < 40) return '#d63638';
    if ($score < 70) return '#dba617';
    if ($score < 90) return '#7ad03a';
    return '#00a32a';
}

// ==========================================
// 12. SEO-КОЛОНКА В СПИСКЕ ЗАПИСЕЙ
// ==========================================

add_action('admin_init', 'svl_seo_register_columns');
function svl_seo_register_columns() {
    foreach (get_post_types(array('public' => true), 'names') as $pt) {
        add_filter("manage_{$pt}_posts_columns",       'svl_seo_columns_add');
        add_action("manage_{$pt}_posts_custom_column", 'svl_seo_columns_render', 10, 2);
    }
}
function svl_seo_columns_add($cols) { $cols['svl_seo'] = __('SEO', 'svl'); return $cols; }
function svl_seo_columns_render($col, $post_id) {
    if ($col !== 'svl_seo') return;
    $s = svl_seo_score($post_id);
    $c = svl_seo_score_color($s['score']);
    echo '<div style="display:flex;align-items:center;gap:6px;">';
    echo '<div style="flex:1;height:6px;background:#f0f0f0;border-radius:3px;max-width:80px;overflow:hidden;"><div style="height:100%;width:' . intval($s['score']) . '%;background:' . esc_attr($c) . ';"></div></div>';
    echo '<span style="font-size:11px;color:' . esc_attr($c) . ';font-weight:600;">' . intval($s['score']) . '%</span>';
    echo '</div>';
}

// ==========================================
// 13. UI НАСТРОЕК
// ==========================================

add_action('admin_menu', 'svl_seo_admin_menu', 20);
function svl_seo_admin_menu() {
    add_submenu_page('svl-stats', __('VIP SEO', 'svl'), __('VIP SEO', 'svl'), 'manage_options', 'svl-seo', 'svl_seo_settings_page');
}

function svl_seo_settings_page() {
    if (!current_user_can('manage_options')) wp_die();
    if (isset($_POST['svl_seo_save']) && check_admin_referer('svl_seo_settings', 'svl_seo_settings_nonce')) {
        foreach (array_keys(svl_seo_default_options()) as $k) {
            $v = isset($_POST[$k]) ? wp_unslash($_POST[$k]) : '';
            if (in_array($k, array('svl_seo_default_teaser', 'svl_seo_archive_desc'), true)) {
                $v = sanitize_textarea_field($v);
            } elseif (in_array($k, array('svl_seo_default_og_image', 'svl_seo_org_logo'), true)) {
                $v = esc_url_raw($v);
            } elseif (in_array($k, array('svl_seo_enabled','svl_seo_apply_all','svl_seo_override_plugin','svl_seo_breadcrumbs','svl_seo_noindex_paged'), true)) {
                $v = $v ? 1 : 0;
            } else {
                $v = sanitize_text_field($v);
            }
            update_option($k, $v);
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Настройки сохранены.', 'svl') . '</p></div>';
    }
    $o = function ($k) { return svl_seo_opt($k); };
    $og_default = $o('svl_seo_default_og_image');
    $logo       = $o('svl_seo_org_logo');
    ?>
    <style>
        .svl-set { max-width: 980px; }
        .svl-set h1 { display: flex; align-items: center; gap: 10px; }
        .svl-set .nav-tab-wrapper { margin-top: 20px; }
        .svl-set .svl-section { display: none; background: #fff; border: 1px solid #c3c4c7; border-top: 0; padding: 20px 24px; }
        .svl-set .svl-section.active { display: block; }
        .svl-set h2.svl-h { margin-top: 0; padding-bottom: 8px; border-bottom: 1px solid #f0f0f1; }
        .svl-set .form-table th { width: 220px; padding-top: 18px; }
        .svl-set .description { font-size: 13px; color: #50575e; max-width: 640px; line-height: 1.6; }
        .svl-set .description strong { color: #1d2327; }
        .svl-set .svl-hint { background: #f0f6fc; border-left: 3px solid #2271b1; padding: 12px 16px; margin: 0 0 20px; font-size: 13px; line-height: 1.6; max-width: 640px; }
        .svl-set .svl-hint code { background: #fff; padding: 1px 6px; border-radius: 3px; }
        .svl-set .svl-warn { background: #fcf0f1; border-left: 3px solid #d63638; padding: 12px 16px; margin: 8px 0; font-size: 13px; max-width: 640px; }
        .svl-set .svl-img-row { display: flex; gap: 10px; align-items: flex-start; }
        .svl-set .svl-img-row input[type=url] { flex: 1; max-width: 480px; }
        .svl-set .svl-img-prev { margin-top: 10px; }
        .svl-set .svl-img-prev img { max-width: 240px; height: auto; border: 1px solid #dcdcde; border-radius: 4px; display: block; }
        .svl-set .svl-toggle { display: inline-flex; align-items: center; gap: 8px; }
        .svl-set .svl-row-doc { background: #fafafa; padding: 8px 10px; border-radius: 4px; margin-top: 6px; font-size: 12px; color: #646970; }
        .svl-set .svl-submit { padding: 16px 0; background: #fff; border: 1px solid #c3c4c7; border-top: 0; padding: 16px 24px; }
    </style>
    <div class="wrap svl-set">
        <div class="svl-hero">
            <h1>🔍 <?php _e('SEO — продвижение и соцсети', 'svl'); ?></h1>
            <p><?php _e('Этот модуль управляет тем, как ваш сайт выглядит в поиске Google/Яндекс и в соцсетях (Telegram, Facebook, ВК, Twitter). Закрытый контент под замком автоматически скрывается от поисковиков, но превью остаётся качественным благодаря публичному тизеру и paywall-разметке.', 'svl'); ?></p>
            <div class="svl-hero-meta">
                <span>📊 <a href="<?php echo esc_url(admin_url('admin.php?page=svl-stats')); ?>" style="color:#fff;text-decoration:underline;">Статистика</a></span>
                <span>⚙️ <a href="<?php echo esc_url(admin_url('admin.php?page=svl-settings')); ?>" style="color:#fff;text-decoration:underline;">Настройки замка</a></span>
                <span>🔍 <a href="https://search.google.com/test/rich-results" target="_blank" style="color:#fff;text-decoration:underline;">Rich Results Test</a></span>
            </div>
        </div>

        <h2 class="nav-tab-wrapper">
            <a href="#tab-main"      class="nav-tab nav-tab-active" data-tab="main">⚙️ <?php _e('Основные', 'svl'); ?></a>
            <a href="#tab-title"     class="nav-tab" data-tab="title">📝 <?php _e('Заголовки', 'svl'); ?></a>
            <a href="#tab-defaults"  class="nav-tab" data-tab="defaults">🎨 <?php _e('Соцсети и бренд', 'svl'); ?></a>
            <a href="#tab-archives"  class="nav-tab" data-tab="archives">📚 <?php _e('Архивы', 'svl'); ?></a>
            <a href="#tab-verify"    class="nav-tab" data-tab="verify">✅ <?php _e('Verification', 'svl'); ?></a>
        </h2>

        <form method="post">
            <?php wp_nonce_field('svl_seo_settings', 'svl_seo_settings_nonce'); ?>

            <!-- ===== TAB: ОСНОВНЫЕ ===== -->
            <div class="svl-section active" id="tab-main">
                <h2 class="svl-h"><?php _e('Основные параметры', 'svl'); ?></h2>
                <p class="svl-hint">💡 <?php _e('Если у вас уже стоит Yoast/RankMath/AIOSEO — оставьте «Перебивать другие SEO-плагины» <strong>выключенным</strong>. Наш модуль будет работать как страховка для пустых полей и paywall-разметки.', 'svl'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><?php _e('Активность модуля', 'svl'); ?></th>
                        <td>
                            <label class="svl-toggle"><input type="checkbox" name="svl_seo_enabled" value="1" <?php checked($o('svl_seo_enabled'), 1); ?>> <?php _e('Включить SEO-модуль', 'svl'); ?></label>
                            <p class="description"><?php _e('Отключает все мета-теги и Schema. Используйте только для отладки или если переходите на другой SEO-плагин.', 'svl'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Область применения', 'svl'); ?></th>
                        <td>
                            <label class="svl-toggle"><input type="checkbox" name="svl_seo_apply_all" value="1" <?php checked($o('svl_seo_apply_all'), 1); ?>> <?php _e('Применять ко всем страницам сайта', 'svl'); ?></label>
                            <p class="description"><?php _e('Если <strong>выключено</strong>, мета-теги генерируются только на страницах с шорткодом <code>[vip_locker]</code>. Если <strong>включено</strong> — на всех записях, страницах, архивах и категориях. Рекомендуется включить.', 'svl'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Override других SEO-плагинов', 'svl'); ?></th>
                        <td>
                            <label class="svl-toggle"><input type="checkbox" name="svl_seo_override_plugin" value="1" <?php checked($o('svl_seo_override_plugin'), 1); ?>> <?php _e('Перебивать вывод Yoast/RankMath/AIOSEO', 'svl'); ?></label>
                            <div class="svl-warn">⚠️ <strong><?php _e('Включайте только если деактивировали Yoast/RankMath/AIOSEO.', 'svl'); ?></strong> <?php _e('Иначе будут дублироваться title/description/og-теги — это вредит SEO.', 'svl'); ?></div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Хлебные крошки (Schema)', 'svl'); ?></th>
                        <td>
                            <label class="svl-toggle"><input type="checkbox" name="svl_seo_breadcrumbs" value="1" <?php checked($o('svl_seo_breadcrumbs'), 1); ?>> <?php _e('Включить разметку BreadcrumbList', 'svl'); ?></label>
                            <p class="description"><?php _e('Google показывает путь «Главная → Категория → Статья» вместо длинного URL в выдаче. Повышает CTR. Никаких визуальных изменений на сайте — только разметка для поисковиков.', 'svl'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Пагинация архивов', 'svl'); ?></th>
                        <td>
                            <label class="svl-toggle"><input type="checkbox" name="svl_seo_noindex_paged" value="1" <?php checked($o('svl_seo_noindex_paged'), 1); ?>> <?php _e('Закрыть от индексации страницы /page/2/, /page/3/...', 'svl'); ?></label>
                            <p class="description"><?php _e('Защита от <strong>каннибализации</strong>: вторая, третья и т.д. страницы архивов попадают в индекс и конкурируют с первой за один и тот же запрос. Рекомендуется включить.', 'svl'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ===== TAB: ЗАГОЛОВКИ ===== -->
            <div class="svl-section" id="tab-title">
                <h2 class="svl-h"><?php _e('Формат заголовков и описаний', 'svl'); ?></h2>
                <p class="svl-hint">💡 <?php _e('Заголовок — самый важный SEO-фактор. Эти настройки задают шаблон для всех страниц, его можно переопределить в каждой конкретной записи.', 'svl'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="svl_seo_title_format"><?php _e('Шаблон заголовка', 'svl'); ?></label></th>
                        <td>
                            <input type="text" id="svl_seo_title_format" name="svl_seo_title_format" value="<?php echo esc_attr($o('svl_seo_title_format')); ?>" class="large-text" style="max-width:480px;">
                            <p class="description">
                                <?php _e('Что подставится в <code>&lt;title&gt;</code> на каждой странице. Доступные плейсхолдеры:', 'svl'); ?><br>
                                <code>%title%</code> — <?php _e('заголовок записи/страницы', 'svl'); ?><br>
                                <code>%sitename%</code> — <?php _e('название сайта', 'svl'); ?><br>
                                <code>%sep%</code> — <?php _e('разделитель (см. ниже)', 'svl'); ?><br>
                                <code>%tagline%</code> — <?php _e('слоган сайта (Настройки → Общие)', 'svl'); ?>
                            </p>
                            <div class="svl-row-doc"><?php _e('Пример:', 'svl'); ?> <code>%title% %sep% %sitename%</code> → <em>Рецепт борща — Мой кулинарный блог</em></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svl_seo_title_sep"><?php _e('Разделитель', 'svl'); ?></label></th>
                        <td>
                            <input type="text" id="svl_seo_title_sep" name="svl_seo_title_sep" value="<?php echo esc_attr($o('svl_seo_title_sep')); ?>" class="small-text" style="text-align:center;">
                            <p class="description"><?php _e('Символ между частями заголовка. Популярные:', 'svl'); ?> <code>—</code> <code>|</code> <code>·</code> <code>•</code> <code>»</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svl_seo_default_teaser"><?php _e('Дефолтное описание', 'svl'); ?></label></th>
                        <td>
                            <textarea id="svl_seo_default_teaser" name="svl_seo_default_teaser" rows="3" class="large-text" style="max-width:640px;" placeholder="<?php esc_attr_e('Например: Подборки колод, гайды и стримы по Hearthstone — обновляется ежедневно.', 'svl'); ?>"><?php echo esc_textarea($o('svl_seo_default_teaser')); ?></textarea>
                            <p class="description"><?php _e('Описание для главной и для записей без своего <code>meta description</code>. <strong>Длина 120–160 символов</strong> — оптимум для сниппета в Google.', 'svl'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ===== TAB: СОЦСЕТИ И БРЕНД ===== -->
            <div class="svl-section" id="tab-defaults">
                <h2 class="svl-h"><?php _e('Open Graph, Twitter Cards и Schema.org Organization', 'svl'); ?></h2>
                <p class="svl-hint">💡 <?php _e('Эти данные определяют, как ваши страницы выглядят при шеринге в Telegram, Facebook, ВК, Twitter, Slack, Discord. Также влияют на «карточку компании» в Google.', 'svl'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="svl_seo_default_og_image"><?php _e('OG-картинка по умолчанию', 'svl'); ?></label></th>
                        <td>
                            <div class="svl-img-row">
                                <input type="url" id="svl_seo_default_og_image" name="svl_seo_default_og_image" value="<?php echo esc_attr($og_default); ?>" placeholder="https://...">
                                <button type="button" class="button" data-svl-pick="svl_seo_default_og_image"><?php _e('Выбрать из библиотеки', 'svl'); ?></button>
                                <button type="button" class="button-link" style="color:#d63638;" data-svl-clear="svl_seo_default_og_image"><?php _e('Очистить', 'svl'); ?></button>
                            </div>
                            <div class="svl-img-prev" data-svl-prev="svl_seo_default_og_image">
                                <?php if ($og_default): ?><img src="<?php echo esc_url($og_default); ?>" alt=""><?php endif; ?>
                            </div>
                            <p class="description">
                                <?php _e('Используется когда у конкретной записи нет своей OG-картинки и нет миниатюры.', 'svl'); ?><br>
                                <strong><?php _e('Размер:', 'svl'); ?> 1200×630 пикселей</strong> — <?php _e('официальный стандарт Facebook/OpenGraph (соотношение 1.91:1).', 'svl'); ?>
                                <?php _e('Плагин автоматически создаёт обрезанную версию <code>1200×630</code> для всех новых картинок. Для уже загруженных — запустите <a href="https://wordpress.org/plugins/regenerate-thumbnails/" target="_blank">Regenerate Thumbnails</a>.', 'svl'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svl_seo_twitter_site"><?php _e('Twitter @username сайта', 'svl'); ?></label></th>
                        <td>
                            <input type="text" id="svl_seo_twitter_site" name="svl_seo_twitter_site" value="<?php echo esc_attr($o('svl_seo_twitter_site')); ?>" class="regular-text" placeholder="@yoursite">
                            <p class="description"><?php _e('Юзернейм сайта в X/Twitter — отображается в карточке при шеринге. С символом @. Если у сайта нет аккаунта — оставьте пустым.', 'svl'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th colspan="2" style="padding-bottom:0;"><h3 style="margin:24px 0 4px;">🏢 <?php _e('Организация (Schema.org)', 'svl'); ?></h3></th>
                    </tr>
                    <tr>
                        <th><label for="svl_seo_org_name"><?php _e('Название организации', 'svl'); ?></label></th>
                        <td>
                            <input type="text" id="svl_seo_org_name" name="svl_seo_org_name" value="<?php echo esc_attr($o('svl_seo_org_name')); ?>" class="regular-text">
                            <p class="description"><?php _e('Название бренда/компании/проекта. Используется в Schema.org для «Knowledge Graph» Google. Если пусто — берётся название сайта.', 'svl'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="svl_seo_org_logo"><?php _e('Логотип организации', 'svl'); ?></label></th>
                        <td>
                            <div class="svl-img-row">
                                <input type="url" id="svl_seo_org_logo" name="svl_seo_org_logo" value="<?php echo esc_attr($logo); ?>" placeholder="https://...">
                                <button type="button" class="button" data-svl-pick="svl_seo_org_logo"><?php _e('Выбрать из библиотеки', 'svl'); ?></button>
                                <button type="button" class="button-link" style="color:#d63638;" data-svl-clear="svl_seo_org_logo"><?php _e('Очистить', 'svl'); ?></button>
                            </div>
                            <div class="svl-img-prev" data-svl-prev="svl_seo_org_logo">
                                <?php if ($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="" style="max-width:150px;"><?php endif; ?>
                            </div>
                            <p class="description">
                                <?php _e('Логотип на прозрачном/белом фоне. <strong>Минимум 112×112</strong>, оптимум — квадрат <strong>500×500</strong>. PNG с прозрачностью или SVG. Используется в Schema, может попасть в карточку Google.', 'svl'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ===== TAB: АРХИВЫ ===== -->
            <div class="svl-section" id="tab-archives">
                <h2 class="svl-h"><?php _e('Описания категорий, тегов и архивов', 'svl'); ?></h2>
                <p class="svl-hint">💡 <?php _e('Если у категории/тега нет своего описания (поле «Описание» в редакторе термина), используется этот шаблон.', 'svl'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="svl_seo_archive_desc"><?php _e('Шаблон описания', 'svl'); ?></label></th>
                        <td>
                            <textarea id="svl_seo_archive_desc" name="svl_seo_archive_desc" rows="3" class="large-text" style="max-width:640px;"><?php echo esc_textarea($o('svl_seo_archive_desc')); ?></textarea>
                            <p class="description">
                                <?php _e('Доступные плейсхолдеры:', 'svl'); ?><br>
                                <code>%name%</code> — <?php _e('название категории/тега/архива', 'svl'); ?><br>
                                <code>%sitename%</code> — <?php _e('название сайта', 'svl'); ?>
                            </p>
                            <div class="svl-row-doc"><?php _e('Пример:', 'svl'); ?> <code>Все статьи в категории «%name%» — %sitename%</code></div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ===== TAB: VERIFICATION ===== -->
            <div class="svl-section" id="tab-verify">
                <h2 class="svl-h"><?php _e('Подтверждение прав на сайт у поисковиков', 'svl'); ?></h2>
                <p class="svl-hint">💡 <?php _e('Чтобы поисковики приняли ваш сайт в свои инструменты вебмастеров (Google Search Console, Yandex Webmaster и т.д.), они просят добавить специальный мета-тег. Скопируйте только <strong>значение content=""</strong>, без всего тега.', 'svl'); ?></p>

                <table class="form-table">
                    <tr>
                        <th>🟢 Google Search Console</th>
                        <td>
                            <input type="text" name="svl_seo_verify_google" value="<?php echo esc_attr($o('svl_seo_verify_google')); ?>" class="large-text" style="max-width:480px;" placeholder="abcDEF123...">
                            <p class="description"><a href="https://search.google.com/search-console" target="_blank">search.google.com/search-console</a> → <em><?php _e('Добавить ресурс → URL-префикс → HTML-тег</em>. Значение из <code>content="..."</code>.', 'svl'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th>🔴 Яндекс Вебмастер</th>
                        <td>
                            <input type="text" name="svl_seo_verify_yandex" value="<?php echo esc_attr($o('svl_seo_verify_yandex')); ?>" class="large-text" style="max-width:480px;" placeholder="1234567890abcdef">
                            <p class="description"><a href="https://webmaster.yandex.ru/" target="_blank">webmaster.yandex.ru</a> → <em><?php _e('Добавить сайт → Мета-тег. Значение из', 'svl'); ?> <code>content="..."</code>.</em></p>
                        </td>
                    </tr>
                    <tr>
                        <th>🔵 Bing Webmaster</th>
                        <td>
                            <input type="text" name="svl_seo_verify_bing" value="<?php echo esc_attr($o('svl_seo_verify_bing')); ?>" class="large-text" style="max-width:480px;">
                            <p class="description"><a href="https://www.bing.com/webmasters" target="_blank">bing.com/webmasters</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th>📌 Pinterest</th>
                        <td>
                            <input type="text" name="svl_seo_verify_pinterest" value="<?php echo esc_attr($o('svl_seo_verify_pinterest')); ?>" class="large-text" style="max-width:480px;">
                            <p class="description"><a href="https://www.pinterest.com/business/" target="_blank">pinterest.com/business</a> — <?php _e('для подтверждения сайта в Pinterest Business (нужен только для шеринга).', 'svl'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="svl-submit">
                <button type="submit" name="svl_seo_save" class="button button-primary button-large">💾 <?php _e('Сохранить настройки', 'svl'); ?></button>
                <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" class="button" style="margin-left:8px;">🔍 <?php _e('Проверить главную', 'svl'); ?></a>
                <a href="https://search.google.com/test/rich-results" target="_blank" class="button" style="margin-left:8px;"><?php _e('Тест Schema (Google)', 'svl'); ?></a>
                <a href="https://cards-dev.twitter.com/validator" target="_blank" class="button" style="margin-left:8px;"><?php _e('Тест Twitter Card', 'svl'); ?></a>
                <a href="<?php echo esc_url(home_url('/sitemap-vip.xml')); ?>" target="_blank" class="button" style="margin-left:8px;">📄 <?php _e('VIP Sitemap', 'svl'); ?></a>
            </div>
        </form>
    </div>

    <script>
    (function(){
        // Табы
        var tabs = document.querySelectorAll('.nav-tab');
        var sections = document.querySelectorAll('.svl-section');
        function activate(id){
            tabs.forEach(function(t){ t.classList.toggle('nav-tab-active', t.getAttribute('data-tab') === id); });
            sections.forEach(function(s){ s.classList.toggle('active', s.id === 'tab-' + id); });
            try { history.replaceState(null, '', '#tab-' + id); } catch(e){}
        }
        tabs.forEach(function(t){ t.addEventListener('click', function(e){ e.preventDefault(); activate(t.getAttribute('data-tab')); }); });
        var hash = (location.hash || '').replace('#tab-', '');
        if (hash) activate(hash);

        // Медиа-пикеры
        function escHtml(s){ return String(s).replace(/[&<>"']/g, function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
        var frames = {};
        document.querySelectorAll('[data-svl-pick]').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                var fid = btn.getAttribute('data-svl-pick');
                var input = document.getElementById(fid);
                var prev = document.querySelector('[data-svl-prev="' + fid + '"]');
                if (!window.wp || !wp.media) { alert('Медиабиблиотека недоступна.'); return; }
                if (!frames[fid]) {
                    frames[fid] = wp.media({
                        title: 'Выбрать картинку',
                        button: { text: 'Использовать' },
                        library: { type: 'image' },
                        multiple: false
                    });
                    frames[fid].on('select', function(){
                        var att = frames[fid].state().get('selection').first().toJSON();
                        var url = (att.sizes && att.sizes.svl_seo_og && att.sizes.svl_seo_og.url)
                            ? att.sizes.svl_seo_og.url
                            : (att.sizes && att.sizes.large ? att.sizes.large.url : att.url);
                        // Для логотипа берём оригинал — нам не нужен кроп
                        if (fid === 'svl_seo_org_logo') url = att.url;
                        input.value = url;
                        if (prev) prev.innerHTML = '<img src="' + escHtml(url) + '" alt=""' + (fid === 'svl_seo_org_logo' ? ' style="max-width:150px;"' : '') + '>';
                    });
                }
                frames[fid].open();
            });
        });
        document.querySelectorAll('[data-svl-clear]').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                var fid = btn.getAttribute('data-svl-clear');
                var input = document.getElementById(fid);
                var prev = document.querySelector('[data-svl-prev="' + fid + '"]');
                input.value = '';
                if (prev) prev.innerHTML = '';
            });
        });
        // ручное обновление превью при правке URL
        ['svl_seo_default_og_image','svl_seo_org_logo'].forEach(function(fid){
            var input = document.getElementById(fid);
            var prev = document.querySelector('[data-svl-prev="' + fid + '"]');
            if (!input || !prev) return;
            input.addEventListener('change', function(){
                var v = input.value.trim();
                prev.innerHTML = v ? '<img src="' + escHtml(v) + '" alt=""' + (fid === 'svl_seo_org_logo' ? ' style="max-width:150px;"' : '') + '>' : '';
            });
        });
    })();
    </script>
    <?php
}
