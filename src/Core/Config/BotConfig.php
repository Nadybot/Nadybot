<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use function Safe\json_decode;

use Amp\File\Filesystem;
use EventSauce\ObjectHydrator\{MapFrom, MapperSettings, ObjectMapperUsingReflection};
use Nadybot\Core\Attributes\Instance;
use Nadylib\IMEX;

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
	 * @param string                    $filePath     The location in the filesystem of this config file
	 * @param Database                  $database     What type of database should be used? ('sqlite', 'postgresql', or 'mysql')
	 * @param Paths                     $paths        Configuration of the different paths of the bot
	 * @param Credentials               $main         Credentials of the main character
	 * @param Credentials[]             $worker       Credentials of the worker characters
	 * @param General                   $general      General config settings
	 * @param ?Proxy                    $proxy        Information about whether and which proxy to use
	 * @param ?AutoUnfreeze             $autoUnfreeze Settings for automatic unfreezing of accounts
	 * @param array<string,null|scalar> $settings     Define settings values which will be immutable
	 */
	public function __construct(
		private string $filePath,
		public ?int $orgId,
		public Database $database,
		public Paths $paths,
		public Credentials $main,
		public General $general,
		public ?Proxy $proxy=null,
		#[MapFrom("auto-unfreeze")]
		public ?AutoUnfreeze $autoUnfreeze=null,
		public array $worker=[],
		public array $settings=[],
	) {
	}

	/** Constructor method. */
	public static function loadFromFile(string $filePath, Filesystem $fs): self {
		self::copyFromTemplateIfNeeded($filePath, $fs);
		$vars = [];
		if (str_ends_with($filePath, '.toml')) {
			$toml = $fs->read($filePath);
			$vars = IMEX\TOML::import($toml);
		} elseif (str_ends_with($filePath, '.json')) {
			$json = $fs->read($filePath);
			$vars = json_decode($json, true);
		} else {
			$php = $fs->read($filePath);
			$vars = IMEX\PHP::import($php);
		}
		$vars = self::convertOldSettings($vars);
		$vars['file_path'] = $filePath;
		$mapper = new ObjectMapperUsingReflection();

		/** @var BotConfig $config */
		$config = $mapper->hydrateObject(
			self::class,
			$vars
		);
		return $config;
	}

	/** Returns file path to the config file. */
	public function getFilePath(): string {
		return $this->filePath;
	}

	/** Saves the config file, creating the file if it doesn't exist yet. */
	public function save(Filesystem $fs): void {
		$mapper = new ObjectMapperUsingReflection();
		$vars = $mapper->serializeObject($this);
		unset($vars["file_path"]);
		unset($vars["org_id"]);
		$vars = array_filter($vars, function (mixed $value): bool {
			return isset($value);
		});
		if (str_ends_with($this->filePath, '.toml')) {
			$toml = IMEX\TOML::export($vars);
			$fs->write($this->filePath, $toml);
			return;
		} elseif (str_ends_with($this->filePath, '.json')) {
			$json = IMEX\JSON::export($vars, JSON_PRETTY_PRINT);
			$fs->write($this->filePath, $json);
			return;
		} elseif (str_ends_with($this->filePath, '.php')) {
			$php = IMEX\PHP::export($vars);
			$fs->write($this->filePath, $php);
			return;
		}
		throw new \Exception("Unknown config file format");
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private static function convertOldSettings(array $settings): array {
		$mapping = [
			"main" => [
				"login" => "login",
				"password" => "password",
				"character" => "name",
				"dimension" => "dimension",
			],
			"database" => [
				"type" => "DB Type",
				"name" => "DB Name",
				"host" => "DB Host",
				"username" => "DB username",
				"password" => "DB password",
			],
			"general" => [
				"org_name" => "my_guild",
				"super_admins" => "SuperAdmin",
				"show_aoml_markup" => "show_aoml_markup",
				"default_module_status" => "default_module_status",
				"enable_console_client" => "enable_console_client",
				"enable_package_module" => "enable_package_module",
			],
			"paths" => [
				"cache" => "cachefolder",
				"html" => "htmlfolder",
				"data" => "datafolder",
				"logs" => "logsfolder",
				"modules" => "module_load_paths",
			],
			"proxy" => [
				"enabled" => "use_proxy",
				"server" => "proxy_server",
				"port" => "proxy_port",
			],
			"auto-unfreeze" => [
				"enabled" => 'auto_unfreeze',
				"login" => 'auto_unfreeze_login',
				"password" => 'auto_unfreeze_password',
				"use_nadyproxy" => 'auto_unfreeze_use_nadyproxy',
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
			if (empty($result[$module])) {
				unset($result[$module]);
			}
		}
		if (isset($settings["settings"])) {
			$result["settings"] = $settings["settings"];
		}
		if (isset($settings["worker"])) {
			$result["worker"] = $settings["worker"];
		}
		return $result;
	}

	/** Copies config.template.php to this config file if it doesn't exist yet. */
	private static function copyFromTemplateIfNeeded(string $filePath, Filesystem $fs): void {
		if ($fs->exists($filePath)) {
			return;
		}
		$parts = explode(".", $filePath);
		$extension = $parts[count($parts)-1];
		$templatePath = __DIR__ . "/../../../conf/config.template.{$extension}";
		$fs->write($filePath, $fs->read($templatePath));
	}
}
