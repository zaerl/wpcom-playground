<?php
/**
 * Jetpack connection manager test stub.
 *
 * @package wpcom-playground
 */

namespace Automattic\Jetpack\Connection;

if ( ! class_exists( Manager::class, false ) ) {
	/**
	 * Minimal stand-in for the Jetpack connection manager used by import tests.
	 */
	class Manager {
		/**
		 * Constructor.
		 *
		 * @param string $plugin_slug Plugin slug.
		 */
		public function __construct( string $plugin_slug = '' ) {}

		/**
		 * Return no connection owner, matching an unconnected test site.
		 *
		 * @return false
		 */
		public function get_connection_owner_id() {
			return false;
		}
	}
}
