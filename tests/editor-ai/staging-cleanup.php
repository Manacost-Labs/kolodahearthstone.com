<?php
/** Restore a native revision, then remove only the marked disposable test account/post. */
if ( 'staging' !== wp_get_environment_type() || 'test.kolodahearthstone.com' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Staging only.' ); }
$f = get_option( 'kh_editor_ai_fixture' );
if ( ! is_array( $f ) || get_post_meta( $f['post_id'], '_kh_editor_ai_fixture', true ) !== $f['marker'] || get_user_by( 'id', $f['user_id'] )->user_login !== $f['marker'] ) { throw new RuntimeException( 'Fixture ownership mismatch.' ); }
wp_set_current_user( $f['user_id'] );
$before = get_post_field( 'post_content', $f['post_id'] );
$revision = wp_save_post_revision( $f['post_id'] );
if ( ! $revision ) { $revision = array_key_first( wp_get_post_revisions( $f['post_id'] ) ); }
wp_update_post( array( 'ID' => $f['post_id'], 'post_content' => 'Следующая тестовая редакция.' ) );
if ( wp_restore_post_revision( $revision ) !== $f['post_id'] || get_post_field( 'post_content', $f['post_id'] ) !== $before ) { throw new RuntimeException( 'Revision did not restore source.' ); }
echo "PASS native revision restore\n";
foreach ( _get_cron_array() as $events ) {
	foreach ( $events['hs_editor_ai_expire'] ?? array() as $event ) {
		$id = $event['args'][0] ?? '';
		$job = get_transient( 'hs_editor_ai_' . $id );
		if ( is_array( $job ) && $job['user'] === $f['user_id'] ) { wp_clear_scheduled_hook( 'hs_editor_ai_expire', array( $id ) ); wp_clear_scheduled_hook( 'hs_editor_ai_process', array( $id ) ); delete_transient( 'hs_editor_ai_' . $id ); }
	}
}
delete_option( 'hs_editor_ai_budget_' . $f['user_id'] );
delete_option( 'hs_editor_ai_lock_' . $f['user_id'] );
wp_delete_post( $f['post_id'], true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $f['user_id'] );
delete_option( 'kh_editor_ai_fixture' );
echo "PASS disposable user/article/queue cleanup\n";
