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

		// Admin hooks.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
			add_action( 'admin_post_uwp_bell_badge_save', array( $this, 'handle_form_submission' ) );
		}
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

		// Add inline CSS based on settings.
		$settings = $this->get_badge_settings();
		$inline_css = $this->generate_badge_css( $settings );
		wp_add_inline_style( $css_handle, $inline_css );
	}

	/**
	 * Get badge settings with defaults.
	 *
	 * @return array Settings array with default values.
	 */
	private function get_badge_settings(): array {
		$defaults = array(
			'badge_color' => '#dc3545',
			'font_color'  => '#ffffff',
			'size'        => '1x',
			'style'       => 'round',
		);

		return wp_parse_args( get_option( 'uwp_bell_badge_settings', array() ), $defaults );
	}

	/**
	 * Register admin menu item.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Notify Count Settings', 'uwp-bell-badge-helper' ),
			__( 'Notify Count', 'uwp-bell-badge-helper' ),
			'manage_options',
			'uwp-bell-badge-settings',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin settings page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'uwp-bell-badge-helper' ) );
		}

		$settings = $this->get_badge_settings();

		// Show admin notices.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = isset( $_GET['reset'] ) && '1' === $_GET['reset'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? __( 'Settings reset to defaults.', 'uwp-bell-badge-helper' )
				: __( 'Settings saved.', 'uwp-bell-badge-helper' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'uwp_bell_badge_settings', 'uwp_bell_badge_nonce' ); ?>
				<input type="hidden" name="action" value="uwp_bell_badge_save" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="badge_color"><?php esc_html_e( 'Badge Color', 'uwp-bell-badge-helper' ); ?></label>
							</th>
							<td>
								<input 
									type="text" 
									id="badge_color" 
									name="badge_color" 
									value="<?php echo esc_attr( $settings['badge_color'] ); ?>" 
									class="regular-text" 
									pattern="^#[0-9A-Fa-f]{6}$"
									placeholder="#dc3545"
								/>
								<p class="description"><?php esc_html_e( 'Enter a hex color code (e.g., #dc3545).', 'uwp-bell-badge-helper' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="font_color"><?php esc_html_e( 'Font Color', 'uwp-bell-badge-helper' ); ?></label>
							</th>
							<td>
								<input 
									type="text" 
									id="font_color" 
									name="font_color" 
									value="<?php echo esc_attr( $settings['font_color'] ); ?>" 
									class="regular-text" 
									pattern="^#[0-9A-Fa-f]{6}$"
									placeholder="#ffffff"
								/>
								<p class="description"><?php esc_html_e( 'Enter a hex color code (e.g., #ffffff).', 'uwp-bell-badge-helper' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="size"><?php esc_html_e( 'Badge Size', 'uwp-bell-badge-helper' ); ?></label>
							</th>
							<td>
								<select id="size" name="size">
									<option value="0.66x" <?php selected( $settings['size'], '0.66x' ); ?>><?php esc_html_e( '0.66x', 'uwp-bell-badge-helper' ); ?></option>
									<option value="1x" <?php selected( $settings['size'], '1x' ); ?>><?php esc_html_e( '1x', 'uwp-bell-badge-helper' ); ?></option>
									<option value="1.25x" <?php selected( $settings['size'], '1.25x' ); ?>><?php esc_html_e( '1.25x', 'uwp-bell-badge-helper' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="style"><?php esc_html_e( 'Badge Style', 'uwp-bell-badge-helper' ); ?></label>
							</th>
							<td>
								<select id="style" name="style">
									<option value="round" <?php selected( $settings['style'], 'round' ); ?>><?php esc_html_e( 'Round', 'uwp-bell-badge-helper' ); ?></option>
									<option value="card" <?php selected( $settings['style'], 'card' ); ?>><?php esc_html_e( 'Card', 'uwp-bell-badge-helper' ); ?></option>
									<option value="square" <?php selected( $settings['style'], 'square' ); ?>><?php esc_html_e( 'Square', 'uwp-bell-badge-helper' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save Settings', 'uwp-bell-badge-helper' ), 'primary', 'submit', false ); ?>
					<?php submit_button( __( 'Reset to Defaults', 'uwp-bell-badge-helper' ), 'secondary', 'reset', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle form submission (save or reset).
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'uwp-bell-badge-helper' ) );
		}

		// Handle reset (check if reset button was clicked).
		if ( isset( $_POST['reset'] ) ) {
			// Verify nonce for reset action.
			if ( ! isset( $_POST['uwp_bell_badge_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwp_bell_badge_nonce'] ) ), 'uwp_bell_badge_settings' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'uwp-bell-badge-helper' ) );
			}
			delete_option( 'uwp_bell_badge_settings' );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => 'uwp-bell-badge-settings',
						'settings-updated' => 'true',
						'reset'           => '1',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// Verify nonce for save action.
		if ( ! isset( $_POST['uwp_bell_badge_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uwp_bell_badge_nonce'] ) ), 'uwp_bell_badge_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'uwp-bell-badge-helper' ) );
		}

		// Validate and sanitize inputs.
		$badge_color = isset( $_POST['badge_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['badge_color'] ) ) : '#dc3545';
		$font_color  = isset( $_POST['font_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['font_color'] ) ) : '#ffffff';

		// Validate size (don't use sanitize_key as it removes dots).
		$size = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '1x';
		$allowed_sizes = array( '0.66x', '1x', '1.25x' );
		if ( ! in_array( $size, $allowed_sizes, true ) ) {
			$size = '1x';
		}

		// Validate style.
		$style = isset( $_POST['style'] ) ? sanitize_text_field( wp_unslash( $_POST['style'] ) ) : 'round';
		$allowed_styles = array( 'round', 'card', 'square' );
		if ( ! in_array( $style, $allowed_styles, true ) ) {
			$style = 'round';
		}

		// Fallback to defaults if colors are invalid.
		if ( empty( $badge_color ) ) {
			$badge_color = '#dc3545';
		}
		if ( empty( $font_color ) ) {
			$font_color = '#ffffff';
		}

		// Save settings.
		$settings = array(
			'badge_color' => $badge_color,
			'font_color'  => $font_color,
			'size'        => $size,
			'style'       => $style,
		);

		update_option( 'uwp_bell_badge_settings', $settings );

		// Redirect back to settings page.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'uwp-bell-badge-settings',
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Generate CSS based on settings.
	 *
	 * @param array $settings Settings array.
	 * @return string Generated CSS.
	 */
	private function generate_badge_css( array $settings ): string {
		// Map size to font-size.
		$size_map = array(
			'0.66x' => '0.66rem',
			'1x'    => '1.0rem',
			'1.25x' => '1.25rem',
		);
		$font_size = isset( $size_map[ $settings['size'] ] ) ? $size_map[ $settings['size'] ] : '1.0rem';

		// Map style to padding and border-radius.
		$style_map = array(
			'round'  => array(
				'padding'      => '0 0.25rem',
				'border-radius' => '999px',
			),
			'card'   => array(
				'padding'      => '0.12rem 0.12rem',
				'border-radius' => '4px',
			),
			'square' => array(
				'padding'      => '0.02rem 0.02rem',
				'border-radius' => '0px',
			),
		);
		$style_css = isset( $style_map[ $settings['style'] ] ) ? $style_map[ $settings['style'] ] : $style_map['round'];

		// Build CSS.
		$css = sprintf(
			'.uwp-bell-badge { background-color: %s; color: %s; font-size: %s; padding: %s; border-radius: %s; }',
			esc_attr( $settings['badge_color'] ),
			esc_attr( $settings['font_color'] ),
			esc_attr( $font_size ),
			esc_attr( $style_css['padding'] ),
			esc_attr( $style_css['border-radius'] )
		);

		return $css;
	}
}

// Bootstrap the plugin.
UWP_Bell_Badge_Helper::get_instance();
