<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use ErrorException;
use Exception;
use Nadybot\Core\Modules\SETUP\Setup;
use Nadybot\Modules\PACKAGE_MODULE\SemanticVersion;
use ReflectionClass;
use ReflectionException;

class BotRunner {

	/**
	 * Nadybot's current version
	 */
	public const VERSION = "5.3.2";

	/**
	 * The command line arguments
	 *
	 * @var string[] $argv
	 */
	private array $argv = [];

	private ?ConfigFile $configFile;

	public ClassLoader $classLoader;

	protected static ?string $latestTag = null;

	protected static ?string $calculatedVersion = null;

	protected LoggerWrapper $logger;

	/**
	 * Create a new instance
	 */
	public function __construct(array $argv) {
		$this->argv = $argv;

		global $version;
		$version = self::getVersion();
	}

	/**
	 * Return the (cached) version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function getVersion(bool $withBranch=true): string {
		if (!isset(static::$calculatedVersion)) {
			static::$calculatedVersion = static::calculateVersion();
		}
		if (!$withBranch) {
			return preg_replace("/@.+/", "", static::$calculatedVersion);
		}
		return static::$calculatedVersion;
	}

	/** Get the base directory of the bot */
	public static function getBasedir(): string {
		return realpath(dirname(dirname(__DIR__)));
	}

	/**
	 * Calculate the version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function calculateVersion(): string {
		$baseDir = static::getBasedir();
		if (!@file_exists("{$baseDir}/.git")) {
			return static::VERSION;
		}
		set_error_handler(function(int $num, string $str, string $file, int $line) {
			throw new ErrorException($str, 0, $num, $file, $line);
		});
		try {
			$ref = explode(": ", trim(@file_get_contents("{$baseDir}/.git/HEAD")), 2)[1];
			$branch = explode("/", $ref, 3)[2];
			$latestTag = static::getLatestTag();
			if (!isset($latestTag)) {
				return $branch;
			}
			if ($branch !== 'stable') {
				return "{$latestTag}@{$branch}";
			}
			$gitDescribe = static::getGitDescribe();
			if ($gitDescribe === null || $gitDescribe === $latestTag) {
				return "{$latestTag}";
			}
			return "{$latestTag}@stable";
		} catch (\Throwable $e) {
			return static::VERSION;
		} finally {
			restore_error_handler();
		}
	}

	public static function getGitDescribe(): ?string {
		$baseDir = static::getBasedir();
		$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];

		$pid = proc_open("git describe --tags", $descriptors, $pipes, $baseDir);
		if ($pid === false) {
			return null;
		}
		fclose($pipes[0]);
		$gitDescribe = trim(stream_get_contents($pipes[1]));
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($pid);
		return $gitDescribe;
	}

	/**
	 * Calculate the latest tag that the checkout was tagged with
	 * and return how many commits were done since then
	 * Like [number of commits, tag]
	 */
	public static function getLatestTag(): ?string {
		if (isset(static::$latestTag)) {
			return static::$latestTag;
		}
		$baseDir = static::getBasedir();
		$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];

		$pid = proc_open("git tag -l", $descriptors, $pipes, $baseDir);
		if ($pid === false) {
			return static::$latestTag = null;
		}
		fclose($pipes[0]);
		$tags = explode("\n", trim(stream_get_contents($pipes[1])));
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($pid);

		$tags = array_map(
			function(string $tag): SemanticVersion {
				return new SemanticVersion($tag);
			},
			$tags
		);
		/** @var SemanticVersion[] $tags*/
		usort(
			$tags,
			function(SemanticVersion $v1, SemanticVersion $v2): int {
				return $v1->cmp($v2);
			}
		);
		$tagString = array_pop($tags)->getOrigVersion();
		return static::$latestTag = $tagString;
	}

	public function checkRequiredPackages(): void {
		try {
			new ReflectionClass("Monolog\\Logger");
		} catch (ReflectionException $e) {
			fwrite(
				STDERR,
				"Nadybot cannot find all the required composer modules in 'vendor'.\n".
				"Please run 'composer install' to install all missing modules\n".
				"or download one of the Nadybot bundles and copy the 'vendor'\n".
				"directory from the zip-file into the Nadybot main directory.\n".
				"\n".
				"See https://github.com/Nadybot/Nadybot/wiki/Running#cloning-the-repository\n".
				"for more information.\n"
			);
			sleep(5);
			exit(1);
		}
	}

	public function checkRequiredModules(): void {
		$missing = [];
		$requiredModules = [
			["bcmath", "gmp"],
			"ctype",
			"date",
			"dom",
			"filter",
			"json",
			"pcre",
			"PDO",
			"simplexml",
			["pdo_mysql", "pdo_sqlite"],
			"Reflection",
			"sockets",
		];
		$configFile = $this->getConfigFile();
		/** @psalm-suppress DocblockTypeContradiction */
		if (strlen($configFile->getVar('amqp_server')??"")
			&& strlen($configFile->getVar('amqp_user')??"")
			&& strlen($configFile->getVar('amqp_password')??"")
		) {
			$requiredModules []= "mbstring";
		}
		foreach ($requiredModules as $requiredModule) {
			if (is_string($requiredModule) && !extension_loaded($requiredModule)) {
				$missing []= $requiredModule;
			} elseif (is_array($requiredModule)) {
				if (!count(array_filter($requiredModule, "extension_loaded"))) {
					$missing []= join(" or ", $requiredModule);
				}
			}
		}
		if (!count($missing)) {
			return;
		}
		fwrite(STDERR, "Nadybot needs the following missing PHP-extensions: " . join(", ", $missing) . ".\n");
		sleep(5);
		exit(1);
	}

	/** Install a signal handler that will immediately terminate the bot when ctrl+c is pressed */
	protected function installCtrlCHandler(): Closure {
		$signalHandler = function (int $sigNo): void {
			$this->logger->notice('Shutdown requested.');
			exit;
		};
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, true);
		} elseif (function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, $signalHandler);
			pcntl_signal(SIGTERM, $signalHandler);
			pcntl_async_signals(true);
		} else {
			$this->logger->error('You need to have the pcntl extension on Linux');
			exit(1);
		}
		return $signalHandler;
	}

	/** Uninstall a previously installed signal handler */
	protected function uninstallCtrlCHandler(Closure $signalHandler): void {
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, false);
		} elseif (function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, SIG_DFL);
			pcntl_signal(SIGTERM, SIG_DFL);
		}
	}

	/**
	 * Run the bot in an endless loop
	 */
	public function run(): void {
		// set default timezone
		date_default_timezone_set("UTC");

		// load $vars
		global $vars;
		$vars = $this->getConfigVars();
		$this->checkRequiredModules();
		$this->checkRequiredPackages();
		$this->createMissingDirs();

		echo $this->getInitialInfoMessage();

		// these must happen first since the classes that are loaded may be used by processes below
		$this->loadPhpLibraries();
		if (isset($vars['timezone']) && @date_default_timezone_set($vars['timezone']) === false) {
			die("Invalid timezone: \"{$vars['timezone']}\"\n");
		}
		$logFolderName = rtrim($vars["logsfolder"] ?? "./logs/", "/");
		$logFolderName = "{$logFolderName}/{$vars['name']}.{$vars['dimension']}";

		$this->setErrorHandling($logFolderName);
		$this->logger = new LoggerWrapper("Core/BotRunner");

		$this->showSetupDialog();
		$this->canonicalizeBotCharacterName();

		$this->setWindowTitle();

		$version = self::getVersion();
		$this->logger->notice(
			"Starting {name} {version} on RK{dimension} using PHP {phpVersion} and {dbType}...",
			[
				"name" => $vars['name'],
				"version" => $version,
				"dimension" => $vars['dimension'],
				"phpVersion" => phpversion(),
				"dbType" => $vars['DB Type'],
			]
		);

		$this->classLoader = new ClassLoader($vars['module_load_paths']);
		Registry::injectDependencies($this->classLoader);
		$this->classLoader->loadInstances();

		$signalHandler = $this->installCtrlCHandler();
		$this->connectToDatabase();
		$this->clearDatabaseInformation();
		$this->uninstallCtrlCHandler($signalHandler);

		$this->runUpgradeScripts();

		[$server, $port] = $this->getServerAndPort($vars);

		/** @var Nadybot */
		$chatBot = Registry::getInstance(Nadybot::class);

		// startup core systems and load modules
		$chatBot->init($this, $vars);

		// connect to ao chat server
		$chatBot->connectAO($vars['login'], $vars['password'], (string)$server, (int)$port);

		// clear login credentials
		unset($vars['login']);
		unset($vars['password']);

		// pass control to Nadybot class
		$chatBot->run();
	}

	protected function createMissingDirs(): void {
		$dirVars = ["cachefolder", "htmlfolder", "datafolder"];
		foreach ($dirVars as $var) {
			$dir = $this->getConfigFile()->getVar($var);
			if (is_string($dir) && !@file_exists($dir)) {
				@mkdir($dir, 0700);
			}
		}
		foreach ($this->getConfigFile()->getVar("module_load_paths") as $dir) {
			if (is_string($dir) && !@file_exists($dir)) {
				@mkdir($dir, 0700);
			}
		}
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
		ini_set("error_log", "${logFolderName}/php_errors.log");
	}

	/**
	 * Load external classes that we need
	 */
	private function loadPhpLibraries(): void {
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
		$this->logger->notice("Reloading configuration and testing your settings.");
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
		$db = Registry::getInstance(DB::class);
		if (!isset($db)) {
			throw new Exception("Cannot find DB instance.");
		}
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

	/** Run migration scripts to keep the SQL schema up-to-date */
	private function runUpgradeScripts(): void {
		/** @var DB */
		$db = Registry::getInstance(DB::class);
		$db->loadMigrations("Core", __DIR__ . "/Migrations");
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
			$this->logger->error("No valid server to connect with! Available dimensions are 4, 5, and 6.");
			die();
		}
		return [$server, $port];
	}
}
