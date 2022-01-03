<?php declare(strict_types=1);

namespace Nadybot\Core;

use Directory;
use Nadybot\Core\Attributes as NCA;
use ReflectionClass;

class ClassLoader {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * Relative directories where to look for modules
	 *
	 * @var string[]
	 */
	private array $moduleLoadPaths;

	/**
	 * Array of module name => path
	 * @var array<string,string>
	 */
	public array $registeredModules = [];

	/**
	 * Initialize the class loader
	 *
	 * @param string[] $moduleLoadPaths Relative paths where to look for modules
	 */
	public function __construct(array $moduleLoadPaths) {
		$this->moduleLoadPaths = $moduleLoadPaths;
	}

	/**
	 * Load all classes that provide an #[Instance]
	 */
	public function loadInstances(): void {
		$newInstances = $this->getInstancesOfClasses(...get_declared_classes());
		unset($newInstances["logger"]);
		unset($newInstances["configfile"]);
		$newInstances = array_merge($newInstances, $this->getNewInstancesInDir(__DIR__));
		foreach ($newInstances as $name => $class) {
			Registry::setInstance($name, new $class->className);
		}

		$this->loadCoreModules();
		$this->loadUserModules();

		$this->logger->info("Inject dependencies for all instances");
		foreach (Registry::getAllInstances() as $instance) {
			Registry::injectDependencies($instance);
		}
	}

	/**
	 * Parse and load all core modules
	 */
	private function loadCoreModules(): void {
		// load the core modules, hard-code to ensure they are loaded in the correct order
		$this->logger->notice("Loading CORE modules...");
		$coreModules = [
			'MESSAGES', 'CONFIG', 'SYSTEM', 'ADMIN', 'BAN', 'HELP', 'LIMITS',
			'PLAYER_LOOKUP', 'BUDDYLIST', 'ALTS', 'USAGE', 'PREFERENCES', 'PROFILE',
			'COLORS', 'DISCORD', 'CONSOLE', 'SECURITY'
		];
		foreach ($coreModules as $moduleName) {
			$this->registerModule(__DIR__ . "/Modules", $moduleName);
		}
	}

	/**
	 * Parse and load all user modules
	 */
	private function loadUserModules(): void {
		$this->logger->notice("Loading USER modules...");
		foreach ($this->moduleLoadPaths as $path) {
			$this->logger->info("Loading modules in path '$path'");
			if (!@file_exists($path) || !(($d = dir($path)) instanceof Directory)) {
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

	/**
	 * Test if $moduleName is a module in $path
	 */
	private function isModuleDir(string $path, string $moduleName): bool {
		return $this->isValidModuleName($moduleName)
			&& is_dir("$path/$moduleName");
	}

	/**
	 * Check if $name is a valid module name
	 */
	private function isValidModuleName(string $name): bool {
		return $name !== '.' && $name !== '..';
	}

	/**
	 * Register a module in a basedir and check compatibility
	 */
	public function registerModule(string $baseDir, string $moduleName): void {
		// read module.ini file (if it exists) from module's directory
		if (file_exists("{$baseDir}/{$moduleName}/module.ini")) {
			$entries = parse_ini_file("{$baseDir}/{$moduleName}/module.ini");
			// check that current PHP version is greater or equal than module's
			// minimum required PHP version
			if (isset($entries["minimum_php_version"])) {
				$minimum = $entries["minimum_php_version"];
				$current = phpversion();
				if (strnatcmp($minimum, $current) > 0) {
					$this->logger->warning("Could not load module"
					." {$moduleName} as it requires at least PHP version '$minimum',"
					." but current PHP version is '$current'");
					return;
				}
			}
		}

		$newInstances = $this->getNewInstancesInDir("{$baseDir}/{$moduleName}");
		foreach ($newInstances as $name => $class) {
			$className = $class->className;
			$obj = new $className();
			$obj->moduleName = $moduleName;
			if (Registry::instanceExists($name) && !$class->overwrite) {
				$this->logger->warning("Instance with name '$name' already registered--replaced with new instance");
			}
			Registry::setInstance($name, $obj);
		}

		if (count($newInstances) == 0) {
			$this->logger->error("Could not load module {$moduleName}. No classes found with #[Instance] attribute!");
			return;
		}
		$this->registeredModules[$moduleName] = "{$baseDir}/{$moduleName}";
	}

	/**
	 * Get a list of all module which provide an #[Instance] for a directory
	 *
	 * @return array<string,ClassInstance> A mapping [module name => class info]
	 */
	public static function getNewInstancesInDir(string $path): array {
		$original = get_declared_classes();
		if ($dir = dir($path)) {
			while (($file = $dir->read()) !== false) {
				if (!is_dir($path . '/' . $file) && preg_match("/\\.php$/i", $file)) {
					require_once "{$path}/{$file}";
				}
			}
			$dir->close();
		}
		$new = array_diff(get_declared_classes(), $original);

		return static::getInstancesOfClasses(...$new);
	}

	/**
	 * Get a list of all instances which provide an #[Instance] from a list of classes
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
}
