<?php
/** Read-only native markup fixture, run with staging WP-CLI. */
defined( 'ABSPATH' ) || exit;

if ( 'test.kolodahearthstone.com' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'Only the isolated Koloda staging site is allowed.' );
}

$gallery_source = getenv( 'KOLODA_GALLERY_SOURCE' );
if ( ! $gallery_source || ! is_dir( $gallery_source ) ) {
	throw new RuntimeException( 'Specify the reviewed gallery source directory.' );
}
require_once $gallery_source . '/wordpress/plugins/hs-api-gallery/hs-api-gallery.php';
$gallery_ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => 6, 'fields' => 'ids' ) );
if ( count( $gallery_ids ) < 6 ) {
	throw new RuntimeException( 'Six existing staging images are required; no media is created.' );
}
$GLOBALS['post'] = new WP_Post( (object) array( 'ID' => 0, 'post_status' => 'draft', 'post_type' => 'post' ) );
$gallery_markup = array();
foreach ( range( 1, 9 ) as $gallery_columns ) {
	foreach ( array( false, true ) as $gallery_rated ) {
		$gallery_markup[ $gallery_columns . ( $gallery_rated ? '-rated' : '-plain' ) ] = gallery_shortcode(
			array( 'ids' => implode( ',', $gallery_ids ), 'columns' => $gallery_columns, 'size' => 'medium', 'link' => 'file', 'hs_ratings' => $gallery_rated ? '1' : '0' )
		);
	}
}
echo wp_json_encode( $gallery_markup );
