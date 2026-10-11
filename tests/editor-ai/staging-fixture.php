<?php
/** Synthetic provider boundary restricted to one explicitly marked staging account/post. */
if ( 'staging' !== wp_get_environment_type() || 'test.kolodahearthstone.com' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'Isolated Koloda staging required.' );
}
add_filter( 'rest_request_before_callbacks', function ( $response, $handler, $request ) {
	if ( 'POST' !== $request->get_method() || ! preg_match( '~^/hs-editor-ai/v1/jobs/([a-f0-9]{32})/run$~', $request->get_route(), $match ) ) {
		return $response;
	}
	$job = get_transient( 'hs_editor_ai_' . $match[1] );
	$fixture = get_option( 'kh_editor_ai_fixture', array() );
	if ( is_array( $job ) && ( $job['user'] ?? 0 ) === get_current_user_id() && ( $job['post_id'] ?? 0 ) === ( $fixture['post_id'] ?? -1 ) && ( $job['user'] ?? 0 ) === ( $fixture['user_id'] ?? -1 ) ) {
		$GLOBALS['kh_editor_ai_fixture_active'] = true;

	}
	return $response;
}, 10, 3 );
// A fixture review may be picked up by cron: scope the same HTTP boundary there.
add_action( 'hs_editor_ai_process', function ( $id ) {
 $job = get_transient( 'hs_editor_ai_' . $id );
 $f = get_option( 'kh_editor_ai_fixture', array() );
 if ( is_array( $job ) && ( $job['post_id'] ?? 0 ) === ( $f['post_id'] ?? -1 ) && ( $job['user'] ?? 0 ) === ( $f['user_id'] ?? -1 ) ) { $GLOBALS['kh_editor_ai_fixture_active'] = true; }
}, 0 );
add_action( 'hs_editor_ai_process', function () { unset( $GLOBALS['kh_editor_ai_fixture_active'] ); }, PHP_INT_MAX );
// Keep only the owned synthetic job off the independent cron runner.
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	unset( $GLOBALS['kh_editor_ai_fixture_active'] );
	$fixture = get_option( 'kh_editor_ai_fixture', array() );
	if ( 'POST' === $request->get_method() && '/hs-editor-ai/v1/jobs' === $request->get_route() && (int) $request['post_id'] === ( $fixture['post_id'] ?? -1 ) && get_current_user_id() === ( $fixture['user_id'] ?? -1 ) ) {
		$id = $response->get_data()['id'] ?? '';
		if ( preg_match( '/^[a-f0-9]{32}$/D', $id ) ) { wp_clear_scheduled_hook( 'hs_editor_ai_process', array( $id ) ); }
	}
	return $response;
}, 10, 3 );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( empty( $GLOBALS['kh_editor_ai_fixture_active'] ) || 'https://api.anthropic.com/v1/messages' !== $url ) { return $pre; }
	usleep( 1200000 );
	$request = json_decode( $args['body'], true );
	$input = json_decode( $request['messages'][0]['content'], true );
	if ( isset( $input['original'] ) ) { $result = array( 'approved' => true ); }
	else {
		$edits = array();
		foreach ( $input['segments'] ?? array() as $segment ) {
			$text = $segment['text'];
			if ( ! empty( $input['catalog'] ) ) {
				$tag = str_contains( $input['context'], 'Полях сражений' ) ? 'hs_bg' : 'hs_card';
				$id = 'hs_bg' === $tag ? get_option( 'kh_editor_ai_fixture' )['bg_id'] : 'EX1_116';
				$name = 'hs_bg' === $tag ? 'Лирой Бесшабашный' : 'Лирой Дженкинс';
				$text = str_replace( $name, '[' . $tag . ' id="' . $id . '"]' . $name . '[/' . $tag . ']', $text );
			} else { $text = str_replace( 'мнаны', 'маны', $text ); }
			if ( $text !== $segment['text'] ) { $edits[] = array( 'segment' => $segment['segment'], 'replacement' => $text ); }
		}
		$result = array( 'edits' => $edits );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result ) ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
}, 10, 3 );
