<?php

/**
 * @package External-Updater
 * @since 0.1.0
 */

if ( ! class_exists( 'External_Updater' ) ) :
class External_Updater {

	public $fullpath;
	public $metadata;
	public $type;
	public $slug;
	public $key;
	public $transient;
	public $current_version = '';

	public function __construct( $fullpath, $metadata ) {
		$this->fullpath = $fullpath;
		$this->metadata = $metadata;
		$this->get_details( $fullpath );
		$this->transient = 'external_updater_' . $this->type . '_' . $this->slug;

		add_filter( 'site_transient_update_' . $this->type . 's', array( $this, 'inject_updater' ) );
	}

	public function get_details( $path ) {
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

		if ( ! empty( $status->update ) ) {
			$transient->response[$this->key] = $status->update;
		} else {
			if ( isset ( $transient->response[$this->key] ) ) {
				unset( $transient->response[$this->key] );
			}
		}

		return $transient;
	}

	public function get_data() {
		$status = get_site_transient( $this->transient );

		if ( ! is_object( $status ) ) {
			$status = $this->check_for_update();
			set_site_transient( $this->transient, $status, HOUR_IN_SECONDS );
		}

		return $status;
	}

	public function check_for_update() {
		$status = new StdClass;
		$status->last_checked = time();
		$status->update = null;
		$status->current_version = $this->get_current_version();
		$response = $this->api_call();

		if ( version_compare( $status->current_version, $response->new_version, '<' ) ) {
			$status->update = $this->format_data( $response );
		}

		return $status;
	}

	public function get_current_version() {
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

	public function api_call() {
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

	public function format_data( $unformatted ) {
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
