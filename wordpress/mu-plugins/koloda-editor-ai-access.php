<?php
/**
 * Plugin Name: Koloda Editor AI Access
 * Description: Administrator-only access bridge for the pinned Manacost editor plugin.
 *
 * @package KolodaHearthstone
 */

defined( 'ABSPATH' ) || exit;

/** Install only the missing rollout predicate after regular plugins have loaded. */
function kh_editor_ai_access_bridge(): void {
	if ( ! class_exists( 'Manacost\\EditorAi\\Jobs' ) || function_exists( 'hs_ew_is_enabled' ) ) {
		return;
	}

	/**
	 * Keep paid AI operations restricted to site administrators.
	 *
	 * @return bool
	 */
	function hs_ew_is_enabled(): bool {
		return current_user_can( 'manage_options' );
	}
}
add_action( 'plugins_loaded', 'kh_editor_ai_access_bridge', 1 );
