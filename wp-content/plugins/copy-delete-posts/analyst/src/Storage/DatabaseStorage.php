<?php

namespace Analyst\Storage;

use Analyst\Contracts\StorageContract;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class DatabaseStorage
 *
 * Persists key-value data using the WordPress wp_options table.
 */
class DatabaseStorage implements StorageContract
{
	/**
	 * @param string $key The wp_options option name.
	 * @param mixed $default
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$value = get_option($key, null);

		return $value !== null ? $value : $default;
	}

	/**
	 * @param string $key The wp_options option name.
	 * @param mixed $value
	 * @return bool
	 */
	public function put($key, $value)
	{
		return update_option($key, $value);
	}

	/**
	 * @param string $key The wp_options option name.
	 * @return bool
	 */
	public function delete($key)
	{
		return delete_option($key);
	}
}
