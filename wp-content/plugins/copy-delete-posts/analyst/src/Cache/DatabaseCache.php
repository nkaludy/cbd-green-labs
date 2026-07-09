<?php

namespace Analyst\Cache;

use Analyst\Contracts\CacheContract;
use Analyst\Contracts\StorageContract;

/**
 * Class DatabaseCache
 *
 * In-memory key-value cache that persists to dual storage backends.
 *
 * @since 1.1.5
 */
class DatabaseCache implements CacheContract
{
	const STORAGE_KEY = 'analyst_cache';

	protected static $instance;

	/**
	 * @var StorageContract
	 */
	private static $primaryStorage;

	/**
	 * @var StorageContract
	 */
	private static $secondaryStorage;

	/**
	 * Key value pairs
	 *
	 * @var array
	 */
	protected $values = [];

	/**
	 * Set the primary and secondary storage backends.
	 *
	 * @param StorageContract $primary
	 * @param StorageContract $secondary
	 */
	public static function setStorageBackends(StorageContract $primary, StorageContract $secondary)
	{
		self::$primaryStorage = $primary;
		self::$secondaryStorage = $secondary;
	}

	/**
	 * Get singleton instance.
	 *
	 * @return DatabaseCache
	 */
	public static function getInstance()
	{
		if (!self::$instance) {
			self::$instance = new DatabaseCache();
		}

		return self::$instance;
	}

	/**
	 * DatabaseCache constructor.
	 */
	public function __construct()
	{
		$this->values = $this->loadValues(self::$primaryStorage);

		if (empty($this->values)) {
			$this->values = $this->loadValues(self::$secondaryStorage);
		}
	}

	/**
	 * Load values from a storage backend.
	 *
	 * @param StorageContract|null $storage
	 * @return array
	 */
	private function loadValues($storage)
	{
		if (!$storage) {
			return [];
		}

		$raw = $storage->get(self::STORAGE_KEY, serialize([]));

		$values = is_array($raw) ? $raw : @unserialize($raw);

		return is_array($values) ? $values : [];
	}

	/**
	 * Save value with given key.
	 *
	 * @param string $key
	 * @param string $value
	 * @return static
	 */
	public function put($key, $value)
	{
		$this->values[$key] = $value;

		$this->sync();

		return $this;
	}

	/**
	 * Get value by given key.
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		return isset($this->values[$key]) ? $this->values[$key] : $default;
	}

	/**
	 * Remove a value by key.
	 *
	 * @param string $key
	 * @return static
	 */
	public function delete($key)
	{
		if (isset($this->values[$key])) {
			unset($this->values[$key]);

			$this->sync();
		}

		return $this;
	}

	/**
	 * Persist current values to both storage backends.
	 */
	protected function sync()
	{
		$serialized = serialize($this->values);

		if (self::$primaryStorage) {
			self::$primaryStorage->put(self::STORAGE_KEY, $serialized);
		}

		if (self::$secondaryStorage) {
			self::$secondaryStorage->put(self::STORAGE_KEY, $serialized);
		}
	}

	/**
	 * Get a value and remove it from cache.
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function pop($key, $default = null)
	{
		$value = $this->get($key, $default);

		$this->delete($key);

		return $value;
	}
}
