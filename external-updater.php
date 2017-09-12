<?php

/**
 * @package External-Updater
 * @since 0.1.0
 */

class External_Updater {

	public $transient;

	public function __construct( $fullpath ) {
		$type = $this->get_type( $fullpath );
		$slug = basename( dirname( $fullpath ) );
		$this->transient = 'external_updater_' . $type . '_' . $slug;

		add_filter( 'site_transient_update_' . $type . 's', array( $this, 'inject_updater' ) );
	}

	public function get_type( $path ) {

		if ( file_exists( dirname( $path ) . '/style.css' ) ) {
			return 'theme';
		} else {
			return 'plugin';
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

		return $status;
	}

}
