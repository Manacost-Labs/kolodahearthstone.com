<?php
/**
 * Koloda Hearthstone home and navigation redesign.
 *
 * Keeps the existing article body and VIP locker presentation intact while
 * applying the Arena parchment, a denser editorial grid, and icon-led menus.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SVL_HOME_REDESIGN_VERSION')) {
    define('SVL_HOME_REDESIGN_VERSION', '1.0.0');
}

/**
 * Gives production an emergency off-switch without requiring a code rollback.
 */
function svl_home_redesign_enabled() {
    return (bool) apply_filters('svl_home_redesign_enabled', true);
}

/**
 * Keeps the first editorial grid balanced at two complete rows.
 */
add_action('parse_tax_query', 'svl_home_redesign_home_post_count', 999);
function svl_home_redesign_home_post_count($query) {
    if (
        !svl_home_redesign_enabled()
        || is_admin()
        || !$query->is_main_query()
        || !is_home()
    ) {
        return;
    }

    $query->set('posts_per_page', 6);
}

/**
 * Adds stable semantic classes to the existing Blocksy desktop/mobile menus.
 */
add_filter('nav_menu_css_class', 'svl_home_redesign_menu_classes', 20, 4);
function svl_home_redesign_menu_classes($classes, $item, $args, $depth) {
    if (!svl_home_redesign_enabled() || intval($depth) !== 0) {
        return $classes;
    }

    $url = strtolower((string) ($item->url ?? ''));
    $raw_title = wp_strip_all_tags((string) ($item->title ?? ''));
    $title = function_exists('mb_strtolower')
        ? mb_strtolower($raw_title, 'UTF-8')
        : strtolower($raw_title);
    $icon_class = '';

    if (
        strpos($url, '/category/meta-otchet/') !== false
        || strpos($title, 'мета-отчет') !== false
        || strpos($title, 'мета-отчёт') !== false
    ) {
        $icon_class = 'kh-menu-icon--meta';
    } elseif (strpos($url, 'vicious-syndicate') !== false || strpos($title, 'vs радар') !== false) {
        $icon_class = 'kh-menu-icon--vs';
    } elseif (strpos($url, '/category/gajdy/') !== false || strpos($title, 'гайды') !== false) {
        $icon_class = 'kh-menu-icon--guides';
    } elseif (strpos($url, '/category/polya-srazhenij/') !== false || strpos($title, 'поля сражений') !== false) {
        $icon_class = 'kh-menu-icon--battlegrounds';
    } elseif (strpos($url, '/category/volnyj/') !== false || strpos($title, 'вольный') !== false) {
        $icon_class = 'kh-menu-icon--wild';
    } elseif (strpos($url, 'hearthpulse.net') !== false || strpos($title, 'статистика') !== false) {
        $icon_class = 'kh-menu-icon--stats';
    } elseif (strpos($url, 'boosty.to') !== false || strpos($title, 'оплатить подписку') !== false) {
        $icon_class = 'kh-menu-icon--boosty';
    }

    if ($icon_class !== '') {
        $classes[] = 'kh-menu-button';
        $classes[] = $icon_class;
    }

    return array_values(array_unique($classes));
}

/**
 * Keeps the primary header to one row: navigation, Statistics, then Boosty.
 */
add_filter('wp_nav_menu_items', 'svl_home_redesign_statistics_menu_item', 30, 2);
function svl_home_redesign_statistics_menu_item($items, $args) {
    if (
        !svl_home_redesign_enabled()
        || strpos($items, 'kh-menu-icon--wild') === false
    ) {
        return $items;
    }

    $items = preg_replace_callback(
        '~<li\b[^>]*>.*?</li>~is',
        static function ($matches) {
            return preg_match('~<a\b[^>]*>\s*FAQ\s*</a>~is', $matches[0])
                ? ''
                : $matches[0];
        },
        $items
    );

    if (strpos($items, 'kh-menu-icon--stats') === false) {
        $statistics_item = sprintf(
            '<li class="menu-item menu-item-type-custom kh-menu-button kh-menu-icon--stats"><a href="%1$s">%2$s</a></li>',
            esc_url('https://hearthpulse.net/'),
            esc_html('Статистика')
        );
        $items = preg_replace(
            '~(<li\b[^>]*\bkh-menu-icon--wild\b[^>]*>.*?</li>)~is',
            '$1' . $statistics_item,
            $items,
            1
        );
    }

    if (is_string($items) && strpos($items, 'kh-menu-icon--boosty') === false) {
        $boosty_item = sprintf(
            '<li class="menu-item menu-item-type-custom kh-menu-button kh-menu-icon--boosty"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
            esc_url('https://boosty.to/kolodahearthstone'),
            esc_html('Оплатить подписку')
        );
        $items = preg_replace(
            '~(<li\b[^>]*\bkh-menu-icon--stats\b[^>]*>.*?</li>)~is',
            '$1' . $boosty_item,
            $items,
            1
        );
    }

    return is_string($items) ? $items : '';
}

/**
 * Returns the editorial label shown on an archive card.
 */
function svl_home_redesign_post_label($post_id) {
    $categories = get_the_category($post_id);
    $slugs = array();

    foreach ($categories as $category) {
        $slugs[] = (string) $category->slug;
    }

    $labels = array(
        'meta-otchet' => 'Мета-отчёт',
        'gajdy' => 'Гайд',
        'polya-srazhenij' => 'Поля Сражений',
        'volnyj' => 'Вольный',
    );

    foreach ($labels as $slug => $label) {
        if (in_array($slug, $slugs, true)) {
            return $label;
        }
    }

    if (has_tag('arena', $post_id)) {
        return 'Арена';
    }

    return !empty($categories) ? (string) $categories[0]->name : 'Материал';
}

/**
 * Supplies card metadata without exposing private content or authentication.
 */
function svl_home_redesign_card_data() {
    global $wp_query;

    $cards = array();
    if (empty($wp_query->posts) || !is_array($wp_query->posts)) {
        return $cards;
    }

    foreach ($wp_query->posts as $post) {
        if (!($post instanceof WP_Post)) {
            continue;
        }

        $content = (string) $post->post_content;
        $is_vip = has_shortcode($content, 'vip_locker')
            || (function_exists('has_block') && has_block('svl/locker', $post));

        $cards[(string) $post->ID] = array(
            'label' => svl_home_redesign_post_label($post->ID),
            'date' => get_the_date('d.m.Y', $post),
            'dateIso' => get_the_date(DATE_W3C, $post),
            'vip' => (bool) $is_vip,
        );
    }

    return $cards;
}

/**
 * Public data endpoint used by the in-place home tag filter.
 */
add_action('rest_api_init', 'svl_home_redesign_register_rest_routes');
function svl_home_redesign_register_rest_routes() {
    foreach (
        array(
            '/home-posts',
            '/home-posts/(?P<term>\d+)/(?P<page>\d+)',
        ) as $route
    ) {
        register_rest_route(
            'koloda/v1',
            $route,
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => 'svl_home_redesign_rest_home_posts',
                'permission_callback' => '__return_true',
                'args' => array(
                    'term' => array(
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ),
                    'page' => array(
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    register_rest_route(
        'koloda/v1',
        '/home-search',
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'svl_home_redesign_rest_home_search',
            'permission_callback' => '__return_true',
            'args' => array(
                'search' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        )
    );
}

function svl_home_redesign_rest_home_posts(WP_REST_Request $request) {
    $tag_id = absint($request->get_param('term'));
    $page = max(1, absint($request->get_param('page')));
    $query_args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 6,
        'paged' => $page,
        'ignore_sticky_posts' => true,
    );

    if ($tag_id > 0) {
        $query_args['tag_id'] = $tag_id;
    }

    $query = new WP_Query($query_args);
    $items = array();

    foreach ($query->posts as $post) {
        $content = (string) $post->post_content;
        $is_vip = has_shortcode($content, 'vip_locker')
            || (function_exists('has_block') && has_block('svl/locker', $post));

        $items[] = array(
            'id' => intval($post->ID),
            'title' => wp_strip_all_tags(get_the_title($post)),
            'url' => get_permalink($post),
            'image' => get_the_post_thumbnail_url($post, 'medium_large') ?: '',
            'date' => get_the_date('d.m.Y', $post),
            'dateIso' => get_the_date(DATE_W3C, $post),
            'label' => svl_home_redesign_post_label($post->ID),
            'vip' => (bool) $is_vip,
        );
    }

    return rest_ensure_response(
        array(
            'items' => $items,
            'page' => $page,
            'totalPages' => max(1, intval($query->max_num_pages)),
            'totalItems' => intval($query->found_posts),
            'tagId' => $tag_id,
        )
    );
}

/**
 * Returns a small, cache-safe set of live-search results.
 *
 * POST is intentional here: the hosting cache currently ignores query-string
 * values for the standard WordPress search endpoint.
 */
function svl_home_redesign_rest_home_search(WP_REST_Request $request) {
    $search = trim((string) $request->get_param('search'));
    $search_length = function_exists('mb_strlen')
        ? mb_strlen($search, 'UTF-8')
        : strlen($search);

    if ($search === '') {
        return rest_ensure_response(array('items' => array()));
    }

    if ($search_length > 100) {
        return new WP_Error(
            'kh_search_too_long',
            'Сократите поисковый запрос до 100 символов.',
            array('status' => 422)
        );
    }

    $terms = preg_split('/[^\p{L}\p{N}._-]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
    $terms = array_values(
        array_filter(
            array_unique($terms),
            static function ($term) {
                return $term !== '';
            }
        )
    );

    if (empty($terms)) {
        return rest_ensure_response(array('items' => array()));
    }

    $title_filter = static function ($where) use ($terms) {
        global $wpdb;

        foreach ($terms as $term) {
            $where .= $wpdb->prepare(
                " AND {$wpdb->posts}.post_title LIKE %s",
                '%' . $wpdb->esc_like($term) . '%'
            );
        }

        return $where;
    };

    add_filter('posts_where', $title_filter, 20);
    $query = new WP_Query(
        array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 7,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'suppress_filters' => false,
        )
    );
    remove_filter('posts_where', $title_filter, 20);
    $charset = get_bloginfo('charset') ?: 'UTF-8';
    $items = array();

    foreach ($query->posts as $post) {
        $title = html_entity_decode(
            wp_strip_all_tags(get_the_title($post)),
            ENT_QUOTES | ENT_HTML5,
            $charset
        );
        $items[] = array(
            'id' => intval($post->ID),
            'title' => $title,
            'url' => get_permalink($post),
        );
    }

    return rest_ensure_response(array('items' => $items));
}

/**
 * Stores article ratings privately and exposes them only inside wp-admin.
 */
function svl_home_redesign_feedback_options() {
    return array(
        'good_content' => array(
            'label' => 'Хорошее содержание',
            'tone' => 'positive',
        ),
        'interesting_information' => array(
            'label' => 'Интересная информация',
            'tone' => 'positive',
        ),
        'clear_explanation' => array(
            'label' => 'Понятное объяснение',
            'tone' => 'positive',
        ),
        'practical_value' => array(
            'label' => 'Практическая польза',
            'tone' => 'positive',
        ),
        'useful_decks' => array(
            'label' => 'Полезные колоды и сборки',
            'tone' => 'positive',
        ),
        'timely_material' => array(
            'label' => 'Актуально для текущей меты',
            'tone' => 'positive',
        ),
        'weak_explanation' => array(
            'label' => 'Слабое объяснение',
            'tone' => 'improvement',
        ),
        'not_deep_enough' => array(
            'label' => 'Недостаточно глубины',
            'tone' => 'improvement',
        ),
        'not_enough_examples' => array(
            'label' => 'Не хватает примеров',
            'tone' => 'improvement',
        ),
        'hard_to_read' => array(
            'label' => 'Сложно воспринимать',
            'tone' => 'improvement',
        ),
        'outdated_information' => array(
            'label' => 'Данные уже неактуальны',
            'tone' => 'improvement',
        ),
        'missing_deck_codes' => array(
            'label' => 'Не хватает кодов колод',
            'tone' => 'improvement',
        ),
    );
}

add_action('init', 'svl_home_redesign_register_feedback_type');
function svl_home_redesign_register_feedback_type() {
    register_post_type(
        'kh_article_feedback',
        array(
            'labels' => array(
                'name' => 'Отзывы о статьях',
                'singular_name' => 'Отзыв о статье',
                'menu_name' => 'Отзывы о статьях',
                'all_items' => 'Все отзывы',
                'edit_item' => 'Просмотреть отзыв',
                'view_item' => 'Просмотреть отзыв',
                'search_items' => 'Искать отзывы',
                'not_found' => 'Отзывов пока нет',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'menu_icon' => 'dashicons-star-filled',
            'supports' => array('title', 'editor'),
            'capabilities' => array(
                'edit_post' => 'manage_options',
                'read_post' => 'manage_options',
                'delete_post' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'delete_posts' => 'manage_options',
                'delete_others_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
                'publish_posts' => 'do_not_allow',
                'create_posts' => 'do_not_allow',
            ),
            'map_meta_cap' => false,
        )
    );
}

add_filter(
    'wp_privacy_personal_data_exporters',
    'svl_home_redesign_feedback_privacy_exporter'
);
function svl_home_redesign_feedback_privacy_exporter($exporters) {
    $exporters['koloda-article-feedback'] = array(
        'exporter_friendly_name' => 'Отзывы о статьях KolodaHearthstone',
        'callback' => 'svl_home_redesign_feedback_export_personal_data',
    );
    return $exporters;
}

function svl_home_redesign_feedback_export_personal_data($email_address, $page = 1) {
    $email = sanitize_email($email_address);
    $per_page = 50;
    $feedback_ids = get_posts(
        array(
            'post_type' => 'kh_article_feedback',
            'post_status' => 'private',
            'posts_per_page' => $per_page,
            'paged' => max(1, absint($page)),
            'fields' => 'ids',
            'meta_key' => '_kh_email',
            'meta_value' => $email,
        )
    );
    $data = array();

    foreach ($feedback_ids as $feedback_id) {
        $source_id = absint(get_post_meta($feedback_id, '_kh_source_post_id', true));
        $data[] = array(
            'group_id' => 'koloda-article-feedback',
            'group_label' => 'Отзывы о статьях',
            'item_id' => 'feedback-' . $feedback_id,
            'data' => array(
                array('name' => 'Email', 'value' => $email),
                array('name' => 'Статья', 'value' => get_the_title($source_id)),
                array('name' => 'Оценка', 'value' => get_post_meta($feedback_id, '_kh_rating', true)),
                array('name' => 'Комментарий', 'value' => get_post_field('post_content', $feedback_id)),
                array('name' => 'Источник доступа', 'value' => get_post_meta($feedback_id, '_kh_access_source', true)),
                array('name' => 'Дата', 'value' => get_post_field('post_date_gmt', $feedback_id)),
            ),
        );
    }

    return array(
        'data' => $data,
        'done' => count($feedback_ids) < $per_page,
    );
}

add_filter(
    'wp_privacy_personal_data_erasers',
    'svl_home_redesign_feedback_privacy_eraser'
);
function svl_home_redesign_feedback_privacy_eraser($erasers) {
    $erasers['koloda-article-feedback'] = array(
        'eraser_friendly_name' => 'Отзывы о статьях KolodaHearthstone',
        'callback' => 'svl_home_redesign_feedback_erase_personal_data',
    );
    return $erasers;
}

function svl_home_redesign_feedback_erase_personal_data($email_address, $page = 1) {
    $email = sanitize_email($email_address);
    $per_page = 50;
    $feedback_ids = get_posts(
        array(
            'post_type' => 'kh_article_feedback',
            'post_status' => 'private',
            'posts_per_page' => $per_page,
            'paged' => 1,
            'fields' => 'ids',
            'meta_key' => '_kh_email',
            'meta_value' => $email,
        )
    );
    $removed = false;

    foreach ($feedback_ids as $feedback_id) {
        $source_id = absint(get_post_meta($feedback_id, '_kh_source_post_id', true));
        delete_post_meta($feedback_id, '_kh_email');
        delete_post_meta($feedback_id, '_kh_email_hash');
        update_post_meta($feedback_id, '_kh_access_verified', 0);
        svl_home_redesign_flush_public_feedback_cache($source_id);
        $removed = true;
    }

    return array(
        'items_removed' => $removed,
        'items_retained' => false,
        'messages' => array(),
        'done' => count($feedback_ids) < $per_page,
    );
}

add_action('admin_init', 'svl_home_redesign_feedback_privacy_policy');
function svl_home_redesign_feedback_privacy_policy() {
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    wp_add_privacy_policy_content(
        'KolodaHearthstone — отзывы о статьях',
        wp_kses_post(
            '<p>При отправке отзыва сохраняется указанный читателем email. '
            . 'Доступ к отзыву подтверждается по уже открытому доступу к статье — '
            . 'через код, Telegram-бота или ссылку Arena. Email используется для '
            . 'защиты рейтинга от повторных голосов и связи по отзыву. '
            . 'Email не публикуется; посетителям показывается только обезличенная '
            . 'статистика оценок и выбранных характеристик статьи.</p>'
        )
    );
}

add_filter(
    'manage_kh_article_feedback_posts_columns',
    'svl_home_redesign_feedback_columns'
);
function svl_home_redesign_feedback_columns($columns) {
    return array(
        'cb' => $columns['cb'],
        'title' => 'Отзыв',
        'kh_rating' => 'Оценка',
        'kh_issues' => 'Что улучшить',
        'kh_positive' => 'Понравилось',
        'kh_article' => 'Статья',
        'kh_email' => 'Email читателя',
        'kh_message' => 'Комментарий',
        'date' => 'Получен',
    );
}

add_filter(
    'manage_edit-kh_article_feedback_sortable_columns',
    'svl_home_redesign_feedback_sortable_columns'
);
function svl_home_redesign_feedback_sortable_columns($columns) {
    $columns['kh_rating'] = 'kh_rating';
    return $columns;
}

add_action(
    'manage_kh_article_feedback_posts_custom_column',
    'svl_home_redesign_feedback_column_content',
    10,
    2
);
function svl_home_redesign_feedback_column_content($column, $feedback_id) {
    if ($column === 'kh_rating') {
        $rating = absint(get_post_meta($feedback_id, '_kh_rating', true));
        $rating_class = $rating <= 3 ? ' is-low' : ' is-positive';
        printf(
            '<span class="kh-feedback-rating%1$s" aria-label="Оценка %2$d из 5"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><strong>%2$d</strong><span>/ 5</span></span>',
            esc_attr($rating_class),
            $rating
        );
        return;
    }

    if ($column === 'kh_issues' || $column === 'kh_positive') {
        $selected = get_post_meta($feedback_id, '_kh_quick_feedback', true);
        $selected = is_array($selected) ? $selected : array();
        $options = svl_home_redesign_feedback_options();
        $labels = array();
        $expected_tone = $column === 'kh_issues' ? 'improvement' : 'positive';

        foreach ($selected as $key) {
            if (
                isset($options[$key])
                && $options[$key]['tone'] === $expected_tone
            ) {
                $labels[] = $options[$key]['label'];
            }
        }

        if (empty($labels)) {
            $empty_label = $column === 'kh_issues'
                ? 'Недостатки не выбраны'
                : 'Положительные пункты не выбраны';
            printf(
                '<span class="kh-feedback-empty" aria-label="%s">—</span>',
                esc_attr($empty_label)
            );
            return;
        }

        $chip_class = $column === 'kh_issues'
            ? 'kh-feedback-chip--issue'
            : 'kh-feedback-chip--positive';
        echo '<div class="kh-feedback-chips">';
        foreach ($labels as $label) {
            printf(
                '<span class="kh-feedback-chip %1$s">%2$s</span>',
                esc_attr($chip_class),
                esc_html($label)
            );
        }
        echo '</div>';
        return;
    }

    if ($column === 'kh_article') {
        $source_id = absint(get_post_meta($feedback_id, '_kh_source_post_id', true));
        $source = get_post($source_id);

        if ($source instanceof WP_Post) {
            printf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url(get_permalink($source)),
                esc_html(get_the_title($source))
            );
        } else {
            echo 'Статья удалена';
        }
        return;
    }

    if ($column === 'kh_email') {
        $email = sanitize_email((string) get_post_meta($feedback_id, '_kh_email', true));
        $verified = (bool) get_post_meta($feedback_id, '_kh_access_verified', true);

        if ($email === '') {
            echo '<span class="kh-feedback-empty" aria-label="Email не сохранён">—</span>';
            return;
        }

        printf(
            '<a href="mailto:%1$s">%2$s</a><br><span class="kh-feedback-email-status%3$s">%4$s</span>',
            esc_attr($email),
            esc_html($email),
            $verified ? ' is-verified' : '',
            $verified ? 'Доступ подтверждён' : 'Доступ не подтверждён'
        );
        return;
    }

    if ($column === 'kh_message') {
        $message = get_post_field('post_content', $feedback_id);
        if ($message === '') {
            echo '<span class="kh-feedback-empty" aria-label="Без текстового комментария">—</span>';
            return;
        }

        echo '<div class="kh-feedback-message">';
        echo esc_html(wp_trim_words($message, 24, '…'));
        printf(
            '<a href="%1$s">Посмотреть полностью</a>',
            esc_url(get_edit_post_link($feedback_id))
        );
        echo '</div>';
    }
}

add_action('restrict_manage_posts', 'svl_home_redesign_feedback_filters', 10, 2);
function svl_home_redesign_feedback_filters($post_type, $which) {
    if ($post_type !== 'kh_article_feedback' || $which !== 'top') {
        return;
    }

    $options = svl_home_redesign_feedback_options();
    $selected_issue = isset($_GET['kh_feedback_issue'])
        ? sanitize_key(wp_unslash($_GET['kh_feedback_issue']))
        : '';
    $selected_rating = isset($_GET['kh_feedback_rating'])
        ? sanitize_key(wp_unslash($_GET['kh_feedback_rating']))
        : '';
    $selected_article = isset($_GET['kh_feedback_article'])
        ? absint($_GET['kh_feedback_article'])
        : 0;
    ?>
    <label class="screen-reader-text" for="kh-feedback-issue-filter">Фильтр по недостатку</label>
    <select id="kh-feedback-issue-filter" name="kh_feedback_issue">
        <option value="">Все недостатки</option>
        <?php foreach ($options as $key => $option) : ?>
            <?php if ($option['tone'] !== 'improvement') continue; ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_issue, $key); ?>>
                <?php echo esc_html($option['label']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label class="screen-reader-text" for="kh-feedback-rating-filter">Фильтр по оценке</label>
    <select id="kh-feedback-rating-filter" name="kh_feedback_rating">
        <option value="">Любая оценка</option>
        <option value="low" <?php selected($selected_rating, 'low'); ?>>Низкая: 1–3 звезды</option>
        <?php for ($rating = 1; $rating <= 5; $rating++) : ?>
            <option value="<?php echo esc_attr($rating); ?>" <?php selected($selected_rating, (string) $rating); ?>>
                <?php echo esc_html($rating . ' из 5'); ?>
            </option>
        <?php endfor; ?>
    </select>
    <?php if ($selected_article > 0 && get_post_type($selected_article) === 'post') : ?>
        <input type="hidden" name="kh_feedback_article" value="<?php echo esc_attr($selected_article); ?>">
        <span class="kh-feedback-active-article">
            Статья: <strong><?php echo esc_html(get_the_title($selected_article)); ?></strong>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=kh_article_feedback')); ?>">Сбросить</a>
        </span>
    <?php endif; ?>
    <?php
}

add_action('pre_get_posts', 'svl_home_redesign_filter_feedback_admin_query');
function svl_home_redesign_filter_feedback_admin_query($query) {
    if (
        !is_admin()
        || !$query->is_main_query()
        || $query->get('post_type') !== 'kh_article_feedback'
    ) {
        return;
    }

    $meta_query = array_values(
        array_filter((array) $query->get('meta_query'), 'is_array')
    );
    $options = svl_home_redesign_feedback_options();
    $selected_issue = isset($_GET['kh_feedback_issue'])
        ? sanitize_key(wp_unslash($_GET['kh_feedback_issue']))
        : '';
    $selected_rating = isset($_GET['kh_feedback_rating'])
        ? sanitize_key(wp_unslash($_GET['kh_feedback_rating']))
        : '';
    $selected_article = isset($_GET['kh_feedback_article'])
        ? absint($_GET['kh_feedback_article'])
        : 0;

    if (
        isset($options[$selected_issue])
        && $options[$selected_issue]['tone'] === 'improvement'
    ) {
        $meta_query[] = array(
            'key' => '_kh_quick_feedback',
            'value' => '"' . $selected_issue . '"',
            'compare' => 'LIKE',
        );
    }

    if ($selected_rating === 'low') {
        $meta_query[] = array(
            'key' => '_kh_rating',
            'value' => 3,
            'compare' => '<=',
            'type' => 'NUMERIC',
        );
    } elseif (in_array($selected_rating, array('1', '2', '3', '4', '5'), true)) {
        $meta_query[] = array(
            'key' => '_kh_rating',
            'value' => absint($selected_rating),
            'compare' => '=',
            'type' => 'NUMERIC',
        );
    }

    if ($selected_article > 0 && get_post_type($selected_article) === 'post') {
        $meta_query[] = array(
            'key' => '_kh_source_post_id',
            'value' => $selected_article,
            'compare' => '=',
            'type' => 'NUMERIC',
        );
    }

    if (!empty($meta_query)) {
        $query->set('meta_query', $meta_query);
    }

    if ($query->get('orderby') === 'kh_rating') {
        $query->set('meta_key', '_kh_rating');
        $query->set('orderby', 'meta_value_num');
    }
}

function svl_home_redesign_feedback_admin_stats() {
    global $wpdb;

    static $stats = null;
    if ($stats !== null) {
        return $stats;
    }

    $options = svl_home_redesign_feedback_options();
    $issue_counts = array();
    foreach ($options as $key => $option) {
        if ($option['tone'] === 'improvement') {
            $issue_counts[$key] = 0;
        }
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT rating.meta_value AS rating, quick.meta_value AS quick_feedback
            FROM {$wpdb->posts} AS feedback
            LEFT JOIN {$wpdb->postmeta} AS rating
                ON rating.post_id = feedback.ID AND rating.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} AS quick
                ON quick.post_id = feedback.ID AND quick.meta_key = %s
            WHERE feedback.post_type = %s AND feedback.post_status = %s",
            '_kh_rating',
            '_kh_quick_feedback',
            'kh_article_feedback',
            'private'
        ),
        ARRAY_A
    );

    $rating_sum = 0;
    $rated_count = 0;
    $low_count = 0;

    foreach ($rows as $row) {
        $rating = absint($row['rating']);
        if ($rating >= 1 && $rating <= 5) {
            $rating_sum += $rating;
            $rated_count++;
            if ($rating <= 3) {
                $low_count++;
            }
        }

        $selected = maybe_unserialize($row['quick_feedback']);
        if (!is_array($selected)) {
            continue;
        }
        foreach ($selected as $key) {
            if (isset($issue_counts[$key])) {
                $issue_counts[$key]++;
            }
        }
    }

    $stats = array(
        'total' => count($rows),
        'average' => $rated_count > 0 ? round($rating_sum / $rated_count, 1) : 0,
        'low_count' => $low_count,
        'issues' => $issue_counts,
    );

    return $stats;
}

function svl_home_redesign_feedback_stats_for_articles($article_ids) {
    $article_ids = array_values(array_filter(array_map('absint', (array) $article_ids)));
    $stats = array();

    foreach ($article_ids as $article_id) {
        $stats[$article_id] = array(
            'total' => 0,
            'average' => 0,
            'rating_sum' => 0,
            'low_count' => 0,
            'issues' => array(),
        );
    }

    if (empty($article_ids)) {
        return $stats;
    }

    $feedback_ids = get_posts(
        array(
            'post_type' => 'kh_article_feedback',
            'post_status' => 'private',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                array(
                    'key' => '_kh_source_post_id',
                    'value' => $article_ids,
                    'compare' => 'IN',
                    'type' => 'NUMERIC',
                ),
            ),
        )
    );
    $options = svl_home_redesign_feedback_options();

    foreach ($feedback_ids as $feedback_id) {
        $article_id = absint(get_post_meta($feedback_id, '_kh_source_post_id', true));
        if (!isset($stats[$article_id])) {
            continue;
        }

        $rating = absint(get_post_meta($feedback_id, '_kh_rating', true));
        $stats[$article_id]['total']++;
        if ($rating >= 1 && $rating <= 5) {
            $stats[$article_id]['rating_sum'] += $rating;
            if ($rating <= 3) {
                $stats[$article_id]['low_count']++;
            }
        }

        $selected = get_post_meta($feedback_id, '_kh_quick_feedback', true);
        if (!is_array($selected)) {
            continue;
        }

        foreach ($selected as $key) {
            if (
                isset($options[$key])
                && $options[$key]['tone'] === 'improvement'
            ) {
                if (!isset($stats[$article_id]['issues'][$key])) {
                    $stats[$article_id]['issues'][$key] = 0;
                }
                $stats[$article_id]['issues'][$key]++;
            }
        }
    }

    foreach ($stats as &$article_stats) {
        if ($article_stats['total'] > 0) {
            $article_stats['average'] = round(
                $article_stats['rating_sum'] / $article_stats['total'],
                1
            );
        }
        arsort($article_stats['issues']);
        unset($article_stats['rating_sum']);
    }
    unset($article_stats);

    return $stats;
}

add_action('admin_menu', 'svl_home_redesign_feedback_article_stats_menu');
function svl_home_redesign_feedback_article_stats_menu() {
    add_submenu_page(
        'edit.php?post_type=kh_article_feedback',
        'Статистика по статьям',
        'Статистика по статьям',
        'manage_options',
        'kh-feedback-by-article',
        'svl_home_redesign_render_feedback_article_stats_page'
    );
}

function svl_home_redesign_render_feedback_article_stats_page() {
    if (!current_user_can('manage_options')) {
        wp_die('У вас нет доступа к этой странице.');
    }

    $search = isset($_GET['s'])
        ? sanitize_text_field(wp_unslash($_GET['s']))
        : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 20;
    $article_query = new WP_Query(
        array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            's' => $search,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
        )
    );
    $article_ids = wp_list_pluck($article_query->posts, 'ID');
    $article_stats = svl_home_redesign_feedback_stats_for_articles($article_ids);
    $options = svl_home_redesign_feedback_options();
    $articles_with_feedback = 0;
    $articles_needing_attention = 0;

    foreach ($article_stats as $stats) {
        if ($stats['total'] > 0) {
            $articles_with_feedback++;
        }
        if ($stats['low_count'] > 0 || !empty($stats['issues'])) {
            $articles_needing_attention++;
        }
    }
    ?>
    <div class="wrap kh-feedback-articles">
        <div class="kh-feedback-articles__title">
            <div>
                <h1>Статистика по статьям</h1>
                <p>Оценки и конкретные замечания читателей для каждой опубликованной статьи.</p>
            </div>
            <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=kh_article_feedback')); ?>">
                Все отзывы
            </a>
        </div>

        <div class="kh-feedback-articles__metrics" aria-label="Сводка текущей страницы">
            <div>
                <span>Всего статей</span>
                <strong><?php echo esc_html(number_format_i18n($article_query->found_posts)); ?></strong>
            </div>
            <div>
                <span>С отзывами на странице</span>
                <strong><?php echo esc_html(number_format_i18n($articles_with_feedback)); ?></strong>
            </div>
            <div>
                <span>Требуют внимания</span>
                <strong><?php echo esc_html(number_format_i18n($articles_needing_attention)); ?></strong>
            </div>
        </div>

        <form class="kh-feedback-articles__search" method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
            <input type="hidden" name="post_type" value="kh_article_feedback">
            <input type="hidden" name="page" value="kh-feedback-by-article">
            <label class="screen-reader-text" for="kh-feedback-article-search">Найти статью</label>
            <input
                id="kh-feedback-article-search"
                type="search"
                name="s"
                value="<?php echo esc_attr($search); ?>"
                placeholder="Поиск по названию статьи"
            >
            <button class="button button-primary" type="submit">Найти</button>
            <?php if ($search !== '') : ?>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=kh_article_feedback&page=kh-feedback-by-article')); ?>">
                    Сбросить
                </a>
            <?php endif; ?>
        </form>

        <div class="kh-feedback-articles__table-wrap">
            <table class="widefat fixed striped kh-feedback-articles__table">
                <thead>
                    <tr>
                        <th scope="col">Статья</th>
                        <th scope="col">Отзывы</th>
                        <th scope="col">Средняя оценка</th>
                        <th scope="col">Оценки 1–3</th>
                        <th scope="col">Что нужно улучшить</th>
                        <th scope="col"><span class="screen-reader-text">Действия</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($article_query->posts)) : ?>
                        <tr>
                            <td colspan="6" class="kh-feedback-articles__empty">
                                По этому запросу статьи не найдены.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($article_query->posts as $article) : ?>
                            <?php
                            $stats = $article_stats[$article->ID];
                            $row_class = $stats['low_count'] > 0 || !empty($stats['issues'])
                                ? ' is-attention'
                                : '';
                            $reviews_url = add_query_arg(
                                array(
                                    'post_type' => 'kh_article_feedback',
                                    'kh_feedback_article' => $article->ID,
                                ),
                                admin_url('edit.php')
                            );
                            ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td data-colname="Статья">
                                    <div class="kh-feedback-article">
                                        <?php if (has_post_thumbnail($article)) : ?>
                                            <?php
                                            echo get_the_post_thumbnail(
                                                $article,
                                                array(72, 46),
                                                array('class' => 'kh-feedback-article__image')
                                            );
                                            ?>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo esc_html(get_the_title($article)); ?></strong>
                                            <span><?php echo esc_html(get_the_date('d.m.Y', $article)); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-colname="Отзывы">
                                    <strong class="kh-feedback-article__number"><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong>
                                </td>
                                <td data-colname="Средняя оценка">
                                    <?php if ($stats['total'] > 0) : ?>
                                        <span class="kh-feedback-rating<?php echo $stats['average'] <= 3 ? ' is-low' : ' is-positive'; ?>">
                                            <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                                            <strong><?php echo esc_html(number_format_i18n($stats['average'], 1)); ?></strong>
                                            <span>/ 5</span>
                                        </span>
                                    <?php else : ?>
                                        <span class="kh-feedback-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-colname="Оценки 1–3">
                                    <strong class="<?php echo $stats['low_count'] > 0 ? 'kh-feedback-article__low' : 'kh-feedback-empty'; ?>">
                                        <?php echo esc_html(number_format_i18n($stats['low_count'])); ?>
                                    </strong>
                                </td>
                                <td data-colname="Что нужно улучшить">
                                    <?php if (empty($stats['issues'])) : ?>
                                        <span class="kh-feedback-empty">Нет отмеченных недостатков</span>
                                    <?php else : ?>
                                        <div class="kh-feedback-chips">
                                            <?php foreach ($stats['issues'] as $key => $count) : ?>
                                                <span class="kh-feedback-chip kh-feedback-chip--issue">
                                                    <?php echo esc_html($options[$key]['label']); ?>
                                                    <strong><?php echo esc_html(number_format_i18n($count)); ?></strong>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-colname="Действия">
                                    <div class="kh-feedback-article__actions">
                                        <?php if ($stats['total'] > 0) : ?>
                                            <a class="button button-primary" href="<?php echo esc_url($reviews_url); ?>">
                                                Отзывы
                                            </a>
                                        <?php endif; ?>
                                        <a class="button" href="<?php echo esc_url(get_permalink($article)); ?>" target="_blank" rel="noopener noreferrer">
                                            Открыть
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $total_pages = max(1, (int) $article_query->max_num_pages);
        if ($total_pages > 1) :
            $pagination_args = array(
                'post_type' => 'kh_article_feedback',
                'page' => 'kh-feedback-by-article',
                'paged' => '%#%',
            );
            if ($search !== '') {
                $pagination_args['s'] = $search;
            }
            $pagination = paginate_links(
                array(
                    'base' => add_query_arg($pagination_args, admin_url('edit.php')),
                    'format' => '',
                    'current' => $paged,
                    'total' => $total_pages,
                    'type' => 'list',
                    'prev_text' => '‹',
                    'next_text' => '›',
                )
            );
            ?>
            <nav class="kh-feedback-articles__pagination" aria-label="Страницы статистики">
                <?php echo wp_kses_post($pagination); ?>
            </nav>
        <?php endif; ?>
    </div>
    <?php
    wp_reset_postdata();
}

add_action('manage_posts_extra_tablenav', 'svl_home_redesign_feedback_summary');
function svl_home_redesign_feedback_summary($which) {
    global $typenow;

    if ($typenow !== 'kh_article_feedback' || $which !== 'top') {
        return;
    }

    $stats = svl_home_redesign_feedback_admin_stats();
    $options = svl_home_redesign_feedback_options();
    $selected_issue = isset($_GET['kh_feedback_issue'])
        ? sanitize_key(wp_unslash($_GET['kh_feedback_issue']))
        : '';
    ?>
    <section class="kh-feedback-summary" aria-labelledby="kh-feedback-summary-title">
        <div class="kh-feedback-summary__heading">
            <div>
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <div>
                    <h2 id="kh-feedback-summary-title">Что чаще всего нужно улучшить</h2>
                    <p>Нажмите на конкретный недостаток, чтобы оставить в таблице только связанные отзывы.</p>
                </div>
            </div>
            <dl class="kh-feedback-summary__metrics">
                <div>
                    <dt>Всего отзывов</dt>
                    <dd><?php echo esc_html(number_format_i18n($stats['total'])); ?></dd>
                </div>
                <div>
                    <dt>Средняя оценка</dt>
                    <dd><?php echo esc_html($stats['average'] > 0 ? $stats['average'] . ' / 5' : '—'); ?></dd>
                </div>
                <div>
                    <dt>Оценок 1–3</dt>
                    <dd><?php echo esc_html(number_format_i18n($stats['low_count'])); ?></dd>
                </div>
            </dl>
        </div>
        <div class="kh-feedback-summary__issues">
            <?php foreach ($stats['issues'] as $key => $count) : ?>
                <?php
                $is_current = $selected_issue === $key;
                $url = add_query_arg(
                    array(
                        'post_type' => 'kh_article_feedback',
                        'kh_feedback_issue' => $key,
                    ),
                    admin_url('edit.php')
                );
                ?>
                <a
                    class="kh-feedback-summary__issue<?php echo $is_current ? ' is-current' : ''; ?>"
                    href="<?php echo esc_url($url); ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                >
                    <span><?php echo esc_html($options[$key]['label']); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($count)); ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($selected_issue !== '' && isset($options[$selected_issue])) : ?>
            <a class="kh-feedback-summary__reset" href="<?php echo esc_url(admin_url('edit.php?post_type=kh_article_feedback')); ?>">
                <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                Сбросить фильтр
            </a>
        <?php endif; ?>
    </section>
    <?php
}

add_filter('post_class', 'svl_home_redesign_feedback_row_class', 10, 3);
function svl_home_redesign_feedback_row_class($classes, $class, $post_id) {
    if (get_post_type($post_id) !== 'kh_article_feedback') {
        return $classes;
    }

    $rating = absint(get_post_meta($post_id, '_kh_rating', true));
    if ($rating > 0 && $rating <= 3) {
        $classes[] = 'kh-feedback-row-low';
    }

    return $classes;
}

add_action('add_meta_boxes_kh_article_feedback', 'svl_home_redesign_feedback_meta_box');
function svl_home_redesign_feedback_meta_box() {
    add_meta_box(
        'kh-feedback-details',
        'Сводка отзыва',
        'svl_home_redesign_render_feedback_meta_box',
        'kh_article_feedback',
        'normal',
        'high'
    );
}

function svl_home_redesign_render_feedback_meta_box($post) {
    $options = svl_home_redesign_feedback_options();
    $selected = get_post_meta($post->ID, '_kh_quick_feedback', true);
    $selected = is_array($selected) ? $selected : array();
    $rating = absint(get_post_meta($post->ID, '_kh_rating', true));
    $source_id = absint(get_post_meta($post->ID, '_kh_source_post_id', true));
    $source = get_post($source_id);
    $email = sanitize_email((string) get_post_meta($post->ID, '_kh_email', true));
    $access_verified = (bool) get_post_meta($post->ID, '_kh_access_verified', true);
    $access_source = sanitize_key((string) get_post_meta($post->ID, '_kh_access_source', true));
    $access_source_labels = array(
        'code' => 'код доступа',
        'telegram' => 'Telegram-бот',
        'arena' => 'Arena',
        'magic' => 'одноразовая ссылка',
        'unlocked' => 'открытый доступ',
        'admin' => 'администратор',
    );
    $access_source_label = isset($access_source_labels[$access_source])
        ? $access_source_labels[$access_source]
        : 'источник не указан';
    ?>
    <div class="kh-feedback-details">
        <div class="kh-feedback-details__rating">
            <span>Оценка читателя</span>
            <strong><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php echo esc_html($rating . ' из 5'); ?></strong>
        </div>
        <div class="kh-feedback-details__subscriber">
            <span>Email читателя</span>
            <?php if ($email !== '') : ?>
                <strong><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></strong>
                <em>
                    <?php
                    echo $access_verified
                        ? esc_html('✓ Доступ к статье подтверждён: ' . $access_source_label)
                        : 'Доступ к статье не подтверждён';
                    ?>
                </em>
            <?php else : ?>
                <strong>Не сохранён</strong>
                <em>Старый отзыв до введения обязательной проверки</em>
            <?php endif; ?>
        </div>
        <div class="kh-feedback-details__groups">
            <section>
                <h3>Что нужно улучшить</h3>
                <div class="kh-feedback-chips">
                    <?php
                    $has_issues = false;
                    foreach ($selected as $key) {
                        if (isset($options[$key]) && $options[$key]['tone'] === 'improvement') {
                            $has_issues = true;
                            printf(
                                '<span class="kh-feedback-chip kh-feedback-chip--issue">%s</span>',
                                esc_html($options[$key]['label'])
                            );
                        }
                    }
                    if (!$has_issues) {
                        echo '<span class="kh-feedback-empty">Не указано</span>';
                    }
                    ?>
                </div>
            </section>
            <section>
                <h3>Что понравилось</h3>
                <div class="kh-feedback-chips">
                    <?php
                    $has_positive = false;
                    foreach ($selected as $key) {
                        if (isset($options[$key]) && $options[$key]['tone'] === 'positive') {
                            $has_positive = true;
                            printf(
                                '<span class="kh-feedback-chip kh-feedback-chip--positive">%s</span>',
                                esc_html($options[$key]['label'])
                            );
                        }
                    }
                    if (!$has_positive) {
                        echo '<span class="kh-feedback-empty">Не указано</span>';
                    }
                    ?>
                </div>
            </section>
        </div>
        <?php if ($source instanceof WP_Post) : ?>
            <p class="kh-feedback-details__article">
                <strong>Статья:</strong>
                <a href="<?php echo esc_url(get_permalink($source)); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html(get_the_title($source)); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

add_action('admin_enqueue_scripts', 'svl_home_redesign_feedback_admin_assets');
function svl_home_redesign_feedback_admin_assets($hook_suffix) {
    $screen = get_current_screen();
    $article_stats_hook = 'kh_article_feedback_page_kh-feedback-by-article';
    if (
        !$screen
        || (
            $screen->post_type !== 'kh_article_feedback'
            && $hook_suffix !== $article_stats_hook
        )
        || !in_array(
            $hook_suffix,
            array(
                'edit.php',
                'post.php',
                $article_stats_hook,
            ),
            true
        )
    ) {
        return;
    }

    $style_file = plugin_dir_path(__FILE__) . 'assets/home-redesign/css/feedback-admin.css';
    if (!file_exists($style_file)) {
        return;
    }

    wp_enqueue_style(
        'svl-feedback-admin',
        plugin_dir_url(__FILE__) . 'assets/home-redesign/css/feedback-admin.css',
        array(),
        (string) filemtime($style_file)
    );
}

add_filter('admin_body_class', 'svl_home_redesign_feedback_admin_body_class');
function svl_home_redesign_feedback_admin_body_class($classes) {
    $page = isset($_GET['page'])
        ? sanitize_key(wp_unslash($_GET['page']))
        : '';

    if ($page === 'kh-feedback-by-article') {
        $classes .= ' post-type-kh_article_feedback';
    }

    return $classes;
}

/**
 * Checks the same article-access cookie used by the VIP locker.
 *
 * Manual codes and redeem links from Telegram/Arena all converge on the
 * vip_access_<code> cookie. The optional source cookie is informational only;
 * it never grants access by itself.
 */
function svl_home_redesign_feedback_access($post_id) {
    $post_id = absint($post_id);
    $post = get_post($post_id);

    if (
        !($post instanceof WP_Post)
        || $post->post_type !== 'post'
        || $post->post_status !== 'publish'
    ) {
        return array('allowed' => false, 'source' => '');
    }

    if (current_user_can('manage_options')) {
        return array('allowed' => true, 'source' => 'admin');
    }

    $default_code = function_exists('svl_opt')
        ? trim((string) svl_opt('svl_default_code'))
        : '';
    if ($default_code === '') {
        $default_code = '12345';
    }

    if (function_exists('svl_bot_extract_codes')) {
        $codes = svl_bot_extract_codes((string) $post->post_content, $default_code);
    } else {
        $codes = array();
        if (preg_match_all('/\[vip_locker\b([^\]]*)\]/i', (string) $post->post_content, $matches)) {
            foreach ($matches[1] as $attributes) {
                if (preg_match('/\bcode\s*=\s*(["\'])([^"\']+)\1/i', $attributes, $code_match)) {
                    $codes[] = trim((string) $code_match[2]);
                } else {
                    $codes[] = $default_code;
                }
            }
        }
    }

    foreach (array_unique(array_filter($codes, 'strlen')) as $code) {
        $cookie_name = 'vip_access_' . preg_replace('/[^a-z0-9]/', '', strtolower((string) $code));
        if ($cookie_name === 'vip_access_' || !isset($_COOKIE[$cookie_name])) {
            continue;
        }

        $cookie_value = strtolower(
            sanitize_text_field(wp_unslash((string) $_COOKIE[$cookie_name]))
        );
        if (!hash_equals('true', $cookie_value)) {
            continue;
        }

        $allowed_sources = array('code', 'telegram', 'arena', 'magic');
        $access_source = isset($_COOKIE['kh_review_access_source'])
            ? sanitize_key(wp_unslash((string) $_COOKIE['kh_review_access_source']))
            : '';
        if (!in_array($access_source, $allowed_sources, true)) {
            $access_source = 'unlocked';
        }

        return array('allowed' => true, 'source' => $access_source);
    }

    return array('allowed' => false, 'source' => '');
}

/**
 * Aggregates article reviews without exposing any reader data.
 *
 * Legacy reviews predate access verification. They remain visible as historic
 * ratings, while every new review is still accepted only with verified access.
 */
function svl_home_redesign_public_feedback_stats($post_id) {
    $post_id = absint($post_id);
    $cache_key = 'kh_feedback_public_stats_v2_' . $post_id;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $distribution = array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0);
    $option_counts = array();
    $rating_sum = 0;
    $verified_total = 0;
    $legacy_total = 0;
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT rating.meta_value AS rating,
                quick.meta_value AS quick_feedback,
                verified.meta_value AS access_verified
            FROM {$wpdb->posts} AS feedback
            INNER JOIN {$wpdb->postmeta} AS source
                ON source.post_id = feedback.ID AND source.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} AS verified
                ON verified.post_id = feedback.ID AND verified.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} AS rating
                ON rating.post_id = feedback.ID AND rating.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} AS quick
                ON quick.post_id = feedback.ID AND quick.meta_key = %s
            WHERE feedback.post_type = %s
                AND feedback.post_status = %s
                AND CAST(source.meta_value AS UNSIGNED) = %d",
            '_kh_source_post_id',
            '_kh_access_verified',
            '_kh_rating',
            '_kh_quick_feedback',
            'kh_article_feedback',
            'private',
            $post_id
        ),
        ARRAY_A
    );
    if (!is_array($rows)) {
        $rows = array();
    }
    $options = svl_home_redesign_feedback_options();

    foreach ($rows as $row) {
        $rating = absint($row['rating']);
        if ($rating < 1 || $rating > 5) {
            continue;
        }

        $distribution[$rating]++;
        $rating_sum += $rating;
        if ((string) $row['access_verified'] === '1') {
            $verified_total++;
        } else {
            $legacy_total++;
        }
        $selected = maybe_unserialize($row['quick_feedback']);
        $selected = is_array($selected) ? array_unique($selected) : array();

        foreach ($selected as $key) {
            if (isset($options[$key])) {
                $option_counts[$key] = isset($option_counts[$key])
                    ? $option_counts[$key] + 1
                    : 1;
            }
        }
    }

    arsort($option_counts);
    $top_positive = array();
    $top_improvement = array();
    foreach ($option_counts as $key => $count) {
        if (!isset($options[$key]) || $count < 1) {
            continue;
        }
        $item = array(
            'key' => $key,
            'label' => $options[$key]['label'],
            'count' => $count,
        );
        if ($options[$key]['tone'] === 'positive' && count($top_positive) < 2) {
            $top_positive[] = $item;
        } elseif ($options[$key]['tone'] === 'improvement' && count($top_improvement) < 2) {
            $top_improvement[] = $item;
        }
    }

    $total = array_sum($distribution);
    $stats = array(
        'total' => $total,
        'average' => $total > 0 ? round($rating_sum / $total, 1) : 0,
        'verifiedTotal' => $verified_total,
        'legacyTotal' => $legacy_total,
        'distribution' => $distribution,
        'topPositive' => $top_positive,
        'topImprovement' => $top_improvement,
    );
    set_transient($cache_key, $stats, 10 * MINUTE_IN_SECONDS);
    return $stats;
}

add_action(
    'save_post_kh_article_feedback',
    'svl_home_redesign_invalidate_public_feedback_stats',
    20
);
function svl_home_redesign_invalidate_public_feedback_stats($feedback_id) {
    $source_id = absint(get_post_meta($feedback_id, '_kh_source_post_id', true));
    if ($source_id > 0) {
        svl_home_redesign_flush_public_feedback_cache($source_id);
    }
}

function svl_home_redesign_flush_public_feedback_cache($post_id) {
    $post_id = absint($post_id);
    if ($post_id < 1) {
        return;
    }

    delete_transient('kh_feedback_public_stats_' . $post_id);
    delete_transient('kh_feedback_public_stats_v2_' . $post_id);
    clean_post_cache($post_id);

    if (function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post_id, true);
    } elseif (function_exists('w3tc_pgcache_flush_post')) {
        w3tc_pgcache_flush_post($post_id, true);
    }
}

add_action('before_delete_post', 'svl_home_redesign_invalidate_deleted_feedback_stats');
function svl_home_redesign_invalidate_deleted_feedback_stats($feedback_id) {
    if (get_post_type($feedback_id) !== 'kh_article_feedback') {
        return;
    }
    svl_home_redesign_invalidate_public_feedback_stats($feedback_id);
}

function svl_home_redesign_render_public_feedback_stats($post_id) {
    $stats = svl_home_redesign_public_feedback_stats($post_id);
    ?>
    <section class="kh-article-feedback__public" aria-labelledby="kh-feedback-public-title-<?php echo esc_attr($post_id); ?>">
        <div class="kh-article-feedback__public-heading">
            <div>
                <p class="kh-article-feedback__public-kicker">Мнение подписчиков</p>
                <h3 id="kh-feedback-public-title-<?php echo esc_attr($post_id); ?>">Оценки читателей с доступом</h3>
            </div>
            <span class="kh-article-feedback__verified-badge">
                <?php if ($stats['legacyTotal'] > 0) : ?>
                    ✓ Новые отзывы — после открытия статьи
                <?php else : ?>
                    ✓ Доступ к статье подтверждён
                <?php endif; ?>
            </span>
        </div>

        <?php if ($stats['total'] < 1) : ?>
            <p class="kh-article-feedback__public-empty">
                Здесь появятся оценки читателей, которые открыли VIP-материал кодом, через Telegram-бота или Arena.
            </p>
        <?php else : ?>
            <div class="kh-article-feedback__public-grid">
                <div class="kh-article-feedback__score">
                    <strong><?php echo esc_html(number_format_i18n($stats['average'], 1)); ?></strong>
                    <span
                        class="kh-article-feedback__score-stars"
                        aria-label="<?php echo esc_attr($stats['average'] . ' из 5'); ?>"
                    >
                        <span aria-hidden="true">★★★★★</span>
                        <span
                            class="kh-article-feedback__score-stars-fill"
                            aria-hidden="true"
                            style="width: <?php echo esc_attr(round(($stats['average'] / 5) * 100, 1)); ?>%"
                        >★★★★★</span>
                    </span>
                    <span>На основе <?php echo esc_html(number_format_i18n($stats['total'])); ?> оценок</span>
                    <?php if ($stats['legacyTotal'] > 0) : ?>
                        <small class="kh-article-feedback__legacy-note">
                            <?php
                            echo esc_html(
                                sprintf(
                                    '%s оставлены до включения обязательной проверки доступа.',
                                    number_format_i18n($stats['legacyTotal'])
                                )
                            );
                            ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="kh-article-feedback__distribution" aria-label="Распределение оценок">
                    <?php for ($rating = 5; $rating >= 1; $rating--) : ?>
                        <?php
                        $count = $stats['distribution'][$rating];
                        $percent = $stats['total'] > 0
                            ? round(($count / $stats['total']) * 100)
                            : 0;
                        ?>
                        <div class="kh-article-feedback__distribution-row">
                            <span><?php echo esc_html($rating); ?> ★</span>
                            <span class="kh-article-feedback__distribution-track" aria-hidden="true">
                                <span style="width: <?php echo esc_attr($percent); ?>%"></span>
                            </span>
                            <span><?php echo esc_html(number_format_i18n($count)); ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="kh-article-feedback__signals">
                <section class="kh-article-feedback__signals-group is-positive">
                    <h4>Что нравится читателям</h4>
                    <?php if (empty($stats['topPositive'])) : ?>
                        <p>Пока нет достаточно частых положительных отметок.</p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($stats['topPositive'] as $item) : ?>
                                <li>
                                    <span><?php echo esc_html($item['label']); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($item['count'])); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
                <section class="kh-article-feedback__signals-group is-improvement">
                    <h4>Что стоит улучшить</h4>
                    <?php if (empty($stats['topImprovement'])) : ?>
                        <p>Критические замечания пока не повторяются.</p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($stats['topImprovement'] as $item) : ?>
                                <li>
                                    <span><?php echo esc_html($item['label']); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($item['count'])); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

add_filter('the_content', 'svl_home_redesign_append_article_feedback', 95);
function svl_home_redesign_append_article_feedback($content) {
    static $rendered_posts = array();

    if (
        !svl_home_redesign_enabled()
        || !is_singular('post')
        || !is_main_query()
        || !in_the_loop()
    ) {
        return $content;
    }

    $post_id = get_the_ID();
    if (isset($rendered_posts[$post_id])) {
        return $content;
    }
    $rendered_posts[$post_id] = true;

    $heading_id = 'kh-article-feedback-title-' . $post_id;
    $feedback_options = svl_home_redesign_feedback_options();
    ob_start();
    ?>
    <section class="kh-article-feedback" aria-labelledby="<?php echo esc_attr($heading_id); ?>">
        <div class="kh-article-feedback__heading">
            <p class="kh-article-feedback__eyebrow">Ваше мнение</p>
            <h2 id="<?php echo esc_attr($heading_id); ?>">Как вам статья?</h2>
            <p>Оцените материал и подскажите, что можно сделать ещё полезнее.</p>
        </div>
        <p class="kh-article-feedback__access-note">
            <span aria-hidden="true">🔐</span>
            Отправить отзыв можно после открытия этой статьи кодом,
            через <a href="https://t.me/kolodahearthstoneauthbot" target="_blank" rel="noopener noreferrer">Telegram-бота</a>
            или <a href="https://hearthpulse.net/" target="_blank" rel="noopener noreferrer">Arena</a>.
        </p>
        <form class="kh-article-feedback__form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <div class="kh-article-feedback__email">
                <label for="kh-article-feedback-email-<?php echo esc_attr($post_id); ?>">
                    Ваш email
                </label>
                <input
                    id="kh-article-feedback-email-<?php echo esc_attr($post_id); ?>"
                    type="email"
                    name="email"
                    maxlength="254"
                    autocomplete="email"
                    inputmode="email"
                    placeholder="name@example.com"
                    required
                >
                <p>Email нужен для защиты от повторных оценок и связи по отзыву. На сайте он не публикуется.</p>
            </div>
            <fieldset class="kh-article-feedback__rating">
                <legend>Ваша оценка</legend>
                <div class="kh-article-feedback__stars" role="group" aria-label="Оценка статьи от 1 до 5 звёзд">
                    <?php for ($rating = 1; $rating <= 5; $rating++) : ?>
                        <button
                            type="button"
                            data-kh-rating="<?php echo esc_attr($rating); ?>"
                            aria-label="<?php echo esc_attr($rating . ' из 5'); ?>"
                            aria-pressed="false"
                        ><span class="dashicons dashicons-star-filled" aria-hidden="true"></span></button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" value="">
            </fieldset>
            <fieldset class="kh-article-feedback__quick">
                <legend>Что особенно заметили?</legend>
                <p>Можно выбрать несколько вариантов.</p>
                <div class="kh-article-feedback__quick-groups">
                    <div class="kh-article-feedback__quick-group">
                        <p>Понравилось</p>
                        <div>
                            <?php foreach ($feedback_options as $key => $option) : ?>
                                <?php if ($option['tone'] !== 'positive') continue; ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="quick_feedback[]"
                                        value="<?php echo esc_attr($key); ?>"
                                    >
                                    <span><?php echo esc_html($option['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="kh-article-feedback__quick-group">
                        <p>Можно улучшить</p>
                        <div>
                            <?php foreach ($feedback_options as $key => $option) : ?>
                                <?php if ($option['tone'] !== 'improvement') continue; ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="quick_feedback[]"
                                        value="<?php echo esc_attr($key); ?>"
                                    >
                                    <span><?php echo esc_html($option['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </fieldset>
            <div class="kh-article-feedback__message">
                <label for="kh-article-feedback-message-<?php echo esc_attr($post_id); ?>">
                    Что бы вы хотели сказать о статье или какие у вас есть предложения?
                </label>
                <textarea
                    id="kh-article-feedback-message-<?php echo esc_attr($post_id); ?>"
                    name="message"
                    rows="5"
                    maxlength="1500"
                    placeholder="Например: какую тему раскрыть подробнее или что добавить в следующий материал"
                ></textarea>
                <p>До 1500 символов. Текст отзыва и выбранные пункты публикуются только в виде общей статистики.</p>
            </div>
            <div class="kh-article-feedback__trap" aria-hidden="true">
                <label for="kh-article-feedback-company-<?php echo esc_attr($post_id); ?>">Ваш сайт</label>
                <input
                    id="kh-article-feedback-company-<?php echo esc_attr($post_id); ?>"
                    type="text"
                    name="company"
                    value=""
                    tabindex="-1"
                    autocomplete="off"
                >
            </div>
            <button class="kh-article-feedback__submit" type="submit">Отправить отзыв</button>
            <p class="kh-article-feedback__status" role="status" aria-live="polite" hidden></p>
        </form>
        <?php svl_home_redesign_render_public_feedback_stats($post_id); ?>
    </section>
    <?php

    return $content . ob_get_clean();
}

add_action('wp_ajax_kh_submit_article_feedback', 'svl_home_redesign_submit_article_feedback');
add_action('wp_ajax_nopriv_kh_submit_article_feedback', 'svl_home_redesign_submit_article_feedback');
function svl_home_redesign_submit_article_feedback() {
    if (!check_ajax_referer('kh_article_feedback', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Страница устарела. Обновите её и попробуйте снова.'), 403);
    }

    if (!empty($_POST['company'])) {
        wp_send_json_success(array('message' => 'Спасибо! Ваш отзыв сохранён.'));
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $rating = isset($_POST['rating']) ? absint($_POST['rating']) : 0;
    $email = isset($_POST['email'])
        ? sanitize_email(wp_unslash($_POST['email']))
        : '';
    $message = isset($_POST['message'])
        ? trim(sanitize_textarea_field(wp_unslash($_POST['message'])))
        : '';
    $source = get_post($post_id);
    $feedback_options = svl_home_redesign_feedback_options();
    $requested_quick_feedback = isset($_POST['quick_feedback'])
        ? (array) wp_unslash($_POST['quick_feedback'])
        : array();
    $quick_feedback = array();

    foreach ($requested_quick_feedback as $key) {
        $key = sanitize_key($key);
        if (isset($feedback_options[$key]) && !in_array($key, $quick_feedback, true)) {
            $quick_feedback[] = $key;
        }
    }

    if (!($source instanceof WP_Post) || $source->post_type !== 'post' || $source->post_status !== 'publish') {
        wp_send_json_error(array('message' => 'Статья не найдена.'), 404);
    }

    $access = svl_home_redesign_feedback_access($post_id);
    if (empty($access['allowed'])) {
        wp_send_json_error(
            array(
                'message' => 'Сначала откройте эту VIP-статью кодом, через Telegram-бота или Arena, затем отправьте отзыв.',
            ),
            403
        );
    }

    if ($rating < 1 || $rating > 5) {
        wp_send_json_error(array('message' => 'Выберите оценку от 1 до 5 звёзд.'), 422);
    }

    if ($email === '' || !is_email($email)) {
        wp_send_json_error(array('message' => 'Введите корректный email.'), 422);
    }

    $message_length = function_exists('mb_strlen')
        ? mb_strlen($message, 'UTF-8')
        : strlen($message);
    if ($message_length > 1500) {
        wp_send_json_error(array('message' => 'Сократите сообщение до 1500 символов.'), 422);
    }

    $remote_addr = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : 'unknown';
    $visitor_hash = hash_hmac('sha256', $remote_addr, wp_salt('nonce'));
    $rate_key = 'kh_feedback_rate_' . substr($visitor_hash, 0, 24);
    $rate_count = absint(get_transient($rate_key));

    if ($rate_count >= 5) {
        wp_send_json_error(
            array('message' => 'Слишком много отправок. Попробуйте немного позже.'),
            429
        );
    }

    set_transient($rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS);

    $email_hash = hash_hmac('sha256', strtolower($email), wp_salt('auth'));
    $dedupe_lock_key = 'kh_feedback_lock_' . md5($post_id . '|' . $email_hash);
    $lock_created = add_option($dedupe_lock_key, time(), '', false);
    if (!$lock_created) {
        $lock_time = absint(get_option($dedupe_lock_key));
        if ($lock_time > 0 && $lock_time < time() - 30) {
            delete_option($dedupe_lock_key);
            $lock_created = add_option($dedupe_lock_key, time(), '', false);
        }
    }
    if (!$lock_created) {
        wp_send_json_error(
            array('message' => 'Этот отзыв уже обрабатывается. Подождите несколько секунд.'),
            409
        );
    }

    $existing_feedback = get_posts(
        array(
            'post_type' => 'kh_article_feedback',
            'post_status' => 'private',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_kh_source_post_id',
                    'value' => $post_id,
                    'type' => 'NUMERIC',
                    'compare' => '=',
                ),
                array(
                    'key' => '_kh_email_hash',
                    'value' => $email_hash,
                    'compare' => '=',
                ),
            ),
        )
    );
    if (!empty($existing_feedback)) {
        delete_option($dedupe_lock_key);
        wp_send_json_success(
            array('message' => 'Спасибо! Вы уже оценили эту статью, повторный отзыв не добавлен.')
        );
    }

    $feedback_id = wp_insert_post(
        array(
            'post_type' => 'kh_article_feedback',
            'post_status' => 'private',
            'post_title' => sprintf(
                'Оценка %1$d из 5 — %2$s',
                $rating,
                wp_strip_all_tags(get_the_title($source))
            ),
            'post_content' => $message,
            'meta_input' => array(
                '_kh_rating' => $rating,
                '_kh_source_post_id' => $post_id,
                '_kh_quick_feedback' => $quick_feedback,
                '_kh_email' => $email,
                '_kh_email_hash' => $email_hash,
                '_kh_access_verified' => 1,
                '_kh_access_source' => sanitize_key((string) $access['source']),
            ),
        ),
        true
    );

    if (is_wp_error($feedback_id)) {
        delete_option($dedupe_lock_key);
        wp_send_json_error(
            array('message' => 'Не удалось сохранить отзыв. Попробуйте ещё раз.'),
            500
        );
    }

    delete_option($dedupe_lock_key);
    svl_home_redesign_flush_public_feedback_cache($post_id);
    wp_send_json_success(
        array('message' => 'Спасибо! Отзыв учтён в оценке читателей с доступом.')
    );
}

add_action('wp_enqueue_scripts', 'svl_home_redesign_enqueue_assets', 100);
function svl_home_redesign_enqueue_assets() {
    if (!svl_home_redesign_enabled() || is_admin()) {
        return;
    }

    $asset_dir = plugin_dir_path(__FILE__) . 'assets/home-redesign/';
    $asset_url = plugin_dir_url(__FILE__) . 'assets/home-redesign/';
    $style_file = $asset_dir . 'css/home-redesign-20260727.css';
    $article_style_file = $asset_dir . 'css/article-redesign.css';
    $script_file = $asset_dir . 'js/home-redesign.js';
    $tag_script_file = $asset_dir . 'js/tag-filter.js';
    $header_script_file = $asset_dir . 'js/header-redesign-20260727b.js';
    $feedback_script_file = $asset_dir . 'js/article-feedback.js';

    wp_enqueue_style(
        'svl-home-redesign',
        $asset_url . 'css/home-redesign-20260727.css',
        array(),
        file_exists($style_file) ? (string) filemtime($style_file) : SVL_HOME_REDESIGN_VERSION
    );

    if (is_singular('post')) {
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'svl-article-redesign',
            $asset_url . 'css/article-redesign.css',
            array('svl-home-redesign', 'dashicons'),
            file_exists($article_style_file)
                ? (string) filemtime($article_style_file)
                : SVL_HOME_REDESIGN_VERSION
        );

        wp_enqueue_script(
            'svl-article-feedback',
            $asset_url . 'js/article-feedback.js',
            array(),
            file_exists($feedback_script_file)
                ? (string) filemtime($feedback_script_file)
                : SVL_HOME_REDESIGN_VERSION,
            array('strategy' => 'defer', 'in_footer' => true)
        );

        wp_add_inline_script(
            'svl-article-feedback',
            'window.KH_ARTICLE_FEEDBACK = Object.freeze('
                . wp_json_encode(
                    array(
                        'ajaxUrl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('kh_article_feedback'),
                        'postId' => get_queried_object_id(),
                        'emailRequired' => 'Введите корректный email.',
                        'ratingRequired' => 'Выберите оценку от 1 до 5 звёзд.',
                        'sending' => 'Отправляем отзыв…',
                        'success' => 'Спасибо! Отзыв учтён в оценке читателей с доступом.',
                        'error' => 'Не удалось отправить отзыв. Попробуйте ещё раз.',
                    ),
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                )
                . ');',
            'before'
        );
    }

    wp_enqueue_script(
        'svl-header-redesign',
        $asset_url . 'js/header-redesign-20260727b.js',
        array(),
        file_exists($header_script_file)
            ? (string) filemtime($header_script_file)
            : SVL_HOME_REDESIGN_VERSION,
        array('strategy' => 'defer', 'in_footer' => true)
    );

    wp_add_inline_script(
        'svl-header-redesign',
        'window.KH_HEADER_REDESIGN = Object.freeze('
            . wp_json_encode(
                array(
                    'boostyIcon' => $asset_url . 'img/boosty.svg',
                ),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            )
            . ');',
        'before'
    );

    if (!(is_home() || is_archive() || is_search())) {
        return;
    }

    wp_enqueue_script(
        'svl-home-redesign',
        $asset_url . 'js/home-redesign.js',
        array(),
        file_exists($script_file) ? (string) filemtime($script_file) : SVL_HOME_REDESIGN_VERSION,
        array('strategy' => 'defer', 'in_footer' => true)
    );

    wp_enqueue_script(
        'svl-home-tag-filter',
        $asset_url . 'js/tag-filter.js',
        array('svl-home-redesign'),
        file_exists($tag_script_file)
            ? (string) filemtime($tag_script_file)
            : SVL_HOME_REDESIGN_VERSION,
        array('strategy' => 'defer', 'in_footer' => true)
    );

    global $wp_query;
    $config = array(
        'cards' => svl_home_redesign_card_data(),
        'readLabel' => 'Читать',
        'readVipLabel' => 'Читать',
        'searchEndpoint' => rest_url('koloda/v1/home-search'),
        'tagEndpoint' => rest_url('koloda/v1/home-posts'),
        'searchLoadingLabel' => 'Ищем статьи…',
        'searchEmptyLabel' => 'По этому запросу статей не найдено.',
        'searchErrorLabel' => 'Поиск временно недоступен. Нажмите «Найти».',
        'tagLoadingLabel' => 'Обновляем статьи…',
        'tagErrorLabel' => 'Не удалось обновить статьи. Попробуйте ещё раз.',
        'initialTotalPages' => ($wp_query instanceof WP_Query)
            ? max(1, intval($wp_query->max_num_pages))
            : 1,
    );

    wp_add_inline_script(
        'svl-home-redesign',
        'window.KH_HOME_REDESIGN = Object.freeze('
            . wp_json_encode(
                $config,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            )
            . ');',
        'before'
    );
}

/**
 * Adds a compact, useful footer inspired by the existing Arena product.
 */
add_action('wp_footer', 'svl_home_redesign_footer', 5);
function svl_home_redesign_footer() {
    if (!svl_home_redesign_enabled() || is_admin()) {
        return;
    }

    $year = wp_date('Y');
    ?>
    <footer id="kh-site-footer" class="kh-site-footer" aria-label="Нижняя навигация">
        <div class="kh-site-footer__inner">
            <div class="kh-site-footer__links">
                <section class="kh-site-footer__section" aria-labelledby="kh-footer-sections">
                    <h2 id="kh-footer-sections">Разделы</h2>
                    <nav aria-label="Разделы сайта">
                        <a href="<?php echo esc_url(home_url('/')); ?>">Главная</a>
                        <a href="<?php echo esc_url(home_url('/category/meta-otchet/')); ?>">Мета-отчёты</a>
                        <a href="<?php echo esc_url(home_url('/category/gajdy/')); ?>">Гайды</a>
                        <a href="<?php echo esc_url(home_url('/category/polya-srazhenij/')); ?>">Поля Сражений</a>
                        <a href="<?php echo esc_url(home_url('/category/volnyj/')); ?>">Вольный</a>
                    </nav>
                </section>
                <section class="kh-site-footer__section kh-site-footer__community" aria-labelledby="kh-footer-community">
                    <h2 id="kh-footer-community">Сообщество</h2>
                    <nav aria-label="Сообщество Koloda Hearthstone">
                        <a href="https://t.me/kolodahearthstoneauthbot" target="_blank" rel="noopener noreferrer">
                            <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/home-redesign/img/telegram.svg'); ?>" alt="" width="18" height="18" aria-hidden="true">
                            Telegram-бот
                        </a>
                        <a href="https://boosty.to/kolodahearthstone" target="_blank" rel="noopener noreferrer">
                            <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/home-redesign/img/boosty.svg'); ?>" alt="" width="18" height="18" aria-hidden="true">
                            Boosty
                        </a>
                    </nav>
                </section>
            </div>
            <div class="kh-site-footer__meta">
                <span>© <?php echo esc_html($year); ?> Koloda Hearthstone</span>
                <span>Hearthstone® — зарегистрированная торговая марка Blizzard Entertainment.</span>
            </div>
        </div>
    </footer>
    <?php
}

/**
 * Prints a real search form and category navigation at the start of the home
 * loop. It remains usable even when JavaScript is unavailable.
 */
add_action('loop_start', 'svl_home_redesign_home_tools', 5);
function svl_home_redesign_home_tools($query) {
    static $printed = false;

    if (
        $printed
        || !svl_home_redesign_enabled()
        || !is_home()
        || !$query->is_main_query()
    ) {
        return;
    }

    $printed = true;
    $filters = array(
        array('label' => 'Все', 'url' => home_url('/'), 'current' => true, 'tag_id' => 0),
    );
    $tags = get_tags(
        array(
            'hide_empty' => true,
            'orderby' => 'term_id',
            'order' => 'DESC',
        )
    );
    $visible_tags = array_slice($tags, 0, 5);
    $more_tags = array_slice($tags, 5);

    foreach ($visible_tags as $tag) {
        $tag_url = get_tag_link($tag);
        if (is_wp_error($tag_url)) {
            continue;
        }

        $filters[] = array(
            'label' => $tag->name,
            'url' => $tag_url,
            'current' => false,
            'tag_id' => intval($tag->term_id),
        );
    }
    ?>
    <section class="kh-home-tools" aria-labelledby="kh-home-title">
        <h1 id="kh-home-title" class="screen-reader-text">Статьи о Hearthstone</h1>
        <form class="kh-home-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
            <label class="screen-reader-text" for="kh-home-search-input">Поиск по статьям</label>
            <input
                id="kh-home-search-input"
                type="search"
                name="s"
                value="<?php echo esc_attr(get_search_query()); ?>"
                placeholder="Поиск по статьям"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="kh-live-search-results"
                aria-expanded="false"
            >
            <button type="submit">Найти</button>
            <div
                id="kh-live-search-results"
                class="kh-live-search"
                role="listbox"
                aria-label="Результаты поиска"
                aria-live="polite"
                hidden
            ></div>
        </form>
        <nav class="kh-home-filters" aria-label="Метки статей">
            <span class="kh-home-filters__label">Метки</span>
            <?php foreach ($filters as $filter) : ?>
                <a
                    class="kh-home-filter<?php echo !empty($filter['current']) ? ' is-active' : ''; ?>"
                    href="<?php echo esc_url($filter['url']); ?>"
                    data-tag-id="<?php echo esc_attr($filter['tag_id']); ?>"
                    <?php echo !empty($filter['current']) ? 'aria-current="page"' : ''; ?>
                ><?php echo esc_html($filter['label']); ?></a>
            <?php endforeach; ?>
            <?php if (!empty($more_tags)) : ?>
                <details class="kh-home-tags-more">
                    <summary>
                        Ещё
                        <span aria-label="<?php echo esc_attr('Скрытых меток: ' . count($more_tags)); ?>">+<?php echo esc_html(count($more_tags)); ?></span>
                    </summary>
                    <div class="kh-home-tags-more__panel">
                        <?php foreach ($more_tags as $tag) : ?>
                            <?php
                            $tag_url = get_tag_link($tag);
                            if (is_wp_error($tag_url)) {
                                continue;
                            }
                            ?>
                            <a
                                class="kh-home-filter"
                                href="<?php echo esc_url($tag_url); ?>"
                                data-tag-id="<?php echo esc_attr($tag->term_id); ?>"
                            ><?php echo esc_html($tag->name); ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </nav>
        <p id="kh-tag-filter-status" class="kh-tag-filter-status" role="status" aria-live="polite" hidden></p>
    </section>
    <?php
}

/**
 * Renders a compact category shelf with three current articles.
 */
function svl_home_redesign_render_category_shelf($args) {
    $defaults = array(
        'slug' => '',
        'title' => '',
        'eyebrow' => '',
        'all_label' => '',
        'icon' => '',
    );
    $args = wp_parse_args($args, $defaults);

    $category = get_category_by_slug($args['slug']);
    if (!$category) {
        return;
    }

    $query = new WP_Query(
        array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 3,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'cat' => intval($category->term_id),
        )
    );

    if (!$query->have_posts()) {
        return;
    }

    $category_url = get_category_link($category);
    $heading_id = 'kh-shelf-' . sanitize_html_class($args['slug']);
    ?>
    <section class="kh-category-shelf kh-category-shelf--<?php echo esc_attr($args['slug']); ?>" aria-labelledby="<?php echo esc_attr($heading_id); ?>">
        <header class="kh-category-shelf__header">
            <div class="kh-category-shelf__title">
                <?php if ($args['icon'] !== '') : ?>
                    <span class="kh-category-shelf__icon" aria-hidden="true">
                        <img
                            src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/home-redesign/img/' . $args['icon']); ?>"
                            alt=""
                            width="48"
                            height="48"
                            loading="lazy"
                            decoding="async"
                        >
                    </span>
                <?php endif; ?>
                <div>
                    <p><?php echo esc_html($args['eyebrow']); ?></p>
                    <h2 id="<?php echo esc_attr($heading_id); ?>">
                        <a href="<?php echo esc_url($category_url); ?>"><?php echo esc_html($args['title']); ?></a>
                    </h2>
                </div>
            </div>
            <a class="kh-category-shelf__all" href="<?php echo esc_url($category_url); ?>">
                <?php echo esc_html($args['all_label']); ?><span aria-hidden="true">→</span>
            </a>
        </header>

        <div class="kh-category-shelf__articles">
            <?php while ($query->have_posts()) : ?>
                <?php
                $query->the_post();
                $post_url = get_permalink();
                $thumbnail = get_the_post_thumbnail_url(get_the_ID(), 'medium_large');
                ?>
                <article class="kh-shelf-card">
                    <?php if ($thumbnail) : ?>
                        <a class="kh-shelf-card__media" href="<?php echo esc_url($post_url); ?>" tabindex="-1" aria-hidden="true">
                            <img
                                src="<?php echo esc_url($thumbnail); ?>"
                                alt=""
                                loading="lazy"
                                decoding="async"
                            >
                        </a>
                    <?php endif; ?>
                    <div class="kh-shelf-card__content">
                        <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date('d.m.Y')); ?></time>
                        <h3><a href="<?php echo esc_url($post_url); ?>"><?php echo esc_html(get_the_title()); ?></a></h3>
                        <a class="kh-shelf-card__read" href="<?php echo esc_url($post_url); ?>">
                            Читать<span aria-hidden="true">→</span>
                        </a>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>
    </section>
    <?php

    wp_reset_postdata();
}

/**
 * Adds two evergreen discovery shelves after the main grid and pagination.
 */
add_action('blocksy:content:bottom', 'svl_home_redesign_category_shelves', 15);
function svl_home_redesign_category_shelves() {
    if (!svl_home_redesign_enabled() || !is_home() || is_paged()) {
        return;
    }
    ?>
    <div class="kh-editorial-sections">
        <div class="ct-container">
            <?php
            svl_home_redesign_render_category_shelf(
                array(
                    'slug' => 'gajdy',
                    'title' => 'Гайды',
                    'eyebrow' => 'Колоды и стратегии',
                    'all_label' => 'Все гайды',
                    'icon' => 'guides.png',
                )
            );

            svl_home_redesign_render_category_shelf(
                array(
                    'slug' => 'polya-srazhenij',
                    'title' => 'Поля Сражений',
                    'eyebrow' => 'Герои, карты и тактики',
                    'all_label' => 'Все материалы',
                    'icon' => 'battlegrounds.png',
                )
            );
            ?>
        </div>
    </div>
    <?php
}
