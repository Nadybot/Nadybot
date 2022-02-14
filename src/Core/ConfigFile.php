<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\Attributes\Instance;
use Spatie\DataTransferObject\{
	Attributes\MapFrom,
	Attributes\MapTo,
	DataTransferObject,
};

/**
 * The ConfigFile class provides convenient interface for reading and saving
 * config files located in conf-subdirectory.
 */
#[Instance]
class ConfigFile extends DataTransferObject {
	private string $filePath;

	public string $login;
	public string $password;
	public string $name;

	#[MapFrom('my_guild')]
	#[MapTo('my_guild')]
	public string $orgName;

	public ?int $orgId = null;

	// 6 for Live (new), 5 for Live (old), 4 for Test.
	public int $dimension = 5;

	// Character name of the Super Administrator.
	#[MapFrom('SuperAdmin')]
	#[MapTo('SuperAdmin')]
	public string $superAdmin;

	/** What type of database should be used? ('sqlite' or 'mysql') */
	#[MapFrom('DB Type')]
	#[MapTo('DB Type')]
	public string $dbType = DB::SQLITE;

	/** Name of the database */
	#[MapFrom('DB Name')]
	#[MapTo('DB Name')]
	public string $dbName = "nadybot.db";

	/** Hostname or sqlite file location */
	#[MapFrom('DB Host')]
	#[MapTo('DB Host')]
	public string $dbHost = "./data/";

	/** MySQL or PostgreSQL username */
	#[MapFrom('DB username')]
	#[MapTo('DB username')]
	public ?string $dbUsername = null;

	/** MySQL or PostgreSQL password */
	#[MapFrom('DB password')]
	#[MapTo('DB password')]
	public ?string $dbPassword = null;

	/** Show AOML markup in logs/console? 1 for enabled, 0 for disabled. */
	#[MapFrom('show_aoml_markup')]
	#[MapTo('show_aoml_markup')]
	public int $showAomlMarkup = 0;

	/** Cache folder for storing organization XML files. */
	#[MapFrom('cachefolder')]
	#[MapTo('cachefolder')]
	public string $cacheFolder = "./cache/";

	/** Folder for storing HTML files of the webserver */
	#[MapFrom('htmlfolder')]
	#[MapTo('htmlfolder')]
	public string $htmlFolder = "./html/";

	/** Folder for storing data files */
	#[MapFrom('datafolder')]
	#[MapTo('datafolder')]
	public string $dataFolder = "./data/";

	/**Folder for storing log files */
	#[MapFrom('logsfolder')]
	#[MapTo('logsfolder')]
	public string $logsFolder = "./logs/";

	/** Default status for new modules? 1 for enabled, 0 for disabled. */
	#[MapFrom('default_module_status')]
	#[MapTo('default_module_status')]
	public int $defaultModuleStatus = 0;

	/** Enable the readline-based console interface to the bot? */
	#[MapFrom('enable_console_client')]
	#[MapTo('enable_console_client')]
	public int $enableConsoleClient = 1;

	/** Enable the module to install other modules from within the bot */
	#[MapFrom('enable_package_module')]
	#[MapTo('enable_package_module')]
	public int $enablePackageModule = 1;

	/** Use AO Chat Proxy? 1 for enabled, 0 for disabled. */
	#[MapFrom('use_proxy')]
	#[MapTo('use_proxy')]
	public int $useProxy = 0;

	#[MapFrom('proxy_server')]
	#[MapTo('proxy_server')]
	public string $proxyServer = "127.0.0.1";

	#[MapFrom('proxy_port')]
	#[MapTo('proxy_port')]
	public int $proxyPort = 9993;

	/**
	 * Define additional paths from where Nadybot should load modules at startup
	 * @var string[]
	 */
	#[MapFrom('module_load_paths')]
	#[MapTo('module_load_paths')]
	public array $moduleLoadPaths = [
		'./src/Modules',
		'./extras'
	];

	/**
	 * Define settings values which will be immutable
	 *
	 * @var array<string,mixed>
	 */
	public array $settings = [];

	public ?string $timezone = null;

	/**
	 * @param array<string,mixed> $args
	 */
	public function __construct(array $args) {
		unset($args["my_guild_id"]);
		$args["my_guild"] ??= "";
		$args["cachefolder"] ??= "./cache/";
		$args["htmlfolder"] ??= "./html/";
		$args["datafolder"] ??= "./data/";
		$args["logsfolder"] ??= "./logs/";
		$args["enable_console_client"] ??= 0;
		$args["enable_package_module"] ??= 0;
		parent::__construct($args);
		$this->superAdmin = ucfirst(strtolower($this->superAdmin));
		$this->name = ucfirst(strtolower($this->name));
	}

	/**
	 * Constructor method.
	 */
	public static function loadFromFile(string $filePath): self {
		self::copyFromTemplateIfNeeded($filePath);
		$vars = [];
		require $filePath;
		$config = new self($vars);
		$config->filePath = $filePath;
		return $config;
	}

	/**
	 * Returns file path to the config file.
	 */
	public function getFilePath(): string {
		return $this->filePath;
	}

	/**
	 * Saves the config file, creating the file if it doesn't exist yet.
	 */
	public function save(): void {
		$vars = $this->except("filePath", "orgId")->toArray();
		$vars = array_filter($vars, function (mixed $value): bool {
			return isset($value);
		});
		self::copyFromTemplateIfNeeded($this->getFilePath());
		$lines = \Safe\file($this->filePath);
		if (!is_array($lines)) {
			throw new Exception("Cannot load {$this->filePath}");
		}
		foreach ($lines as $key => $line) {
			if (preg_match("/^(.+)vars\[('|\")(.+)('|\")](.*)=(.*)\"(.*)\";(.*)$/si", $line, $arr)) {
				$lines[$key] = "$arr[1]vars['$arr[3]']$arr[5]=$arr[6]\"{$vars[$arr[3]]}\";$arr[8]";
				unset($vars[$arr[3]]);
			} elseif (preg_match("/^(.+)vars\[('|\")(.+)('|\")](.*)=([ 	]+)([0-9]+);(.*)$/si", $line, $arr)) {
				$lines[$key] = "$arr[1]vars['$arr[3]']$arr[5]=$arr[6]{$vars[$arr[3]]};$arr[8]";
				unset($vars[$arr[3]]);
			}
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
					$lines []= "\$vars['$name'] = \"$value\";\n";
				} else {
					$lines []= "\$vars['$name'] = $value;\n";
				}
			}
			// $lines []= "\n";
		}

		\Safe\file_put_contents($this->filePath, $lines);
	}

	/**
	 * Copies config.template.php to this config file if it doesn't exist yet.
	 */
	private static function copyFromTemplateIfNeeded(string $filePath): void {
		if (@file_exists($filePath)) {
			return;
		}
		$templatePath = __DIR__ . '/../../conf/config.template.php';
		\Safe\copy($templatePath, $filePath);
	}
}
