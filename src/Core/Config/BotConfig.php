<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use function Safe\{json_decode, json_encode};
use EventSauce\ObjectHydrator\{MapFrom, MapperSettings, UnableToHydrateObject};
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Attributes\{Instance, JSON\Ignore};
use Nadybot\Core\{BotRunner, Filesystem, Hydrator, Safe};
use Nadylib\IMEX;
use Nadylib\IMEX\ImportException;

/**
 * The BotConfig class provides convenient interface for reading and saving
 * config files located in conf-subdirectory.
 */
#[
	Instance,
	MapperSettings(serializePublicMethods: false)
]
class BotConfig {
	/**
	 * @param string                $filePath     The location in the filesystem of this config file
	 * @param Database              $database     What type of database should be used? ('sqlite', 'postgresql', or 'mysql')
	 * @param Paths                 $paths        Configuration of the different paths of the bot
	 * @param Credentials           $main         Credentials of the main character
	 * @param General               $general      General config settings
	 * @param ?Proxy                $proxy        Information about whether and which proxy to use
	 * @param ?AutoUnfreeze         $autoUnfreeze Settings for automatic unfreezing of accounts
	 * @param Credentials[]         $worker       Credentials of the worker characters
	 * @param array<string,?scalar> $settings     Define settings values which will be immutable
	 *
	 * @psalm-param list<Credentials> $worker
	 */
	public function __construct(
		private string $filePath,
		#[Ignore] public ?int $orgId,
		public Database $database,
		public Paths $paths,
		public Credentials $main,
		public General $general,
		public ?Proxy $proxy=null,
		#[MapFrom('auto-unfreeze')] public ?AutoUnfreeze $autoUnfreeze=null,
		#[CastListToType(Credentials::class)] public array $worker=[],
		public array $settings=[],
	) {
	}

	/** Constructor method. */
	public static function loadFromFile(string $filePath, Filesystem $fs): self {
		self::copyFromTemplateIfNeeded($filePath, $fs);
		$vars = [];
		if (str_ends_with($filePath, '.toml')) {
			$toml = $fs->read($filePath);
			try {
				$vars = IMEX\TOML::import($toml);
			} catch (ImportException $e) {
				$errorMessages = [$e->getMessage()];
				while (($e = $e->getPrevious()) !== null) {
					$errorMessages []= $e->getMessage();
				}
				$cleanToml = Safe::pregReplace('/password\s*=\s*[\'"].*/m', 'password = "***REDACTED***"', $toml);
				// @phpstan-ignore-next-line
				fwrite(
					\STDERR,
					"Your configuration file {$filePath} is invalid TOML:\n\n".
					implode("\n", $errorMessages) . "\n\n".
					$cleanToml.
					"\n\n"
				);
				exit(1);
			}
		} elseif (str_ends_with($filePath, '.json')) {
			$json = $fs->read($filePath);
			$vars = json_decode($json, true);
		} else {
			$php = $fs->read($filePath);
			$vars = IMEX\PHP::import($php);
		}
		if (!is_array($vars)) {
			// @phpstan-ignore-next-line
			fwrite(
				\STDERR,
				"Your configuration file {$filePath} is not in the right format\n"
			);
			exit(1);
		}

		/** @var array<string,mixed> $vars */
		$settings = self::convertOldSettings($vars);
		$settings['file_path'] = $filePath;

		if (isset($settings['worker']) && is_array($settings['worker'])) {
			for ($i = 0; $i < count($settings['worker']); $i++) {
				if (!is_array($settings['worker'][$i])) {
					continue;
				}
				$settings['worker'][$i]['dimension'] ??= $settings['main']['dimension'] ?? null;
				$settings['worker'][$i]['login']     ??= $settings['main']['login'] ?? null;
				$settings['worker'][$i]['password']  ??= $settings['main']['password'] ?? null;
			}
		}

		try {
			$config = Hydrator::hydrate(self::class, $settings);
			$config->autoUnfreeze ??= new AutoUnfreeze();
		} catch (UnableToHydrateObject $e) {
			if (!BotRunner::getArguments()->testRun) {
				throw $e;
			}
			$errorMessages = [$e->getMessage()];
			while (($e = $e->getPrevious()) !== null) {
				$errorMessages []= $e->getMessage();
			}
			// @phpstan-ignore-next-line
			fwrite(
				\STDERR,
				"Your configuration file {$filePath} is invalid:\n\n".
				implode("\n", $errorMessages) . "\n\n".
				json_encode($settings, \JSON_PRETTY_PRINT|\JSON_UNESCAPED_SLASHES|\JSON_UNESCAPED_UNICODE).
				"\n\n"
			);
			exit(1);
		}
		return $config;
	}

	/** Returns file path to the config file. */
	public function getFilePath(): string {
		return $this->filePath;
	}

	/** Saves the config file, creating the file if it doesn't exist yet. */
	public function save(Filesystem $fs): void {
		/** @var array<string,mixed> */
		$vars = Hydrator::serialize($this);
		unset($vars['file_path']);
		unset($vars['org_id']);
		$vars = array_filter($vars, static function (mixed $value): bool {
			return isset($value);
		});
		if (str_ends_with($this->filePath, '.toml')) {
			$toml = IMEX\TOML::export($vars);
			$fs->write($this->filePath, $toml);
			return;
		} elseif (str_ends_with($this->filePath, '.json')) {
			$json = IMEX\JSON::export($vars, \JSON_PRETTY_PRINT);
			$fs->write($this->filePath, $json);
			return;
		} elseif (str_ends_with($this->filePath, '.php')) {
			$php = IMEX\PHP::export($vars);
			$fs->write($this->filePath, $php);
			return;
		}
		throw new \Exception('Unknown config file format');
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private static function convertOldSettings(array $settings): array {
		$mapping = [
			'main' => [
				'login' => 'login',
				'password' => 'password',
				'character' => 'name',
				'dimension' => 'dimension',
			],
			'database' => [
				'type' => 'DB Type',
				'name' => 'DB Name',
				'host' => 'DB Host',
				'username' => 'DB username',
				'password' => 'DB password',
			],
			'general' => [
				'org_name' => 'my_guild',
				'super_admins' => 'SuperAdmin',
				'show_aoml_markup' => 'show_aoml_markup',
				'default_module_status' => 'default_module_status',
				'enable_console_client' => 'enable_console_client',
				'enable_package_module' => 'enable_package_module',
			],
			'paths' => [
				'cache' => 'cachefolder',
				'html' => 'htmlfolder',
				'data' => 'datafolder',
				'logs' => 'logsfolder',
				'modules' => 'module_load_paths',
			],
			'proxy' => [
				'enabled' => 'use_proxy',
				'server' => 'proxy_server',
				'port' => 'proxy_port',
			],
			'auto-unfreeze' => [
				'enabled' => 'auto_unfreeze',
				'login' => 'auto_unfreeze_login',
				'password' => 'auto_unfreeze_password',
				'use_nadyproxy' => 'auto_unfreeze_use_nadyproxy',
			],
		];
		$result = [];
		foreach ($mapping as $module => $modMap) {
			$result[$module] = [];
			if (isset($settings[$module]) && is_array($settings[$module])) {
				$result[$module] = $settings[$module];
			}
			foreach ($modMap as $new => $old) {
				if (isset($settings[$old])) {
					$result[$module][$new] = $settings[$old];
					unset($settings[$old]);
				}
			}
			if (!count($result[$module])) {
				unset($result[$module]);
			}
		}
		if (isset($settings['settings'])) {
			$result['settings'] = $settings['settings'];
		}
		if (isset($settings['worker'])) {
			$result['worker'] = $settings['worker'];
		}
		return $result;
	}

	/** Copies config.template.php to this config file if it doesn't exist yet. */
	private static function copyFromTemplateIfNeeded(string $filePath, Filesystem $fs): void {
		if ($fs->exists($filePath)) {
			return;
		}
		$parts = explode('.', $filePath);
		$extension = $parts[count($parts)-1];
		$templatePath = __DIR__ . "/../../../conf/config.template.{$extension}";
		$fs->write($filePath, $fs->read($templatePath));
	}
}
