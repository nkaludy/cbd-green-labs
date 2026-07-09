<?php


namespace Analyst;

if ( ! defined( 'ABSPATH' ) ) exit;

use Account\Account;
use Account\AccountDataFactory;
use Analyst\Cache\DatabaseCache;
use Analyst\Contracts\AnalystContract;
use Analyst\Contracts\RequestorContract;
use Analyst\Storage\DatabaseStorage;
use Analyst\Storage\FileStorage;

class Analyst implements AnalystContract
{
	/**
	 * All plugin's accounts
	 *
	 * @var array
	 */
	protected $accounts = array();

	/**
	 * @var Mutator
	 */
	protected $mutator;

	/**
	 * @var AccountDataFactory
	 */
	protected $accountDataFactory;

	/**
	 * Base url to api
	 *
	 * @var string
	 */
	protected $apiBase = 'https://feedback.sellcodes.com/api/v1';

	/**
	 * @var Collector
	 */
	protected $collector;

	/**
	 * Singleton instance
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Get instance of analyst
	 *
	 * @return Analyst
	 * @throws \Exception
	 */
	public static function getInstance()
	{
		if (!static::$instance) {
			static::$instance = new Analyst();
		}

		return static::$instance;
	}

	protected function __construct()
	{
		$dbStorage = new DatabaseStorage();
		$fileStorage = new FileStorage();

		DatabaseCache::setStorageBackends($dbStorage, $fileStorage);
		AccountDataFactory::setStorageBackends($dbStorage, $fileStorage);

		$this->mutator = new Mutator();

		$this->accountDataFactory = AccountDataFactory::instance();

		$this->mutator->initialize();

		$this->collector = new Collector($this);

		$this->initialize();
	}

	/**
	 * Initialize rest of application
	 */
	public function initialize()
	{
		add_action('wp_loaded', function () {
			$this->collector->loadCurrentUser();
		});
	}

	/**
	 * Register new account
	 *
	 * @param Account $account
	 * @return Analyst
	 * @throws \Exception
	 */
	public function registerAccount($account)
	{
		// Stop propagation when account is already registered
		if ($this->isAccountRegistered($account)) {
			return $this;
		}

		// Resolve account data from factory
		$accountData = $this->accountDataFactory->resolvePluginAccountData($account);

		$account->setData($accountData);

		$account->setRequestor(
			$this->resolveRequestorForAccount($account)
		);

		$account->setCollector($this->collector);

		$account->registerHooks();

		$this->accounts[$account->getId()] = $account;

		return $this;
	}

	/**
	 * Must return version of analyst
	 *
	 * @return string
	 */
	public static function version()
	{
	    $version = require __DIR__ . '/../version.php';

		return $version['sdk'];
	}

	/**
	 * Is this account registered
	 *
	 * @param Account $account
	 * @return bool
	 */
	protected function isAccountRegistered($account)
	{
		return isset($this->accounts[$account->getId()]);
	}

	/**
	 * Resolves requestor for account
	 *
	 * @param Account $account
	 * @return RequestorContract
	 * @throws \Exception
	 */
	protected function resolveRequestorForAccount(Account $account)
	{
		$requestor = new ApiRequestor($account->getId(), $account->getClientSecret(), $this->apiBase);

		// Set SDK version
		$requestor->setDefaultHeader(
			'x-analyst-client-user-agent',
			sprintf('Analyst/%s', $this->version())
		);

		return $requestor;
	}

	/**
	 * @return string
	 */
	public function getApiBase()
	{
		return $this->apiBase;
	}
}
