<?php declare(strict_types=1);

namespace Nadybot;

use Addendum\ReflectionAnnotatedClass;
use Addendum\ReflectionAnnotatedProperty;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

require 'vendor/autoload.php';


class Migrator {
	/**
	 * @return \RecursiveIteratorIterator<SplFileInfo>
	 */
	private function getFileIterator(): RecursiveIteratorIterator {
		$ite = new RecursiveDirectoryIterator(__DIR__ . "/src");
		return new RecursiveIteratorIterator($ite);
	}

	private function convertFile(SplFileInfo $file): void {
		$oldClasses = get_declared_classes();
		require_once $file->getPathname();
		$newClasses = get_declared_classes();

		$classes = array_values(array_diff($newClasses, $oldClasses));
		foreach ($classes as $class) {
			// $this->convertClass($class);
			$this->convertProperties($class);
		}
	}

	private function convertClass(string $class): void {
		$reflection = new ReflectionAnnotatedClass($class);
		$annotations = $reflection->getAllAnnotations();
		if (empty($annotations)) {
			return;
		}
		$attribs = [];
		$code = file_get_contents($reflection->getFileName());
		foreach ($annotations as $annotation) {
			$annoName = class_basename($annotation);
			$code = preg_replace(
				"/(\n[ \t]*\*[ \t]*)?@" . $annoName . "([ \t]*(?=\n)|\(.*?\)(?=\n)|\(.*?\)(?= \*\/))/s",
				"",
				$code
			);
			$params = [];
			$keys = [];
			$refObj = new ReflectionClass($annotation);
			foreach ($annotation as $key => $value) {
				if (!isset($value)) {
					continue;
				}
				$refProp = $refObj->getProperty($key);
				if ($refProp->hasDefaultValue() && $refProp->getDefaultValue() === $value) {
					continue;
				}
				$keys []= $key;
				$params []= "$key: " . str_replace("\\n", "\\n\".\n\t\t\"", json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS));
			}
			if (empty($keys)) {
				$attribs []= "NCA\\$annoName";
			} elseif ($keys === ["value"]) {
				$attribs []= "NCA\\$annoName(" . str_replace("\\n", "\\n\".\n\t\"", json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS)) . ")";
			} else {
				$attribs []= "NCA\\$annoName(\n\t" . join(",\n\t", $params) . "\n)";
			}
		}
		if (count($attribs) === 1) {
			$attrString = "#[{$attribs[0]}]";
		} else {
			$attrString = "#[\n\t".
				join(",\n\t", array_map(fn($a) => join("\n\t", explode("\n", $a)), $attribs)).
			"\n]";
		}
		$classSearch = "\nclass " . class_basename($class);
		$code = str_replace($classSearch, "\n{$attrString}{$classSearch}", $code);
		$code = preg_replace("/\nuse /s", "\nuse Nadybot\\Core\Attributes as NCA;\nuse ", $code, 1);
		$code = preg_replace("/\n\/\*\*\s*\*\//", "", $code);
		// var_dump($code);
	}

	private function convertProperties(string $class): void {
		$reflection = new ReflectionAnnotatedClass($class);
		/** @var ReflectionAnnotatedProperty[] */
		$properties = $reflection->getProperties();
		if (empty($properties)) {
			return;
		}
		$code = file_get_contents($reflection->getFileName());
		foreach ($properties as $property) {
			$annotations = $property->getAllAnnotations();
			if (empty($annotations)) {
				continue;
			}
			$attribs = [];
			foreach ($annotations as $annotation) {
				$annoName = class_basename($annotation);
				$code = preg_replace(
					"/[ \t]@" . $annoName . "([ \t]*(?=\n)|(\(.*?\))?(?=\n| \*\/))/s",
					"",
					$code,
					1
				);
				$params = [];
				$keys = [];
				$refObj = new ReflectionClass($annotation);
				foreach ($annotation as $key => $value) {
					if (!isset($value)) {
						continue;
					}
					$refProp = $refObj->getProperty($key);
					if ($refProp->hasDefaultValue() && $refProp->getDefaultValue() === $value) {
						continue;
					}
					$keys []= $key;
					$params []= "$key: " . str_replace("\\n", "\\n\".\n\t\t\"", json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS));
				}
				if (empty($keys)) {
					$attribs []= "NCA\\$annoName";
				} elseif ($keys === ["value"]) {
					$attribs []= "NCA\\$annoName(" . str_replace("\\n", "\\n\".\n\t\"", json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS)) . ")";
				} else {
					$attribs []= "NCA\\$annoName(\n\t" . join(",\n\t", $params) . "\n)";
				}
			}
			if (count($attribs) === 1) {
				$attrString = "#[{$attribs[0]}]";
			} else {
				$attrString = "#[\n\t\t".
					join(",\n\t\t", array_map(fn($a) => join("\n\t\t", explode("\n", $a)), $attribs)).
				"\n\t]";
			}
			$code = preg_replace(
				"/(\n[ \t]*)(public|protected|private)[ \ta-zA-Z0-9_]+\\$" . $property->getName() . "\b/s",
				"$1{$attrString}$0",
				$code,
				1
			);
		}
		if (strpos($code, "Attributes as NCU") === false) {
			$code = preg_replace("/\nuse /s", "\nuse Nadybot\\Core\Attributes as NCA;\nuse ", $code, 1);
		}
		$code = preg_replace("/\n[ \t]*\/\*\*[\s*]*\*\//", "", $code);
		var_dump($code);
	}

	public function run(): void {
		foreach (glob("src/Core/Annotations/*") as $file) {
			require_once($file);
		}
		$ite = $this->getFileIterator();
		foreach ($ite as $file) {
			if ($file->isDir() || $file->getExtension() !== "php") {
				continue;
			}
			$this->convertFile($file);
		}
	}
}

$migrator = new Migrator();
$migrator->run();
