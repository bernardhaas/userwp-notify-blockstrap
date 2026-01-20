<?php
/**
 * Plugin Name: UWP Bell Badge Helper
 * Description: Adds a REST endpoint and front-end badge for UsersWP realtime notifications bell inside BlockStrap menus.
 * Author: SiteSpot
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * WHY THIS PLUGIN EXISTS:
 * =======================
 *
 * The UsersWP Notifications plugin uses a classic menu hook to inject the bell badge:
 * - Filter: 'uwp_setup_nav_menu_item' (in class-uwp-notifications.php)
 * - Method: uwp_setup_nav_menu_item() modifies menu items with class 'users-wp-notifications-nav'
 * - It appends the badge HTML directly to the menu item title during menu rendering
 *
 * BlockStrap uses block-based navigation (wp_navigation post type), which:
 * - Renders via block templates, not the traditional wp_nav_menu() walker
 * - Does NOT fire the 'uwp_setup_nav_menu_item' filter
 * - Cannot reliably render shortcodes inside nav templates
 *
 * SOLUTION:
 * =========
 * This helper uses a client-side injection approach:
 * 1. REST endpoint provides unread count (avoids shortcode limitations)
 * 2. JS finds the nav link by CSS class (.uwp-bell-link) and injects badge element
 * 3. Badge is populated via AJAX call to the REST endpoint
 * This works with any navigation system (classic or block-based) as long as the link has the class.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UWP_Bell_Badge_Helper {

	/**
	 * Singleton instance.
	 *
	 * @var UWP_Bell_Badge_Helper|null
	 */
	private static ?UWP_Bell_Badge_Helper $instance = null;

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private string $slug = 'uwp-bell-badge-helper';

	/**
	 * Get singleton instance.
	 *
	 * @return UWP_Bell_Badge_Helper
	 */
	public static function get_instance(): UWP_Bell_Badge_Helper {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * UWP_Bell_Badge_Helper constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'maybe_load' ) );
	}

	/**
	 * Only load on front-end when UsersWP Notifications is active.
	 *
	 * Keeps things lightweight and avoids fatals if the dependency is missing.
	 *
	 * @return void
	 */
	public function maybe_load(): void {
		if ( is_admin() ) {
			return;
		}

		// Soft dependency check – plugin defines this function.
		if ( ! function_exists( 'uwp_get_unread_notification_count' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register custom REST routes.
	 *
	 * Route: /wp-json/uwp-bell/v1/unread-count
	 *
	 * This endpoint exists because:
	 * - Shortcodes cannot be reliably rendered in BlockStrap nav templates
	 * - The classic menu filter 'uwp_setup_nav_menu_item' doesn't fire for block nav
	 * - We need a lightweight way to fetch the count without server-side rendering
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'uwp-bell/v1',
			'/unread-count',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get_unread_count' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * REST callback: return unread notification count for current user.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_unread_count( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'count' => 0,
				),
				200
			);
		}

		$count = 0;

		// Preferred: use the helper from UsersWP Notifications if available.
		if ( function_exists( 'uwp_get_unread_notification_count' ) ) {
			$count = (int) uwp_get_unread_notification_count( $user_id );
		} else {
			// Fallback: query storage model directly if the function is not available for some reason.
			global $wpdb;

			if ( function_exists( 'uwp_get_table_prefix' ) ) {
				$table_name = uwp_get_table_prefix() . 'uwp_realtime_notifications';

				$status_sql = " AND `status` = '0' ";
				if ( function_exists( 'uwp_get_active_notification_types' ) ) {
					$types = uwp_get_active_notification_types( $user_id );
					if ( ! empty( $types ) && is_array( $types ) ) {
						$types      = join( "','", array_map( 'sanitize_key', $types ) );
						$status_sql .= " AND `type` IN ('" . $types . "') ";
					}
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT count(`id`) AS `count`
						FROM {$table_name}
						WHERE `user_id` != %d
						  AND `notify_user_id` = %d
						  {$status_sql}
						ORDER BY id DESC",
						$user_id,
						$user_id
					)
				);

				if ( ! empty( $results[0]->count ) ) {
					$count = (int) $results[0]->count;
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'count' => $count,
			),
			200
		);
	}

	/**
	 * Enqueue front-end assets for the BlockStrap bell badge.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$handle     = $this->slug . '-script';
		$css_handle = $this->slug . '-style';

		$plugin_url = plugin_dir_url( __FILE__ );
		$version    = '1.0.0';

		wp_enqueue_style(
			$css_handle,
			$plugin_url . 'assets/css/uwp-bell-badge.css',
			array(),
			$version
		);

		wp_enqueue_script(
			$handle,
			$plugin_url . 'assets/js/uwp-bell-badge.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			$handle,
			'uwpBellBadge',
			array(
				'root'  => esc_url_raw( rest_url( 'uwp-bell/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}

// Bootstrap the plugin.
UWP_Bell_Badge_Helper::get_instance();
