<?php
/**
 * Plugin Name: KolodaHearthstone Article Tail Controls
 * Description: Removes the author card and adjacent-post navigation from single articles.
 *
 * Blocksy keeps these settings in the runtime Customizer option. Filters keep the
 * production presentation source-controlled without editing Blocksy itself.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hides a Blocksy single-post element while preserving the stored Customizer value.
 *
 * @param string $value Current Blocksy setting.
 * @return string Disabled Blocksy switch value.
 */
function hs_article_tail_disable_blocksy_element( $value ) {
	return 'no';
}

add_filter(
	'theme_mod_single_blog_post_has_author_box',
	'hs_article_tail_disable_blocksy_element'
);

add_filter(
	'theme_mod_single_blog_post_has_post_nav',
	'hs_article_tail_disable_blocksy_element'
);
