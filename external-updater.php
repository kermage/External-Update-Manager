<?php

/**
 * @package External-Updater
 * @since 0.1.0
 */

class External_Updater {

	public $transient;

	public function __construct( $type, $slug ) {
		$this->transient = 'external_updater_' . $type . '_' . $slug;

		add_filter( 'site_transient_update_' . $type . 's', array( $this, 'inject_updater' ) );
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
