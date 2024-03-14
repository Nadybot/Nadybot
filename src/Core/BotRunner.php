<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\async;
use function Amp\ByteStream\{getStderr};
use function Amp\File\{createDefaultDriver, filesystem};
use function Safe\{fwrite, getopt, ini_set, json_encode, parse_url, putenv, sapi_windows_set_ctrl_handler};

use Amp\ByteStream\BufferedReader;
use Amp\File\Driver\{BlockingFilesystemDriver, EioFilesystemDriver, ParallelFilesystemDriver};
use Amp\File\{FilesystemDriver};
use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Process\Process;
use ErrorException;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Modules\SETUP\Setup;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionObject;
use Revolt\EventLoop;
use Safe\Exceptions\InfoException;
use Throwable;

class BotRunner {
	/** Nadybot's current version */
	public const VERSION = "7.0.0.alpha";

	/**
	 * The parsed command line arguments
	 *
	 * @var array<string,mixed>
	 */
	public static array $arguments = [];

	public ClassLoader $classLoader;

	private static ?string $latestTag = null;

	private static ?string $calculatedVersion = null;

	private LoggerInterface $logger;

	/**
	 * The command line arguments
	 *
	 * @var string[]
	 */
	private array $argv = [];

	private ?BotConfig $configFile;

	private static Filesystem $fs;

	/**
	 * Create a new instance
	 *
	 * @param string[] $argv
	 */
	public function __construct(array $argv) {
		$this->argv = $argv;
		self::$fs = new Filesystem(filesystem());
	}

	/**
	 * Return the (cached) version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function getVersion(bool $withBranch=true): string {
		if (!isset(self::$calculatedVersion)) {
			self::$calculatedVersion = self::calculateVersion();
		}
		if (!$withBranch) {
			return Safe::pregReplace("/@.+/", "", self::$calculatedVersion);
		}
		return self::$calculatedVersion;
	}

	/** Get the base directory of the bot */
	public static function getBasedir(): string {
		return self::$fs->realPath(dirname(__DIR__, 2));
	}

	/**
	 * Calculate the version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function calculateVersion(): string {
		$baseDir = self::getBasedir();
		if (!self::$fs->exists("{$baseDir}/.git")) {
			return self::VERSION;
		}
		set_error_handler(function (int $num, string $str, string $file, int $line): void {
			throw new ErrorException($str, 0, $num, $file, $line);
		});
		try {
			$refs = explode(": ", trim(self::$fs->read("{$baseDir}/.git/HEAD")), 2);
			if (count($refs) !== 2) {
				throw new Exception("Unknown Git format detected");
			}
			$parts = explode("/", $refs[1], 3);
			if (count($parts) !== 3) {
				throw new Exception("Unknown Git format detected");
			}
			$branch = $parts[2];
			$latestTag = self::getLatestTag();
			if (!isset($latestTag)) {
				return $branch;
			}

			if ($latestTag === '') {
				$latestTag = self::VERSION;
			} elseif (strncmp(self::VERSION, $latestTag, min(strlen(self::VERSION), strlen($latestTag))) > 0) {
				$latestTag = self::VERSION;
			}
			if ($branch !== 'stable') {
				// return "{$latestTag}@{$branch}";
			}
			$gitDescribe = self::getGitDescribe();
			if ($gitDescribe === null || $gitDescribe === $latestTag) {
				return "{$latestTag}";
			}
			return "{$gitDescribe}@{$branch}";
		} catch (\Throwable) {
			return self::VERSION;
		} finally {
			restore_error_handler();
		}
	}

	public static function getGitDescribe(): ?string {
		$baseDir = self::getBasedir();
		$process = Process::start("git describe --tags", $baseDir);
		$bufReader = new BufferedReader($process->getStdout());
		$reader = async($bufReader->buffer(...));
		$exitCode = $process->join();
		$stdout = $reader->await();
		if ($exitCode !== 0 || $stdout === "") {
			return null;
		}
		return trim($stdout);
	}

	/**
	 * Calculate the latest tag that the checkout was tagged with
	 * and return how many commits were done since then
	 * Like [number of commits, tag]
	 */
	public static function getLatestTag(): ?string {
		if (isset(self::$latestTag)) {
			return self::$latestTag;
		}
		$baseDir = self::getBasedir();
		$process = Process::start("git tag -l", $baseDir);
		$bufReader = new BufferedReader($process->getStdout());
		$reader = async($bufReader->buffer(...));
		$exitCode = $process->join();
		$stdout = $reader->await();
		if ($exitCode !== 0 || $stdout === "") {
			return null;
		}
		$tags = explode("\n", trim($stdout));
		$tags = array_diff($tags, ["nightly"]);

		$tags = array_map(
			function (string $tag): SemanticVersion {
				return new SemanticVersion($tag);
			},
			$tags
		);

		/** @var SemanticVersion[] $tags */
		usort(
			$tags,
			function (SemanticVersion $v1, SemanticVersion $v2): int {
				return $v1->cmp($v2);
			}
		);
		$tagString = array_pop($tags)->getOrigVersion();
		return self::$latestTag = $tagString;
	}

	/** Run the bot in an endless loop */
	public function run(): void {
		if (!self::isLinux()) {
			putenv('AMP_FS_DRIVER=' . BlockingFilesystemDriver::class);
		}
		$fsDriverClass = getenv('AMP_FS_DRIVER');
		if ($fsDriverClass !== false && class_exists($fsDriverClass) && is_subclass_of($fsDriverClass, FilesystemDriver::class)) {
			$fsDriver = new $fsDriverClass();
		} else {
			$fsDriver = createDefaultDriver();
		}
		if ($fsDriver instanceof EioFilesystemDriver) {
			$fsDriver = new ParallelFilesystemDriver();
		}
		if ($fsDriver instanceof ParallelFilesystemDriver) {
			$fsDriver = new BlockingFilesystemDriver();
		}

		self::$fs = new Filesystem(filesystem($fsDriver));
		LegacyLogger::$fs = self::$fs;
		LoggerWrapper::$fs = self::$fs;
		$this->parseOptions();
		// set default timezone
		date_default_timezone_set("UTC");

		$config = $this->getConfigFile();
		Registry::setInstance(Registry::formatName(BotConfig::class), $config);
		$retryHandler = new HttpRetry(8);
		Registry::injectDependencies($retryHandler);
		$rateLimitRetryHandler = new HttpRetryRateLimits();
		Registry::injectDependencies($rateLimitRetryHandler);
		$httpClientBuilder = (new HttpClientBuilder())
			->retry(0)
			->intercept(new SetRequestHeaderIfUnset("User-Agent", "Nadybot ".self::getVersion()))
			->intercept($retryHandler)
			->intercept($rateLimitRetryHandler);
		$httpProxy = getenv('http_proxy');
		if ($httpProxy !== false) {
			$proxyHost = parse_url($httpProxy, PHP_URL_HOST);
			$proxyScheme = parse_url($httpProxy, PHP_URL_SCHEME);
			$proxyPort = parse_url($httpProxy, PHP_URL_PORT) ?? ($proxyScheme === 'https' ? 443 : 80);
			if (is_string($proxyScheme) && is_string($proxyHost) && is_int($proxyPort)) {
				$connector = new Http1TunnelConnector("{$proxyHost}:{$proxyPort}");
				$httpClientBuilder = $httpClientBuilder->usingPool(
					new UnlimitedConnectionPool(
						new DefaultConnectionFactory($connector)
					)
				);
			}
		}
		Registry::setInstance("HttpClientBuilder", $httpClientBuilder);
		$this->checkRequiredModules();
		$this->checkRequiredPackages();
		$this->createMissingDirs();

		// these must happen first since the classes that are loaded may be used by processes below
		$timezone = $config->general->timezone;
		if (isset($timezone) && strlen($timezone) > 1) {
			/** @psalm-suppress ArgumentTypeCoercion */
			if (@date_default_timezone_set($timezone) === false) {
				die("Invalid timezone: \"{$timezone}\"\n");
			}
		}
		$logFolderName = "{$config->paths->logs}/{$config->main->character}.{$config->main->dimension}";

		$this->setErrorHandling($logFolderName);
		$this->logger = new LoggerWrapper("Core/BotRunner");
		// self::$fs->setLogger(new LoggerWrapper("Core/Filesystem"));
		Registry::injectDependencies($this->logger);

		$this->sendBotBanner();

		if ($this->showSetupDialog($config)) {
			$config = $this->getConfigFile();
		}
		$this->setWindowTitle($config);

		$version = self::getVersion();
		Registry::setInstance(Registry::formatName(Filesystem::class), self::$fs);

		$this->logger->notice(
			"Starting {name} {version} on RK{dimension} using ".
			"PHP {phpVersion}, {loopType} event loop, ".
			"{fsType} filesystem, and {dbType}...",
			[
				"name" => $config->main->character,
				"version" => $version,
				"dimension" => $config->main->dimension,
				"phpVersion" => phpversion(),
				"loopType" => class_basename(EventLoop::getDriver()),
				"fsType" => class_basename($fsDriver),
				"dbType" => $config->database->type->name,
			]
		);

		$this->classLoader = new ClassLoader($config->paths->modules);
		Registry::injectDependencies($this->classLoader);
		$this->classLoader->loadInstances();
		$msgHub = Registry::getInstance(MessageHub::class);
		if (isset($msgHub) && $msgHub instanceof MessageHub) {
			LegacyLogger::registerMessageEmitters($msgHub);
		}

		$signalHandler = function (): void {
			$this->logger->notice('Shutdown requested.');
			exit;
		};
		$handlers = [];
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, true);
		} else {
			$handlers []= EventLoop::onSignal(SIGINT, $signalHandler);
			$handlers []= EventLoop::onSignal(SIGTERM, $signalHandler);
		}
		$this->connectToDatabase();
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, false);
		}
		foreach ($handlers as $handler) {
			EventLoop::cancel($handler);
		}
		$this->prefillSettingProperties();

		$this->runUpgradeScripts();
		EventLoop::run();
		if ((self::$arguments["migrate-only"]??true) === false) {
			exit(0);
		}

		/** @var Nadybot */
		$chatBot = Registry::getInstance(Nadybot::class);

		// startup core systems, load modules and call setup methods
		/** @var DB */
		$db = Registry::getInstance(DB::class);
		if ($db->table(CommandManager::DB_TABLE)->exists()) {
			$this->logger->notice("Initializing modules...");
		} else {
			$this->logger->notice("Initializing modules and db tables...");
		}
		$chatBot->init($this);

		if ((self::$arguments["setup-only"]??true) === false) {
			exit(0);
		}

		// connect to ao chat server
		$chatBot->connectAO();

		// pass control to Nadybot class
		$chatBot->run();
	}

	/** Utility function to check whether the bot is running Windows */
	public static function isWindows(): bool {
		return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	}

	/** Utility function to check whether the bot is running Linux */
	public static function isLinux(): bool {
		return PHP_OS_FAMILY === 'Linux';
	}

	private function getConfigFile(): BotConfig {
		if (isset($this->configFile)) {
			return $this->configFile;
		}
		$configFilePath = self::$arguments["c"] ?? "conf/config.php";
		return $this->configFile = BotConfig::loadFromFile($configFilePath, self::$fs);
	}

	private function createMissingDirs(): void {
		$path = $this->getConfigFile()->paths;
		foreach (get_object_vars($path) as $name => $dir) {
			if (is_string($dir) && !self::$fs->exists($dir)) {
				self::$fs->createDirectory($dir, 0700);
			}
		}
		foreach ($path->modules as $dir) {
			if (is_string($dir) && !self::$fs->exists($dir)) {
				self::$fs->createDirectory($dir, 0700);
			}
		}
	}

	private function checkRequiredPackages(): void {
		if (!class_exists("Revolt\\EventLoop")) {
			// @phpstan-ignore-next-line
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

	private function checkRequiredModules(): void {
		if (version_compare(PHP_VERSION, "8.1.17", "<")) {
			// @phpstan-ignore-next-line
			fwrite(STDERR, "Nadybot 7 needs at least PHP version 8 to run, you have " . PHP_VERSION . "\n");
			sleep(5);
			exit(1);
		}
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
			"fileinfo",
			"tokenizer",
		];
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
			// @phpstan-ignore-next-line
		fwrite(STDERR, "Nadybot needs the following missing PHP-extensions: " . join(", ", $missing) . ".\n");
		sleep(5);
		exit(1);
	}

	private function parseOptions(): void {
		try {
			/** @var array<string,mixed> $options */
			$options = getopt(
				"c:v",
				[
					"help",
					"migrate-only",
					"setup-only",
					"strict",
					"log-config:",
					"migration-errors-fatal",
				],
				$restPos
			);

			/** @var int $restPos */
		} catch (InfoException $e) {
			getStderr()->write(
				"Unable to parse arguments passed to the bot: " . $e->getMessage()
			);
			sleep(5);
			exit(1);
		}
		$argv = array_slice($this->argv, $restPos);
		self::$arguments = $options;
		if (count($argv) > 0) {
			self::$arguments["c"] = array_shift($argv);
		}
		if (isset(self::$arguments["help"])) {
			$this->showSyntaxHelp();
			exit(0);
		}
	}

	private function showSyntaxHelp(): void {
		echo(
			"Usage: " . PHP_BINARY . " " . ($_SERVER["argv"][0] ?? "main.php").
			" [options] [-c] <config file>\n\n".
			"positional arguments:\n".
			"  <config file>         A Nadybot configuration file, usually conf/config.php\n".
			"\n".
			"options:\n".
			"  --help                Show this help message and exit\n".
			"  --migrate-only        Only run the database migration and then exit\n".
			"  --setup-only          Stop the bot after the setup handlers have been called\n".
			"  --log-config=<file>   Use an alternative config file for the logger. The default\n".
			"                        configuration is in conf/logging.json\n".
			"  --migration-errors-fatal\n".
			"                        Stop the bot startup if any of the database migrations fails\n".
			"  -v                    Enable logging INFO. Use -v -v to also log DEBUG\n"
		);
	}

	/**
	 * Make sure that the values of properties that are linked to settings
	 * is filled with the last known values from the database
	 */
	private function prefillSettingProperties(): void {
		/** @var SettingManager */
		$settingManager = Registry::getInstance(SettingManager::class);
		foreach (Registry::getAllInstances() as $name => $instance) {
			$refObj = new ReflectionObject($instance);
			foreach ($refObj->getProperties() as $refProp) {
				foreach ($refProp->getAttributes(NCA\DefineSetting::class, ReflectionAttribute::IS_INSTANCEOF) as $refAttr) {
					try {
						/** @var NCA\DefineSetting */
						$attr = $refAttr->newInstance();
					} catch (\Throwable $e) {
						$this->logger->error('Incompatible attribute #[{attrName}] in {loc}: {error}', [
							"attrName" => str_replace('Nadybot\Core\Attributes', 'NCA', $refAttr->getName()),
							"error" => $e->getMessage(),
							"exception" => $e,
							"loc" => $refProp->getDeclaringClass()->getName() . '::$' . $refProp->getName(),
						]);
						exit;
					}
					$attr->name ??= Nadybot::toSnakeCase($refProp->getName());
					try {
						$value = $settingManager->getTyped($attr->name);
					} catch (Throwable) {
						// If the database is initialized for the first time
						return;
					}
					if ($value !== null) {
						$this->logger->info('Setting {class}::${property} to {value}', [
							"class" => class_basename($instance),
							"property" => $refProp->getName(),
							"value" => json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
						]);
						try {
							$refProp->setValue($instance, $value);
						} catch (Throwable) {
						}
					}
				}
			}
		}
	}

	/** Get a message describing the bot's codebase */
	private function sendBotBanner(): void {
		$this->logger->notice(
			PHP_EOL.
			" _   _  __     ".PHP_EOL.
			"| \ | |/ /_    Nadybot version: {version}".PHP_EOL.
			"|  \| | '_ \   Project Site:    {project_url}".PHP_EOL.
			"| |\  | (_) |  In-Game Contact: {in_game_contact}".PHP_EOL.
			"|_| \_|\___/   Discord:         {discord_link}".PHP_EOL.
			PHP_EOL,
			[
				"version" => self::getVersion(),
				"project_url" => "https://github.com/Nadybot/Nadybot",
				"in_game_contact" => "Nady",
				"discord_link" => "https://discord.gg/aDR9UBxRfg",
			]
		);
	}

	/** Setup proper error-reporting, -handling and -logging */
	private function setErrorHandling(string $logFolderName): void {
		error_reporting(E_ALL & ~E_STRICT & ~E_WARNING & ~E_NOTICE);
		ini_set("log_errors", "1");
		ini_set('display_errors', "1");
		ini_set("error_log", "{$logFolderName}/php_errors.log");
	}

	/** Guide customer through setup if needed */
	private function showSetupDialog(BotConfig $config): bool {
		if (!$this->shouldShowSetup($config)) {
			return false;
		}
		$setup = new Setup($this->getConfigFile(), self::$fs);
		$setup->showIntro();
		$this->logger->notice("Reloading configuration and testing your settings.");
		return true;
	}

	/** Is information missing to run the bot? */
	private function shouldShowSetup(BotConfig $config): bool {
		return !strlen($config->main->login)
			|| !strlen($config->main->password)
			|| !strlen($config->main->character);
	}

	/** Set the title of the command prompt window in Windows */
	private function setWindowTitle(BotConfig $config): void {
		if ($this->isWindows() === false) {
			return;
		}
		async(Process::start(...), "title {$config->main->character} - Nadybot")->ignore();
	}

	/** Connect to the database */
	private function connectToDatabase(): void {
		/** @var ?DB */
		$db = Registry::getInstance(DB::class);
		if (!isset($db)) {
			throw new Exception("Cannot find DB instance.");
		}
		$config = $this->getConfigFile();
		$db->connect($config->database);
	}

	/** Run migration scripts to keep the SQL schema up-to-date */
	private function runUpgradeScripts(): void {
		/** @var DB */
		$db = Registry::getInstance(DB::class);
		$db->createDatabaseSchema();
	}
}
