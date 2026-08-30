<?php
/**
 * KolodaHearthstone Blocksy child theme bootstrap.
 *
 * Keep this file intentionally small. Site behavior belongs in first-party
 * plugins; this child theme owns only update-safe presentation overrides.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$stylesheet = get_stylesheet_directory() . '/style.css';

		wp_enqueue_style(
			'kolodahearthstone-blocksy-child',
			get_stylesheet_directory_uri() . '/style.css',
			array(),
			file_exists( $stylesheet ) ? (string) filemtime( $stylesheet ) : '0.1.0'
		);
	},
	20
);
