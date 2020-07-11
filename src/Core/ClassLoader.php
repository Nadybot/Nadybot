<?php

namespace Budabot\Core;

use Addendum\ReflectionAnnotatedClass;

class ClassLoader {
	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * Relative directories where to look for modules
	 *
	 * @var string[] $moduleLoadPaths
	 */
	private $moduleLoadPaths;

	/**
	 * Initialize the class loader
	 *
	 * @param string[] $moduleLoadPaths Relative paths where to look for modules
	 * @return self
	 */
	public function __construct($moduleLoadPaths) {
		$this->moduleLoadPaths = $moduleLoadPaths;
	}

	/**
	 * Load all classes that provide an @Instance
	 *
	 * @return void
	 */
	public function loadInstances() {
		$newInstances = $this->getNewInstancesInDir(__DIR__);
		foreach ($newInstances as $name => $className) {
			Registry::setInstance($name, new $className);
		}

		$this->loadCoreModules();
		$this->loadUserModules();

		$this->logger->log('DEBUG', "Inject dependencies for all instances");
		foreach (Registry::getAllInstances() as $instance) {
			Registry::injectDependencies($instance);
		}
	}

	/**
	 * Parse and load all core modules
	 *
	 * @return void
	 */
	private function loadCoreModules() {
		// load the core modules, hard-code to ensure they are loaded in the correct order
		$this->logger->log('INFO', "Loading CORE modules...");
		$core_modules = array('CONFIG', 'SYSTEM', 'ADMIN', 'BAN', 'HELP', 'LIMITS', 'PLAYER_LOOKUP', 'BUDDYLIST', 'ALTS', 'USAGE', 'PREFERENCES', 'PROFILE', 'COLORS', 'DISCORD');
		foreach ($core_modules as $moduleName) {
			$this->registerModule(__DIR__ . "/Modules", $moduleName);
		}
	}

	/**
	 * Parse and load all user modules
	 *
	 * @return void
	 */
	private function loadUserModules() {
		$this->logger->log('INFO', "Loading USER modules...");
		foreach ($this->moduleLoadPaths as $path) {
			$this->logger->log('DEBUG', "Loading modules in path '$path'");
			if (file_exists($path) && $d = dir($path)) {
				while (false !== ($moduleName = $d->read())) {
					if ($this->isModuleDir($path, $moduleName)) {
						$this->registerModule($path, $moduleName);
					}
				}
				$d->close();
			}
		}
	}

	/**
	 * Test if $moduleName is a module in $path
	 *
	 * @param string $path relative direcotry
	 * @param string $moduleName Name of the module
	 * @return boolean
	 */
	private function isModuleDir($path, $moduleName) {
		return $this->isValidModuleName($moduleName)
			&& is_dir("$path/$moduleName");
	}

	/**
	 * Check if $name is a valid module name
	 *
	 * @param string $name
	 * @return boolean
	 */
	private function isValidModuleName($name) {
		return $name !== '.' && $name !== '..';
	}

	/**
	 * Register a modue in a basedir and check compatibility
	 *
	 * @param string $baseDir The base module dir (src/Modules, extras)
	 * @param string $moduleName Name of the module (WHOIS_MODULE)
	 * @return void
	 */
	public function registerModule($baseDir, $moduleName) {
		// read module.ini file (if it exists) from module's directory
		if (file_exists("{$baseDir}/{$moduleName}/module.ini")) {
			$entries = parse_ini_file("{$baseDir}/{$moduleName}/module.ini");
			// check that current PHP version is greater or equal than module's
			// minimum required PHP version
			if (isset($entries["minimum_php_version"])) {
				$minimum = $entries["minimum_php_version"];
				$current = phpversion();
				if (strnatcmp($minimum, $current) > 0) {
					$this->logger->log('WARN', "Could not load module"
					." {$moduleName} as it requires at least PHP version '$minimum',"
					." but current PHP version is '$current'");
					return;
				}
			}
		}

		$newInstances = $this->getNewInstancesInDir("{$baseDir}/{$moduleName}");
		foreach ($newInstances as $name => $className) {
			$obj = new $className;
			$obj->moduleName = $moduleName;
			if (Registry::instanceExists($name)) {
				$this->logger->log('WARN', "Instance with name '$name' already registered--replaced with new instance");
			}
			Registry::setInstance($name, $obj);
		}

		if (count($newInstances) == 0) {
			$this->logger->log('ERROR', "Could not load module {$moduleName}. No classes found with @Instance annotation!");
			return;
		}
	}

	/**
	 * Get a list of all module which provide an @Instance for a directory
	 *
	 * @param string $path The relative path where to look
	 * @return string[] A mapping [module name => class name]
	 */
	public static function getNewInstancesInDir($path) {
		$original = get_declared_classes();
		if ($dir = dir($path)) {
			while (false !== ($file = $dir->read())) {
				if (!is_dir($path . '/' . $file) && preg_match("/\\.php$/i", $file)) {
					require_once "{$path}/{$file}";
				}
			}
			$dir->close();
		}
		$new = array_diff(get_declared_classes(), $original);

		$newInstances = array();
		foreach ($new as $className) {
			$reflection = new ReflectionAnnotatedClass($className);
			if ($reflection->hasAnnotation('Instance')) {
				if ($reflection->getAnnotation('Instance')->value !== null) {
					$name = $reflection->getAnnotation('Instance')->value;
				} else {
					$name = Registry::formatName($className);
				}
				$newInstances[$name] = $className;
			}
		}
		return $newInstances;
	}
}
