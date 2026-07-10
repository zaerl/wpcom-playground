<?php
/**
 * Jetpack Host stub.
 *
 * @package wpcomsh
 */

namespace Automattic\Jetpack\Status;

/**
 * Stub for the Jetpack Host class.
 */
class Host {
	/**
	 * Whether the site is hosted on WordPress.com Atomic.
	 *
	 * @var bool
	 */
	public static $is_woa_site = false;

	/**
	 * Check whether the site is hosted on WordPress.com Atomic.
	 *
	 * @return bool Whether the site is hosted on WordPress.com Atomic.
	 */
	public function is_woa_site(): bool {
		return self::$is_woa_site;
	}
}
