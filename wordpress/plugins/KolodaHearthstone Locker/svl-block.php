<?php
/**
 * SVL Gutenberg Block — нативный блок «VIP Locker» для редактора блоков.
 * Динамический рендер через существующий шорткод.
 */
if (!defined('ABSPATH')) exit;

add_action('init', 'svl_block_register');
function svl_block_register() {
    if (!function_exists('register_block_type')) return;

    wp_register_script(
        'svl-block-editor',
        plugins_url('svl-block.js', __FILE__),
        array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor'),
        SVL_VERSION,
        true
    );

    // Передаём список тем в JS
    $themes = function_exists('svl_pro_themes') ? svl_pro_themes() : array('cream' => array('name' => 'Cream'));
    $theme_options = array();
    foreach ($themes as $key => $info) {
        $theme_options[] = array('value' => $key, 'label' => $info['name']);
    }
    wp_localize_script('svl-block-editor', 'svlBlockData', array(
        'themes'      => $theme_options,
        'defaultCode' => function_exists('svl_opt') ? svl_opt('svl_default_code') : '12345',
    ));

    register_block_type('svl/locker', array(
        'editor_script'   => 'svl-block-editor',
        'render_callback' => 'svl_block_render',
        'attributes'      => array(
            'code'      => array('type' => 'string', 'default' => ''),
            'teaser'    => array('type' => 'string', 'default' => ''),
            'theme'     => array('type' => 'string', 'default' => 'cream'),
            'garland'   => array('type' => 'boolean', 'default' => true),
            'image'     => array('type' => 'string', 'default' => ''),
            'message'   => array('type' => 'string', 'default' => ''),
            'content'   => array('type' => 'string', 'default' => 'Закрытый контент здесь'),
            'seo_title' => array('type' => 'string', 'default' => ''),
            'seo_desc'  => array('type' => 'string', 'default' => ''),
            'keywords'  => array('type' => 'string', 'default' => ''),
        ),
    ));
}

function svl_block_render($atts, $content = '') {
    $shortcode_atts = array();
    foreach (array('code','teaser','theme','image','message','seo_title','seo_desc','keywords') as $k) {
        if (!empty($atts[$k])) $shortcode_atts[] = $k . '="' . esc_attr($atts[$k]) . '"';
    }
    if (isset($atts['garland']) && $atts['garland'] === false) $shortcode_atts[] = 'garland="0"';
    $inner = !empty($atts['content']) ? $atts['content'] : 'Закрытый контент';
    $sc = '[vip_locker ' . implode(' ', $shortcode_atts) . ']' . $inner . '[/vip_locker]';
    return do_shortcode($sc);
}
