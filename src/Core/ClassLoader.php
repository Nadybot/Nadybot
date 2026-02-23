<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{array_flip, parse_ini_string};

use Amp\File\FilesystemException;
use Amp\Parallel\Worker\TaskFailureError;
use Amp\TimeoutCancellation;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	Types\ModuleInstanceInterface,
};
use Nadybot\Core\Exceptions\{
	IntegratedIntoBaseException,
	InvalidCodeException,
	InvalidVersionException
};
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use ReflectionClass;
use RegexIterator;

/** The class loader keeps track of and loads all modules and their classes */
class ClassLoader {
	/**
	 * A list of old modules that are now part of Nadybot
	 *
	 * @var list<string>
	 */
	public const INTEGRATED_MODULES = [
		'ALLIANCE_RELAY_MODULE',
		'SPAWNTIME_MODULE',
		'BIGBOSS_MODULE',
		'GAUNTLET_MODULE',
		'IMPQL_MODULE',
		'EXPORT_MODULE',
	];

	/**
	 * Array of module name => path
	 *
	 * @var array<string,string>
	 */
	private array $registeredModules = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Filesystem $fs;

	/**
	 * Initialize the class loader
	 *
	 * @param list<string> $moduleLoadPaths Relative paths where to look for modules
	 */
	public function __construct(private array $moduleLoadPaths) {
	}

	/**
	 * Get the path for a given module, relative to the bot's base dir
	 *
	 * @return ?string `null` if that module isn't registered, otherwise the path
	 */
	public function getModulePath(string $module): ?string {
		return $this->registeredModules[$module] ?? null;
	}

	/** Remove a module from our registry */
	public function unregisterModule(string $module): void {
		unset($this->registeredModules[$module]);
	}

	/**
	 * Set the path of a given module in our registry to a given value
	 *
	 * @param string $module Name of the module for which to change the path
	 * @param string $path   the relative path of the module
	 */
	public function setModulePath(string $module, string $path): string {
		return $this->registeredModules[$module] = $path;
	}

	/**
	 * Get a list of all registered modules
	 * as an Array of module name => path
	 *
	 * @return array<string,string>
	 */
	public function getRegisteredModules(): array {
		return $this->registeredModules;
	}

	/** Load all classes that provide an #[Instance] and inject their dependencies */
	public function loadInstances(): void {
		$newInstances = static::getInstancesOfClasses(...get_declared_classes());
		unset($newInstances['logger']);
		unset($newInstances[strtolower(Registry::formatName(BotConfig::class))]);

		/** @var array<string,ClassInstance> */
		$newInstances = array_merge($newInstances, $this->getNewInstancesInDir(__DIR__));
		foreach ($newInstances as $name => $class) {
			/** @psalm-suppress MixedMethodCall */
			Registry::setInstance($name, new $class->className());
		}

		$this->loadCoreModules();
		$this->loadUserModules();

		$this->logger->info('Inject dependencies for all instances');
		foreach (Registry::getAllInstances() as $instance) {
			Registry::injectDependencies($instance);
		}

		$this->logger->info('Inject dependencies for all static variables');
		$classes = get_declared_classes();
		foreach ($classes as $className) {
			if (explode('\\', $className)[0] !== 'Nadybot') {
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
			if (isset($entries['minimum_php_version'])) {
				$minimum = (string)$entries['minimum_php_version'];
				$current = \PHP_VERSION;
				if (strnatcmp($minimum, $current) > 0) {
					$this->logger->warning(
						'Could not load module {module} as it requires at least PHP '.
						"version '{minimum_php_version}', but current PHP version is ".
						"{current_php_version}'",
						[
							'module' => $moduleName,
							'minimum_php_version' => $minimum,
							'current_php_version' => $current,
						]
					);
					return;
				}
			}
		}

		try {
			$newInstances = $this->getNewInstancesInDir("{$baseDir}/{$moduleName}");
		} catch (IntegratedIntoBaseException) {
			$this->logger->error('The module {module} got integrated into Nadybot. You can remove it from {path}.', [
				'path' => "{$baseDir}/{$moduleName}",
				'module' => $moduleName,
			]);
			return;
		} catch (InvalidCodeException $e) {
			$this->logger->error('Could not load module {module}: {error}', [
				'module' => $moduleName,
				'error' => 'Parse error in ' . $e->getMessage(). '.',
				'exception' => $e,
			]);
			return;
		} catch (InvalidVersionException) {
			$this->logger->warning(
				"Not enabling module {module}, because it's not compatible with Nadybot {version}.",
				[
					'version' => BotRunner::getVersion(false),
					'module' => $moduleName,
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
			if (Registry::hasInstance($name) && !$class->overwrite) {
				$this->logger->warning("Instance with name '{instance}' already registered--replaced with new instance", [
					'instance' => $name,
				]);
			}
			Registry::setInstance($name, $obj);
		}

		if (count($newInstances) === 0) {
			$this->logger->error('Could not load module {module}: {error}', [
				'module' => $moduleName,
				'error' => 'No classes found with #[Instance] attribute',
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
		$isExtraModule = !str_contains($path, '/src/Core')
			&& strncmp($path, './src/', 6) !== 0;
		$checkCode = extension_loaded('pcntl') && $isExtraModule;
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
			if (substr($fileName, strlen($path), 9) === \DIRECTORY_SEPARATOR . 'Modules' . \DIRECTORY_SEPARATOR) {
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

			$overwrite = false;

			$instanceAttr = $instanceAnnos[0]->newInstance();
			if ($instanceAttr->name !== null) {
				$name = $instanceAttr->name;
				$overwrite = $instanceAttr->overwrite;
			} else {
				$name = Registry::formatName($className);
			}
			$newInstances[$name] = new ClassInstance(
				name: $name,
				className: $className,
				overwrite: $overwrite,
			);
		}
		return $newInstances;
	}

	/** Parse and load all core modules in the correct order */
	private function loadCoreModules(): void {
		// load the core modules, hard-code to ensure they are loaded in the correct order
		$this->logger->notice('Loading CORE modules...');
		$coreModules = [
			'MESSAGES', 'CONFIG', 'SYSTEM', 'ADMIN', 'BAN', 'HELP', 'LIMITS',
			'PLAYER_LOOKUP', 'BUDDYLIST', 'ALTS', 'USAGE', 'PREFERENCES', 'PROFILE',
			'COLORS', 'DISCORD', 'CONSOLE', 'SECURITY',
		];
		$foundModules = array_flip($this->fs->listFiles(__DIR__ . '/Modules'));
		unset($foundModules['SETUP']);
		foreach ($coreModules as $moduleName) {
			$this->registerModule(__DIR__ . '/Modules', $moduleName);
			unset($foundModules[$moduleName]);
		}
		if (count($foundModules)) {
			throw new \Error('Found unexpected modules: '.implode(', ', array_keys($foundModules)));
		}
	}

	/** Parse and load all user modules */
	private function loadUserModules(): void {
		$this->logger->notice('Loading USER modules...');
		foreach ($this->moduleLoadPaths as $path) {
			$this->loadModulesInPath($path);
		}
	}

	/** Parse and load all modules in a given path */
	private function loadModulesInPath(string $path): void {
		$this->logger->info("Loading modules in path '{path}'", ['path' => $path]);
		try {
			$files = $this->fs->listFiles($path);
		} catch (FilesystemException) {
			return;
		}
		foreach ($files as $moduleName) {
			if (in_array($moduleName, ['BIGBOSS_MODULE', 'GAUNTLET_MODULE'], true)) {
				continue;
			}
			if ($this->isModuleDir($path, $moduleName)) {
				$this->registerModule($path, $moduleName);
			}
		}
	}

	/** Test if `$moduleName` is a module in `$path` */
	private function isModuleDir(string $path, string $moduleName): bool {
		return $this->isValidModuleName($moduleName)
			&& $this->fs->isDirectory("{$path}/{$moduleName}");
	}

	/** Check if `$name` is a valid module name */
	private function isValidModuleName(string $name): bool {
		return $name !== '.' && $name !== '..';
	}

	/**
	 * Check if a given version requirement spec matches this bot's version
	 *
	 * @param string $spec The version spec to check.
	 *                     Must be in the format `^6.0.0` or `6.0.0-6.1.0`
	 */
	private function versionRangeCompatible(string $spec): bool {
		$parts = Safe::pregSplit("/\s*,\s*/", $spec);
		foreach ($parts as $part) {
			if (!count($matches = Safe::pregMatch('/^([!=<>^]+)(.+)$/', $part))) {
				return false;
			}
			if (!SemanticVersion::compareUsing(BotRunner::getVersion(false), $matches[2], $matches[1])) {
				return false;
			}
		}
		return true;
	}

	/** Check if the module in `$path` is compatible with this Nadybot version */
	private function isModuleCompatible(string $path): bool {
		if (!$this->fs->exists("{$path}/aopkg.toml")) {
			return true;
		}
		try {
			$toml = $this->fs->read("{$path}/aopkg.toml");
		} catch (FilesystemException) {
			return true;
		}
		if (!count($matches = Safe::pregMatch("/^\s*bot_version\s*=\s*(['\"])(.+)\\1\s*$/m", $toml))) {
			return true;
		}
		return $this->versionRangeCompatible($matches[2]);
	}

	/**
	 * Check if `$fileName` contains no parsing errors and a require would work
	 *
	 * @param string $fileName The filename of the PHP file to load.
	 *                         Either relative to this bot's base directory,
	 *                         or an absolute path.
	 *
	 * @return bool `true` if the file can be loaded, `false` on any compile or linter errors
	 */
	private function checkFileLoads(string $fileName): bool {
		$task = new LintTask($fileName);
		$worker = \Amp\Parallel\Worker\getWorker();
		$execution = $worker->submit($task);
		try {
			$execution->await(new TimeoutCancellation(5));
		} catch (TaskFailureError $e) {
			$this->logger->error('Error loading file {file}: {error}', [
				'file' => $fileName,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return false;
		} finally {
			// $worker->shutdown();
		}
		return true;
	}
}
