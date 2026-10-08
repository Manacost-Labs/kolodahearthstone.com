<?php
/**
 * Plugin Name: Koloda Native Gallery Layout
 * Description: Column layouts for native article galleries on Blocksy.
 * Version: 1.0.0
 *
 * @package KolodaHearthstone
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( 'blocksy' !== get_template() || ! is_singular() || is_feed() ) {
			return;
		}
		$article = get_post();
		if ( ! $article || ! has_shortcode( $article->post_content, 'gallery' ) ) {
			return;
		}
		wp_enqueue_style(
			'koloda-native-gallery',
			plugins_url( 'koloda-native-gallery/layout.css', __FILE__ ),
			array(),
			'1.0.0'
		);
	},
	30
);
