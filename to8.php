<?php declare(strict_types=1);

namespace Nadybot;

use Addendum\ReflectionAnnotatedClass;
use Addendum\ReflectionAnnotatedProperty;
use Nadybot\Core\Annotations\Inject;
use Nadybot\Core\Annotations\Instance;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

require 'vendor/autoload.php';


class Migrator {
	/**
	 * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
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
			if ($class !== \Nadybot\Core\ClassLoader::class) {
				// continue;
			}
			$code = $this->convertClass($class);
			$code = $this->convertProperties($class, $code);
			$code = $this->convertMethods($class, $code);
			$ref = new ReflectionClass($class);
			file_put_contents($ref->getFileName(), $code);
		}
	}

	private function addUse(string $code): string {
		if (strpos($code, "Attributes as NCA") === false) {
			$code = preg_replace("/\nuse /s", "\nuse Nadybot\\Core\\Attributes as NCA;\nuse ", $code, 1);
		}
		if (strpos($code, "Attributes as NCA") === false) {
			$code = preg_replace("/\nnamespace.+?\n/s", "$0\nuse Nadybot\\Core\\Attributes as NCA;\n", $code, 1);
		}
		return $code;
	}

	private function convertClass(string $class): string {
		$reflection = new ReflectionAnnotatedClass($class);
		$code = file_get_contents($reflection->getFileName());
		$annotations = $reflection->getAllAnnotations();
		if (empty($annotations)) {
			return $code;
		}
		$attribs = [];
		foreach ($annotations as $annotation) {
			$annoName = class_basename($annotation);
			$code = preg_replace(
				"/(\n[ \t]*\*[ \t]*)@" . $annoName . "([ \t]*(?=\n)|\(.*?\)(?=\n)|\(.*?\)(?= \*\/))/s",
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
				$attribs []= "NCA\\$annoName(" . str_replace("\\n", "\\n\".\n\t\"", json_encode($annotation->value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS)) . ")";
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
		$code = $this->addUse($code);
		$code = preg_replace("/\n\/\*\*\s*\*\//", "", $code);
		return $code;
	}

	private function convertProperties(string $class, ?string $code): string {
		$reflection = new ReflectionAnnotatedClass($class);
		$code ??= file_get_contents($reflection->getFileName());
		/** @var ReflectionAnnotatedProperty[] */
		$properties = $reflection->getProperties();
		if (empty($properties)) {
			return $code;
		}
		$changed = false;
		foreach ($properties as $property) {
			$annotations = $property->getAllAnnotations();
			if (empty($annotations)) {
				continue;
			}
			$attribs = [];
			foreach ($annotations as $annotation) {
				$changed = true;
				$annoName = class_basename($annotation);
				$code = preg_replace(
					"/\*[ \t]+@" . $annoName . "([ \t]*(?=\n)|(\(.*?\))?(?=\n| \*\/))/s",
					"*",
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
					$attribs []= "NCA\\$annoName(" . str_replace("\\n", "\\n\".\n\t\"", json_encode($annotation->value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS)) . ")";
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
		if (!$changed) {
			return $code;
		}
		$code = $this->addUse($code);
		$code = preg_replace("/\n[ \t]*\/\*\*[\s*]*\*\//", "", $code);
		return $code;
	}

	private function convertMethods(string $class, ?string $code): string {
		$reflection = new ReflectionAnnotatedClass($class);
		$code ??= file_get_contents($reflection->getFileName());
		/** @var ReflectionAnnotatedProperty[] */
		$methods = $reflection->getMethods();
		if (empty($methods)) {
			return $code;
		}
		$changed = false;
		foreach ($methods as $method) {
			if (in_array($method->getName(), ["scanApiAnnotations", "scanRouteAnnotations"])) {
				continue;
			}
			$annotations = $method->getAllAnnotations();
			if (empty($annotations)) {
				continue;
			}
			$attribs = [];
			foreach ($annotations as $annotation) {
				if ($annotation instanceof Instance) {
					continue;
				}
				if ($annotation instanceof Inject) {
					continue;
				}
				$changed = true;
				$annoName = class_basename($annotation);
				$code = preg_replace(
					"/\*[ \t]+@" . $annoName . "([ \t]*(?=\n)|(\(.*?\))?(?=\n| \*\/))/s",
					"*",
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
					$attribs []= "NCA\\$annoName(" . str_replace("\\n", "\\n\".\n\t\"", json_encode($annotation->value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS)) . ")";
				} else {
					$attribs []= "NCA\\$annoName(" . join(", ", $params) . ")";
				}
			}
			if (empty($attribs)) {
				continue;
			}
			if (count($attribs) === 1) {
				$attrString = "#[{$attribs[0]}]";
			} else {
				$attrString = "#[\n\t\t".
					join(",\n\t\t", array_map(fn($a) => join("\n\t\t", explode("\n", $a)), $attribs)).
				"\n\t]";
			}
			$code = preg_replace(
				"/(\n[ \t]*)(public|protected|private)[ \ta-zA-Z0-9_]+function[ \t]+" . $method->getName() . "\b/s",
				"$1{$attrString}$0",
				$code,
				1
			);
		}
		if (!$changed) {
			return $code;
		}
		$code = $this->addUse($code);
		$code = preg_replace("/\n[ \t]*\/\*\*[\s*]*\*\//s", "", $code);
		$code = preg_replace("/(\n[ \t]*\*[ \t]*)+(\n[ \t]*\*\/)/s", "$2", $code);
		$code = preg_replace("/(\n[ \t]*\*[ \t]*){2,}/s", "$1", $code);
		return $code;
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
