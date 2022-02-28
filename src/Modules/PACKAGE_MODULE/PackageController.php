<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use Illuminate\Support\Collection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Safe\Exceptions\DirException;
use Safe\Exceptions\FilesystemException;
use SplFileInfo;
use Throwable;
use ZipArchive;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CacheManager,
	CacheResult,
	CmdContext,
	ConfigFile,
	DB,
	Http,
	HttpResponse,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	ParamClass\PWord,
	Text,
};
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "package",
		accessLevel: "admin",
		description: "Install or update external packages",
		alias: ['packages', 'module'],
	)
]
class PackageController extends ModuleInstance {
	public const DB_TABLE = "package_files_<myname>";
	public const EXTRA = 2;
	public const BUILT_INT = 1;
	public const UNINST = 0;
	public const API = "https://pkg.aobots.org/api";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->scanForUnregisteredExtraModules();
	}

	/**
	 * Scan for and add all unregistered extra modules into the database
	 */
	protected function scanForUnregisteredExtraModules(): void {
		$targetDir = $this->getExtraModulesDir();
		if ($targetDir === null) {
			return;
		}
		try {
			$dh = \Safe\opendir($targetDir);
			while (($dir = readdir($dh)) !== false) {
				if (in_array($dir, [".", ".."], true)) {
					continue;
				}
				$this->scanExtraModule($targetDir, $dir);
			}
			closedir($dh);
		} catch (DirException) {
		}
	}

	/** Return if a module id extra (2) built-in (1) or not installed (0) */
	public function getInstalledModuleType(string $module): int {
		$path = $this->chatBot->runner->classLoader->registeredModules[$module] ?? null;
		if (!isset($path)) {
			return static::UNINST;
		}
		if (\Safe\realpath(dirname($path)) === \Safe\realpath(dirname(__DIR__))) {
			return static::BUILT_INT;
		}
		if (\Safe\realpath(dirname($path)) === \Safe\realpath(dirname(dirname(__DIR__))."/Core/Modules")) {
			return static::BUILT_INT;
		}
		return static::EXTRA;
	}

	/**
	 * Scan for and add all files of $module into the database
	 */
	protected function scanExtraModule(string $targetDir, string $module): void {
		$exists = $this->db->table(self::DB_TABLE)
			->where("module", $module)
			->exists();
		if ($exists) {
			return;
		}

		$version = $this->getInstalledVersion($module, $targetDir);
		$dirIterator = new RecursiveDirectoryIterator(
			"{$targetDir}/{$module}"
		);
		$iterator = new RecursiveIteratorIterator(
			$dirIterator,
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (in_array($file->getFilename(), [".", ".."], true)) {
				continue;
			}
			$relPath = substr($file->getPathname(), strlen($targetDir) + 1);
			if (substr($relPath, 0, 2) === "..") {
				continue;
			}
			$this->db->table(self::DB_TABLE)
				->insert([
					"module" => $module,
					"version" => $version ?? "",
					"file" => $relPath
				]);
		}
	}

	public function isValidJSON(?string $data): bool {
		if (!isset($data)) {
			return false;
		}
		try {
			$data = \Safe\json_decode($data, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return false;
		}
		return true;
	}

	/** Download and parse the full package index */
	public function getPackages(callable $callback, mixed ...$args): void {
		$this->cacheManager->asyncLookup(
			static::API . "/packages",
			"PACKAGE_MODULE",
			"packages",
			[$this, "isValidJSON"],
			3600,
			false,
			[$this, "parsePackages"],
			$callback,
			...$args
		);
	}

	/**
	 * Download and parse the package index for $package
	 */
	public function getPackage(string $package, callable $callback, mixed ...$args): void {
		$this->cacheManager->asyncLookup(
			static::API . "/packages/{$package}",
			"PACKAGE_MODULE",
			"package_" . md5($package),
			[$this, "isValidJSON"],
			3600,
			false,
			[$this, "parsePackages"],
			$callback,
			...$args
		);
	}

	/**
	 * @psalm-param callable(null|Package[], mixed...) $callback
	 */
	public function parsePackages(CacheResult $response, callable $callback, mixed ...$args): void {
		if ($response->data === null) {
			$callback(null, ...$args);
			return;
		}
		try {
			$data = \Safe\json_decode($response->data, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$callback(null, ...$args);
			return;
		}
		if (!is_array($data)) {
			$callback(null, ...$args);
			return;
		}
		/** @var Collection<Package> */
		$packages = new Collection();
		foreach ($data as $pack) {
			$packages[]= JsonImporter::convert(Package::class, $pack);
		}
		$packages = $packages->filter(function(Package $package): bool {
			return $package->bot_type === "Nadybot";
		})
		->each(function (Package $package): void {
			$package->compatible = $this->isVersionCompatible($package->bot_version);
			$package->state = $this->getInstalledModuleType($package->name);
		})
		->values()
		->toArray();
		$callback($packages, ...$args);
	}

	/** Get a full list of all available packages*/
	#[NCA\HandlesCommand("package")]
	public function listPackagesCommand(CmdContext $context, #[NCA\Str("list")] string $action): void {
		$this->getPackages([$this, "displayPackages"], $context);
	}

	/**
	 * @param null|Package[] $packages
	 */
	public function displayPackages(?array $packages, CmdContext $context): void {
		if (!isset($packages)) {
			$context->reply("There was an error retrieving the list of available packages.");
			return;
		}
		/** @var array<string,PackageGroup> */
		$groupedPackages = [];
		/** @var Package[] $packages */
		if (!count($packages)) {
			$context->reply("There are currently no packages available for Nadybot.");
			return;
		}
		foreach ($packages as $package) {
			$pGroup = $groupedPackages[$package->name] ?? null;
			if (!isset($pGroup)) {
				$pGroup = new PackageGroup();
				$pGroup->name = $package->name;
				$groupedPackages[$package->name] = $pGroup;
			}
			$pGroup->highest ??= $package;
			if ($package->compatible) {
				$pGroup->highest_supported ??= $package;
			}
		}
		$blobs = [];
		ksort($groupedPackages);
		foreach ($groupedPackages as $pName => $pGroup) {
			$package = $pGroup->highest;
			if (!isset($package)) {
				continue;
			}
			$infoLink = $this->text->makeChatcmd("details", "/tell <myname> package info {$package->name}");
			$installLink = "";
			$installedVersion = null;
			if ($package->state === static::EXTRA) {
				$installedVersion = (string)$this->db->table(self::DB_TABLE)
					->where("module", $package->name)
					->max("version");
			}
			if (in_array($package->name, ['GAUNTLET_MODULE', 'BIGBOSS_MODULE', 'IMPQL_MODULE'])) {
				$installLink = "<i>Included in Nadybot now</i>";
			} elseif (isset($pGroup->highest_supported) && $package->state !== static::BUILT_INT) {
				// @phpstan-ignore-next-line
				if ($pGroup->highest_supported && isset($installedVersion) && $installedVersion !== "") {
					if (SemanticVersion::compareUsing($installedVersion, $pGroup->highest_supported->version, '<')) {
						$installLink = "[" . $this->text->makeChatcmd(
							"update",
							"/tell <myname> package update {$pGroup->highest_supported->name} {$pGroup->highest_supported->version}"
						) . "]";
					}
				} else {
					$installLink = "[" . $this->text->makeChatcmd(
						"install",
						"/tell <myname> package install {$pGroup->highest_supported->name} {$pGroup->highest_supported->version}"
					) . "]";
				}
			} elseif ($package->state === static::BUILT_INT) {
				$installLink = "<i>Included in Nadybot now</i>";
			}
			$blob = "<pagebreak><header2>{$package->name}<end>\n".
				"<tab>Description: <highlight>{$package->short_description}<end>\n".
				"<tab>Newest version: <highlight>{$package->version}<end> [{$infoLink}]";
			if (is_object($pGroup->highest_supported) && $pGroup->highest_supported->version === $package->version) {
				$blob .= " {$installLink}";
			} elseif (isset($pGroup->highest)) {
				$blob .= "\n<tab>Highest compatible version: ".
					($pGroup->highest_supported
						? "<highlight>{$pGroup->highest_supported->version}"
						: "<red>none (needs Nadybot " . htmlspecialchars($pGroup->highest->bot_version) . ")"
					).
					"<end> {$installLink}";
			}
			$blob .= "\n";
			if (isset($installedVersion)) {
				$blob .= "<tab>Installed: <highlight>".
					($installedVersion ?: "unknown version").
					"<end> [".
					$this->text->makeChatcmd(
						"uninstall",
						"/tell <myname> package uninstall {$package->name}"
					) . "]\n";
			}
			$blobs []= $blob;
		}
		$msg = $this->text->makeBlob(
			"Available Packages (" . count($groupedPackages) . ")",
			join("\n", $blobs)
		);
		$context->reply($msg);
	}

	/** Get information about a specific package */
	#[NCA\HandlesCommand("package")]
	public function packageInfoCommand(
		CmdContext $context,
		#[NCA\Str("info")] string $action,
		string $package
	): void {
		$this->getPackage($package, [$this, "displayPackageDetail"], $package, $context);
	}

	/**
	 * @param Package[]|null $packages
	 */
	public function displayPackageDetail(?array $packages, string $packageName, CmdContext $context): void {
		if (!isset($packages)) {
			$context->reply("There was an error retrieving information about {$packageName}.");
			return;
		}
		if (!count($packages)) {
			$context->reply("{$packageName} is not compatible with Nadybot.");
			return;
		}
		if ($packages[0]->state === static::EXTRA) {
			$installedVersion = (string)$this->db->table(self::DB_TABLE)
				->where("module", $packages[0]->name)
				->max("version");
		}
		$blob = trim($this->renderHTML($packages[0]->description));
		$blob .= "\n\n<header2>Details<end>\n".
			"<tab>Name: <highlight>{$packages[0]->name}<end>\n".
			"<tab>Author: <highlight>{$packages[0]->author}<end>\n";
		if ($packages[0]->state === static::BUILT_INT) {
			$blob .= "<tab>Status: <highlight>Included in Nadybot now<end>\n";
		} elseif (isset($installedVersion)) {
			$blob .= "<tab>Installed: <highlight>".
				($installedVersion !== "" ? $installedVersion : "yes, unknown version").
				"<end> [".
				$this->text->makeChatcmd(
					"uninstall",
					"/tell <myname> package uninstall {$packages[0]->name}"
				) . "]\n";
		}
		$blob .= "\n<header2>Available versions<end>\n";
		foreach ($packages as $package) {
			$blob .= "<tab><highlight>{$package->version}<end>";
			if ($package->compatible) {
				if ($package->state === static::EXTRA) {
					$installLink = $this->text->makeChatcmd(
						"install",
						"/tell <myname> package install {$package->name} {$package->version}"
					);
					$updateLink = $this->text->makeChatcmd(
						"update",
						"/tell <myname> package update {$package->name} {$package->version}"
					);
					$installedVersion ??= "";
					if ($installedVersion !== "" && SemanticVersion::compareUsing($installedVersion, $package->version, "<")) {
						$blob .= " [{$updateLink}]";
					} elseif ($installedVersion !== "" && SemanticVersion::compareUsing($installedVersion, $package->version, "==")) {
						$blob .= " <i>Installed</i>";
					} elseif ($installedVersion === "") {
						$blob .= " [{$installLink}]";
					}
				}
				$blob .= "\n";
			} else {
				$blob .= " <i>incompatible with your version</i>\n";
			}
		}
		$msg = $this->text->makeBlob("Details for {$packageName}", $blob);
		$context->reply($msg);
	}

	/** Try to render the API's HTML into AOML */
	public function renderHTML(string $html): string {
		$html = preg_replace_callback(
			"/<code.*?>(.+?)<\/code>/is",
			function (array $matches): string {
				return "<highlight>" . str_replace("\n", "<br />", $matches[1]) . "<end>";
			},
			$html
		);
		$html = preg_replace("/\n/", "", $html);
		$html = preg_replace("/<br \/>/", "\n", $html);
		$html = preg_replace("/<h1.*?>/", "<header2>", $html);
		$html = preg_replace("/<\/h1>/", "<end>\n", $html);
		$html = preg_replace("/<blockquote>/", "&gt;&gt; ", $html);
		$html = preg_replace("/<\/blockquote>/", "", $html);
		$html = preg_replace("/<h2.*?>/", "<u>", $html);
		$html = preg_replace("/<\/h2>/", "</u>\n", $html);
		$html = preg_replace("/<em.*?>/", "<i>", $html);
		$html = preg_replace("/<\/em>/", "</i>", $html);
		$html = preg_replace("/<p>/", "", $html);
		$html = preg_replace("/<\/p>/", "\n\n", $html);
		$html = preg_replace("/<a.*?>/", "<u><blue>", $html);
		$html = preg_replace("/<\/a>/", "<end></u>", $html);
		$html = preg_replace("/<pre.*?>/", "", $html);
		$html = preg_replace("/<\/pre>/", "", $html);
		$html = preg_replace("/<(code|strong)>/", "<highlight>", $html);
		$html = preg_replace("/<\/(code|strong)>/", "<end>", $html);
		$html = str_replace("&nbsp;", " ", $html);
		$html = preg_replace_callback(
			"/<ol.*?>(.*?)<\/ol>/is",
			function (array $matches): string {
				$num = 0;
				return preg_replace_callback(
					"/<li>(.*?)<\/li>/is",
					function (array $matches) use (&$num): string {
						$num++;
						return "<tab>{$num}. {$matches[1]}\n";
					},
					$matches[1]
				);
			},
			$html
		);
		$html = preg_replace_callback(
			"/<ul.*?>(.*?)<\/ul>/is",
			function (array $matches): string {
				return preg_replace(
					"/<li>(.*?)<\/li>/is",
					"<tab>* $1\n",
					$matches[1]
				) . "\n";
			},
			$html
		);
		return $html;
	}

	/**
	 * Parse a version-requirement string (>=5.0.0, <6.0.0)
	 * against our bot's version
	 * @param string $version
	 * @return bool true if we match, false if not
	 */
	public function isVersionCompatible(string $version): bool {
		$parts = \Safe\preg_split("/\s*,\s*/", $version);
		$ourVersion = BotRunner::getVersion();

		foreach ($parts as $part) {
			if (!preg_match("/^([!=<>]+)(.+)$/", $part, $matches)) {
				return false;
			}
			if (!SemanticVersion::compareUsing($ourVersion, $matches[2], $matches[1])) {
				return false;
			}
		}
		return true;
	}

	/** Install a package */
	#[NCA\HandlesCommand("package")]
	public function packageInstallCommand(
		CmdContext $context,
		#[NCA\Str("install")] string $action,
		PWord $package,
		?string $version
	): void {
		if (!$this->config->enablePackageModule) {
			$context->reply(
				"In order to be allowed to install modules from within Nadybot, ".
				"you have to set <highlight>\$vars['enable_package_module'] = 1;<end> in your ".
				"bot's config file."
			);
			return;
		}
		$cmd = new PackageAction($package(), PackageAction::INSTALL);
		$cmd->version = $version ? new SemanticVersion($version) : null;
		$cmd->sender = $context->char->name;
		$cmd->sendto = $context;
		$this->getPackage($package(), [$this, "checkAndInstall"], $cmd);
	}

	/** Update an already installed package, optionally to a specific version */
	#[NCA\HandlesCommand("package")]
	public function packageUpdateCommand(
		CmdContext $context,
		#[NCA\Str("update")] string $action,
		PWord $package,
		?string $version
	): void {
		if (!$this->config->enablePackageModule) {
			$context->reply(
				"In order to be allowed to update modules from within Nadybot, ".
				"you have to set <highlight>\$vars['enable_package_module'] = 1;<end> in your ".
				"bot's config file."
			);
			return;
		}
		$cmd = new PackageAction($package(), PackageAction::UPGRADE);
		$cmd->version = $version ? new SemanticVersion($version) : null;
		$cmd->sender = $context->char->name;
		$cmd->sendto = $context;
		$this->getPackage($package(), [$this, "checkAndInstall"], $cmd);
	}

	/** Uninstall a package */
	#[NCA\HandlesCommand("package")]
	public function packageUninstallCommand(
		CmdContext $context,
		#[NCA\Str("uninstall", "delete", "remove", "erase", "del", "rm")] string $action,
		string $package
	): void {
		if (!$this->config->enablePackageModule) {
			$context->reply(
				"In order to be allowed to uninstall modules from within Nadybot, ".
				"you have to set <highlight>\$vars['enable_package_module'] = 1;<end> in your ".
				"bot's config file."
			);
			return;
		}
		$module = strtoupper($package);
		$instType = $this->getInstalledModuleType($module);
		if ($instType === static::UNINST) {
			$context->reply("<highlight>{$module}<end> is not installed.");
			return;
		}
		if ($instType === static::BUILT_INT) {
			$context->reply(
				"<highlight>{$module}<end> is a built-in Nadybot module and ".
				"cannot be uninstalled."
			);
			return;
		}
		$modulePath = $this->chatBot->runner->classLoader->registeredModules[$module];
		try {
			$path = \Safe\realpath($modulePath);
		} catch (FilesystemException $e) {
			$this->logger->error("Cannot determine absolute path of {$modulePath}", [
				"exception" => $e
			]);
			$context->reply("Something is wrong with the path of this module.");
			return;
		}
		$this->logger->info("Removing {$modulePath} ({$path}) recursively");
		$dirIterator = new RecursiveDirectoryIterator($path);
		$iterator = new RecursiveIteratorIterator(
			$dirIterator,
			RecursiveIteratorIterator::SELF_FIRST
		);

		$toDelete = [];
		foreach ($iterator as $file) {
			$this->logger->info("Encountered " . $file->getFilename() . " (" . $file->getPathname() . ")");
			/** @var SplFileInfo $file */
			if (in_array($file->getFilename(), [".", ".."], true)) {
				$this->logger->info("Skipping, because . or ..");
				continue;
			}
			$relPath = substr($file->getPathname(), strlen($path) + 1);
			if (substr($relPath, 0, 2) === "..") {
				$this->logger->info("Skipping, because . or ..");
				continue;
			}
			$this->logger->info("Adding as " . $file->getRealPath());
			$realPath = $file->getRealPath();
			if ($realPath !== false) {
				$toDelete []= $realPath;
			}
		}
		$toDelete []= $path;
		$this->logger->info("Sorting by path length descending");
		usort(
			$toDelete,
			function (string $file1, string $file2): int {
				return strlen($file2) <=> strlen($file1);
			}
		);
		$baseDir = dirname($path) . "/";
		foreach ($toDelete as $file) {
			$this->logger->info("Removing {$file}");
			$relFile = substr($file, strlen($baseDir));
			if (!@file_exists($file)) {
				$this->logger->info("{$file} does not exist");
				continue;
			}
			if (is_dir($file)) {
				$this->logger->notice("rmdir {$relFile}");
				try {
					\Safe\rmdir($file);
				} catch (FilesystemException $e) {
					$context->reply(
						"Error deleting directory {$relFile}: " . $e->getMessage()
					);
					return;
				}
			} else {
				$this->logger->notice("del {$relFile}");
				try {
					\Safe\unlink($file);
				} catch (FilesystemException $e) {
					$context->reply("Error deleting {$relFile}: " . $e->getMessage());
					return;
				}
			}
		}
		$this->logger->info("Deleting done");
		$context->reply(
			"<highlight>{$package}<end> uninstalled. Restart the bot ".
			"for the changes to take effect."
		);
		unset($this->chatBot->runner->classLoader->registeredModules[$module]);
	}

	/**
	 * Check if the package is compatible with Bot type and version and install/update if so
	 * @param Package[]|null $packages
	 */
	public function checkAndInstall(?array $packages, PackageAction $cmd): void {
		if (!isset($packages)) {
			$cmd->sendto->reply(
				"There was an error retrieving information about ".
				"<highlight>{$cmd->package}<end>."
			);
			return;
		}
		if (!count($packages)) {
			$cmd->sendto->reply("{$cmd->package} is not compatible with Nadybot.");
			return;
		}
		if ($packages[0]->state === static::BUILT_INT) {
			$cmd->sendto->reply(
				"<highlight>{$cmd->package}<end> is a built-in module in ".
				"Nadybot " . BotRunner::getVersion() ." and cannot be managed ".
				"with this command."
			);
			return;
		}
		$missingExtensions = [];
		foreach ($packages[0]->requires as $requirement) {
			if (preg_match("/^ext-(.+)$/", $requirement->name, $matches)) {
				if (!extension_loaded($matches[1])) {
					$missingExtensions[$matches[1]] = true;
				}
			}
		}
		if (count($missingExtensions)) {
			$cmd->sendto->reply(
				"<highlight>{$cmd->package}<end> needs the following missing PHP ".
				"extension" . ((count($missingExtensions) > 1) ? "s" : "") . " ".
				"<highlight>" . join(", ", array_keys($missingExtensions)) . "<end>."
			);
			return;
		}
		if (isset($cmd->version)) {
			$packages = array_values(
				array_filter(
					$packages,
					function(Package $package) use ($cmd): bool {
						return $cmd->version->cmpStr($package->version) === 0;
					}
				)
			);
			/** @var Package[] $packages */
			if (!count($packages)) {
				$cmd->sendto->reply(
					"<highlight>{$cmd->package}<end> does not exist in ".
					"version <highlight>{$cmd->version}<end>."
				);
				return;
			}
			if (!$packages[0]->compatible) {
				$cmd->sendto->reply(
					"<highlight>{$cmd->package} {$cmd->version}<end> ".
					" is not compatible with Nadybot " . BotRunner::getVersion()
				);
				return;
			}
		} else {
			$packages = array_values(
				array_filter(
					$packages,
					function(Package $package): bool {
						return $package->compatible;
					}
				)
			);
			$newestPackage = $packages[0] ?? false;
			if ($newestPackage === false) {
				$cmd->sendto->reply(
					"No version of <highlight>{$cmd->package}<end> found that ".
					"is compatible with Nadybot " . BotRunner::getVersion() . "."
				);
				return;
			}
			$cmd->version = new SemanticVersion($newestPackage->version);
		}
		$this->http->get(static::API . "/packages/{$cmd->package}/{$cmd->version}/download")
			->withTimeout(10)
			->withCallback([$this, "installPackage"], $cmd);
	}

	/**
	 * Get the latest installed version of $package
	 * @return string null if uninstalled, "" if unknown version, "x.y.z" otherwise
	 */
	public function getInstalledVersion(string $package, ?string $moduleDir): ?string {
		$moduleDir ??= $this->getExtraModulesDir();
		if (!isset($moduleDir)) {
			return null;
		}
		if (!@file_exists("{$moduleDir}/{$package}")) {
			return null;
		}
		if (!@file_exists("{$moduleDir}/{$package}/aopkg.toml")) {
			return "";
		}
		try {
			$content = \Safe\file_get_contents("{$moduleDir}/{$package}/aopkg.toml");
		} catch (FilesystemException) {
			return "";
		}
		if (!preg_match("/^\s*version\s*=\s*\"(.*?)\"/m", $content, $matches)) {
			return "";
		}
		return $matches[1];
	}

	/** Try to get a ZipArchive from a HttpResponse */
	protected function getResponseZip(HttpResponse $response, PackageAction $cmd): ?ZipArchive {
		if ($response->body === null || $response->error) {
			$cmd->sendto->reply("Error downloading {$cmd->package} {$cmd->version}.");
			return null;
		}
		if ($response->headers["status-code"] === "404") {
			$cmd->sendto->reply(
				"<highlight>{$cmd->package} {$cmd->version}<end> does not exist."
			);
			return null;
		}
		if ($response->headers["status-code"] !== "200"
			|| $response->headers["content-type"] !== "application/zip") {
			$cmd->sendto->reply("Error downloading {$cmd->package} {$cmd->version}.");
			return null;
		}
		try {
			$temp = \Safe\tempnam(sys_get_temp_dir(), "nadybot-module");
			\Safe\file_put_contents($temp, $response->body);
		} catch (FilesystemException $e) {
			$cmd->sendto->reply(
				"Error writing to temporary file: " . $e->getMessage()
			);
			return null;
		}
		$zip = new ZipArchive();
		$openResult = $zip->open($temp);
		@unlink($temp);
		if ($openResult !== true) {
			$cmd->sendto->reply("The downloaded file was corrupt.");
			return null;
		}
		if ($zip->numFiles < 1) {
			$cmd->sendto->reply("The package didn't contain any data.");
			return null;
		}
		return $zip;
	}

	/** Install a requested package that comes as a callback */
	public function installPackage(HttpResponse $response, PackageAction $cmd): void {
		if (!extension_loaded("zip")) {
			$cmd->sendto->reply(
				"Your PHP version does not have the \"zip\" extension installed. ".
				"If you want to be able to use this command, make sure to add that ".
				"extension on your system."
			);
			return;
		}
		$zip = $this->getResponseZip($response, $cmd);
		if (!isset($zip)) {
			return;
		}
		$targetDir = $this->getExtraModulesDir();

		if ($targetDir === null) {
			$cmd->sendto->reply(
				"Your Bot configuration does not have an extra modules dir defined. ".
				"If you want to be able to install additional, user-provided modules, ".
				"please add one."
			);
			return;
		}
		$oldVersion = $this->getInstalledVersion($cmd->package, $targetDir);
		$cmd->oldVersion = isset($oldVersion) ? new SemanticVersion($oldVersion) : null;
		if (!$this->canInstallVersion($cmd)) {
			return;
		}

		$this->logger->notice("Installing module {$cmd->package} into {$targetDir}/{$cmd->package}");
		if (!@file_exists("{$targetDir}/{$cmd->package}/")) {
			try {
				\Safe\mkdir("{$targetDir}/{$cmd->package}", 0700, true);
			} catch (FilesystemException $e) {
				$cmd->sendto->reply(
					"There was an error creating ".
					"<highlight>{$targetDir}/{$cmd->package}<end>."
				);
				$this->logger->error("Error on mkdir of {$targetDir}/{$cmd->package}: " .
					$e->getMessage());
				return;
			}
		}
		$this->removePackageInstallation($cmd, $targetDir);

		$this->db->table(self::DB_TABLE)
			->where("module", $cmd->package)
			->delete();
		if (!$this->installAndRegisterZip($zip, $cmd, $targetDir)) {
			return;
		}

		if ($cmd->action === $cmd::INSTALL) {
			$cmd->sendto->reply(
				"<highlight>{$cmd->package} {$cmd->version}<end> installed successfully. ".
				"Restart the bot for the changes to take effect."
			);
		} else {
			$cmd->sendto->reply(
				"<highlight>{$cmd->package}<end> successfully upgraded ".
				($cmd->oldVersion ? "from {$cmd->oldVersion} " : "").
				"to {$cmd->version}. ".
				"Restart the bot for the changes to take effect."
			);
		}
		$this->chatBot->runner->classLoader->registeredModules[$cmd->package] = $targetDir . "/" . $cmd->package;
	}

	/**
	 * Remove all files from the old version in the filesystem, excluding
	 * the module directory itself
	 */
	public function removePackageInstallation(PackageAction $cmd, string $targetDir): bool {
		$query = $this->db->table(self::DB_TABLE)
			->where("module", $cmd->package);
		$query->orderByColFunc("LENGTH", "file", "desc");
		/** @var PackageFile[] */
		$oldFiles = $query->asObj(PackageFile::class)->toArray();
		foreach ($oldFiles as $oldFile) {
			$fullFilename = "{$targetDir}/{$oldFile->file}";
			if (!@file_exists($fullFilename)) {
				continue;
			}
			if (@is_dir($fullFilename)) {
				$this->logger->notice("rmdir {$fullFilename}");
				@rmdir($fullFilename);
			} else {
				$this->logger->notice("del {$fullFilename}");
				@unlink($fullFilename);
			}
		}
		return true;
	}

	/**
	 * Extract all files from the zip file according to spec
	 * and register them in the database for update
	 */
	public function installAndRegisterZip(ZipArchive $zip, PackageAction $cmd, string $targetDir): bool {
		$subDir = $this->getSubdir($zip);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$fileName = $zip->getNameIndex($i);
			if ($subDir === $fileName || $fileName === false) {
				continue;
			}
			$targetFile = "{$targetDir}/{$cmd->package}/" . substr($fileName, strlen($subDir));
			if (substr($targetFile, -1, 1) === "/") {
				try {
					\Safe\mkdir($targetFile, 0700, true);
				} catch (FilesystemException $e) {
					$cmd->sendto->reply(
						"There was an error creating <highlight>{$targetFile}<end>."
					);
					$this->logger->error("Error on mkdir of {$targetFile}: ".
						$e->getMessage());
					return false;
				}
			} else {
				try {
					\Safe\file_put_contents($targetFile, $zip->getFromIndex($i));
				} catch (FilesystemException $e) {
					$cmd->sendto->reply(
						"There was an error extracting <highlight>{$targetFile}<end>."
					);
					$this->logger->error("Error on extraction of {$targetFile}: ".
						$e->getMessage());
					return false;
				}
			}
			$index = $zip->getNameIndex($i);
			if ($index === false) {
				continue;
			}
			$this->logger->notice("unzip -> {$targetFile}");
			$this->db->table(self::DB_TABLE)
				->insert([
					"module" => $cmd->package,
					"version" => $cmd->version,
					"file" => "{$cmd->package}/" . substr($index, strlen($subDir)),
				]);
		}
		return true;
	}

	/**
	 * Check if the action in $cmd can be done version-wise
	 */
	public function canInstallVersion(PackageAction $cmd): bool {
		if (!isset($cmd->version)) {
			$cmd->sendto->reply("<highlight>{$cmd->package}<end> is not installed and doesn't provide proper version info.");
			return false;
		}
		if (!isset($cmd->oldVersion) && $cmd->action === $cmd::UPGRADE) {
			$cmd->sendto->reply("<highlight>{$cmd->package}<end> is not installed, nothing to upgrade.");
			return false;
		}
		if (!isset($cmd->oldVersion)) {
			return true;
		}
		// Installed in unknown (pre-aopkg format) version
		if ((string)$cmd->oldVersion === "") {
			return true;
		}
		$cmp = $cmd->oldVersion->cmp($cmd->version);
		if ($cmp < 0 && $cmd->action !== $cmd::UPGRADE) {
			$cmd->sendto->reply(
				"You have <highlight>{$cmd->package} {$cmd->oldVersion}<end> ".
				"installed. Use <highlight><symbol>package update {$cmd->package} {$cmd->version}<end> ".
				"to update this installation."
			);
			return false;
		} elseif ($cmp === 0) {
			$cmd->sendto->reply(
				"<highlight>{$cmd->package} {$cmd->oldVersion}<end> is already installed."
			);
			return false;
		} elseif ($cmp > 0) {
			$cmd->sendto->reply(
				"You cannot downgrade to <highlight>{$cmd->package} {$cmd->version}<end>."
			);
			return false;
		}
		return true;
	}

	/**
	 * Try to find out if all files in the ZIP are in a base
	 * directory (the module dir) or directly in /
	 */
	protected function getSubdir(ZipArchive $zip): string {
		$subdir = "";
		for ($i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				return "";
			}
			$slashPos = strpos($name, "/");
			if ($slashPos === false) {
				return "";
			}
			$subdir = substr($name, 0, $slashPos+1);
		}
		return $subdir;
	}

	/** Try to determine the directory where custom modules shall be installed */
	public function getExtraModulesDir(): ?string {
		$moduleDirs = array_map("realpath", $this->config->moduleLoadPaths);
		$moduleDirs = array_diff($moduleDirs, [\Safe\realpath("./src/Modules")]);
		$extraDir = end($moduleDirs);
		if ($extraDir === false) {
			return null;
		}
		return $extraDir;
	}
}
