<?php declare(strict_types=1);

namespace Nadybot\Core;

use LoggerConfiguratorDefault;
use Logger;
use ErrorException;
use Nadybot\Core\ConfigFile;
use Nadybot\Core\Modules\SETUP\Setup;

class BotRunner {

	/**
	 * Nadybot's current version
	 */
	public const VERSION = "5.0";

	/**
	 * The command line arguments
	 *
	 * @var string[] $argv
	 */
	private array $argv = [];

	private ?ConfigFile $configFile;

	protected static $latestTag = null;

	/**
	 * Create a new instance
	 */
	public function __construct(array $argv) {
		$this->argv = $argv;

		global $version;
		$version = self::getVersion();
	}

	/**
	 * Return the version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function getVersion(): string {
		if (!@file_exists(dirname(dirname(__DIR__)) . '/.git')) {
			return static::VERSION;
		}
		set_error_handler(function($num, $str, $file, $line) {
			throw new ErrorException($str, 0, $num, $file, $line);
		});
		try {
			$ref = explode(": ", trim(@file_get_contents(dirname(dirname(__DIR__)) . '/.git/HEAD')), 2)[1];
			$branch = explode("/", $ref, 3)[2];
			$latestTag = static::getLatestTag();
			if (!isset($latestTag)) {
				return $branch;
			}
			if ($latestTag[0]) {
				return "{$latestTag[1]}@{$branch}";
			}
			return "{$latestTag[1]}";
		} catch (\Throwable $e) {
			return static::VERSION;
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Read all currently defined tags and their hashes
	 * and return them in [hash => tag]
	 *
	 * @return array<string,string>
	 */
	public static function getTagsForHashes(): array {
		$result = [];
		$files = glob(dirname(dirname(__DIR__)) . '/.git/refs/tags/*');
		foreach (array_reverse($files) as $file) {
			$result[trim(file_get_contents($file))] = basename($file);
		}
		return $result;
	}


	/**
	 * Calculate the latest tag that the checkout was tagged with
	 * and return how many commits were done since then
	 * Like [number of commits, tag]
	 */
	public static function getLatestTag(): ?array {
		if (isset(static::$latestTag)) {
			return static::$latestTag;
		}
		$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];

		$pid = proc_open("git describe --tags --abbrev=1", $descriptors, $pipes);
		if ($pid === false) {
			return static::$latestTag = null;
		}
		fclose($pipes[0]);
		$tagString = trim(stream_get_contents($pipes[1]));
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($pid);
		if (isset($tagString) && preg_match("/^(.+?)-(\d+)-([a-z0-9]+)$/", $tagString, $matches)) {
			return static::$latestTag = [(int)$matches[2], $matches[1]];
		}
		if (isset($tagString) && preg_match("/^(\d+\.\d+(?:-[^\d].+))$/", $tagString, $matches)) {
			return static::$latestTag = [0, $matches[1]];
		}
		return static::$latestTag = null;
	}

	/**
	 * Run the bot in an endless loop
	 */
	public function run(): void {
		// set default timezone
		date_default_timezone_set("UTC");

		echo $this->getInitialInfoMessage();

		// these must happen first since the classes that are loaded may be used by processes below
		$this->loadPhpLibraries();

		// load $vars
		global $vars;
		$vars = $this->getConfigVars();
		if (isset($vars['timezone']) && @date_default_timezone_set($vars['timezone']) === false) {
			die("Invalid timezone: \"{$vars['timezone']}\"\n");
		}
		$logFolderName = $vars['name'] . '.' . $vars['dimension'];

		$this->setErrorHandling($logFolderName);

		$this->showSetupDialog();
		$this->canonicalizeBotCharacterName();

		$this->configureLogger($logFolderName);

		$this->setWindowTitle();

		$version = self::getVersion();
		LegacyLogger::log('INFO', 'StartUp', "Starting {$vars['name']} {$version} on RK{$vars['dimension']} using PHP ".phpversion()."...");

		$classLoader = new ClassLoader($vars['module_load_paths']);
		Registry::injectDependencies($classLoader);
		$classLoader->loadInstances();

		$this->connectToDatabase();
		$this->clearDatabaseInformation();

		$this->runUpgradeScripts();

		[$server, $port] = $this->getServerAndPort($vars);

		/** @var Nadybot */
		$chatBot = Registry::getInstance('chatBot');

		// startup core systems and load modules
		$chatBot->init($this, $vars);

		// connect to ao chat server
		$chatBot->connectAO($vars['login'], $vars['password'], $server, $port);

		// clear login credentials
		unset($vars['login']);
		unset($vars['password']);

		// pass control to Nadybot class
		$chatBot->run();
	}

	/**
	 * Utility function to check whether the bot is running Windows
	 */
	public static function isWindows(): bool {
		return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	}

	/**
	 * Get a message describing the bot's codebase
	 */
	private function getInitialInfoMessage(): string {
		$version = substr(sprintf("%-23s", self::getVersion()), 0, 23);
		return
			"+------------------------------------------------------------------+".PHP_EOL.
			'|                                                                  |'.PHP_EOL.
			'| 888b    888               888          888               888     |'.PHP_EOL.
			'| 8888b   888               888          888               888     |'.PHP_EOL.
			'| 88888b  888               888          888               888     |'.PHP_EOL.
			'| 888Y88b 888  8888b.   .d88888 888  888 88888b.   .d88b.  888888  |'.PHP_EOL.
			'| 888 Y88b888     "88b d88" 888 888  888 888 "88b d88""88b 888     |'.PHP_EOL.
			'| 888  Y88888 .d888888 888  888 888  888 888  888 888  888 888     |'.PHP_EOL.
			'| 888   Y8888 888  888 Y88b 888 Y88b 888 888 d88P Y88..88P Y88b.   |'.PHP_EOL.
			'| 888    Y888 "Y888888  "Y88888  "Y88888 88888P"   "Y88P"   "Y888  |'.PHP_EOL.
			'|                                    888                           |'.PHP_EOL.
			'|                               Y8b d88P                           |'.PHP_EOL.
			'| Nadybot ' . $version .        ' Y88P"                            |'.PHP_EOL.
			'|                                                                  |'.PHP_EOL.
			'| Project Site:     https://github.com/Nadybot/Nadybot             |'.PHP_EOL.
			'| In-Game Contact:  Nadyita                                        |'.PHP_EOL.
			'+------------------------------------------------------------------+'.PHP_EOL.
			PHP_EOL;
	}

	public function getConfigFile(): ConfigFile {
		if (isset($this->configFile)) {
			return $this->configFile;
		}
		$configFilePath = $this->argv[1] ?? "conf/config.php";
		global $configFile;
		$this->configFile = new ConfigFile($configFilePath);
		$this->configFile->load();
		return $configFile = $this->configFile;
	}

	/**
	 * Parse and load our configuration and return it
	 *
	 * @return array<string,mixed>
	 */
	protected function getConfigVars(): array {
		return $this->getConfigFile()->getVars();
	}

	/**
	 * Setup proper error-reporting, -handling and -logging
	 */
	private function setErrorHandling(string $logFolderName): void {
		error_reporting(E_ALL & ~E_STRICT & ~E_WARNING & ~E_NOTICE);
		ini_set("log_errors", "1");
		ini_set('display_errors', "1");
		ini_set("error_log", "./logs/${logFolderName}/php_errors.log");
	}

	/**
	 * Load external classes that we need
	 */
	private function loadPhpLibraries(): void {
		foreach (glob(__DIR__ . "/Annotations/*.php") as $file) {
			require_once $file;
		}
	}

	/**
	 * Guide customer through setup if needed
	 */
	private function showSetupDialog(): void {
		if (!$this->shouldShowSetup()) {
			return;
		}
		$setup = new Setup($this->getConfigFile());
		$setup->showIntro();
		LegacyLogger::log('INFO', 'StartUp', "Reloading configuration and testing your settings.");
		unset($this->configFile);
		global $vars;
		$vars = $this->getConfigVars();
	}

	/**
	 * Is information missing to run the bot?
	 */
	private function shouldShowSetup(): bool {
		global $vars;
		return $vars['login'] == "" || $vars['password'] == "" || $vars['name'] == "";
	}

	/**
	 * Canonicaize the botname: starts with capital letter, rest lowercase
	 */
	private function canonicalizeBotCharacterName(): void {
		global $vars;
		$vars["name"] = ucfirst(strtolower($vars["name"]));
	}

	/**
	 * Configure log files to be separate for each bot
	 */
	private function configureLogger(string $logFolderName): void {
		$configurator = new LoggerConfiguratorDefault();
		$config = $configurator->parse('conf/log4php.xml');
		$file = $config['appenders']['defaultFileAppender']['params']['file'];
		$file = str_replace("./logs/", "./logs/" . $logFolderName . "/", $file);
		$config['appenders']['defaultFileAppender']['params']['file'] = $file;
		Logger::configure($config);
	}

	/**
	 * Set the title of the command prompt window in Windows
	 */
	private function setWindowTitle(): void {
		if ($this->isWindows() === false) {
			return;
		}
		global $vars;
		system("title {$vars['name']} - Nadybot");
	}

	/**
	 * Connect to the database
	 */
	private function connectToDatabase(): void {
		global $vars;
		$db = Registry::getInstance('db');
		$db->connect($vars["DB Type"], $vars["DB Name"], $vars["DB Host"], $vars["DB username"], $vars["DB password"]);
	}

	/**
	 * Delete all database-related information from memory
	 */
	private function clearDatabaseInformation(): void {
		global $vars;
		unset($vars["DB Type"]);
		unset($vars["DB Name"]);
		unset($vars["DB Host"]);
		unset($vars["DB username"]);
		unset($vars["DB password"]);
	}

	/**
	 * Runs upgrade.php, which is needed if the SQL schema has changed between releases.
	 */
	private function runUpgradeScripts(): void {
		if (file_exists('upgrade.php')) {
			include 'upgrade.php';
		}
	}

	/**
	 * Get AO's chat server hostname and port
	 * @return (string|int)[] [(string)Server, (int)Port]
	 */
	protected function getServerAndPort(array $vars): array {
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
			die();
		}
		return [$server, $port];
	}
}
