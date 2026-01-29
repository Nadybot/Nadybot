<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\async;
use function Amp\ByteStream\getStderr;
use function Amp\File\{createDefaultDriver, filesystem};
use function Safe\{fwrite, getopt, ini_set, parse_url, putenv, sapi_windows_set_ctrl_handler, sleep};

use Amp\ByteStream\BufferedReader;
use Amp\File\Driver\{BlockingFilesystemDriver, EioFilesystemDriver, ParallelFilesystemDriver};
use Amp\File\FilesystemDriver;
use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Process\Process;
use Amp\Sync\{KeyedMutex, LocalKeyedMutex};
use ErrorException;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\CmdCfg,
	Modules\SETUP\Setup,
};
use Nadylib\IMEX\JSON;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionObject;
use Revolt\EventLoop;
use Safe\Exceptions\InfoException;
use Throwable;

/** This class sets up the bot before passing execution to it */
class BotRunner {
	/** Nadybot's current version */
	public const VERSION = '7.0.0.alpha';
	public const COMMIT = '';

	/** The parsed command line arguments */
	private static Options $arguments;

	private ClassLoader $classLoader;

	private static ?string $latestTag = null;

	private static ?string $calculatedVersion = null;

	private LoggerInterface $logger;

	/**
	 * The command line arguments
	 *
	 * @var list<string>
	 */
	private array $argv = [];

	private ?BotConfig $configFile = null;

	private static Filesystem $fs;

	private static string $fsDriver = 'Unknown';

	/**
	 * Create a new instance
	 *
	 * @param list<string> $argv
	 */
	public function __construct(array $argv) {
		$this->argv = $argv;
		self::$arguments = new Options();
	}

	/** Get the arguments with which the bot was started */
	public static function getArguments(): Options {
		return self::$arguments;
	}

	/** Get the latest Git commit ID (if running via Git) */
	public static function getCommit(): string {
		$baseDir = self::getBasedir();

		// @phpstan-ignore-next-line
		if (self::COMMIT !== '') {
			return self::COMMIT;
		}
		if (!self::getFS()->exists("{$baseDir}/.git")) {
			throw new Exception('Unable to get a commit ID for the code');
		}
		$process = Process::start('git rev-parse HEAD', $baseDir);
		$bufReader = new BufferedReader($process->getStdout());
		$reader = async($bufReader->buffer(...));
		$exitCode = $process->join();
		$stdout = $reader->await();
		if ($exitCode !== 0 || $stdout === '') {
			throw new Exception('Unable to get a commit ID for the code. Make sure git is installed.');
		}
		return trim($stdout);
	}

	/**
	 * Return the (cached) version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function getVersion(bool $withBranch=true): string {
		if (!isset(self::$calculatedVersion)) {
			self::$calculatedVersion = self::calculateVersion();
			$gitver = new SemanticVersion(self::$calculatedVersion);
			$apiver = new SemanticVersion(self::VERSION);
			if ($apiver->cmp($gitver) === 1) {
				self::$calculatedVersion = self::VERSION;
			}
		}
		if (!$withBranch) {
			return Safe::pregReplace('/@.+/', '', self::$calculatedVersion);
		}
		return self::$calculatedVersion;
	}

	/** Get the base directory of the bot */
	public static function getBasedir(): string {
		return self::getFS()->realPath(dirname(__DIR__, 2));
	}

	/**
	 * Calculate the version number of the bot.
	 * Depending on where you got the source from,
	 * it's either the latest tag, the branch or a fixed version
	 */
	public static function calculateVersion(): string {
		$baseDir = self::getBasedir();
		if (!self::getFS()->exists("{$baseDir}/.git")) {
			return self::VERSION;
		}
		set_error_handler(static function (int $num, string $str, string $file, int $line): void {
			throw new ErrorException($str, 0, $num, $file, $line);
		});
		try {
			$refs = explode(': ', trim(self::getFS()->read("{$baseDir}/.git/HEAD")), 2);
			if (count($refs) !== 2) {
				throw new Exception('Unknown Git format detected');
			}
			$parts = explode('/', $refs[1], 3);
			if (count($parts) !== 3) {
				throw new Exception('Unknown Git format detected');
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

	/** Get a git tag plus commit, plus hash all in one string */
	public static function getGitDescribe(): ?string {
		$baseDir = self::getBasedir();
		$process = Process::start('git describe --tags', $baseDir);
		$bufReader = new BufferedReader($process->getStdout());
		$reader = async($bufReader->buffer(...));
		$exitCode = $process->join();
		$stdout = $reader->await();
		if ($exitCode !== 0 || $stdout === '') {
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
		$process = Process::start('git tag -l', $baseDir);
		$bufReader = new BufferedReader($process->getStdout());
		$reader = async($bufReader->buffer(...));
		$exitCode = $process->join();
		$stdout = $reader->await();
		if ($exitCode !== 0 || $stdout === '') {
			return null;
		}
		$tagString = (new Collection(explode("\n", trim($stdout))))
			->diff(['nightly'])
			->map(static function (string $tag): SemanticVersion {
				return new SemanticVersion($tag);
			})
			->sort(static function (SemanticVersion $v1, SemanticVersion $v2): int {
				return $v1->cmp($v2);
			})->last()?->getOrigVersion();
		return self::$latestTag = $tagString;
	}

	/** Run the bot in an endless loop */
	public function run(): void {
		try {
			LegacyLogger::$fs = self::getFS();
			LoggerWrapper::$fs = self::getFS();
			self::$arguments = $this->parseOptions();
			// set default timezone
			date_default_timezone_set('UTC');

			$this->checkRequiredModules();
			$this->checkRequiredPackages();
			$this->checkRequiredPrograms();

			$config = $this->getConfigFile();
			Registry::setInstance(Registry::formatName(BotConfig::class), $config);
			Registry::setInstance(Registry::formatName(KeyedMutex::class), new LocalKeyedMutex());
			$retryHandler = new HttpRetry(8);
			Registry::injectDependencies($retryHandler);
			$rateLimitRetryHandler = new HttpRetryRateLimits();
			Registry::injectDependencies($rateLimitRetryHandler);
			$httpClientBuilder = (new HttpClientBuilder())
				->retry(0)
				->intercept(new SetRequestHeaderIfUnset('User-Agent', 'Nadybot '.self::getVersion()))
				->intercept($retryHandler)
				->intercept($rateLimitRetryHandler);
			$httpProxy = getenv('http_proxy');
			if ($httpProxy !== false) {
				$proxyHost = parse_url($httpProxy, \PHP_URL_HOST);
				$proxyScheme = parse_url($httpProxy, \PHP_URL_SCHEME);
				$proxyPort = parse_url($httpProxy, \PHP_URL_PORT) ?? ($proxyScheme === 'https' ? 443 : 80);
				if (is_string($proxyScheme) && is_string($proxyHost) && is_int($proxyPort)) {
					$connector = new Http1TunnelConnector("{$proxyHost}:{$proxyPort}");
					$httpClientBuilder = $httpClientBuilder->usingPool(
						new UnlimitedConnectionPool(
							new DefaultConnectionFactory($connector)
						)
					);
				}
			}
			Registry::setInstance('HttpClientBuilder', $httpClientBuilder);
			$this->createMissingDirs();

			// these must happen first since the classes that are loaded may be used by processes below
			$timezone = $config->general->timezone;
			if (isset($timezone) && strlen($timezone) > 1) {
				try {
					Safe::exceptionWrapper(date_default_timezone_set(...), $timezone);
				} catch (ErrorException) {
					getStderr()->write("Invalid timezone: \"{$timezone}\"\n");
					sleep(5);
					exit(1);
				}
			}
			$logFolderName = "{$config->paths->logs}/{$config->main->character}.{$config->main->dimension}";

			$this->setErrorHandling($logFolderName);
			$this->logger = new LoggerWrapper('Core/BotRunner');
			self::getFS()->setLogger(new LoggerWrapper('Core/Filesystem'));
			Registry::injectDependencies($this->logger);
		} catch (\Throwable $e) {
			register_shutdown_function(static function (): void {
				exit(1);
			});
			throw $e;
		}

		$this->sendBotBanner();

		if ($this->showSetupDialog($config)) {
			$config = $this->getConfigFile();
			Registry::setInstance(Registry::formatName(BotConfig::class), $config);
		}
		$this->setWindowTitle($config);

		$version = self::getVersion();
		Registry::setInstance(Registry::formatName(Filesystem::class), self::$fs);

		$this->logger->notice(
			'Starting {name} {version} on RK{dimension} using '.
			'PHP {phpVersion}, {loopType} event loop, '.
			'{fsType} filesystem, and {dbType}...',
			[
				'name' => $config->main->character,
				'version' => $version,
				'dimension' => $config->main->dimension,
				'phpVersion' => \PHP_VERSION,
				'loopType' => class_basename(EventLoop::getDriver()),
				'fsType' => class_basename(self::$fsDriver),
				'dbType' => $config->database->type->name,
			]
		);

		$this->classLoader = new ClassLoader($config->paths->modules);
		Registry::injectDependencies($this->classLoader);
		Registry::setInstance(Registry::formatName(ClassLoader::class), $this->classLoader);
		$this->classLoader->loadInstances();
		$msgHub = Registry::getInstance(MessageHub::class);
		LegacyLogger::registerMessageEmitters($msgHub);

		$signalHandler = function (): void {
			$this->logger->notice('Shutdown requested.');
			exit;
		};
		$handlers = [];
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, true);
		} else {
			$handlers []= EventLoop::onSignal(\SIGINT, $signalHandler);
			$handlers []= EventLoop::onSignal(\SIGTERM, $signalHandler);
		}
		$this->connectToDatabase();
		if (function_exists('sapi_windows_set_ctrl_handler')) { // @phpstan-ignore-line
			sapi_windows_set_ctrl_handler($signalHandler, false);
		}
		foreach ($handlers as $handler) {
			EventLoop::cancel($handler);
		}
		$this->prefillSettingProperties();

		$this->runUpgradeScripts();
		EventLoop::run();
		if (self::$arguments->migrateOnly) {
			exit(0);
		}

		/** @var Nadybot */
		$chatBot = Registry::getInstance(Nadybot::class);

		// startup core systems, load modules and call setup methods
		$db = Registry::getInstance(DB::class);
		if ($db->table(CmdCfg::getTable())->exists()) {
			$this->logger->notice('Initializing modules...');
		} else {
			$this->logger->notice('Initializing modules and db tables...');
		}
		$chatBot->init();

		if (self::$arguments->setupOnly) {
			exit(0);
		}

		// connect to AO chat server
		$chatBot->connectAO();

		// pass control to Nadybot class
		$chatBot->run();
	}

	/** Utility function to check whether the bot is running Windows */
	public static function isWindows(): bool {
		return strtoupper(substr(\PHP_OS, 0, 3)) === 'WIN';
	}

	/** Utility function to check whether the bot is running Linux */
	public static function isLinux(): bool {
		return \PHP_OS_FAMILY === 'Linux';
	}

	/** Get the filesystem class that is configured */
	private static function getFS(): Filesystem {
		if (isset(self::$fs)) {
			return self::$fs;
		}
		if (!self::isLinux()) {
			putenv('AMP_FS_DRIVER=' . BlockingFilesystemDriver::class);
		}
		$fsDriverClass = getenv('AMP_FS_DRIVER');
		if ($fsDriverClass !== false && class_exists($fsDriverClass) && is_subclass_of($fsDriverClass, FilesystemDriver::class)) {
			$fsDriver = new $fsDriverClass();
		} else {
			$fsDriver = createDefaultDriver();
			if ($fsDriver instanceof EioFilesystemDriver || $fsDriver instanceof ParallelFilesystemDriver) {
				$fsDriver = new BlockingFilesystemDriver();
			}
		}
		self::$fsDriver = class_basename($fsDriver);

		self::$fs = new Filesystem(filesystem($fsDriver));
		return self::$fs;
	}

	/** Get the bot configuration from the configured config file */
	private function getConfigFile(): BotConfig {
		if (isset($this->configFile)) {
			return $this->configFile;
		}
		$configFilePath = self::$arguments->configFile;
		if (!isset($configFilePath) && self::getFS()->exists('conf/config.toml')) {
			$configFilePath = 'conf/config.toml';
		} elseif (!isset($configFilePath) && self::getFS()->exists('conf/config.php')) {
			$configFilePath = 'conf/config.php';
		}
		$configFilePath ??= 'conf/config.toml';
		return $this->configFile = BotConfig::loadFromFile($configFilePath, self::getFS());
	}

	/** Create the directories given in the config file, if they don't exist */
	private function createMissingDirs(): void {
		$path = $this->getConfigFile()->paths;
		foreach (get_object_vars($path) as $name => $dir) {
			if (is_string($dir) && !self::getFS()->exists($dir)) {
				self::getFS()->createDirectory($dir, 0o700);
			}
		}
		foreach ($path->modules as $dir) {
			if (!self::getFS()->exists($dir)) {
				self::getFS()->createDirectory($dir, 0o700);
			}
		}
	}

	/** Check if some required key composer packages are installed properly */
	private function checkRequiredPackages(): void {
		if (
			!class_exists('Revolt\\EventLoop')
			|| !class_exists('Amp\\Future')
		) {
			// @phpstan-ignore-next-line
			fwrite(
				\STDERR,
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

	/** Ensure that all external programs required to run the bot, are present */
	private function checkRequiredPrograms(): void {
		if (!self::isWindows()) {
			return;
		}
	}

	/** Check if all the modules that the bot needs, are installed */
	private function checkRequiredModules(): void {
		// @phpstan-ignore if.alwaysFalse
		if (version_compare(\PHP_VERSION, '8.1.17', '<')) {
			// @phpstan-ignore-next-line
			fwrite(\STDERR, 'Nadybot 7 needs at least PHP version 8 to run, you have ' . \PHP_VERSION . "\n");
			sleep(5);
			exit(1);
		}
		$missing = [];
		$requiredModules = [
			['bcmath', 'gmp'],
			'ctype',
			'date',
			'dom',
			'fileinfo',
			'filter',
			'json',
			'mbstring',
			'openssl',
			'pcre',
			'PDO',
			'simplexml',
			['pdo_mysql', 'pdo_sqlite'],
			'Reflection',
			'sockets',
			'fileinfo',
			'tokenizer',
		];
		if (!self::isWindows()) {
			$requiredModules []= 'pcntl';
			$requiredModules []= 'posix';
		}
		foreach ($requiredModules as $requiredModule) {
			if (is_string($requiredModule) && !extension_loaded($requiredModule)) {
				$missing []= $requiredModule;
			} elseif (is_array($requiredModule)) {
				if (!count(array_filter($requiredModule, 'extension_loaded'))) {
					$missing []= implode(' or ', $requiredModule);
				}
			}
		}
		if (extension_loaded('uv')) {
			$uvVersion = phpversion('uv');
			if (is_string($uvVersion) && version_compare($uvVersion, '0.3.0', '<') === true) {
				$missing []= 'uv>=0.3.0';
			}
		}
		if (!count($missing)) {
			return;
		}
		// @phpstan-ignore-next-line
		fwrite(\STDERR, 'Nadybot needs the following missing PHP-extensions: ' . implode(', ', $missing) . ".\n");
		sleep(5);
		exit(1);
	}

	/** Parse all command line options and return them */
	private function parseOptions(): Options {
		try {
			/** @var array<string,mixed> $options */
			$options = getopt(
				'c:v',
				[
					'help',
					'migrate-only',
					'setup-only',
					'test-run',
					'test-show-errors-only',
					'test-file:',
					'vue-dev',
					'strict',
					'log-config:',
					'migration-errors-fatal',
				],
				$restPos
			);

			/** @var int $restPos */
		} catch (InfoException $e) {
			getStderr()->write(
				'Unable to parse arguments passed to the bot: ' . $e->getMessage()
			);
			sleep(5);
			exit(1);
		}
		$argv = array_slice($this->argv, $restPos);
		if (count($argv) > 0) {
			$options['c'] = array_shift($argv);
		}
		$arguments = Hydrator::hydrate(Options::class, $options);
		if ($arguments->help) {
			$this->showSyntaxHelp();
			exit(0);
		}
		return $arguments;
	}

	/** Show a help page how to run the bot */
	private function showSyntaxHelp(): void {
		echo(
			'Usage: ' . \PHP_BINARY . ' ' . ($_SERVER['argv'][0] ?? 'main.php').
			" [options] [-c] <config file>\n\n".
			"positional arguments:\n".
			"  <config file>            A Nadybot configuration file, usually conf/config.toml\n".
			"\n".
			"options:\n".
			"  --help                   Show this help message and exit\n".
			"  --migrate-only           Only run the database migration and then exit\n".
			"  --setup-only             Stop the bot after the setup handlers have been called\n".
			"  --test-run               Don't run the bot normally. Instead, run a series of tests,\n".
			"                           and terminate with an appropriate exit code\n".
			"  --test-show-errors-only  Show only errors, not notices or warnings, during testing\n".
			"  --test-file=<file>       Only run the given test file. Can be given more than once\n".
			"                           and terminate with an appropriate exit code.\n".
			"  --vue-dev                Don't serve web-files locally, connect to the\n".
			"                           vite development server for hot reloading\n".
			"  --log-config=<file>      Use an alternative config file for the logger. The default\n".
			"                           configuration is in conf/logging.json\n".
			"  --migration-errors-fatal Stop the bot startup if any of the database migrations fail\n".
			"  -v                       Enable logging INFO. Use -v -v to also log DEBUG\n"
		);
	}

	/**
	 * Make sure that the values of properties that are linked to settings
	 * is filled with the last known values from the database
	 */
	private function prefillSettingProperties(): void {
		$settingManager = Registry::getInstance(SettingManager::class);
		foreach (Registry::getAllInstances() as $name => $instance) {
			$refObj = new ReflectionObject($instance);
			foreach ($refObj->getProperties() as $refProp) {
				foreach ($refProp->getAttributes(NCA\DefineSetting::class, ReflectionAttribute::IS_INSTANCEOF) as $refAttr) {
					try {
						$attr = $refAttr->newInstance();
					} catch (\Throwable $e) {
						$this->logger->error('Incompatible attribute #[{attrName}] in {loc}: {error}', [
							'attrName' => str_replace('Nadybot\Core\Attributes', 'NCA', $refAttr->getName()),
							'error' => $e->getMessage(),
							'exception' => $e,
							'loc' => $refProp->getDeclaringClass()->getName() . '::$' . $refProp->getName(),
						]);
						exit;
					}
					$attr->name ??= Text::toSnakeCase($refProp->getName());
					try {
						$value = $settingManager->getTyped($attr->name);
					} catch (Throwable) {
						// If the database is initialized for the first time
						return;
					}
					if ($value !== null) {
						$this->logger->info('Setting {class}::${property} to {value}', [
							'class' => class_basename($instance),
							'property' => $refProp->getName(),
							'value' => JSON::export($value),
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
			'{eol}'.
			' _  _ ____   Nadybot version: {version}{eol}'.
			'| \| |__  |  Project Site:    {project_url}{eol}'.
			'| .` | / /   In-Game Contact: {in_game_contact}{eol}'.
			'|_|\_|/_/    Discord:         {discord_link}{eol}{eol}',
			[
				'eol' => \PHP_EOL,
				'version' => self::getVersion(),
				'project_url' => 'https://github.com/Nadybot/Nadybot',
				'in_game_contact' => 'Nady',
				'discord_link' => 'https://discord.gg/aDR9UBxRfg',
			]
		);
	}

	/** Setup proper error-reporting, -handling and -logging */
	private function setErrorHandling(string $logFolderName): void {
		$errorLevel = \E_ALL & ~\E_WARNING & ~\E_NOTICE;
		if (defined('\\E_USER_DEPRECATED')) {
			$errorLevel = $errorLevel & ~\E_USER_DEPRECATED;
		}
		error_reporting($errorLevel);
		ini_set('log_errors', '1');
		ini_set('display_errors', '1');
		ini_set('error_log', "{$logFolderName}/php_errors.log");
	}

	/** Guide customer through setup if needed */
	private function showSetupDialog(BotConfig $config): bool {
		if (!$this->shouldShowSetup($config)) {
			return false;
		}
		$setup = new Setup(
			configFile: $this->getConfigFile(),
			fs: self::getFS(),
			options: self::$arguments,
			logger: $this->logger
		);
		$this->configFile = $setup->showIntro();
		$this->logger->notice('Reloading configuration and testing your settings.');
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
		if (self::isWindows() === false) {
			return;
		}
		async(Process::start(...), "title {$config->main->character} - Nadybot")->ignore();
	}

	/** Connect to the database */
	private function connectToDatabase(): void {
		$db = Registry::getInstance(DB::class);
		$config = $this->getConfigFile();
		$db->connect($config->database);
	}

	/** Run migration scripts to keep the SQL schema up-to-date */
	private function runUpgradeScripts(): void {
		Registry::getInstance(DB::class)->createDatabaseSchema();
	}
}
