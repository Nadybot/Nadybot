<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{fclose, parse_ini_string, preg_split};

use Amp\File\{Filesystem, FilesystemException};
use Directory;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use ReflectionClass;
use RegexIterator;

class ClassLoader {
	public const INTEGRATED_MODULES = [
		"ALLIANCE_RELAY_MODULE",
		"SPAWNTIME_MODULE",
		"BIGBOSS_MODULE",
		"GAUNTLET_MODULE",
		"IMPQL_MODULE",
		"EXPORT_MODULE",
	];

	/**
	 * Array of module name => path
	 *
	 * @var array<string,string>
	 */
	public array $registeredModules = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Filesystem $fs;

	/**
	 * Relative directories where to look for modules
	 *
	 * @var string[]
	 */
	private array $moduleLoadPaths;

	/**
	 * Initialize the class loader
	 *
	 * @param string[] $moduleLoadPaths Relative paths where to look for modules
	 */
	public function __construct(array $moduleLoadPaths) {
		$this->moduleLoadPaths = $moduleLoadPaths;
	}

	/** Load all classes that provide an #[Instance] */
	public function loadInstances(): void {
		$newInstances = $this->getInstancesOfClasses(...get_declared_classes());
		unset($newInstances["logger"]);
		unset($newInstances[strtolower(Registry::formatName(BotConfig::class))]);
		$newInstances = array_merge($newInstances, $this->getNewInstancesInDir(__DIR__));
		foreach ($newInstances as $name => $class) {
			Registry::setInstance($name, new $class->className());
		}

		$this->loadCoreModules();
		$this->loadUserModules();

		$this->logger->info("Inject dependencies for all instances");
		foreach (Registry::getAllInstances() as $instance) {
			Registry::injectDependencies($instance);
		}

		$this->logger->info("Inject dependencies for all static variables");
		$classes = get_declared_classes();
		foreach ($classes as $className) {
			if (explode("\\", $className)[0] !== 'Nadybot') {
				continue;
			}
			$reflection = new ReflectionClass($className);
			$instanceAnnos = $reflection->getAttributes(NCA\Instance::class);
			if (count($instanceAnnos)) {
				continue;
			}
			Registry::injectDependencies($className);
		}
	}

	/** Register a module in a basedir and check compatibility */
	public function registerModule(string $baseDir, string $moduleName): void {
		// read module.ini file (if it exists) from module's directory
		if ($this->fs->exists("{$baseDir}/{$moduleName}/module.ini")) {
			$entries = parse_ini_string($this->fs->read("{$baseDir}/{$moduleName}/module.ini"));
			// check that current PHP version is greater or equal than module's
			// minimum required PHP version
			if (is_array($entries) && isset($entries["minimum_php_version"])) {
				$minimum = $entries["minimum_php_version"];
				$current = phpversion();
				if (strnatcmp($minimum, $current) > 0) {
					$this->logger->warning("Could not load module"
					." {$moduleName} as it requires at least PHP version '{$minimum}',"
					." but current PHP version is '{$current}'");
					return;
				}
			}
		}

		try {
			$newInstances = $this->getNewInstancesInDir("{$baseDir}/{$moduleName}");
		} catch (IntegratedIntoBaseException $e) {
			$this->logger->error("The module {module} got integrated into Nadybot. You can remove it from {path}.", [
				"path" => "{$baseDir}/{$moduleName}",
				"module" => $moduleName,
			]);
			return;
		} catch (InvalidCodeException $e) {
			$this->logger->error("Could not load module {module}: {error}", [
				"module" => $moduleName,
				"error" => "Parse error in " . $e->getMessage(). ".",
			]);
			return;
		} catch (InvalidVersionException $e) {
			$this->logger->warning(
				"Not enabling module {module}, because it's not compatible with Nadybot {version}.",
				[
					"version" => BotRunner::getVersion(false),
					"module" => $moduleName,
				]
			);
			return;
		}
		foreach ($newInstances as $name => $class) {
			$className = $class->className;
			if (!class_exists($className) || !is_subclass_of($className, ModuleInstanceInterface::class)) {
				continue;
			}
			$obj = new $className();
			$obj->setModuleName($moduleName);
			if (Registry::instanceExists($name) && !$class->overwrite) {
				$this->logger->warning("Instance with name '{instance}' already registered--replaced with new instance", [
					"instance" => $name,
				]);
			}
			Registry::setInstance($name, $obj);
		}

		if (count($newInstances) == 0) {
			$this->logger->error("Could not load module {module}: {error}", [
				"module" => $moduleName,
				"error" => "No classes found with #[Instance] attribute",
			]);
			return;
		}
		$this->registeredModules[$moduleName] = "{$baseDir}/{$moduleName}";
	}

	/**
	 * Get a list of all module which provide an #[Instance] for a directory
	 *
	 * @return array<string,ClassInstance> A mapping [module name => class info]
	 *
	 * @throws InvalidVersionException     If the module is not compatible
	 * @throws InvalidCodeException        If the module doesn't parse
	 * @throws IntegratedIntoBaseException If the module has been integrated into Nadybot
	 */
	public function getNewInstancesInDir(string $path): array {
		$original = get_declared_classes();
		$files = [];
		$isExtraModule = !str_contains($path, "/src/Core")
			&& strncmp($path, "./src/", 6) !== 0;
		$checkCode = extension_loaded("pcntl") && $isExtraModule;
		if (!$this->isModuleCompatible($path)) {
			throw new InvalidVersionException();
		}
		foreach (self::INTEGRATED_MODULES as $integrated) {
			if (str_ends_with($path, "/{$integrated}") && $isExtraModule) {
				throw new IntegratedIntoBaseException('');
			}
		}
		$dirIter = new RecursiveDirectoryIterator($path);
		$outerIter = new RecursiveIteratorIterator($dirIter);
		$iter = new RegexIterator($outerIter, '/\.php$/i', RecursiveRegexIterator::MATCH);
		foreach ($iter as $file) {
			/** @var \SplFileInfo $file */
			$fileName = $file->getPathname();
			if (substr($fileName, strlen($path), 9) === DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR) {
				continue;
			}
			if ($checkCode && !$this->checkFileLoads($fileName)) {
				throw new InvalidCodeException($fileName);
			}
			$files []= $fileName;
		}

		foreach ($files as $file) {
			require_once "{$file}";
		}
		$new = array_diff(get_declared_classes(), $original);

		return static::getInstancesOfClasses(...$new);
	}

	/**
	 * Get a list of all instances which provide an #[Instance] from a list of classes
	 *
	 * @phpstan-param class-string $classes
	 *
	 * @return array<string,ClassInstance> A mapping [instance name => class info]
	 */
	public static function getInstancesOfClasses(string ...$classes): array {
		$newInstances = [];
		foreach ($classes as $className) {
			$reflection = new ReflectionClass($className);
			$instanceAnnos = $reflection->getAttributes(NCA\Instance::class);
			if (!count($instanceAnnos)) {
				continue;
			}
			$instance = new ClassInstance();
			$instance->className = $className;

			/** @var NCA\Instance */
			$instanceAttr = $instanceAnnos[0]->newInstance();
			if ($instanceAttr->name !== null) {
				$name = $instanceAttr->name;
				$instance->overwrite = $instanceAttr->overwrite;
			} else {
				$name = Registry::formatName($className);
			}
			$instance->name = $name;
			$newInstances[$name] = $instance;
		}
		return $newInstances;
	}

	/** Parse and load all core modules */
	private function loadCoreModules(): void {
		// load the core modules, hard-code to ensure they are loaded in the correct order
		$this->logger->notice("Loading CORE modules...");
		$coreModules = [
			'MESSAGES', 'CONFIG', 'SYSTEM', 'ADMIN', 'BAN', 'HELP', 'LIMITS',
			'PLAYER_LOOKUP', 'BUDDYLIST', 'ALTS', 'USAGE', 'PREFERENCES', 'PROFILE',
			'COLORS', 'DISCORD', 'CONSOLE', 'SECURITY',
		];
		foreach ($coreModules as $moduleName) {
			$this->registerModule(__DIR__ . "/Modules", $moduleName);
		}
	}

	/** Parse and load all user modules */
	private function loadUserModules(): void {
		$this->logger->notice("Loading USER modules...");
		foreach ($this->moduleLoadPaths as $path) {
			$this->logger->info("Loading modules in path '{path}'", ["path" => $path]);
			if (!$this->fs->exists($path) || !(($d = dir($path)) instanceof Directory)) {
				continue;
			}
			while (false !== ($moduleName = $d->read())) {
				if (in_array($moduleName, ["BIGBOSS_MODULE", "GAUNTLET_MODULE"])) {
					continue;
				}
				if ($this->isModuleDir($path, $moduleName)) {
					$this->registerModule($path, $moduleName);
				}
			}
			$d->close();
		}
	}

	/** Test if $moduleName is a module in $path */
	private function isModuleDir(string $path, string $moduleName): bool {
		return $this->isValidModuleName($moduleName)
			&& $this->fs->isDirectory("{$path}/{$moduleName}");
	}

	/** Check if $name is a valid module name */
	private function isValidModuleName(string $name): bool {
		return $name !== '.' && $name !== '..';
	}

	private function versionRangeCompatible(string $spec): bool {
		$parts = preg_split("/\s*,\s*/", $spec);
		foreach ($parts as $part) {
			if (!preg_match("/^([!=<>^]+)(.+)$/", $part, $matches)) {
				return false;
			}
			if (!SemanticVersion::compareUsing(BotRunner::getVersion(false), $matches[2], $matches[1])) {
				return false;
			}
		}
		return true;
	}

	/** Check if the module in $path is compatible with this Nadybot version */
	private function isModuleCompatible(string $path): bool {
		if (!$this->fs->exists("{$path}/aopkg.toml")) {
			return true;
		}
		try {
			$toml = $this->fs->read("{$path}/aopkg.toml");
		} catch (FilesystemException) {
			return true;
		}
		if (!preg_match("/^\s*bot_version\s*=\s*(['\"])(.+)\\1\s*$/m", $toml, $matches)) {
			return true;
		}
		return $this->versionRangeCompatible($matches[2]);
	}

	/** Check if $fileName contains no parsing errors and a require would work */
	private function checkFileLoads(string $fileName): bool {
		if (!extension_loaded("pcntl")) {
			return true;
		}
		$pid = pcntl_fork();
		if ($pid === 0) {
			// The child merely closes all pipes
			// @todo Rewrite with AMP3
			// @phpstan-ignore-next-line
			fclose($fd = STDOUT);
			// @phpstan-ignore-next-line
			fclose($fd = STDERR);
			// If this gives an error, the child will exit with != 0
			require_once "{$fileName}";
			exit(0);
		} elseif ($pid > 0) {
			pcntl_waitpid($pid, $status);
			return $status === 0;
		}
		// Error forking
		return true;
	}
}
