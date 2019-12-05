<?php

/**
 * A drop-in library for WordPress themes or plugins to manage updates
 *
 * self-hosted...can't be submitted to official WordPress repository...
 * non-GPL licensed...custom-made...commercial...etc.
 *
 * @package External Update Manager
 * @link    https://github.com/kermage/External-Update-Manager
 * @author  Gene Alyson Fortunado Torcende
 * @version 1.9.2
 * @license GPL-3.0
 */

if ( ! class_exists( 'External_Update_Manager' ) ) {

	/**
	 * @package External Update Manager
	 * @since   0.1.0
	 */
	class External_Update_Manager {

		private $update_url;
		private $update_data;
		private $item_type;
		private $item_slug;
		private $item_key;
		private $item_name;
		private $item_version = '';
		private $transient    = 'eum_';
		private $has_update   = false;

		public function __construct( $full_path, $update_url ) {
			$this->update_url = $update_url;
			$this->get_file_details( $full_path );
			$this->transient .= $this->item_type . '_' . $this->item_slug;

			add_filter( 'site_transient_update_' . $this->item_type . 's', array( $this, 'set_available_update' ) );
			add_filter( 'delete_site_transient_update_' . $this->item_type . 's', array( $this, 'reset_cached_data' ) );

			if ( 'plugin' === $this->item_type ) {
				add_filter( 'plugins_api', array( $this, 'set_plugin_info' ), 10, 3 );
				add_filter( 'plugin_row_meta', array( $this, 'add_view_details' ), 10, 2 );
				add_action( 'in_plugin_update_message-' . $this->item_key, array( $this, 'plugin_update_message' ) );
			}

			add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
			add_action( 'admin_init', array( $this, 'do_notices' ) );

			$this->maybe_delete_transient();
		}

		private function get_file_details( $path ) {
			$folder      = dirname( $path );
			$folder_name = basename( $folder );

			$this->item_slug = $folder_name;

			if ( file_exists( $folder . '/style.css' ) ) {
				$this->item_type = 'theme';
				$this->item_key  = $folder_name;

				$data = wp_get_theme( $folder_name );

				$this->item_name    = $data->get( 'Name' );
				$this->item_version = $data->get( 'Version' );
			} else {
				$this->item_type = 'plugin';
				$this->item_key  = plugin_basename( $path );

				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$data = get_plugin_data( $path, false, false );

				$this->item_name    = $data['Name'];
				$this->item_version = $data['Version'];
			}
		}

		public function set_available_update( $transient ) {
			$remote_data = $this->get_remote_data();

			if ( ! is_object( $remote_data ) ) {
				return $transient;
			}

			if ( isset( $transient->response[ $this->item_key ] ) ) {
				unset( $transient->response[ $this->item_key ] );
			}

			if ( version_compare( $this->item_version, $remote_data->new_version, '<' ) ) {
				$transient->response[ $this->item_key ] = $this->format_response( $remote_data );

				$this->has_update = true;
			}

			return $transient;
		}

		public function reset_cached_data( $transient ) {
			$this->update_data = null;
			delete_site_transient( $this->transient );

			return $transient;
		}

		public function set_plugin_info( $data, $action = '', $args = null ) {
			/** @var stdClass $args */
			if ( 'plugin_information' !== $action || $args->slug !== $this->item_slug ) {
				return $data;
			}

			$remote_data = $this->get_remote_data();

			return $this->format_response( $remote_data );
		}

		public function add_view_details( $meta, $file ) {
			if ( $file !== $this->item_key ) {
				return $meta;
			}

			if ( $this->has_update ) {
				return $meta;
			}

			$url  = 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $this->item_slug ) . '&TB_iframe=true';
			$link = sprintf(
				'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">View details</a>',
				esc_url( network_admin_url( $url ) ),
				/* translators: %s: plugin name */
				esc_attr( sprintf( __( 'More information about %s' ), $this->item_name ) ),
				esc_attr( $this->item_name )
			);

			$meta[] = $link;

			return $meta;
		}

		private function get_remote_data() {
			if ( $this->update_data ) {
				return $this->update_data;
			}

			$data = get_site_transient( $this->transient );

			if ( ! is_object( $data ) ) {
				$args = array(
					'type' => $this->item_type,
					'slug' => $this->item_slug,
				);
				$data = $this->call_remote_api( $args );
				set_site_transient( $this->transient, $data, HOUR_IN_SECONDS );
			}

			$this->update_data = $data;

			return $data;
		}

		private function call_remote_api( $request ) {
			$url      = add_query_arg( $request, $this->update_url );
			$options  = array( 'timeout' => 10 );
			$response = wp_remote_get( $url, $options );

			if ( ! is_array( $response ) || is_wp_error( $response ) ) {
				return false;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {
				return json_decode( $body, false );
			}

			return false;
		}

		private function format_response( $unformatted ) {
			if ( 'theme' === $this->item_type ) {
				$formatted = (array) $unformatted;

				$formatted['theme'] = $this->item_slug;
			} else {
				$formatted = (object) $unformatted;

				$formatted->name          = $this->item_name;
				$formatted->slug          = $this->item_slug;
				$formatted->plugin        = $this->item_key;
				$formatted->version       = $unformatted->new_version;
				$formatted->download_link = $unformatted->package;
				$formatted->homepage      = $unformatted->url;
				$formatted->author        = sprintf( '<a href="%s">%s</a>', $unformatted->author_url, $unformatted->author_name );
				$formatted->sections      = (array) $unformatted->sections;

				if ( ! empty( $unformatted->banners ) ) {
					$formatted->banners = (array) $unformatted->banners;
				}

				if ( ! empty( $unformatted->icons ) ) {
					$formatted->icons = (array) $unformatted->icons;
				}
			}

			return $formatted;
		}

		private function maybe_delete_transient() {
			if ( isset( $_GET['force-check'] ) && 'update-core.php' === $GLOBALS['pagenow'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				delete_site_transient( $this->transient );
			}
		}

		public function fix_directory_name( $source, $remote_source, $upgrader = null, $hook_extra = null ) {
			/** @var WP_Filesystem_Base $wp_filesystem */
			global $wp_filesystem;

			if ( ( isset( $hook_extra['theme'] ) && $hook_extra['theme'] === $this->item_key ) ||
				( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->item_key ) ) {
				$corrected_source = trailingslashit( $remote_source ) . $this->item_slug . '/';

				if ( $source === $corrected_source ) {
					return $source;
				}

				if ( $wp_filesystem->move( $source, $corrected_source ) ) {
					return $corrected_source;
				}

				return new WP_Error(
					'rename-failed',
					'Unable to rename the update to match the existing directory.'
				);
			}

			return $source;
		}

		public function do_notices() {
			$updates = get_site_transient( 'update_' . $this->item_type . 's' );

			if ( isset( $updates->response[ $this->item_key ] ) && current_user_can( 'install_plugins' ) ) {
				add_action( 'admin_notices', array( $this, 'show_update_message' ) );
			}
		}

		public function show_update_message() {
			global $pagenow;

			if ( 'update-core.php' === $pagenow ||
				( 'theme' === $this->item_type && 'themes.php' === $pagenow ) ||
				( 'plugin' === $this->item_type && 'plugins.php' === $pagenow ) ) {
				return;
			}

			$remote_data = $this->get_remote_data();

			if ( ! is_object( $remote_data ) ) {
				return;
			}

			if ( version_compare( $this->item_version, $remote_data->new_version, '>=' ) ) {
				return;
			}

			wp_enqueue_script( 'plugin-install' );

			if ( 'theme' === $this->item_type ) {
				$details_args = array( 'TB_iframe' => 'true' );
				$details_url  = $remote_data->url;
			} else {
				$details_args = array(
					'tab'       => 'plugin-information',
					'plugin'    => $this->item_slug,
					'section'   => 'changelog',
					'TB_iframe' => 'true',
				);
				$details_url  = self_admin_url( 'plugin-install.php' );
			}

			$details_url = add_query_arg( $details_args, $details_url );
			$update_args = array(
				'action'         => 'upgrade-' . $this->item_type,
				$this->item_type => rawurlencode( $this->item_key ),
				'_wpnonce'       => wp_create_nonce( 'upgrade-' . $this->item_type . '_' . $this->item_key ),
			);
			$update_url  = add_query_arg( $update_args, self_admin_url( 'update.php' ) );

			/* phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped */
			echo '<div class="notice notice-info is-dismissible"><p><strong>';
			/* translators: 1: plugin name, 2: details URL, 3: additional link attributes, 4: version number, 5: update URL, 6: additional link attributes */
			printf( __( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a> or <a href="%5$s" %6$s>update now</a>.' ),
				$this->item_name,
				esc_url( $details_url ),
				sprintf( 'class="thickbox open-plugin-details-modal" aria-label="%s"',
					/* translators: 1: plugin name, 2: version number */
					esc_attr( sprintf( __( 'View %1$s version %2$s details' ), $this->item_name, $remote_data->new_version ) )
				),
				$remote_data->new_version,
				esc_url( $update_url ),
				sprintf( 'class="update-link" aria-label="%s"',
					/* translators: %s: plugin name */
					esc_attr( sprintf( __( 'Update %s now' ), $this->item_name ) )
				)
			);
			echo '</strong></p></div>';
			/* phpcs:enable */
		}

		public function plugin_update_message( $plugin_data ) {
			if ( ! empty( $plugin_data['upgrade_notice'] ) ) {
				echo '<br>' . esc_html( $plugin_data['upgrade_notice'] );
			}
		}

	}

}
