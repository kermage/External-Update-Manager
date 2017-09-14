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
		$this->get_details( $fullpath );
		$this->transient = 'external_updater_' . $this->type . '_' . $this->slug;

		add_filter( 'site_transient_update_' . $this->type . 's', array( $this, 'inject_updater' ) );
	}

	private function get_details( $path ) {
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

	public function inject_updater( $transient ) {
		$status = $this->get_data();

		if ( version_compare( $this->get_current_version(), $status->new_version, '<' ) ) {
			$transient->response[$this->key] = $this->format_data( $status );
		}

		return $transient;
	}

	private function get_data() {
		if ( $this->update_status ) {
			return $this->update_status;
		}

		$status = get_site_transient( $this->transient );

		if ( ! is_object( $status ) ) {
			$status = $this->api_call();
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

	private function api_call() {
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

	private function format_data( $unformatted ) {
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

}
endif;
