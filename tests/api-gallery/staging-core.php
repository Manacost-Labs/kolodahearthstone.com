<?php
/**
 * Real staging media/editor/vote regression on a dedicated disposable fixture.
 *
 * @package KolodaHearthstoneTests
 */

use Manacost\ApiGallery\Catalog;
use Manacost\ApiGallery\Gallery;
use Manacost\ApiGallery\Importer;
use Manacost\ApiGallery\Votes;

/** Fail without logging private request or identity data. */
function koloda_gallery_port_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

koloda_gallery_port_assert(
	defined( 'WP_CLI' ) && WP_CLI && 'staging' === wp_get_environment_type()
	&& 'test.kolodahearthstone.com' === wp_parse_url( home_url(), PHP_URL_HOST ),
	'Staging WP-CLI only'
);
$post_id = (int) getenv( 'KOLODA_GALLERY_FIXTURE_POST_ID' );
$post    = get_post( $post_id );
$marker  = get_post_meta( $post_id, '_koloda_api_gallery_port_fixture', true );
$author  = $post ? get_userdata( $post->post_author ) : false;
koloda_gallery_port_assert(
	$post && 'draft' === $post->post_status && is_string( $marker )
	&& str_starts_with( $marker, 'gallery-port-' ) && $author && $author->user_login === $marker,
	'Not an owned draft fixture'
);
wp_set_current_user( $post->post_author );
Votes::install();
Votes::install();
koloda_gallery_port_assert( '1' === get_option( 'hs_api_gallery_schema' ), 'Schema idempotence failed' );
$data = Catalog::page( 'diamond-cards', 1, 'all' );
koloda_gallery_port_assert( ! is_wp_error( $data ) && count( $data['items'] ) >= 3, 'Diamond catalog unavailable' );
$ids = array();
foreach ( array_slice( $data['items'], 0, 3 ) as $item ) {
	$variant = array_key_first( $item['images'] );
	$id      = Importer::import( $post_id, 'diamond-cards', $item['id'], $variant );
	koloda_gallery_port_assert( ! is_wp_error( $id ), 'Image import failed' );
	koloda_gallery_port_assert( Importer::import( $post_id, 'diamond-cards', $item['id'], $variant ) === $id, 'Retry created duplicate media' );
	$file = get_attached_file( $id );
	koloda_gallery_port_assert( is_file( $file ) && hash_file( 'sha256', $file ) === get_post_meta( $id, '_hs_api_gallery_sha256', true ), 'Snapshot SHA256 mismatch' );
	$ids[] = $id;
}
$content = '[gallery ids="' . implode( ',', $ids ) . '" columns="3" size="medium" link="file" hs_ratings="1"]';
$key     = hash( 'sha256', 'koloda-gallery-port-owned-test-' . $post_id );
try {
	wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
	wp_save_post_revision( $post_id );
	koloda_gallery_port_assert( get_post( $post_id )->post_content === $content, 'Stored shortcode changed' );
	require_once ABSPATH . 'wp-admin/includes/post.php';
	$autosave = wp_create_post_autosave(
		array(
			'post_ID'      => $post_id,
			'post_type'    => 'post',
			'post_author'  => $post->post_author,
			'post_title'   => $post->post_title,
			'post_content' => $content . "\nАвтосохранение",
			'post_excerpt' => '',
		)
	);
	koloda_gallery_port_assert( ! is_wp_error( $autosave ) && (bool) $autosave, 'Autosave failed' );
	wp_update_post( array( 'ID' => $post_id, 'post_content' => $content . "\nВерсия для восстановления" ) );
	$restore = 0;
	foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
		if ( $revision->post_content === $content ) {
			$restore = $revision->ID;
			break;
		}
	}
	koloda_gallery_port_assert( $restore && wp_restore_post_revision( $restore ) && get_post( $post_id )->post_content === $content, 'Revision restore failed' );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	koloda_gallery_port_assert( Gallery::eligible( $post_id ) === $ids, 'Published membership failed' );
	koloda_gallery_port_assert( Votes::save( $post_id, $ids[0], $key, 5 ) && Votes::save( $post_id, $ids[0], $key, 4 ), 'Vote writes failed' );
	$summary = Votes::summary( $post_id, $ids[0], $key );
	koloda_gallery_port_assert( 1 === (int) $summary['count'] && 4 === (int) $summary['mine'], 'Vote upsert failed' );
	Votes::save( $post_id, $ids[0], $key, 0 );
	koloda_gallery_port_assert( 0 === (int) Votes::summary( $post_id, $ids[0], $key )['count'], 'Vote removal failed' );
} finally {
	Votes::save( $post_id, $ids[0], $key, 0 );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
}
koloda_gallery_port_assert( ! Gallery::eligible( $post_id ), 'Draft is vote eligible' );
echo wp_json_encode(
	array(
		'post_id'     => $post_id,
		'attachments' => $ids,
		'status'      => 'PASS',
		'checks'      => array( 'schema', 'diamond import', 'sha256', 'retry', 'stored shortcode', 'autosave', 'revision', 'published membership', 'vote upsert/remove', 'draft rejection' ),
	)
);
