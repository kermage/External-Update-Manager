<?php

/**
 * @package External-Updater
 * @since 0.1.0
 */

class External_Updater {

	public $fullpath;
	public $type;
	public $slug;
	public $transient;

	public function __construct( $fullpath ) {
		$this->fullpath = $fullpath;
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
		} else {
			$this->type = 'plugin';
		}

	}

	public function inject_updater( $transient ) {
		$status = get_site_transient( $this->transient );

		if ( ! is_object( $status ) ) {
			$status = $this->check_for_update();
			set_site_transient( $this->transient, $status, HOUR_IN_SECONDS );
		}

		return $transient;
	}

	public function check_for_update() {
		$status = new StdClass;
		$status->last_checked = time();
		$status->update = null;
		$status->current_version = $this->get_current_version();

		return $status;
	}

	public function get_current_version() {
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

		return $version;
		}
	}

}
