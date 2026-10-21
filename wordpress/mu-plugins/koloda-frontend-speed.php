<?php
/**
 * Plugin Name: Koloda Frontend Speed
 * Description: Prerendered internal navigation with a native cross-fade, an eagerly loaded first cover and a non-blocking jQuery for anonymous readers.
 * Version: 1.0.0
 *
 * @package KolodaHearthstone
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps public navigation fast and smooth without changing page content.
 *
 * Every behavior has a kill switch constant so it can be disabled from
 * wp-config.php without a code deployment.
 */
final class Koloda_Frontend_Speed {
	/** Paths that are personal, mutating or not documents; never speculated. */
	private const EXCLUDED_PATHS = array(
		'/lichnyj-kabinet*',
		'/dobavlenie-kolody*',
		'/login*',
		'/logout*',
		'/feed*',
		'/*/feed*',
		'/sitemap*',
		'/comments/*',
	);

	/** @var int|null Attachment rendered as the page's leading image. */
	private static ?int $leading_image = null;

	/** Registers public frontend hooks. */
	public static function boot(): void {
		if ( self::enabled( 'KOLODA_PRERENDER_ENABLED' ) ) {
			add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'configure_speculation' ) );
			add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'exclude_paths' ) );
		}
		if ( self::enabled( 'KOLODA_VIEW_TRANSITIONS_ENABLED' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'print_view_transition_styles' ), 1 );
		}
		if ( self::enabled( 'KOLODA_LEADING_IMAGE_ENABLED' ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'prioritize_leading_image' ), 20, 2 );
		}
		if ( self::enabled( 'KOLODA_FOOTER_JQUERY_ENABLED' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'move_jquery_to_footer' ), PHP_INT_MAX );
		}
	}

	/**
	 * Prerenders on hover (desktop) or Chrome's viewport heuristic (mobile).
	 *
	 * Core already limits rules to logged-out visitors with pretty permalinks.
	 *
	 * @param array<string, string>|null $config Core configuration; null means disabled.
	 * @return array<string, string>|null
	 */
	public static function configure_speculation( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}

		return array(
			'mode'      => 'prerender',
			'eagerness' => 'moderate',
		);
	}

	/**
	 * Adds site-specific private and non-document paths to core's exclusions.
	 *
	 * @param array<int, string> $paths Existing exclusion patterns.
	 * @return array<int, string>
	 */
	public static function exclude_paths( $paths ): array {
		return array_values( array_unique( array_merge( (array) $paths, self::EXCLUDED_PATHS ) ) );
	}

	/** Cross-fades same-origin navigations; the old page stays opaque until the new one covers it. */
	public static function print_view_transition_styles(): void {
		if ( is_admin() || is_customize_preview() ) {
			return;
		}
		?>
<style id="koloda-view-transitions">@media (prefers-reduced-motion:no-preference){@view-transition{navigation:auto}::view-transition-group(root){animation-duration:180ms;animation-timing-function:ease-out}::view-transition-old(root){animation:none}::view-transition-new(root){animation:180ms ease-out both koloda-page-enter;mix-blend-mode:normal}@keyframes koloda-page-enter{from{opacity:0}to{opacity:1}}}</style>
		<?php
	}

	/**
	 * Loads the first listing cover or the article cover eagerly with high priority.
	 *
	 * W3 Total Cache leaves tags containing loading="eager" alone, so the image
	 * that becomes LCP no longer waits for the lazy-load script.
	 *
	 * @param array<string, string> $attributes Image attributes.
	 * @param WP_Post|mixed         $attachment Attachment post.
	 * @return array<string, string>
	 */
	public static function prioritize_leading_image( $attributes, $attachment ): array {
		$attributes = (array) $attributes;
		$id         = $attachment instanceof WP_Post ? (int) $attachment->ID : 0;
		if ( 0 === $id || is_admin() || is_feed() || ( ! in_the_loop() && ! is_singular() ) ) {
			return $attributes;
		}

		if ( null === self::$leading_image ) {
			if ( is_singular() ) {
				self::$leading_image = (int) get_post_thumbnail_id( get_queried_object_id() );
			} elseif ( ( is_home() || is_archive() || is_search() ) && in_the_loop() && is_main_query() ) {
				self::$leading_image = $id;
			}
		}

		// Blocksy filters the same attachment twice per render; both passes must agree.
		if ( self::$leading_image === $id ) {
			$attributes['loading']       = 'eager';
			$attributes['fetchpriority'] = 'high';
			$attributes['decoding']      = 'async';
		}

		return $attributes;
	}

	/**
	 * Prints jQuery with the footer scripts that use it instead of blocking first paint.
	 *
	 * WordPress keeps jQuery in the head on its own when a head script depends on it.
	 */
	public static function move_jquery_to_footer(): void {
		if ( is_admin() || is_user_logged_in() || is_customize_preview() ) {
			return;
		}

		$scripts = wp_scripts();
		foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $handle ) {
			if ( isset( $scripts->registered[ $handle ] ) ) {
				$scripts->add_data( $handle, 'group', 1 );
			}
		}
	}

	/** Reads an optional kill switch; features are on unless the constant is false. */
	private static function enabled( string $constant ): bool {
		return ! defined( $constant ) || false !== constant( $constant );
	}
}

Koloda_Frontend_Speed::boot();
