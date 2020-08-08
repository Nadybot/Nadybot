<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\LegacyLogger;

/**
 * The ConfigFile class provides convenient interface for reading and saving
 * config files located in conf-subdirectory.
 */
class ConfigFile {

	private string $filePath;
	private array $vars = array();

	/**
	 * Constructor method.
	 */
	public function __construct(string $filePath) {
		$this->filePath = $filePath;
	}

	/**
	 * Returns file path to the config file.
	 */
	public function getFilePath(): string {
		return $this->filePath;
	}

	/**
	 * Loads the config file, creating the file if it doesn't exist yet.
	 */
	public function load(): void {
		$this->copyFromTemplateIfNeeded();
		require $this->filePath;
		$this->vars = $vars;
	}

	/**
	 * Saves the config file, creating the file if it doesn't exist yet.
	 */
	public function save(): void {
		$vars = $this->vars;
		$this->copyFromTemplateIfNeeded();
		$lines = file($this->filePath);
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

		// if there are additional vars which were not present in the config
		// file or in template file then add them at end of the config file
		if (!empty($vars)) {
			$lines []= "<?php\n";
			foreach ($vars as $name => $value) {
				if (is_string($value)) {
					$lines []= "\$vars['$name'] = \"$value\";\n";
				} else {
					$lines []= "\$vars['$name'] = $value;\n";
				}
			}
			$lines []= "\n";
		}

		file_put_contents($this->filePath, $lines);
	}

	/**
	 * Returns the $vars variable's contents from the config file.
	 */
	public function getVars(): array {
		return $this->vars;
	}

	/**
	 * Returns var from the config file.
	 *
	 * @param string $name name of the var
	 */
	public function getVar(string $name) {
		if (isset($this->vars[$name])) {
			return $this->vars[$name];
		}
		return null;
	}

	/**
	 * Inserts the $vars array's contents. Any existing indexes are replaced
	 * with the new values.
	 */
	public function insertVars(array $vars): void {
		$this->vars = array_merge($this->vars, $vars);
	}

	/**
	 * Sets var to the config file.
	 */
	public function setVar(string $name, $value): void {
		$this->vars[$name] = $value;
	}

	/**
	 * Copies config.template.php to this config file if it doesn't exist yet.
	 */
	private function copyFromTemplateIfNeeded(): void {
		if (file_exists($this->filePath)) {
			return;
		}
		$templatePath = __DIR__ . '/../../conf/config.template.php';
		if (copy($templatePath, $this->filePath) === false) {
			LegacyLogger::log('ERROR', 'StartUp', "could not create config file: {$this->filePath}");
		}
	}
}
