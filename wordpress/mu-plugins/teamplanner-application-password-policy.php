<?php
/**
 * Plugin Name: TeamPlanner Application Password Policy
 * Description: Allows WordPress application passwords only for the dedicated TeamPlanner publisher.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_is_application_passwords_available_for_user',
	static function ( $available, $user ) {
		if ( ! $available || ! ( $user instanceof WP_User ) ) {
			return false;
		}

		return hash_equals( 'teamplanner_bot', (string) $user->user_login )
			&& $user->has_cap( 'edit_posts' );
	},
	PHP_INT_MAX,
	2
);
