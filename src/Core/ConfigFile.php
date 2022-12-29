<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_encode;
use EventSauce\ObjectHydrator\{MapFrom, MapperSettings, ObjectMapper, ObjectMapperUsingReflection, PropertyCaster, PropertySerializer};
use Exception;
use Nadybot\Core\Attributes\Instance;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
final class ForceList implements PropertyCaster, PropertySerializer {
	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		return (array)$value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value should be an array');
		if (count($value) === 1) {
			return array_shift($value);
		}
		return $value;
	}
}

/**
 * The ConfigFile class provides convenient interface for reading and saving
 * config files located in conf-subdirectory.
 */
#[
	Instance,
	MapperSettings(serializePublicMethods: false)
]
class ConfigFile {
	public function __construct(
		private string $filePath,
		public string $login,
		public string $password,
		public string $name,
		#[MapFrom('my_guild')]
		public string $orgName,
		public ?int $orgId,

		/** 6 for Live (new), 5 for Live (old), 4 for Test. */
		public int $dimension,

		/**
		 * Character name of the Super Administrator.
		 *
		 * @var string[]
		 */
		#[ForceList]
		#[MapFrom('SuperAdmin')]
		public array $superAdmins,

		/** What type of database should be used? ('sqlite' or 'mysql') */
		#[MapFrom('DB Type')]
		public string $dbType=DB::SQLITE,

		/** Name of the database */
		#[MapFrom('DB Name')]
		public string $dbName="nadybot.db",

		/** Hostname or sqlite file location */
		#[MapFrom('DB Host')]
		public string $dbHost="./data/",

		/** MySQL or PostgreSQL username */
		#[MapFrom('DB username')]
		public ?string $dbUsername=null,

		/** MySQL or PostgreSQL password */
		#[MapFrom('DB password')]
		public ?string $dbPassword=null,

		/** Show AOML markup in logs/console? 1 for enabled, 0 for disabled. */
		public int $showAomlMarkup=0,

		/** Cache folder for storing organization XML files. */
		#[MapFrom('cachefolder')]
		public string $cacheFolder="./cache/",

		/** Folder for storing HTML files of the webserver */
		#[MapFrom('htmlfolder')]
		public string $htmlFolder="./html/",

		/** Folder for storing data files */
		#[MapFrom('datafolder')]
		public string $dataFolder="./data/",

		/* Folder for storing log files */
		#[MapFrom('logsfolder')]
		public string $logsFolder="./logs/",

		/** Default status for new modules? 1 for enabled, 0 for disabled. */
		public int $defaultModuleStatus=0,

		/** Enable the readline-based console interface to the bot? */
		public int $enableConsoleClient=1,

		/** Enable the module to install other modules from within the bot */
		public int $enablePackageModule=1,

		/** Use AO Chat Proxy? 1 for enabled, 0 for disabled. */
		public int $useProxy=0,
		public string $proxyServer="127.0.0.1",
		public int $proxyPort=9993,

		/**
		 * Define additional paths from where Nadybot should load modules at startup
		 *
		 * @var string[]
		 */
		public array $moduleLoadPaths=[
			'./src/Modules',
			'./extras',
		],

		/**
		 * Define settings values which will be immutable
		 *
		 * @var array<string,mixed>
		 */
		public array $settings=[],
		public ?string $timezone=null,
	) {
		$this->superAdmins = array_map(function (string $char): string {
			return ucfirst(strtolower($char));
		}, $this->superAdmins);
		$this->name = ucfirst(strtolower($this->name));
	}

	/** Constructor method. */
	public static function loadFromFile(string $filePath): self {
		self::copyFromTemplateIfNeeded($filePath);
		$vars = [];
		require $filePath;
		$vars['file_path'] = $filePath;
		$mapper = new ObjectMapperUsingReflection();

		/** @var ConfigFile $config */
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
		$vars = array_filter($vars, function (mixed $value): bool {
			return isset($value);
		});
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
			if (preg_match("/\s*\/\//", $line) || $inComment) {
				continue;
			}
			if (preg_match("/^(.+)vars\[('|\")(.+)('|\")](.*)=(.*)\"(.*)\";(.*)$/si", $line, $arr)) {
				$lines[$key] = "{$arr[1]}vars['{$arr[3]}']{$arr[5]}={$arr[6]}".
					json_encode($vars[$arr[3]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).
					";{$arr[8]}";
				$usedVars[$arr[3]] = true;
			} elseif (preg_match("/^(.+)vars\[('|\")(.+)('|\")](.*)=([ 	]+)([0-9]+);(.*)$/si", $line, $arr)) {
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
