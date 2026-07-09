<?php

namespace Analyst\Storage;

use Analyst\Contracts\StorageContract;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class FileStorage
 *
 * Persists key-value data as individual dotname files inside wp-content.
 * Each key maps to a file named ".analyst_{key}" in WP_CONTENT_DIR.
 * Values are serialized and base64-encoded to safely store arbitrary data.
 */
class FileStorage implements StorageContract
{
	/**
	 * Base directory for storage files.
	 *
	 * @var string
	 */
	protected $directory;

	public function __construct()
	{
		$this->directory = rtrim(WP_CONTENT_DIR, '/\\');
	}

	/**
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$filePath = $this->resolveFilePath($key);

		if (!file_exists($filePath) || !is_readable($filePath)) {
			return $default;
		}

		$encoded = @file_get_contents($filePath);

		if ($encoded === false || $encoded === '') {
			return $default;
		}

		$raw = base64_decode($encoded, true);

		if ($raw === false) {
			return $default;
		}

		return @unserialize($raw);
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * @return bool
	 */
	public function put($key, $value)
	{
		$filePath = $this->resolveFilePath($key);

		$encoded = base64_encode(serialize($value));

		return @file_put_contents($filePath, $encoded, LOCK_EX) !== false;
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function delete($key)
	{
		$filePath = $this->resolveFilePath($key);

		if (file_exists($filePath)) {
			return @unlink($filePath);
		}

		return true;
	}

	/**
	 * Build the absolute file path for a given key.
	 *
	 * @param string $key
	 * @return string
	 */
	private function resolveFilePath($key)
	{
		$safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);

		return $this->directory . '/.analyst_' . $safeKey;
	}
}
