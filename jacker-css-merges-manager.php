<?php
/**
 * Plugin Name:       Jacker CSS Merges Manager
 * Plugin URI:        https://jackerteo.com/plugin/jacker-css-merges-manager
 * Description:       Merges enqueued CSS files into one request. Icon fonts excluded. Per-page cache management via admin bar and settings page. Auto-integrates with OneWebP.
 * Version:           1.0.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Jacker Architect
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jacker-css-merges-manager
 *
 * @package JCSSMM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JCSSMM_VERSION', '1.0.1' );
define( 'JCSSMM_OPTION', 'jcssmm_settings' );
define( 'JCSSMM_MAP_OPTION', 'jcssmm_page_map' );
define( 'JCSSMM_CACHE_DIR', 'jcssmm' );

/**
 * Main plugin class.
 */
final class JCSSMM {

	/**
	 * Singleton instance.
	 *
	 * @var JCSSMM|null
	 */
	private static $instance = null;

	/**
	 * Runtime settings.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Handles or source fragments that are never merged.
	 *
	 * @var array
	 */
	private $hard_ignore_src = array(
		'font-awesome',
		'fontawesome',
		'elementor-icons',
		'eicons',
		'dashicons',
		'glyphicons',
		'ionicons',
		'icomoon',
		'genericons',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return JCSSMM
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = self::get_settings();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_jcssmm_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_post_jcssmm_purge_page', array( $this, 'handle_purge_page' ) );
		add_action( 'admin_post_jcssmm_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );

		if ( ! is_admin() ) {
			add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 99 );
		}

		add_action( 'save_post', array( $this, 'invalidate_cache' ) );
		add_action( 'switch_theme', array( $this, 'invalidate_cache' ) );
		add_action( 'customize_save_after', array( $this, 'invalidate_cache' ) );
		add_action( 'upgrader_process_complete', array( $this, 'invalidate_cache' ) );
		add_action( 'activated_plugin', array( $this, 'invalidate_cache' ) );
		add_action( 'deactivated_plugin', array( $this, 'invalidate_cache' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'invalidate_cache' ) );
		add_action( 'widget_update_callback', array( $this, 'invalidate_cache' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enable_merge' => 1,
			'cache_ttl'    => 86400,
			'exclude_urls' => '',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( JCSSMM_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Parse a newline or comma separated setting into an array.
	 *
	 * @param string $value Raw value.
	 * @return array
	 */
	private function parse_list( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$parts = preg_split( '/[\r\n,]+/', $value );
		$parts = array_map( 'trim', $parts );
		return array_values( array_filter( $parts ) );
	}

	/**
	 * Check whether the current request is excluded.
	 *
	 * @return bool
	 */
	private function is_excluded() {
		$patterns = $this->parse_list( $this->settings['exclude_urls'] );
		if ( empty( $patterns ) ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $uri, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a handle or source should never be merged.
	 *
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @return bool
	 */
	private function is_hard_ignored( $handle, $src ) {
		$haystack = strtolower( $handle . ' ' . $src );
		foreach ( $this->hard_ignore_src as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Current page URL path (without query string).
	 *
	 * @return string
	 */
	private function current_page_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = strtok( $uri, '?' );
		return $path ? $path : '/';
	}

	/**
	 * Full URL of the current request.
	 *
	 * @return string
	 */
	private function current_full_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $scheme . '://' . $host . $uri;
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_options_page(
			__( 'JCSSMM Settings', 'jacker-css-merges-manager' ),
			__( 'JCSSMM', 'jacker-css-merges-manager' ),
			'manage_options',
			'jcssmm',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'jcssmm_settings_group',
			JCSSMM_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'jcssmm_section_main',
			__( 'CSS Merger', 'jacker-css-merges-manager' ),
			array( $this, 'section_main_intro' ),
			'jcssmm'
		);

		add_settings_field(
			'jcssmm_field_enable_merge',
			__( 'Enable CSS merging', 'jacker-css-merges-manager' ),
			array( $this, 'render_checkbox' ),
			'jcssmm',
			'jcssmm_section_main',
			array(
				'key'         => 'enable_merge',
				'description' => __( 'Combines local CSS files into one request. Icon font libraries are automatically excluded. JavaScript is never touched.', 'jacker-css-merges-manager' ),
			)
		);

		add_settings_field(
			'jcssmm_field_cache_ttl',
			__( 'Cache TTL (seconds)', 'jacker-css-merges-manager' ),
			array( $this, 'render_text' ),
			'jcssmm',
			'jcssmm_section_main',
			array(
				'key'         => 'cache_ttl',
				'type'        => 'number',
				'description' => __( 'How long merged files are kept. 86400 = 1 day.', 'jacker-css-merges-manager' ),
			)
		);

		add_settings_field(
			'jcssmm_field_exclude_urls',
			__( 'Exclude URL patterns', 'jacker-css-merges-manager' ),
			array( $this, 'render_textarea' ),
			'jcssmm',
			'jcssmm_section_main',
			array(
				'key'         => 'exclude_urls',
				'description' => __( 'One substring per line. Pages whose URL contains any of these are skipped.', 'jacker-css-merges-manager' ),
			)
		);
	}

	/**
	 * Section intro.
	 *
	 * @return void
	 */
	public function section_main_intro() {
		echo '<p>' . esc_html__( 'Merges local CSS files into one request. Icon font libraries are skipped automatically. JavaScript is never touched.', 'jacker-css-merges-manager' ) . '</p>';
	}

	/**
	 * Render a field description.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	private function render_description( $args ) {
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_checkbox( $args ) {
		$key = $args['key'];
		$val = isset( $this->settings[ $key ] ) ? (int) $this->settings[ $key ] : 0;
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" id="jcssmm_%2$s" value="1" %3$s /> %4$s</label>',
			esc_attr( JCSSMM_OPTION ),
			esc_attr( $key ),
			checked( 1, $val, false ),
			esc_html__( 'Enabled', 'jacker-css-merges-manager' )
		);
		$this->render_description( $args );
	}

	/**
	 * Render text field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_text( $args ) {
		$key  = $args['key'];
		$type = isset( $args['type'] ) ? $args['type'] : 'text';
		$val  = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
		printf(
			'<input type="%1$s" class="regular-text" name="%2$s[%3$s]" id="jcssmm_%3$s" value="%4$s" />',
			esc_attr( $type ),
			esc_attr( JCSSMM_OPTION ),
			esc_attr( $key ),
			esc_attr( $val )
		);
		$this->render_description( $args );
	}

	/**
	 * Render textarea field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_textarea( $args ) {
		$key = $args['key'];
		$val = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
		printf(
			'<textarea class="large-text code" rows="4" name="%1$s[%2$s]" id="jcssmm_%2$s">%3$s</textarea>',
			esc_attr( JCSSMM_OPTION ),
			esc_attr( $key ),
			esc_textarea( $val )
		);
		$this->render_description( $args );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$out      = array();

		$out['enable_merge'] = ( isset( $input['enable_merge'] ) && '1' === (string) $input['enable_merge'] ) ? 1 : 0;
		$out['cache_ttl']    = isset( $input['cache_ttl'] ) ? max( 300, absint( $input['cache_ttl'] ) ) : $defaults['cache_ttl'];
		$out['exclude_urls'] = isset( $input['exclude_urls'] ) ? sanitize_textarea_field( $input['exclude_urls'] ) : '';

		self::purge_all_cache();

		return $out;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$files = self::list_cache_files();
		$count = count( $files );
		$size  = 0;
		foreach ( $files as $file ) {
			$size += (int) filesize( $file );
		}

		$map     = self::get_page_map();
		$notice  = isset( $_GET['jcssmm_notice'] ) ? sanitize_key( $_GET['jcssmm_notice'] ) : '';
		$onewebp = $this->get_onewebp_version();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( 'cleared' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'JCSSMM cache cleared.', 'jacker-css-merges-manager' ); ?></p></div>
			<?php elseif ( 'page_purged' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Page cache purged.', 'jacker-css-merges-manager' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Merged files:', 'jacker-css-merges-manager' ); ?></strong> <?php echo esc_html( (string) $count ); ?></p>
				<p><strong><?php esc_html_e( 'Total size:', 'jacker-css-merges-manager' ); ?></strong> <?php echo esc_html( self::format_bytes( $size ) ); ?></p>
				<p><strong><?php esc_html_e( 'Tracked pages:', 'jacker-css-merges-manager' ); ?></strong> <?php echo esc_html( (string) count( $map ) ); ?></p>
				<p><strong><?php esc_html_e( 'Hard-ignored sources:', 'jacker-css-merges-manager' ); ?></strong> <?php echo esc_html( implode( ', ', $this->hard_ignore_src ) ); ?></p>
				<p><strong><?php esc_html_e( 'Plugin version:', 'jacker-css-merges-manager' ); ?></strong> <?php echo esc_html( JCSSMM_VERSION ); ?></p>
			</div>

			<div class="notice <?php echo '' !== $onewebp ? 'notice-success' : 'notice-info'; ?>">
				<p>
					<strong><?php esc_html_e( 'OneWebP integration:', 'jacker-css-merges-manager' ); ?></strong>
					<?php if ( '' !== $onewebp ) : ?>
						<?php
						printf(
							/* translators: %s: OneWebP version number. */
							esc_html__( 'Detected OneWebP v%s. Background images inside merged CSS will be rewritten to WebP when a matching .jo.webp file exists.', 'jacker-css-merges-manager' ),
							esc_html( $onewebp )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'OneWebP not detected. Background URLs are left as-is.', 'jacker-css-merges-manager' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'jcssmm_settings_group' );
				do_settings_sections( 'jcssmm' );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Per-page cache', 'jacker-css-merges-manager' ); ?></h2>
			<?php if ( empty( $map ) ) : ?>
				<p><?php esc_html_e( 'No pages cached yet. Visit your site as a logged-in admin to populate this list.', 'jacker-css-merges-manager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page URL', 'jacker-css-merges-manager' ); ?></th>
							<th><?php esc_html_e( 'File', 'jacker-css-merges-manager' ); ?></th>
							<th><?php esc_html_e( 'Size', 'jacker-css-merges-manager' ); ?></th>
							<th><?php esc_html_e( 'Action', 'jacker-css-merges-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $map as $path => $entry ) : ?>
							<?php
							$file_path = trailingslashit( self::cache_dir() ) . $entry['file'];
							$file_size = is_file( $file_path ) ? (int) filesize( $file_path ) : 0;
							$purge_url = wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'jcssmm_purge_page',
										'path'   => rawurlencode( $path ),
									),
									admin_url( 'admin-post.php' )
								),
								'jcssmm_purge_page'
							);
							?>
							<tr>
								<td><code><?php echo esc_html( $path ); ?></code></td>
								<td><?php echo esc_html( $entry['file'] ); ?></td>
								<td><?php echo esc_html( self::format_bytes( $file_size ) ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $purge_url ); ?>"><?php esc_html_e( 'Purge this page', 'jacker-css-merges-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Global actions', 'jacker-css-merges-manager' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="jcssmm_clear_cache" />
				<?php wp_nonce_field( 'jcssmm_clear_cache' ); ?>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Purge all cache', 'jacker-css-merges-manager' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Admin bar
	 * ------------------------------------------------------------------ */

	/**
	 * Add admin bar menu.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 * @return void
	 */
	public function add_admin_bar_menu( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'jcssmm',
				'title' => 'JCSSMM',
				'href'  => admin_url( 'options-general.php?page=jcssmm' ),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'jcssmm-purge-this',
				'parent' => 'jcssmm',
				'title'  => __( 'Purge This Page', 'jacker-css-merges-manager' ),
				'href'   => $this->purge_this_page_url(),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'jcssmm-purge-all',
				'parent' => 'jcssmm',
				'title'  => __( 'Purge All', 'jacker-css-merges-manager' ),
				'href'   => $this->purge_all_url(),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'jcssmm-settings',
				'parent' => 'jcssmm',
				'title'  => __( 'Settings', 'jacker-css-merges-manager' ),
				'href'   => admin_url( 'options-general.php?page=jcssmm' ),
			)
		);
	}

	/**
	 * URL for "Purge This Page" action.
	 *
	 * @return string
	 */
	private function purge_this_page_url() {
		$path = $this->current_page_path();
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'jcssmm_purge_page',
					'path'   => rawurlencode( $path ),
					'return' => rawurlencode( $this->current_full_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'jcssmm_purge_page'
		);
	}

	/**
	 * URL for "Purge All" action.
	 *
	 * @return string
	 */
	private function purge_all_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'jcssmm_purge_all',
					'return' => rawurlencode( $this->current_full_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			'jcssmm_purge_all'
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin-post handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Handle global clear cache.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'jacker-css-merges-manager' ) );
		}
		check_admin_referer( 'jcssmm_clear_cache' );
		self::purge_all_cache();
		wp_safe_redirect( add_query_arg( 'jcssmm_notice', 'cleared', admin_url( 'options-general.php?page=jcssmm' ) ) );
		exit;
	}

	/**
	 * Handle purge this page.
	 *
	 * @return void
	 */
	public function handle_purge_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'jacker-css-merges-manager' ) );
		}
		check_admin_referer( 'jcssmm_purge_page' );

		$path = isset( $_GET['path'] ) ? rawurldecode( wp_unslash( $_GET['path'] ) ) : '';
		$path = sanitize_text_field( $path );

		if ( '' !== $path ) {
			self::purge_page( $path );
		}

		$return = isset( $_GET['return'] ) ? rawurldecode( wp_unslash( $_GET['return'] ) ) : '';
		$return = esc_url_raw( $return );
		if ( '' === $return ) {
			$return = add_query_arg( 'jcssmm_notice', 'page_purged', admin_url( 'options-general.php?page=jcssmm' ) );
		}
		wp_safe_redirect( $return );
		exit;
	}

	/**
	 * Handle purge all.
	 *
	 * @return void
	 */
	public function handle_purge_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'jacker-css-merges-manager' ) );
		}
		check_admin_referer( 'jcssmm_purge_all' );
		self::purge_all_cache();

		$return = isset( $_GET['return'] ) ? rawurldecode( wp_unslash( $_GET['return'] ) ) : '';
		$return = esc_url_raw( $return );
		if ( '' === $return ) {
			$return = add_query_arg( 'jcssmm_notice', 'cleared', admin_url( 'options-general.php?page=jcssmm' ) );
		}
		wp_safe_redirect( $return );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Cache helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Cache directory.
	 *
	 * @return string
	 */
	public static function cache_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . JCSSMM_CACHE_DIR;
	}

	/**
	 * Cache URL.
	 *
	 * @return string
	 */
	public static function cache_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . JCSSMM_CACHE_DIR;
	}

	/**
	 * Ensure cache directory exists.
	 *
	 * @return bool
	 */
	public static function ensure_cache_dir() {
		$dir = self::cache_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return is_dir( $dir ) && is_writable( $dir );
	}

	/**
	 * List cache files.
	 *
	 * @return array
	 */
	public static function list_cache_files() {
		$dir = self::cache_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( trailingslashit( $dir ) . '*.css' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Get page map.
	 *
	 * @return array
	 */
	public static function get_page_map() {
		$map = get_option( JCSSMM_MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Save page map entry.
	 *
	 * @param string $path Page path.
	 * @param string $file Merged file name.
	 * @return void
	 */
	public static function set_page_map_entry( $path, $file ) {
		$map          = self::get_page_map();
		$map[ $path ] = array(
			'file' => $file,
			'time' => time(),
		);
		update_option( JCSSMM_MAP_OPTION, $map, false );
	}

	/**
	 * Remove a single page's cache.
	 *
	 * @param string $path Page path.
	 * @return void
	 */
	public static function purge_page( $path ) {
		$map = self::get_page_map();
		if ( isset( $map[ $path ] ) ) {
			$file = trailingslashit( self::cache_dir() ) . $map[ $path ]['file'];
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			unset( $map[ $path ] );
			update_option( JCSSMM_MAP_OPTION, $map, false );
		}
	}

	/**
	 * Purge all merged files and reset the page map.
	 *
	 * @return void
	 */
	public static function purge_all_cache() {
		$files = self::list_cache_files();
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		update_option( JCSSMM_MAP_OPTION, array(), false );
	}

	/**
	 * Format bytes.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return round( $bytes / 1048576, 2 ) . ' MB';
	}

	/**
	 * Invalidate cache on content change.
	 *
	 * @return void
	 */
	public function invalidate_cache() {
		self::purge_all_cache();
	}

	/* ---------------------------------------------------------------------
	 * OneWebP integration
	 * ------------------------------------------------------------------ */

	/**
	 * Get the OneWebP plugin version if it is active.
	 *
	 * Returns an empty string when OneWebP is not installed or not active.
	 *
	 * @return string
	 */
	private function get_onewebp_version() {
		if ( ! defined( 'ONEWEBP_VERSION' ) ) {
			return '';
		}
		return (string) ONEWEBP_VERSION;
	}

	/**
	 * Check whether OneWebP is active and background rewriting is enabled.
	 *
	 * @return bool
	 */
	private function is_onewebp_active() {
		if ( '' === $this->get_onewebp_version() ) {
			return false;
		}

		/**
		 * Filter whether JCSSMM should rewrite background URLs to WebP.
		 *
		 * @param bool $enabled Default true when OneWebP is active.
		 */
		return (bool) apply_filters( 'jcssmm_rewrite_bg_to_webp', true );
	}

	/**
	 * Rewrite background image URLs inside the CSS to their WebP versions
	 * when a matching .jo.webp file exists on disk.
	 *
	 * Only URLs that:
	 * - are not data URIs
	 * - are not already .jo.webp
	 * - point inside the uploads directory
	 * - end with .jpg / .jpeg / .png / .gif
	 * - have a corresponding .jo.webp file
	 * are rewritten. Everything else is left untouched.
	 *
	 * @param string $css CSS content.
	 * @return string Modified CSS content.
	 */
	private function rewrite_background_urls_to_webp( $css ) {
		if ( strpos( $css, 'url(' ) === false ) {
			return $css;
		}

		$upload   = wp_upload_dir();
		$base_url = $upload['baseurl'];
		$base_dir = $upload['basedir'];

		return preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			function ( $m ) use ( $base_url, $base_dir ) {
				$quote = $m[1];
				$url   = $m[2];

				// Skip data URIs.
				if ( 0 === strpos( $url, 'data:' ) ) {
					return $m[0];
				}

				// Skip already rewritten URLs.
				if ( false !== strpos( $url, '.jo.webp' ) ) {
					return $m[0];
				}

				// Only process URLs inside the uploads directory.
				if ( 0 !== strpos( $url, $base_url ) ) {
					return $m[0];
				}

				// Only process image extensions.
				if ( ! preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $url ) ) {
					return $m[0];
				}

				$url_path  = preg_replace( '/\?.*$/', '', $url );
				$webp_url  = $url_path . '.jo.webp';
				$webp_path = str_replace( $base_url, $base_dir, $webp_url );

				if ( ! file_exists( $webp_path ) ) {
					return $m[0];
				}

				return 'url(' . $quote . $webp_url . $quote . ')';
			},
			$css
		);
	}

	/**
	 * Return a fingerprint that changes whenever a .jo.webp file is added
	 * or removed under the uploads directory.
	 *
	 * The fingerprint is the newest modification time of any .jo.webp file
	 * combined with the total count. It is cheap to compute and used only
	 * when generating a new merged file.
	 *
	 * @return string
	 */
	private function get_webp_fingerprint() {
		$upload   = wp_upload_dir();
		$base_dir = $upload['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return '0';
		}

		$latest_mtime = 0;
		$count        = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$name = $file->getFilename();
				if ( '.jo.webp' !== substr( $name, -8 ) ) {
					continue;
				}
				$mtime = $file->getMTime();
				if ( $mtime > $latest_mtime ) {
					$latest_mtime = $mtime;
				}
				$count++;
			}
		} catch ( Exception $e ) {
			return '0';
		}

		return $latest_mtime . ':' . $count;
	}

	/* ---------------------------------------------------------------------
	 * Frontend
	 * ------------------------------------------------------------------ */

	/**
	 * Start output buffering.
	 *
	 * @return void
	 */
	public function maybe_start_buffer() {
		if ( empty( $this->settings['enable_merge'] ) ) {
			return;
		}
		if ( is_admin() || is_feed() || is_customize_preview() ) {
			return;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return;
		}
		if ( function_exists( 'is_404' ) && is_404() ) {
			return;
		}
		if ( $this->is_excluded() ) {
			return;
		}
		if ( ! function_exists( 'wp_styles' ) ) {
			return;
		}

		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * Process final HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$merged_url = $this->get_merged_url();
		if ( '' === $merged_url ) {
			return $html;
		}

		$result = $this->rewrite_css_links( $html, $merged_url );

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Build or reuse the merged CSS file for the current request.
	 *
	 * @return string
	 */
	private function get_merged_url() {
		if ( ! self::ensure_cache_dir() ) {
			return '';
		}

		$wp_styles = wp_styles();
		if ( ! $wp_styles || empty( $wp_styles->queue ) || ! is_array( $wp_styles->queue ) ) {
			return '';
		}

		$sources = array();
		foreach ( $wp_styles->queue as $handle ) {
			$reg = isset( $wp_styles->registered[ $handle ] ) ? $wp_styles->registered[ $handle ] : null;
			if ( ! $reg || empty( $reg->src ) ) {
				continue;
			}
			if ( $this->is_hard_ignored( $handle, $reg->src ) ) {
				continue;
			}
			$url = $this->normalize_url( $reg->src );
			if ( '' === $url ) {
				continue;
			}
			$path = $this->url_to_path( $url );
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}
			$sources[] = array(
				'url'  => $url,
				'path' => $path,
			);
		}

		if ( count( $sources ) < 2 ) {
			return '';
		}

		$hash_data = '';
		foreach ( $sources as $s ) {
			$hash_data .= $s['path'] . '|' . filemtime( $s['path'] ) . "\n";
		}

		// Include the OneWebP version and .jo.webp fingerprint so the
		// merged file is regenerated when OneWebP is updated or when new
		// WebP files are created.
		$onewebp_version = $this->get_onewebp_version();
		if ( '' !== $onewebp_version && $this->is_onewebp_active() ) {
			$hash_data .= '|onewebp:' . $onewebp_version;
			$hash_data .= '|webp:' . $this->get_webp_fingerprint();
		}

		$hash = substr( md5( $hash_data . JCSSMM_VERSION ), 0, 12 );
		$name = 'merged-' . $hash . '.css';
		$file = trailingslashit( self::cache_dir() ) . $name;

		if ( ! file_exists( $file ) ) {
			$css             = '';
			$rewrite_enabled = $this->is_onewebp_active();

			foreach ( $sources as $s ) {
				$content = file_get_contents( $s['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $content || '' === $content ) {
					continue;
				}
				$content = $this->absolutify_urls( $content, dirname( $s['url'] ) );

				// Rewrite background images only when OneWebP is active.
				if ( $rewrite_enabled ) {
					$content = $this->rewrite_background_urls_to_webp( $content );
				}

				$css .= "\n/* " . basename( $s['path'] ) . " */\n" . $content;
			}

			if ( '' === $css ) {
				return '';
			}
			$written = file_put_contents( $file, $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $written ) {
				return '';
			}
		}

		self::set_page_map_entry( $this->current_page_path(), $name );

		return trailingslashit( self::cache_url() ) . $name;
	}

	/**
	 * Replace local CSS links with the merged one.
	 *
	 * @param string $html       HTML.
	 * @param string $merged_url Merged file URL.
	 * @return string
	 */
	private function rewrite_css_links( $html, $merged_url ) {
		$found = false;

		$result = preg_replace_callback(
			'#<link\s+[^>]*rel=[\'"]stylesheet[\'"][^>]*>#i',
			function ( $m ) use ( $merged_url, &$found ) {
				$tag  = $m[0];
				$href = '';
				if ( preg_match( '/href=[\'"]([^\'"]+)[\'"]/i', $tag, $hm ) ) {
					$href = $hm[1];
				}
				if ( '' === $href ) {
					return '';
				}

				$home     = home_url();
				$is_local = ( 0 === strpos( $href, $home ) ) || ( 0 === strpos( $href, '/' ) );
				if ( ! $is_local ) {
					return $tag;
				}
				if ( $this->is_hard_ignored( '', $href ) ) {
					return $tag;
				}

				if ( ! $found ) {
					$found = true;
					$new   = preg_replace( '/href=[\'"][^\'"]+[\'"]/i', 'href="' . esc_url( $merged_url ) . '"', $tag );
					return $new;
				}

				return '';
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Normalize a CSS URL to absolute.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( preg_match( '#^https?://#', $url ) ) {
			return $url;
		}
		$wp_styles = wp_styles();
		if ( $wp_styles && ! empty( $wp_styles->base_url ) ) {
			return $wp_styles->base_url . ltrim( $url, '/' );
		}
		return home_url( '/' . ltrim( $url, '/' ) );
	}

	/**
	 * Convert a local URL to a filesystem path.
	 *
	 * @param string $url URL.
	 * @return string|false
	 */
	private function url_to_path( $url ) {
		$home = home_url();
		if ( 0 !== strpos( $url, $home ) ) {
			return false;
		}
		$rel = substr( $url, strlen( $home ) );
		$rel = strtok( $rel, '?' );
		return rtrim( ABSPATH, '/' ) . '/' . ltrim( $rel, '/' );
	}

	/**
	 * Rewrite relative url() to absolute.
	 *
	 * @param string $css      CSS content.
	 * @param string $base_dir Base directory URL.
	 * @return string
	 */
	private function absolutify_urls( $css, $base_dir ) {
		$base_dir = rtrim( $base_dir, '/' );
		return preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			function ( $m ) use ( $base_dir ) {
				$path = trim( $m[2] );
				if ( '' === $path || preg_match( '#^(https?:|data:|//|/)#i', $path ) ) {
					return $m[0];
				}
				$abs = $base_dir . '/' . $path;
				while ( false !== strpos( $abs, '/../' ) ) {
					$abs = preg_replace( '#/[^/]+/\.\./#', '/', $abs, 1 );
				}
				$abs = str_replace( '/./', '/', $abs );
				return 'url("' . $abs . '")';
			},
			$css
		);
	}
}

JCSSMM::instance();