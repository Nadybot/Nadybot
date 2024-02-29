<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use function Safe\{file_get_contents, json_decode, json_encode, preg_match};
use EventSauce\ObjectHydrator\PropertyCasters\CastToType;
use EventSauce\ObjectHydrator\{MapFrom, MapperSettings, ObjectMapperUsingReflection};
use Exception;
use Nadybot\Core\Attributes\{ForceList, Instance};
use Yosymfony\Toml\Toml;

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
	 * @param string   $filePath                 The location in the filesystem of this config file
	 * @param string   $login                    The AO account login
	 * @param string   $password                 The AO account password
	 * @param string   $name                     The name of the bot character
	 * @param string   $orgName                  The exact name of the org to manage or an empty string if not an orgbot
	 * @param bool     $autoOrgName              Try to automatically determine the org's name and track it for changes
	 * @param int      $dimension                6 for Live (new), 5 for Live (old), 4 for Test
	 * @param string[] $superAdmins              Character names of the Super Administrators
	 * @param Database $database                 What type of database should be used? ('sqlite', 'postgresql', or 'mysql')
	 * @param int      $showAomlMarkup           Show AOML markup in logs/console? 1 for enabled, 0 for disabled.
	 * @param string   $cacheFolder              Cache folder for storing organization XML files.
	 * @param string   $htmlFolder               Folder for storing HTML files of the webserver
	 * @param string   $dataFolder               Folder for storing data files
	 * @param string   $logsFolder               Folder for storing log files
	 * @param int      $defaultModuleStatus      Default status for new modules: 1 for enabled, 0 for disabled.
	 * @param int      $enableConsoleClient      Enable the readline-based console interface to the bot?
	 * @param int      $enablePackageModule      Enable the module to install other modules from within the bot
	 * @param bool     $autoUnfreeze             Try to automatically unfreeze frozen bot accounts
	 * @param string   $autoUnfreezeLogin        If the bot is on a shared account, this is the login of the main account
	 * @param string   $autoUnfreezePassword     If the bot is on a shared account, this is the password of the main account
	 * @param bool     $autoUnfreezeUseNadyproxy Try to unlock a frozen account via a special proxy that prevents getting locked out
	 * @param int      $useProxy                 Use an AO Chat Proxy? 1 for enabled, 0 for disabled
	 * @param int      $proxyPort
	 * @param string[] $moduleLoadPaths          Define additional paths from where Nadybot should load modules at startup
	 * @param array    $settings                 Define settings values which will be immutable
	 *
	 * @psalm-param array<string,null|scalar> $settings
	 */
	public function __construct(
		private string $filePath,
		public string $login,
		public string $password,
		public string $name,
		#[MapFrom('my_guild')]
		public string $orgName,
		public ?int $orgId,
		public int $dimension,
		#[ForceList]
		#[MapFrom('SuperAdmin')]
		public array $superAdmins,
		public Database $database,
		#[CastToType('int')]
		public int $showAomlMarkup=0,
		#[MapFrom('cachefolder')]
		public string $cacheFolder="./cache/",
		#[MapFrom('htmlfolder')]
		public string $htmlFolder="./html/",
		#[MapFrom('datafolder')]
		public string $dataFolder="./data/",
		#[MapFrom('logsfolder')]
		public string $logsFolder="./logs/",
		public int $defaultModuleStatus=0,
		#[CastToType('int')]
		public int $enableConsoleClient=1,
		#[CastToType('int')]
		public int $enablePackageModule=1,
		#[CastToType('bool')]
		public bool $autoUnfreeze=false,
		public ?string $autoUnfreezeLogin=null,
		public ?string $autoUnfreezePassword=null,
		public bool $autoUnfreezeUseNadyproxy=true,
		#[CastToType('int')]
		public int $useProxy=0,
		public string $proxyServer="127.0.0.1",
		#[CastToType('int')]
		public int $proxyPort=9993,
		public array $moduleLoadPaths=['./src/Modules', './extras'],
		public array $settings=[],
		public ?string $timezone=null,
		#[CastToType('bool')]
		#[MapFrom('auto_guild_name')]
		public bool $autoOrgName=false,
	) {
		if ($this->autoUnfreezeLogin === "") {
			$this->autoUnfreezeLogin = null;
		}
		if ($this->autoUnfreezePassword === "") {
			$this->autoUnfreezePassword = null;
		}
		$this->superAdmins = array_map(function (string $char): string {
			return ucfirst(strtolower($char));
		}, $this->superAdmins);
		$this->name = ucfirst(strtolower($this->name));
	}

	/** Constructor method. */
	public static function loadFromFile(string $filePath): self {
		self::copyFromTemplateIfNeeded($filePath);
		$vars = [];
		if (str_ends_with($filePath, '.toml')) {
			$toml = file_get_contents($filePath);
			$vars = Toml::parse($toml);
		} elseif (str_ends_with($filePath, '.json')) {
			$json = file_get_contents($filePath);
			$vars = json_decode($json, true);
		} else {
			require $filePath;
		}
		foreach ($vars as $key => $value) {
			if (preg_match('/^DB (.+)$/', $key, $matches)) {
				$vars['database'] ??= [];
				$vars['database'][strtolower($matches[1])] = $value;
				unset($vars[$key]);
			}
		}
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
	public function save(): void {
		$mapper = new ObjectMapperUsingReflection();
		$vars = $mapper->serializeObject($this);
		unset($vars["file_path"]);
		unset($vars["org_id"]);
		$vars = array_filter($vars, function (mixed &$value): bool {
			if (is_array($value)) {
				$value = array_filter($value, function (mixed $value2): bool {
					return isset($value2);
				});
				if (empty($value)) {
					return false;
				}
			}
			return isset($value);
		});
		if (str_ends_with($this->filePath, '.toml')) {
			return;
		} elseif (str_ends_with($this->filePath, '.json')) {
			$json = json_encode($vars, JSON_PRETTY_PRINT);
			\Safe\file_put_contents($this->filePath, $json);
			return;
		}
		self::copyFromTemplateIfNeeded($this->getFilePath());
		$lines = \Safe\file($this->filePath);
		if (!is_array($lines)) {
			throw new Exception("Cannot load {$this->filePath}");
		}
		$inComment = false;
		$usedVars = [];
		foreach ($lines as $key => $line) {
			if (preg_match("/^\s*\/\*/", $line)) {
				$inComment = true;
			}
			if (preg_match("/\*\/\s*$/", $line)) {
				$inComment = false;
				continue;
			}
			if (preg_match("/^\s*\/\//", $line) || $inComment) {
				continue;
			}
			if (preg_match("/^(.+)vars\[('|\")(.+)('|\")](.*)=(.*)\"(.*)\";(.*)$/si", $line, $arr)) {
				$lines[$key] = "{$arr[1]}vars['{$arr[3]}']{$arr[5]}={$arr[6]}".
					json_encode($vars[$arr[3]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).
					";{$arr[8]}";
				$usedVars[$arr[3]] = true;
			} elseif (preg_match("/^(.+)vars\[('|\")(.+)('|\")](.*)=([ 	]+)([0-9]+|true|false);(.*)$/si", $line, $arr)) {
				$lines[$key] = "{$arr[1]}vars['{$arr[3]}']{$arr[5]}={$arr[6]}{$vars[$arr[3]]};{$arr[8]}";
				$usedVars[$arr[3]] = true;
			}
		}
		foreach ($usedVars as $varName => $true) {
			unset($vars[$varName]);
		}

		unset($vars['module_load_paths']); // hacky
		unset($vars['settings']); // hacky

		// if there are additional vars which were not present in the config
		// file or in template file then add them at end of the config file
		if (!empty($vars)) {
			if (empty($lines)) {
				$lines []= "<?php\n";
			}
			foreach ($vars as $name => $value) {
				if (is_string($value)) {
					$lines []= "\$vars['{$name}'] = \"{$value}\";\n";
				} elseif (is_array($value)) {
					$lines []= "\$vars['{$name}'] = " . json_encode($value) . ";\n";
				} else {
					$lines []= "\$vars['{$name}'] = {$value};\n";
				}
			}
		}

		\Safe\file_put_contents($this->filePath, $lines);
	}

	/** Copies config.template.php to this config file if it doesn't exist yet. */
	private static function copyFromTemplateIfNeeded(string $filePath): void {
		if (@file_exists($filePath)) {
			return;
		}
		$templatePath = __DIR__ . '/../../conf/config.template.php';
		\Safe\copy($templatePath, $filePath);
	}
}
