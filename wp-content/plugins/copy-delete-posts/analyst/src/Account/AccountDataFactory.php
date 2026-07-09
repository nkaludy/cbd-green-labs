<?php

namespace Account;


use Analyst\Contracts\StorageContract;
use Analyst\Core\AbstractFactory;

/**
 * Class AccountDataFactory
 *
 * Holds information about this
 * wordpress project plugins accounts
 *
 */
class AccountDataFactory extends AbstractFactory
{
	private static $instance;

	CONST STORAGE_KEY = 'analyst_accounts_data';

	/**
	 * @var AccountData[]
	 */
	protected $accounts = [];

	/**
	 * @var StorageContract
	 */
	private static $primaryStorage;

	/**
	 * @var StorageContract
	 */
	private static $secondaryStorage;

	/**
	 * Set the primary and secondary storage backends.
	 *
	 * @param StorageContract $primary
	 * @param StorageContract $secondary
	 */
	public static function setStorageBackends(StorageContract $primary, StorageContract $secondary)
	{
		static::$primaryStorage = $primary;
		static::$secondaryStorage = $secondary;
	}

	/**
	 * Read factory from storage or make fresh instance.
	 * Tries primary storage first, then falls back to secondary.
	 *
	 * @return static
	 */
	public static function instance()
	{
		if (!static::$instance) {
			static::$instance = static::loadFromStorage(static::$primaryStorage);

			if (!static::$instance || empty(static::$instance->accounts)) {
				$fallback = static::loadFromStorage(static::$secondaryStorage);

				if ($fallback && !empty($fallback->accounts)) {
					static::$instance = $fallback;
				}
			}

			if (!static::$instance) {
				static::$instance = new self();
			}
		}

		return static::$instance;
	}

	/**
	 * Attempt to load the factory from a storage backend.
	 *
	 * @param StorageContract|null $storage
	 * @return static|null
	 */
	private static function loadFromStorage($storage)
	{
		if (!$storage) {
			return null;
		}

		$raw = $storage->get(self::STORAGE_KEY);

		if ($raw instanceof self) {
			return $raw;
		}

		if (is_string($raw) && !empty($raw)) {
			return static::unserialize($raw);
		}

		return null;
	}

	/**
	 * Persist current state to both storage backends.
	 */
	public function sync()
	{
		$serialized = serialize($this);

		if (static::$primaryStorage) {
			static::$primaryStorage->put(self::STORAGE_KEY, $serialized);
		}

		if (static::$secondaryStorage) {
			static::$secondaryStorage->put(self::STORAGE_KEY, $serialized);
		}
	}

	/**
	 * Sync the singleton instance to storage.
	 */
	public static function syncData()
	{
		static::instance()->sync();
	}

	/**
	 * Find plugin account data or create fresh one
	 *
	 * @param Account $account
	 * @return AccountData|null
	 */
	public function resolvePluginAccountData(Account $account)
	{
		$accountData = $this->findAccountDataById($account->getId());

		if (!$accountData) {
			$accountData = new AccountData();

			// Set proper default values
			$accountData->setPath($account->getPath());
			$accountData->setId($account->getId());
			$accountData->setSecret($account->getClientSecret());

			array_push($this->accounts, $accountData);
		}

		return $accountData;
	}

	/**
	 * Should return account data by base path
	 *
	 * @param $basePath
	 * @return AccountData
	 */
	public function getAccountDataByBasePath($basePath)
	{
		foreach ($this->accounts as $iterable) {
			$iterableBasePath = plugin_basename($iterable->getPath());

			if ($iterableBasePath === $basePath) {
				return $iterable;
			}
		}

		return null;
	}

	/**
	 * Return account by id
	 *
	 * @param $id
	 * @return AccountData|null
	 */
	private function findAccountDataById($id)
	{
		foreach ($this->accounts as &$iterable) {
			if ($iterable->getId() === $id) {
				return $iterable;
			}
		}

		return null;
	}
}
