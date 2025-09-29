<?php
/**
 * Nfinite Site Audit — Site Info Page
 * Location: includes/site-info.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'nfinite_site_info_register_menu' ) ) {

	/**
	 * Register submenu once, after your top-level menu is in place.
	 * Keeps parent slug as-is (nfinite-audit) and pushes this item to the bottom.
	 */
	add_action( 'admin_menu', 'nfinite_site_info_register_menu', 20 );

	/**
	 * Handle Refresh via nonce; clears the transient and redirects.
	 */
	add_action( 'admin_init', 'nfinite_site_info_handle_refresh' );

	function nfinite_site_info_register_menu() {
		if ( is_network_admin() ) {
			return; // Hide from Network Admin per acceptance criteria
		}

		$parent_slug = 'nfinite-audit'; // must match your add_menu_page() parent slug
		$page_title  = __( 'Site Info', 'nfinite' );
		$menu_title  = __( 'Site Info', 'nfinite' );
		$capability  = 'manage_options';
		$menu_slug   = 'nfinite-site-info';
		$callback    = 'nfinite_site_info_render_page';
		$position    = 99; // push to bottom of this submenu group

		add_submenu_page(
			$parent_slug,
			$page_title,
			$menu_title,
			$capability,
			$menu_slug,
			$callback,
			$position
		);
	}

	/**
	 * Build & cache the site info payload.
	 * Cached for ~10 minutes via transient. Always escape on output.
	 */
	function nfinite_site_info_get_data( $force = false ) {
		global $wpdb;

		$key = 'nfinite_site_info_cache';

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		// Detect key PHP extensions
		$extensions  = array( 'curl', 'mbstring', 'gd', 'imagick', 'intl', 'openssl', 'zip', 'dom', 'simplexml', 'xml', 'mysqli', 'pdo_mysql' );
		$ext_status  = array();
		foreach ( $extensions as $ext ) {
			$ext_status[ $ext ] = extension_loaded( $ext ) ? 'yes' : 'no';
		}

		// OPcache
		$opcache_enabled_fn = function_exists( 'opcache_get_status' );
		$opcache_info       = $opcache_enabled_fn ? opcache_get_status( false ) : false;
		$opcache_enabled    = ( $opcache_info && ! empty( $opcache_info['opcache_enabled'] ) ) ? 'yes' : ( $opcache_enabled_fn ? 'unknown' : 'no' );

		// WP Theme
		$theme         = wp_get_theme();
		$theme_name    = $theme ? $theme->get( 'Name' ) : '';
		$theme_version = $theme ? $theme->get( 'Version' ) : '';

		// Active plugins (site level)
		$active_plugins    = (array) get_option( 'active_plugins', array() );
		$plugins_readable  = array();
		if ( ! empty( $active_plugins ) ) {
			$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
			foreach ( $active_plugins as $file ) {
				if ( isset( $all_plugins[ $file ] ) ) {
					$plugins_readable[] = $all_plugins[ $file ]['Name'] . ' ' . $all_plugins[ $file ]['Version'];
				} else {
					$plugins_readable[] = $file;
				}
			}
		}

		// Web server
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';

		// REST API availability (light check)
		$rest_check = 'unknown';
		$rest_url   = rest_url();
		$response   = wp_remote_get( $rest_url, array( 'timeout' => 3 ) );
		if ( ! is_wp_error( $response ) ) {
			$code       = (int) wp_remote_retrieve_response_code( $response );
			$rest_check = ( $code >= 200 && $code < 500 ) ? 'reachable' : 'unreachable';
		} else {
			$rest_check = 'unreachable';
		}

		// Cron status
		$cron_status = 'enabled';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$cron_status = 'disabled (DISABLE_WP_CRON true)';
		}

		$data = array(
			'generated_at' => current_time( 'mysql' ),
			'php'          => array(
				'version'            => PHP_VERSION,
				'memory_limit'       => ini_get( 'memory_limit' ),
				'max_execution_time' => ini_get( 'max_execution_time' ),
				'opcache'            => $opcache_enabled,
				'extensions'         => $ext_status,
			),
			'wordpress'    => array(
				'version'   => get_bloginfo( 'version' ),
				'multisite' => is_multisite() ? 'yes' : 'no',
				'locale'    => get_locale(),
				'rest_api'  => $rest_check,
				'cron'      => $cron_status,
			),
			'theme'        => array(
				'active'     => trim( $theme_name . ' ' . $theme_version ),
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
			),
			'plugins'      => array(
				'active'       => $plugins_readable, // name + version
				'total_active' => count( $plugins_readable ),
			),
			'server'       => array(
				'software'   => $server,
				'db_version' => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '',
			),
			'urls'         => array(
				'site_url' => site_url(),
				'home_url' => home_url(),
				'rest_url' => $rest_url,
			),
		);

		// Cache for 10 minutes
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Handle Refresh: clear transient & soft-redirect to clean URL.
	 */
	function nfinite_site_info_handle_refresh() {
		if ( empty( $_GET['nfinite_site_info_refresh'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'nfinite_site_info_refresh' );
		delete_transient( 'nfinite_site_info_cache' );

		$redirect = remove_query_arg( array( 'nfinite_site_info_refresh', '_wpnonce' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the Site Info page.
	 */
	function nfinite_site_info_render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$data        = nfinite_site_info_get_data();
		$json_flags  = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
		$json_str    = wp_json_encode( $data, $json_flags );
		$refresh_url = wp_nonce_url(
			add_query_arg( 'nfinite_site_info_refresh', '1' ),
			'nfinite_site_info_refresh'
		);
		?>
		<div class="wrap nfinite-site-info">
			<h1><?php echo esc_html__( 'Site Info', 'nfinite' ); ?></h1>

			<p class="description">
				<?php echo esc_html__( 'Snapshot of your environment to speed up diagnostics. Cached for ~10 minutes.', 'nfinite' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( $refresh_url ); ?>" class="button">
					<?php echo esc_html__( 'Refresh', 'nfinite' ); ?>
				</a>
				<button id="nfinite-copy-btn" class="button button-primary">
					<?php echo esc_html__( 'Copy to clipboard', 'nfinite' ); ?>
				</button>
			</p>

			<h2 class="screen-reader-text"><?php echo esc_html__( 'Environment Details', 'nfinite' ); ?></h2>

			<table class="widefat striped" role="table" aria-describedby="nfinite-site-info-help">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Generated', 'nfinite' ); ?></th>
						<td><?php echo esc_html( $data['generated_at'] ); ?></td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'PHP', 'nfinite' ); ?></th>
						<td>
							<?php
							printf(
								'%s: %s | %s: %s | %s: %s | OPcache: %s',
								esc_html__( 'Version', 'nfinite' ),
								esc_html( $data['php']['version'] ),
								esc_html__( 'memory_limit', 'nfinite' ),
								esc_html( $data['php']['memory_limit'] ),
								esc_html__( 'max_execution_time', 'nfinite' ),
								esc_html( $data['php']['max_execution_time'] ),
								esc_html( $data['php']['opcache'] )
							);
							?>
							<br />
							<strong><?php echo esc_html__( 'Extensions', 'nfinite' ); ?>:</strong>
							<?php
							$ext_out = array();
							foreach ( $data['php']['extensions'] as $ext => $ok ) {
								$ext_out[] = esc_html( $ext ) . ': ' . esc_html( $ok );
							}
							echo esc_html( implode( ', ', $ext_out ) );
							?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'WordPress', 'nfinite' ); ?></th>
						<td>
							<?php
							printf(
								'%s: %s | Multisite: %s | %s: %s | REST: %s | Cron: %s',
								esc_html__( 'Version', 'nfinite' ),
								esc_html( $data['wordpress']['version'] ),
								esc_html( $data['wordpress']['multisite'] ),
								esc_html__( 'Locale', 'nfinite' ),
								esc_html( $data['wordpress']['locale'] ),
								esc_html( $data['wordpress']['rest_api'] ),
								esc_html( $data['wordpress']['cron'] )
							);
							?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Active Theme', 'nfinite' ); ?></th>
						<td>
							<?php
							echo esc_html( $data['theme']['active'] );
							printf(
								' — %s: %s, %s: %s',
								esc_html__( 'Stylesheet', 'nfinite' ),
								esc_html( $data['theme']['stylesheet'] ),
								esc_html__( 'Template', 'nfinite' ),
								esc_html( $data['theme']['template'] )
							);
							?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Active Plugins', 'nfinite' ); ?></th>
						<td>
							<?php
							if ( ! empty( $data['plugins']['active'] ) ) {
								echo '<ul style="margin:0;">';
								foreach ( $data['plugins']['active'] as $p ) {
									echo '<li>' . esc_html( $p ) . '</li>';
								}
								echo '</ul>';
							} else {
								echo esc_html__( 'None', 'nfinite' );
							}
							?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Server / Database', 'nfinite' ); ?></th>
						<td>
							<?php
							printf(
								'%s: %s | %s: %s',
								esc_html__( 'Server', 'nfinite' ),
								esc_html( $data['server']['software'] ),
								esc_html__( 'DB Version', 'nfinite' ),
								esc_html( $data['server']['db_version'] )
							);
							?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'URLs', 'nfinite' ); ?></th>
						<td>
							<?php
							printf(
								'%s: %s<br>%s: %s<br>%s: %s',
								esc_html__( 'Site URL', 'nfinite' ),
								esc_url( $data['urls']['site_url'] ),
								esc_html__( 'Home URL', 'nfinite' ),
								esc_url( $data['urls']['home_url'] ),
								esc_html__( 'REST URL', 'nfinite' ),
								esc_url( $data['urls']['rest_url'] )
							);
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<p id="nfinite-site-info-help" class="description" style="margin-top:10px;">
				<?php echo esc_html__( 'Use “Copy to clipboard” to share full details with support.', 'nfinite' ); ?>
			</p>

			<textarea id="nfinite-site-info-json" readonly rows="18" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $json_str ); ?></textarea>
		</div>

		<script>
			(function(){
				const btn  = document.getElementById('nfinite-copy-btn');
				const area = document.getElementById('nfinite-site-info-json');
				if (!btn || !area) return;

				btn.addEventListener('click', async function(){
					try {
						area.select();
						area.setSelectionRange(0, area.value.length);
						await navigator.clipboard.writeText(area.value);
						btn.innerText = '<?php echo esc_js( __( 'Copied!', 'nfinite' ) ); ?>';
						setTimeout(() => { btn.innerText = '<?php echo esc_js( __( 'Copy to clipboard', 'nfinite' ) ); ?>'; }, 1500);
					} catch(e) {
						area.select();
						document.execCommand('copy');
						btn.innerText = '<?php echo esc_js( __( 'Copied!', 'nfinite' ) ); ?>';
						setTimeout(() => { btn.innerText = '<?php echo esc_js( __( 'Copy to clipboard', 'nfinite' ) ); ?>'; }, 1500);
					}
				});
			})();
		</script>

		<style>
			.nfinite-site-info .widefat th { width: 220px; }
			.nfinite-site-info textarea { margin-top: 10px; }
		</style>
		<?php
	}
}
