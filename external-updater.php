<?php

/**
 * @package External-Updater
 * @since 0.1.0
 */

if ( ! class_exists( 'External_Updater' ) ) :
class External_Updater {

	private $fullpath;
	private $metadata;
	private $type;
	private $slug;
	private $key;
	private $transient;
	private $current_version = '';
	private $update_status = null;

	public function __construct( $fullpath, $metadata ) {
		$this->fullpath = $fullpath;
		$this->metadata = $metadata;
		$this->get_file_details( $fullpath );
		$this->transient = 'external_updater_' . $this->type . '_' . $this->slug;

		add_filter( 'site_transient_update_' . $this->type . 's', array( $this, 'set_available_update' ) );

		$this->maybe_delete_transient();
	}

	private function get_file_details( $path ) {
		$folder = dirname( $path );
		$folder_name = basename( $folder );

		$this->slug = $folder_name;

		if ( file_exists( $folder . '/style.css' ) ) {
			$this->type = 'theme';
			$this->key = $folder_name;
		} else {
			$this->type = 'plugin';
			$this->key = plugin_basename( $path );
		}
	}

	public function set_available_update( $transient ) {
		$status = $this->get_remote_data();

		if ( version_compare( $this->get_current_version(), $status->new_version, '<' ) ) {
			$transient->response[$this->key] = $this->format_response( $status );
		}

		return $transient;
	}

	private function get_remote_data() {
		if ( $this->update_status ) {
			return $this->update_status;
		}

		$status = get_site_transient( $this->transient );

		if ( ! is_object( $status ) ) {
			$status = $this->call_remote_api();
			set_site_transient( $this->transient, $status, HOUR_IN_SECONDS );
		}

		$this->update_status = $status;

		return $status;
	}

	private function get_current_version() {
		if ( $this->current_version ) {
			return $this->current_version;
		}

		if ( $this->type == 'theme' ) {
			$data = wp_get_theme( $this->slug );
			$version = $data->get( 'Version' );
		} else {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
			}

			$data = get_plugin_data( $this->fullpath, false, false );
			$version = $data['Version'];
		}

		$this->current_version = $version;

		return $version;
	}

	private function call_remote_api() {
		$request = array(
			'action' => 'update',
			'type' => $this->type,
			'slug' => $this->slug
		);
		$url = add_query_arg( $request, $this->metadata );
		$options = array( 'timeout' => 10 );
		$response = wp_remote_get( $url, $options );
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code === 200 ) {
			return json_decode( $body );
		}
	}

	private function format_response( $unformatted ) {
		if ( $this->type == 'theme' ) {
			$formatted = (array) $unformatted;
			$formatted['theme'] = $this->slug;
		} else {
			$formatted = (object) $unformatted;
			$formatted->slug = $this->slug;
			$formatted->plugin = $this->key;
		}

		return $formatted;
	}

	private function maybe_delete_transient() {
		if ( $GLOBALS['pagenow'] === 'update-core.php' && isset( $_GET['force-check'] ) ) {
			delete_site_transient( $this->transient );
		}
	}

}
endif;
