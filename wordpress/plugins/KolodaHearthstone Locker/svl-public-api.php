<?php
/**
 * Stable public content API and protected one-time redeem-link issuer.
 *
 * Public:
 *   GET  /wp-json/koloda/v1/articles
 *   GET  /wp-json/koloda/v1/articles/{id}
 *
 * Bearer-protected (uses the existing Telegram bot secret):
 *   POST /wp-json/koloda/v1/redeem-links
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'svl_public_api_register_routes');
function svl_public_api_register_routes() {
    register_rest_route(
        'koloda/v1',
        '/articles',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'svl_public_api_articles',
            'permission_callback' => '__return_true',
            'args' => array(
                'page' => array(
                    'description' => 'Номер страницы, начиная с 1.',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_positive_int',
                ),
                'pageSize' => array(
                    'description' => 'Количество статей на странице (1–50).',
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_page_size',
                ),
                'search' => array(
                    'description' => 'Поиск по заголовку и содержимому.',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'svl_public_api_validate_short_text',
                ),
                'category' => array(
                    'description' => 'Slug рубрики WordPress.',
                    'default' => '',
                    'sanitize_callback' => 'svl_public_api_sanitize_slug',
                ),
                'tag' => array(
                    'description' => 'Slug метки WordPress.',
                    'default' => '',
                    'sanitize_callback' => 'svl_public_api_sanitize_slug',
                ),
                'order' => array(
                    'description' => 'Порядок публикации: desc или asc.',
                    'default' => 'desc',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => 'svl_public_api_validate_order',
                ),
            ),
        )
    );

    register_rest_route(
        'koloda/v1',
        '/articles/page/(?P<page>\d+)/(?P<pageSize>\d+)',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'svl_public_api_articles',
            'permission_callback' => '__return_true',
            'args' => array(
                'page' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_positive_int',
                ),
                'pageSize' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_page_size',
                ),
            ),
        )
    );

    register_rest_route(
        'koloda/v1',
        '/articles/query',
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'svl_public_api_articles',
            'permission_callback' => '__return_true',
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_positive_int',
                ),
                'pageSize' => array(
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_page_size',
                ),
                'search' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'svl_public_api_validate_short_text',
                ),
                'category' => array(
                    'default' => '',
                    'sanitize_callback' => 'svl_public_api_sanitize_slug',
                ),
                'tag' => array(
                    'default' => '',
                    'sanitize_callback' => 'svl_public_api_sanitize_slug',
                ),
                'order' => array(
                    'default' => 'desc',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => 'svl_public_api_validate_order',
                ),
            ),
        )
    );

    register_rest_route(
        'koloda/v1',
        '/articles/(?P<id>\d+)',
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'svl_public_api_article',
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'description' => 'ID опубликованной статьи WordPress.',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_positive_int',
                ),
            ),
        )
    );

    register_rest_route(
        'koloda/v1',
        '/redeem-links',
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'svl_public_api_redeem_link',
            'permission_callback' => 'svl_public_api_redeem_permission',
            'args' => array(
                'articleId' => array(
                    'description' => 'ID VIP-статьи.',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_positive_int',
                ),
                'userId' => array(
                    'description' => 'Стабильный ID пользователя во внешней системе.',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'svl_public_api_validate_user_id',
                ),
                'ttlSeconds' => array(
                    'description' => 'Срок жизни одноразовой ссылки в секундах (60–86400).',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'svl_public_api_validate_ttl',
                ),
            ),
        )
    );
}

function svl_public_api_validate_positive_int($value) {
    return is_numeric($value) && intval($value) >= 1;
}

function svl_public_api_validate_page_size($value) {
    return is_numeric($value) && intval($value) >= 1 && intval($value) <= 50;
}

function svl_public_api_validate_short_text($value) {
    $value = (string) $value;
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return $length <= 100;
}

function svl_public_api_validate_order($value) {
    return in_array(strtolower((string) $value), array('asc', 'desc'), true);
}

function svl_public_api_sanitize_slug($value) {
    return sanitize_title((string) $value);
}

function svl_public_api_validate_user_id($value) {
    $value = trim((string) $value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return $length >= 1 && $length <= 100;
}

function svl_public_api_validate_ttl($value) {
    if ($value === '' || $value === null || intval($value) === 0) {
        return true;
    }
    return is_numeric($value) && intval($value) >= 60 && intval($value) <= 86400;
}

function svl_public_api_articles(WP_REST_Request $request) {
    $page = max(1, absint($request->get_param('page')));
    $page_size = max(1, min(50, absint($request->get_param('pageSize'))));
    $search = trim((string) $request->get_param('search'));
    $category = sanitize_title((string) $request->get_param('category'));
    $tag = sanitize_title((string) $request->get_param('tag'));
    $order = strtolower((string) $request->get_param('order')) === 'asc' ? 'ASC' : 'DESC';

    $query_args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $page_size,
        'paged' => $page,
        'orderby' => 'date',
        'order' => $order,
        'ignore_sticky_posts' => true,
    );

    if ($search !== '') {
        $query_args['s'] = $search;
    }
    if ($category !== '') {
        $query_args['category_name'] = $category;
    }
    if ($tag !== '') {
        $query_args['tag'] = $tag;
    }

    $query = new WP_Query($query_args);
    $items = array_map('svl_public_api_format_article', $query->posts);
    $total_pages = intval($query->max_num_pages);

    $response = new WP_REST_Response(
        array(
            'data' => $items,
            'pagination' => array(
                'page' => $page,
                'pageSize' => $page_size,
                'totalItems' => intval($query->found_posts),
                'totalPages' => $total_pages,
            ),
        ),
        200
    );
    if ($request->get_method() === 'POST') {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    } else {
        $response->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }
    $response->header('X-Koloda-API-Version', '1');
    return $response;
}

function svl_public_api_article(WP_REST_Request $request) {
    $post = get_post(absint($request->get_param('id')));
    if (!($post instanceof WP_Post) || $post->post_type !== 'post' || $post->post_status !== 'publish') {
        return new WP_Error(
            'koloda_article_not_found',
            'Статья не найдена.',
            array('status' => 404)
        );
    }

    $response = new WP_REST_Response(
        array('data' => svl_public_api_format_article($post)),
        200
    );
    $response->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    $response->header('X-Koloda-API-Version', '1');
    return $response;
}

function svl_public_api_format_article($post) {
    $post = get_post($post);
    $is_vip = false;
    if ($post instanceof WP_Post) {
        $content = (string) $post->post_content;
        $is_vip = has_shortcode($content, 'vip_locker')
            || (function_exists('has_block') && has_block('svl/locker', $post));
    }

    return array(
        'id' => intval($post->ID),
        'title' => html_entity_decode(
            wp_strip_all_tags(get_the_title($post)),
            ENT_QUOTES | ENT_HTML5,
            get_bloginfo('charset') ?: 'UTF-8'
        ),
        'url' => get_permalink($post),
        'publishedAt' => get_post_time(DATE_W3C, true, $post),
        'modifiedAt' => get_post_modified_time(DATE_W3C, true, $post),
        'excerpt' => svl_public_api_excerpt($post),
        'featuredImage' => svl_public_api_featured_image($post),
        'categories' => svl_public_api_terms($post->ID, 'category'),
        'tags' => svl_public_api_terms($post->ID, 'post_tag'),
        'access' => $is_vip ? 'vip' : 'public',
        'redeemEligible' => (bool) $is_vip,
    );
}

function svl_public_api_excerpt($post) {
    $text = trim((string) $post->post_excerpt);
    if ($text === '') {
        $text = strip_shortcodes((string) $post->post_content);
    }
    $text = html_entity_decode(
        wp_strip_all_tags($text),
        ENT_QUOTES | ENT_HTML5,
        get_bloginfo('charset') ?: 'UTF-8'
    );
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    return wp_trim_words($text, 36, '…');
}

function svl_public_api_featured_image($post) {
    $attachment_id = get_post_thumbnail_id($post);
    if (!$attachment_id) {
        return null;
    }

    $image = wp_get_attachment_image_src($attachment_id, 'large');
    if (!$image) {
        return null;
    }

    return array(
        'url' => (string) $image[0],
        'width' => intval($image[1]),
        'height' => intval($image[2]),
        'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
    );
}

function svl_public_api_terms($post_id, $taxonomy) {
    $terms = get_the_terms($post_id, $taxonomy);
    if (empty($terms) || is_wp_error($terms)) {
        return array();
    }

    return array_values(
        array_map(
            static function ($term) {
                $url = get_term_link($term);
                return array(
                    'id' => intval($term->term_id),
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                    'url' => is_wp_error($url) ? '' : (string) $url,
                );
            },
            $terms
        )
    );
}

function svl_public_api_redeem_permission(WP_REST_Request $request) {
    if (!function_exists('svl_bot_check_bearer')) {
        return new WP_Error(
            'koloda_redeem_unavailable',
            'Сервис redeem-ссылок временно недоступен.',
            array('status' => 503)
        );
    }
    return svl_bot_check_bearer($request);
}

function svl_public_api_redeem_link(WP_REST_Request $request) {
    if (!function_exists('svl_bot_extract_codes') || !function_exists('svl_bot_create_token')) {
        return new WP_Error(
            'koloda_redeem_unavailable',
            'Сервис redeem-ссылок временно недоступен.',
            array('status' => 503)
        );
    }

    $article_id = absint($request->get_param('articleId'));
    $user_id = trim((string) $request->get_param('userId'));
    $post = get_post($article_id);

    if (!($post instanceof WP_Post) || $post->post_type !== 'post' || $post->post_status !== 'publish') {
        return new WP_Error(
            'koloda_article_not_found',
            'Опубликованная статья не найдена.',
            array('status' => 404)
        );
    }

    $default_code = function_exists('svl_opt') ? trim((string) svl_opt('svl_default_code')) : '';
    if ($default_code === '') {
        $default_code = '12345';
    }
    $codes = svl_bot_extract_codes((string) $post->post_content, $default_code);
    if (empty($codes)) {
        return new WP_Error(
            'koloda_article_not_redeemable',
            'В этой статье нет VIP-блока для выдачи redeem-ссылки.',
            array('status' => 422)
        );
    }

    $ttl_default = max(
        60,
        intval(get_option(SVL_BOT_OPT_TTL, defined('SVL_BOT_DEFAULT_TTL') ? SVL_BOT_DEFAULT_TTL : 900))
    );
    $ttl_requested = absint($request->get_param('ttlSeconds'));
    $ttl = $ttl_requested > 0 ? max(60, min(86400, $ttl_requested)) : $ttl_default;
    $user_fingerprint = substr(hash('sha256', $user_id), 0, 32);
    $token = svl_bot_create_token($codes[0], $ttl, 'api:' . $user_fingerprint, $article_id);

    if (!$token) {
        return new WP_Error(
            'koloda_redeem_create_failed',
            'Не удалось создать redeem-ссылку.',
            array('status' => 500)
        );
    }

    $expires_at = time() + $ttl;
    $response = new WP_REST_Response(
        array(
            'data' => array(
                'redeemUrl' => rest_url(
                    'vip/v1/redeem/' . rawurlencode($token) . '/' . $article_id
                ),
                'article' => array(
                    'id' => $article_id,
                    'title' => html_entity_decode(
                        wp_strip_all_tags(get_the_title($post)),
                        ENT_QUOTES | ENT_HTML5,
                        get_bloginfo('charset') ?: 'UTF-8'
                    ),
                    'url' => get_permalink($post),
                    'publishedAt' => get_post_time(DATE_W3C, true, $post),
                ),
                'expiresAt' => gmdate(DATE_RFC3339, $expires_at),
                'ttlSeconds' => $ttl,
                'oneTime' => true,
            ),
        ),
        201
    );
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('X-Koloda-API-Version', '1');
    return $response;
}
