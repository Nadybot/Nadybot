<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use function Amp\call;
use function Amp\File\filesystem;
use function Safe\json_decode;
use Amp\Cache\FileCache;
use Amp\File\FilesystemException as AmpFilesystemException;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Amp\Sync\LocalKeyedMutex;
use Amp\{Failure, Promise, Success};
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CacheManager,
	ClassLoader,
	CmdContext,
	Config\BotConfig,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	ParamClass\PWord,
	SemanticVersion,
	Text,
	UserException,
};
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Safe\Exceptions\{DirException, FilesystemException, JsonException};

use SplFileInfo;
use Throwable;
use ZipArchive;

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
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): Generator {
		yield from $this->scanForUnregisteredExtraModules();
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
		if (\Safe\realpath(dirname($path)) === \Safe\realpath(dirname(__DIR__, 2)."/Core/Modules")) {
			return static::BUILT_INT;
		}
		return static::EXTRA;
	}

	/** Get a full list of all available packages*/
	#[NCA\HandlesCommand("package")]
	public function listPackagesCommand(
		CmdContext $context,
		#[NCA\Str("list")]
		string $action
	): void {
		$packages = yield $this->getPackages();
		$msg = $this->renderPackageList($packages);
		$context->reply($msg);
	}

	/**
	 * @param Package[] $packages
	 *
	 * @return string|string[]
	 */
	public function renderPackageList(array $packages): string|array {
		/** @var array<string,PackageGroup> */
		$groupedPackages = [];

		/** @var Package[] $packages */
		if (!count($packages)) {
			return "There are currently no packages available for Nadybot.";
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
			if (in_array($package->name, ClassLoader::INTEGRATED_MODULES)) {
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
					(
						$pGroup->highest_supported
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
		return $msg;
	}

	/** Get information about a specific package */
	#[NCA\HandlesCommand("package")]
	public function packageInfoCommand(
		CmdContext $context,
		#[NCA\Str("info")]
		string $action,
		string $package
	): void {
		$packages = yield $this->getPackage($package);
		$msg = $this->getPackageDetail($packages);
		$context->reply($msg);
	}

	/** @param Package[]|null $packages */
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
	 *
	 * @return bool true if we match, false if not
	 */
	public function isVersionCompatible(string $version): bool {
		$parts = \Safe\preg_split("/\s*,\s*/", $version);
		$ourVersion = BotRunner::getVersion();

		foreach ($parts as $part) {
			if (preg_match("/^<\d+\.0\.0$/", $part)) {
				$part .= "-0";
			}
			if (!preg_match("/^([!=<>^]+)(.+)$/", $part, $matches)) {
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
		#[NCA\Str("install")]
		string $action,
		PWord $package,
		?string $version
	): void {
		if (!$this->config->general->enablePackageModule) {
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
		$packages = yield $this->getPackage($package());
		if (!count($packages)) {
			$context->reply("{$package} is not compatible with Nadybot.");
			return;
		}
		$cmd->version = yield $this->getHighestCompatibleVersion($packages, $cmd);
		$data = yield $this->downloadPackage($cmd->package, $cmd->version);
		$msg = yield $this->installPackage($data, $cmd);
		$context->reply($msg);
	}

	/** Update an already installed package, optionally to a specific version */
	#[NCA\HandlesCommand("package")]
	public function packageUpdateCommand(
		CmdContext $context,
		#[NCA\Str("update")]
		string $action,
		PWord $package,
		?string $version
	): void {
		if (!$this->config->general->enablePackageModule) {
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
		$packages = yield $this->getPackage($package());
		if (!count($packages)) {
			$context->reply("{$package} is not compatible with Nadybot.");
			return;
		}
		$cmd->version = yield $this->getHighestCompatibleVersion($packages, $cmd);
		$data = yield $this->downloadPackage($cmd->package, $cmd->version);
		$msg = yield $this->installPackage($data, $cmd);
		$context->reply($msg);
	}

	/** Uninstall a package */
	#[NCA\HandlesCommand("package")]
	public function packageUninstallCommand(
		CmdContext $context,
		#[NCA\Str("uninstall", "delete", "remove", "erase", "del", "rm")]
		string $action,
		string $package
	): void {
		if (!$this->config->general->enablePackageModule) {
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
			$this->logger->error("Cannot determine absolute path of {module_path}", [
				"module_path" => $modulePath,
				"exception" => $e,
			]);
			$context->reply("Something is wrong with the path of this module.");
			return;
		}
		$this->logger->info("Removing {module_path} ({path}) recursively", [
			"module_path" => $modulePath,
			"path" => $path,
		]);
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
			$this->logger->info("Removing {file}", ["file" => $file]);
			$relFile = substr($file, strlen($baseDir));
			if (!@file_exists($file)) {
				$this->logger->info("{file} does not exist", ["file" => $file]);
				continue;
			}
			if (is_dir($file)) {
				$this->logger->notice("rmdir {dir}", ["dir" => $relFile]);
				try {
					\Safe\rmdir($file);
				} catch (FilesystemException $e) {
					$context->reply(
						"Error deleting directory {$relFile}: " . $e->getMessage()
					);
					return;
				}
			} else {
				$this->logger->notice("del {file}", ["file" => $relFile]);
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
	 * Get the latest installed version of $package
	 *
	 * @return Promise<?string> null if uninstalled, "" if unknown version, "x.y.z" otherwise
	 */
	public function getInstalledVersion(string $package, ?string $moduleDir): Promise {
		return call(function () use ($package, $moduleDir): Generator {
			$moduleDir ??= $this->getExtraModulesDir();
			if (!isset($moduleDir)) {
				return null;
			}
			if (false === yield filesystem()->exists("{$moduleDir}/{$package}")) {
				return null;
			}
			if (false === yield filesystem()->exists("{$moduleDir}/{$package}/aopkg.toml")) {
				return "";
			}
			try {
				$content = yield filesystem()->read("{$moduleDir}/{$package}/aopkg.toml");
			} catch (AmpFilesystemException) {
				return "";
			}
			if (!preg_match("/^\s*version\s*=\s*\"(.*?)\"/m", $content, $matches)) {
				return "";
			}
			return $matches[1];
		});
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
				$this->logger->notice("rmdir {dir}", ["dir" => $fullFilename]);
				@rmdir($fullFilename);
			} else {
				$this->logger->notice("del {file}", ["file" => $fullFilename]);
				@unlink($fullFilename);
			}
		}
		return true;
	}

	/** Try to determine the directory where custom modules shall be installed */
	public function getExtraModulesDir(): ?string {
		$moduleDirs = array_map("realpath", $this->config->paths->modules);
		$moduleDirs = array_diff($moduleDirs, [\Safe\realpath("./src/Modules")]);
		$extraDir = end($moduleDirs);
		if ($extraDir === false) {
			return null;
		}
		return $extraDir;
	}

	/**
	 * Try to find out if all files in the ZIP are in a base
	 * directory (the module dir) or directly in /
	 */
	protected function getSubdir(ZipArchive $zip): string {
		$subdir = "";
		for ($i = 0; $i < $zip->numFiles; $i++) {
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

	/** Scan for and add all unregistered extra modules into the database */
	private function scanForUnregisteredExtraModules(): Generator {
		$targetDir = $this->getExtraModulesDir();
		if ($targetDir === null) {
			return;
		}
		try {
			$files = yield filesystem()->listFiles($targetDir);
			foreach ($files as $file) {
				if (!is_dir($file)) {
					continue;
				}
				yield from $this->scanExtraModule($targetDir, $file);
			}
		} catch (DirException) {
		}
	}

	/** Scan for and add all files of $module into the database */
	private function scanExtraModule(string $targetDir, string $module): Generator {
		$exists = $this->db->table(self::DB_TABLE)
			->where("module", $module)
			->exists();
		if ($exists) {
			return;
		}

		$version = yield $this->getInstalledVersion($module, $targetDir);
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
					"file" => $relPath,
				]);
		}
	}

	/**
	 * Download and parse the full package index
	 *
	 * @return Promise<Package[]>
	 */
	private function getPackages(): Promise {
		return call(function (): Generator {
			$cache = new FileCache(
				$this->config->paths->cache . "/PACKAGE_MODULE",
				new LocalKeyedMutex()
			);
			if (null !== $body = yield $cache->get("packages")) {
				return $this->parsePackages($body);
			}
			$client = $this->builder->build();

			/** @var Response */
			$response = yield $client->request(new Request(static::API . "/packages"));
			if ($response->getStatus() !== 200) {
				throw new UserException("There was an error retrieving the list of available packages.");
			}
			$body = yield $response->getBody()->buffer();
			if ($body === '') {
				throw new UserException("Empty response while retrieving the list of available packages.");
			}
			$packages = $this->parsePackages($body);
			$cache->set("packages", $body, 3600);
			return $packages;
		});
	}

	/**
	 * Download and parse the package index for $package
	 *
	 * @return Promise<Package[]>
	 */
	private function getPackage(string $package): Promise {
		return call(function () use ($package): Generator {
			$cache = new FileCache(
				$this->config->paths->cache . "/PACKAGE_MODULE",
				new LocalKeyedMutex()
			);
			if (null !== $body = yield $cache->get($package)) {
				return $this->parsePackages($body);
			}
			$client = $this->builder->build();

			/** @var Response */
			$response = yield $client->request(new Request(static::API . "/packages/{$package}"));
			if ($response->getStatus() === 404) {
				throw new UserException("No such module <highlight>{$package}<end>.");
			} elseif ($response->getStatus() !== 200) {
				throw new UserException("HTTP error retrieving the data for {$package}.");
			}
			$body = yield $response->getBody()->buffer();
			if ($body === '') {
				throw new UserException("Empty response received from HTTP server.");
			}
			$packages = $this->parsePackages($body);
			$cache->set($package, $body, 3600);
			return $packages;
		});
	}

	/** @return Package[] */
	private function parsePackages(string $body): array {
		try {
			$data = json_decode($body, false);
		} catch (JsonException $e) {
			throw new UserException("Package data contained invalid JSON");
		}
		if (!is_array($data)) {
			throw new UserException("Package data was not in the expected format");
		}

		/** @var Collection<Package> */
		$packages = new Collection();
		foreach ($data as $pack) {
			$packages []= JsonImporter::convert(Package::class, $pack);
		}
		$packages = $packages->filter(function (Package $package): bool {
			return $package->bot_type === "Nadybot";
		})->each(function (Package $package): void {
			$package->compatible = $this->isVersionCompatible($package->bot_version);
			$package->state = $this->getInstalledModuleType($package->name);
		})->values()
		->toArray();
		return $packages;
	}

	/**
	 * @param Package[] $packages
	 *
	 * @return string|string[]
	 */
	private function getPackageDetail(array $packages): string|array {
		if (!count($packages)) {
			return "This package is not compatible with Nadybot.";
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
		return $this->text->makeBlob("Details for {$packages[0]->name}", $blob);
	}

	/**
	 * Check if the package is compatible with our Bot
	 *
	 * @param Package[] $packages
	 *
	 * @return Promise<SemanticVersion>
	 */
	private function getHighestCompatibleVersion(array $packages, PackageAction $cmd): Promise {
		if ($packages[0]->state === static::BUILT_INT) {
			return new Failure(new UserException(
				"<highlight>{$cmd->package}<end> is a built-in module in ".
				"Nadybot " . BotRunner::getVersion() ." and cannot be managed ".
				"with this command."
			));
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
			return new Failure(new UserException(
				"<highlight>{$cmd->package}<end> needs the following missing PHP ".
				"extension" . ((count($missingExtensions) > 1) ? "s" : "") . " ".
				"<highlight>" . join(", ", array_keys($missingExtensions)) . "<end>."
			));
		}
		if (isset($cmd->version)) {
			$packages = array_values(
				array_filter(
					$packages,
					function (Package $package) use ($cmd): bool {
						return $cmd->version->cmpStr($package->version) === 0;
					}
				)
			);

			/** @var Package[] $packages */
			if (!count($packages)) {
				return new Failure(new UserException(
					"<highlight>{$cmd->package}<end> does not exist in ".
					"version <highlight>{$cmd->version}<end>."
				));
			}
			if (!$packages[0]->compatible) {
				// return new Failure(new UserException(
				// 	"<highlight>{$cmd->package} {$cmd->version}<end> ".
				// 	"is not compatible with Nadybot " . BotRunner::getVersion()
				// ));
			}
			return new Success($cmd->version);
		}
		$packages = array_values(
			array_filter(
				$packages,
				function (Package $package): bool {
					return $package->compatible;
				}
			)
		);
		$newestPackage = $packages[0] ?? false;
		if ($newestPackage === false) {
			return new Failure(new UserException(
				"No version of <highlight>{$cmd->package}<end> found that ".
				"is compatible with Nadybot " . BotRunner::getVersion() . "."
			));
		}
		return new Success(new SemanticVersion($newestPackage->version));
	}

	/** @return Promise<string> */
	private function downloadPackage(string $package, SemanticVersion $version): Promise {
		return call(function () use ($package, $version): Generator {
			$url = static::API . "/packages/{$package}/{$version}/download";
			$client = $this->builder->build();

			/** @var Response */
			$response = yield $client->request(new Request($url));
			if ($response->getStatus() === 404) {
				throw new UserException(
					"No package <highlight>{$package}<end> found in version <highlight>{$version}<end>."
				);
			} elseif ($response->getStatus() !== 200) {
				throw new UserException("Error downloading the package.");
			}
			if ($response->getHeader('content-type') !== "application/zip") {
				throw new UserException("The downloaded data was not a package");
			}
			$body = yield $response->getBody()->buffer();
			if ($body === '') {
				throw new UserException("The server returned an empty reply when downloading the package.");
			}
			return $body;
		});
	}

	/** Try to get a ZipArchive from a HttpResponse */
	private function getZip(string $data): ZipArchive {
		try {
			$temp = \Safe\tempnam(sys_get_temp_dir(), "nadybot-module");
			\Safe\file_put_contents($temp, $data);
		} catch (FilesystemException $e) {
			throw new UserException(
				"Error writing to temporary file: " . $e->getMessage()
			);
		}
		$zip = new ZipArchive();
		$openResult = $zip->open($temp);
		@unlink($temp);
		if ($openResult !== true) {
			throw new UserException("The downloaded file was corrupt.");
		}
		if ($zip->numFiles < 1) {
			throw new UserException("The package didn't contain any data.");
		}
		return $zip;
	}

	/**
	 * Install a requested package that comes as a callback
	 *
	 * @return Promise<string>
	 */
	private function installPackage(string $data, PackageAction $cmd): Promise {
		return call(function () use ($data, $cmd): Generator {
			if (!extension_loaded("zip")) {
				throw new UserException(
					"Your PHP version does not have the \"zip\" extension installed. ".
					"If you want to be able to use this command, make sure to add that ".
					"extension on your system."
				);
			}
			try {
				$zip = $this->getZip($data);
			} catch (UserException $e) {
				return new Failure($e);
			}
			$targetDir = $this->getExtraModulesDir();

			if ($targetDir === null) {
				throw new UserException(
					"Your Bot configuration does not have an extra modules dir defined. ".
					"If you want to be able to install additional, user-provided modules, ".
					"please add one."
				);
			}
			$oldVersion = yield $this->getInstalledVersion($cmd->package, $targetDir);
			$cmd->oldVersion = isset($oldVersion) ? new SemanticVersion($oldVersion) : null;
			try {
				yield $this->checkCanInstallVersion($cmd);
			} catch (UserException $e) {
				return new Failure($e);
			}

			$this->logger->notice("Installing module {package} into {dir}", [
				"package" => $cmd->package,
				"dir" => $targetDir . DIRECTORY_SEPARATOR . $cmd->package,
			]);
			if (!@file_exists("{$targetDir}/{$cmd->package}/")) {
				try {
					\Safe\mkdir("{$targetDir}/{$cmd->package}", 0700, true);
				} catch (FilesystemException $e) {
					$this->logger->error("Error on mkdir of {dir}: {error}", [
						"dir" => $targetDir . DIRECTORY_SEPARATOR . $cmd->package,
						"error" => $e->getMessage(),
						"exception" => $e,
					]);
					throw new UserException(
						"There was an error creating ".
						"<highlight>{$targetDir}/{$cmd->package}<end>."
					);
				}
			}
			$this->removePackageInstallation($cmd, $targetDir);

			$this->db->table(self::DB_TABLE)
				->where("module", $cmd->package)
				->delete();
			yield $this->installAndRegisterZip($zip, $cmd, $targetDir);

			$this->chatBot->runner->classLoader->registeredModules[$cmd->package] = $targetDir . "/" . $cmd->package;
			if ($cmd->action === $cmd::INSTALL) {
				return "<highlight>{$cmd->package} {$cmd->version}<end> installed successfully. ".
					"Restart the bot for the changes to take effect.";
			}
			return "<highlight>{$cmd->package}<end> successfully upgraded ".
					($cmd->oldVersion ? "from {$cmd->oldVersion} " : "").
					"to {$cmd->version}. ".
					"Restart the bot for the changes to take effect.";
		});
	}

	/**
	 * Extract all files from the zip file according to spec
	 * and register them in the database for update
	 *
	 * @return Promise<void>
	 */
	private function installAndRegisterZip(ZipArchive $zip, PackageAction $cmd, string $targetDir): Promise {
		return call(function () use ($zip, $cmd, $targetDir): Generator {
			$subDir = $this->getSubdir($zip);
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$fileName = $zip->getNameIndex($i);
				if ($subDir === $fileName || $fileName === false) {
					continue;
				}
				$targetFile = "{$targetDir}/{$cmd->package}/" . substr($fileName, strlen($subDir));
				if (substr($targetFile, -1, 1) === "/") {
					try {
						if (false === yield filesystem()->exists($targetFile)) {
							yield filesystem()->createDirectoryRecursively($targetFile, 0700);
						}
					} catch (Throwable $e) {
						$this->logger->error("Error on mkdir of {dir}: {error}", [
							"dir" => $targetFile,
							"error" => $e->getMessage(),
							"exception" => $e,
						]);
						throw new UserException(
							"There was an error creating <highlight>{$targetFile}<end>."
						);
					}
				} else {
					try {
						$fileData = $zip->getFromIndex($i);
						if ($fileData === false) {
							continue;
						}
						yield filesystem()->write($targetFile, $fileData);
					} catch (Throwable $e) {
						$this->logger->error("Error on extraction of {file}: {error}", [
							"file" => $targetFile,
							"error" => $e->getMessage(),
							"exception" => $e,
						]);
						throw new UserException(
							"There was an error extracting <highlight>{$targetFile}<end>."
						);
					}
				}
				$index = $zip->getNameIndex($i);
				if ($index === false) {
					continue;
				}
				$this->logger->notice("unzip -> {file}", ["file" => $targetFile]);
				$this->db->table(self::DB_TABLE)
					->insert([
						"module" => $cmd->package,
						"version" => $cmd->version,
						"file" => "{$cmd->package}/" . substr($index, strlen($subDir)),
					]);
			}
		});
	}

	/**
	 * Check if the action in $cmd can be done version-wise
	 *
	 * @return Promise<bool>
	 */
	private function checkCanInstallVersion(PackageAction $cmd): Promise {
		if (!isset($cmd->version)) {
			return new Failure(new UserException(
				"<highlight>{$cmd->package}<end> is not installed and doesn't provide proper version info."
			));
		}
		if (!isset($cmd->oldVersion) && $cmd->action === $cmd::UPGRADE) {
			return new Failure(new UserException(
				"<highlight>{$cmd->package}<end> is not installed, nothing to upgrade."
			));
		}
		if (!isset($cmd->oldVersion)) {
			return new Success(true);
		}
		// Installed in unknown (pre-aopkg format) version
		if ((string)$cmd->oldVersion === "") {
			return new Success(true);
		}
		$cmp = $cmd->oldVersion->cmp($cmd->version);
		if ($cmp < 0 && $cmd->action !== $cmd::UPGRADE) {
			return new Failure(new UserException(
				"You have <highlight>{$cmd->package} {$cmd->oldVersion}<end> ".
				"installed. Use <highlight><symbol>package update {$cmd->package} {$cmd->version}<end> ".
				"to update this installation."
			));
		} elseif ($cmp === 0) {
			return new Failure(new UserException(
				"<highlight>{$cmd->package} {$cmd->oldVersion}<end> is already installed."
			));
		} elseif ($cmp > 0) {
			return new Failure(new UserException(
				"You cannot downgrade to <highlight>{$cmd->package} {$cmd->version}<end>."
			));
		}
		return new Success(true);
	}
}
