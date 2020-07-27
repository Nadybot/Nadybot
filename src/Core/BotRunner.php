<?php

namespace Budabot\Core;

use LoggerConfiguratorDefault;
use Logger;

class BotRunner {

	/**
	 * Budabot's current version
	 *
	 * @var string $version
	 */
	public $version = "4.2_RC3";

	/**
	 * The command line arguments
	 *
	 * @var string[] $argv
	 */
	private $argv = array();

	/**
	 * Create a new instance
	 *
	 * @param string[] $argv
	 * @return self
	 */
	public function __construct($argv) {
		$this->argv = $argv;

		global $version;
		$version = $this->version;
	}

	/**
	 * Run the bot in an endless loop
	 *
	 * @return void
	 */
	public function run() {
		// set default timezone
		date_default_timezone_set("UTC");

		echo $this->getInitialInfoMessage();
		$this->loadPhpExtensions();

		// these must happen first since the classes that are loaded may be used by processes below
		$this->loadPhpLibraries();

		// load $vars
		global $vars;
		$vars = $this->getConfigVars();
		$logFolderName = $vars['name'] . '.' . $vars['dimension'];

		$this->setErrorHandling($logFolderName);

		$this->showSetupDialog();
		$this->canonicalizeBotCharacterName();

		$this->configureLogger($logFolderName);

		$this->setWindowTitle();

		LegacyLogger::log('INFO', 'StartUp', "Starting {$vars['name']} ($this->version) on RK{$vars['dimension']}...");

		$classLoader = new ClassLoader($vars['module_load_paths']);
		Registry::injectDependencies($classLoader);
		$classLoader->loadInstances();

		$this->connectToDatabase();
		$this->clearDatabaseInformation();

		$this->runUpgradeScripts();

		list($server, $port) = $this->getServerAndPort($vars);

		$chatBot = Registry::getInstance('chatBot');

		// startup core systems and load modules
		$chatBot->init($vars);

		// connect to ao chat server
		$chatBot->connectAO($vars['login'], $vars['password'], $server, $port);

		// clear login credentials
		unset($vars['login']);
		unset($vars['password']);

		// pass control to Budabot class
		$chatBot->run();
	}

	/**
	 * Utility function to check whether the bot is running Windows
	 *
	 * @return bool true if running Windows, else false
	 */
	public static function isWindows() {
		return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	}

	/**
	 * Get a message describing the bot's codebase
	 *
	 * @return string
	 */
	private function getInitialInfoMessage() {
		return "**************************************************\n".
			"Budabot {$this->version}\n".
			"\n".
			"Project Site:     https://github.com/Nadyita/Budabot\n".
			"In-Game Contact:  Nadychat\n".
			"**************************************************\n".
			"\n";
	}

	/**
	 * Load all required PHP modules
	 *
	 * @return void
	 */
	private function loadPhpExtensions() {
		if ($this->isWindows()) {
			// Load database and socket extensions
			dl("php_sockets.dll");
			dl("php_pdo_sqlite.dll");
			dl("php_pdo_mysql.dll");
		} else {
			// Load database extensions, if not already loaded
			// These are normally present in a modern Linux system--this is a safeguard
			if (!extension_loaded('pdo_sqlite')) {
				@dl('pdo_sqlite.so');
			}
			if (!extension_loaded('pdo_mysql')) {
				@dl('pdo_mysql.so');
			}
		}
	}

	/**
	 * Parse and load our configuration and returnn it
	 *
	 * @return mixed[]
	 */
	protected function getConfigVars() {
		// require_once 'ConfigFile.class.php';

		// Load the config
		$configFilePath = $this->argv[1];
		global $configFile;
		$configFile = new ConfigFile($configFilePath);
		$configFile->load();
		$vars = $configFile->getVars();
		return $vars;
	}

	/**
	 * Setup proper error-reporting, -handling and -logging
	 *
	 * @param string $logFolderName Subdirectory in the logs-folder where to log to
	 * @return void
	 */
	private function setErrorHandling($logFolderName) {
		error_reporting(E_ALL & ~E_STRICT & ~E_WARNING & ~E_NOTICE);
		ini_set("log_errors", 1);
		ini_set('display_errors', 1);
		ini_set("error_log", "./logs/" . $logFolderName . "/php_errors.log");
	}

	/**
	 * Load external classes that we need
	 *
	 * @return void
	 */
	private function loadPhpLibraries() {
		foreach (glob(__DIR__ . "/Annotations/*.php") as $file) {
			require_once $file;
		}
	}

	/**
	 * Guide customer through setup if needed
	 *
	 * @return void
	 */
	private function showSetupDialog() {
		if ($this->shouldShowSetup()) {
			global $vars;
			include __DIR__ . "/Modules/SETUP/setup.php";
		}
	}

	/**
	 * Is information missing to run the bot?
	 *
	 * @return boolean true if login, password or name are not given, false if everything's good to go
	 */
	private function shouldShowSetup() {
		global $vars;
		return $vars['login'] == "" || $vars['password'] == "" || $vars['name'] == "";
	}

	/**
	 * Canonicaize the botname: starts with capital letter, rest lowercase
	 *
	 * @return void
	 */
	private function canonicalizeBotCharacterName() {
		global $vars;
		$vars["name"] = ucfirst(strtolower($vars["name"]));
	}

	/**
	 * Configure log files to be separate for each bot
	 *
	 * @param string $logFolderName The subfolder where to put the logs into
	 * @return void
	 */
	private function configureLogger($logFolderName) {
		$configurator = new LoggerConfiguratorDefault();
		$config = $configurator->parse('conf/log4php.xml');
		$file = $config['appenders']['defaultFileAppender']['params']['file'];
		$file = str_replace("./logs/", "./logs/" . $logFolderName . "/", $file);
		$config['appenders']['defaultFileAppender']['params']['file'] = $file;
		Logger::configure($config);
	}

	/**
	 * Set the title of the command prompt window in Windows
	 *
	 * @return void
	 */
	private function setWindowTitle() {
		if ($this->isWindows() === false) {
			return;
		}
		global $vars;
		system("title {$vars['name']} - Budabot");
	}

	/**
	 * Connect to the database
	 *
	 * @return void
	 */
	private function connectToDatabase() {
		global $vars;
		$db = Registry::getInstance('db');
		$db->connect($vars["DB Type"], $vars["DB Name"], $vars["DB Host"], $vars["DB username"], $vars["DB password"]);
	}

	/**
	 * Delete all database-related information from memory
	 *
	 * @return void
	 */
	private function clearDatabaseInformation() {
		global $vars;
		unset($vars["DB Type"]);
		unset($vars["DB Name"]);
		unset($vars["DB Host"]);
		unset($vars["DB username"]);
		unset($vars["DB password"]);
	}

	/**
	 * Runs upgrade.php, which is needed if the SQL schema has changed between releases.
	 *
	 * @return void
	 */
	private function runUpgradeScripts() {
		if (file_exists('upgrade.php')) {
			include 'upgrade.php';
		}
	}

	/**
	 * Get AO's chat server hostname and port
	 *
	 * @param mixed[] $vars The configuration variables from our config file
	 * @return (string|int)[] [(string)Server, (int)Port]
	 */
	protected function getServerAndPort($vars) {
		// Choose server
		if ($vars['use_proxy'] == 1) {
			// For use with the AO chat proxy ONLY!
			$server = $vars['proxy_server'];
			$port = $vars['proxy_port'];
		} elseif ($vars["dimension"] == 4) {
			$server = "chat.dt.funcom.com";
			$port = 7109;
		} elseif ($vars["dimension"] == 5) {
			$server = "chat.d1.funcom.com";
			$port = 7105;
		} elseif ($vars["dimension"] == 6) {
			$server = "chat.d1.funcom.com";
			$port = 7106;
		} else {
			LegacyLogger::log('ERROR', 'StartUp', "No valid server to connect with! Available dimensions are 4, 5, and 6.");
			sleep(10);
			die();
		}
		return array($server, $port);
	}
}
