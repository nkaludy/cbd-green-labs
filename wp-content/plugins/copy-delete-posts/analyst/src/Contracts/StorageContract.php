<?php

namespace Analyst\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Interface StorageContract
 *
 * Defines a simple key-value persistence layer.
 * Implementations may use the database, filesystem, or any other backend.
 */
interface StorageContract
{
	/**
	 * Retrieve a value by key.
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get($key, $default = null);

	/**
	 * Store a value under the given key.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return bool
	 */
	public function put($key, $value);

	/**
	 * Remove a value by key.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function delete($key);
}
