<?php
/**
 * Plugin Name: Manacost Belwe Font
 * Description: Adds the Cyrillic Belwe Bold font to the Classic Editor font list and public content.
 * Version: 1.1.1
 * Author: Manacost
 */

defined( 'ABSPATH' ) || exit;

const HS_BELWE_FONT_VERSION = '1.1.1';
const HS_BELWE_FONT_FORMAT  = 'Belwe Bold by RUS=Belwe Bold by RUS,serif';

/**
 * TinyMCE 4 defaults bundled with WordPress. Defining them here preserves the
 * existing list when font_formats was not customized by another plugin.
 */
const HS_BELWE_TINYMCE_DEFAULT_FORMATS = 'Andale Mono=andale mono,monospace;'
	. 'Arial=arial,helvetica,sans-serif;'
	. 'Arial Black=arial black,sans-serif;'
	. 'Book Antiqua=book antiqua,palatino,serif;'
	. 'Comic Sans MS=comic sans ms,sans-serif;'
	. 'Courier New=courier new,courier,monospace;'
	. 'Georgia=georgia,palatino,serif;'
	. 'Helvetica=helvetica,arial,sans-serif;'
	. 'Impact=impact,sans-serif;'
	. 'Symbol=symbol;'
	. 'Tahoma=tahoma,arial,helvetica,sans-serif;'
	. 'Terminal=terminal,monaco,monospace;'
	. 'Times New Roman=times new roman,times,serif;'
	. 'Trebuchet MS=trebuchet ms,geneva,sans-serif;'
	. 'Verdana=verdana,geneva,sans-serif;'
	. 'Webdings=webdings;'
	. 'Wingdings=wingdings,zapf dingbats';

function hs_belwe_font_stylesheet_url(): string {
	return set_url_scheme(
		content_url( '/mu-plugins/hs-belwe-font/editor.css' ),
		'https'
	);
}

function hs_belwe_font_content_stylesheet_url(): string {
	return set_url_scheme(
		content_url( '/mu-plugins/hs-belwe-font/content.css' ),
		'https'
	);
}

/**
 * Add Belwe without discarding defaults or custom font formats.
 *
 * @param array<string, mixed> $mce_init TinyMCE configuration.
 * @return array<string, mixed>
 */
function hs_belwe_font_tinymce_config( array $mce_init, string $editor_id = '' ): array {
	unset( $editor_id );

	$font_formats = isset( $mce_init['font_formats'] ) && is_string( $mce_init['font_formats'] )
		? trim( $mce_init['font_formats'], " \t\n\r\0\x0B;" )
		: HS_BELWE_TINYMCE_DEFAULT_FORMATS;

	if ( ! str_contains( $font_formats, 'Belwe Bold by RUS=' ) ) {
		$font_formats = HS_BELWE_FONT_FORMAT . ';' . $font_formats;
	}

	$mce_init['font_formats'] = $font_formats;
	return $mce_init;
}

function hs_belwe_font_tinymce_css( string $stylesheets ): string {
	$stylesheets = trim( $stylesheets, " \t\n\r\0\x0B," );
	$belwe_css    = hs_belwe_font_content_stylesheet_url() . '?ver=' . rawurlencode( HS_BELWE_FONT_VERSION );

	return '' === $stylesheets ? $belwe_css : $stylesheets . ',' . $belwe_css;
}

function hs_belwe_font_enqueue_public_style(): void {
	wp_enqueue_style(
		'hs-belwe-bold-rus',
		hs_belwe_font_content_stylesheet_url(),
		[],
		HS_BELWE_FONT_VERSION
	);
}

function hs_belwe_font_enqueue_admin_style( string $hook_suffix ): void {
	if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}

	wp_enqueue_style(
		'hs-belwe-bold-rus-admin',
		hs_belwe_font_stylesheet_url(),
		[],
		HS_BELWE_FONT_VERSION
	);
}

add_filter( 'tiny_mce_before_init', 'hs_belwe_font_tinymce_config', 100, 2 );
add_filter( 'mce_css', 'hs_belwe_font_tinymce_css', 100 );
add_action( 'wp_enqueue_scripts', 'hs_belwe_font_enqueue_public_style' );
add_action( 'admin_enqueue_scripts', 'hs_belwe_font_enqueue_admin_style' );
