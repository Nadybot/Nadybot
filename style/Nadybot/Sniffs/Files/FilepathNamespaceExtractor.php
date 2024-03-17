<?php declare(strict_types=1);

namespace PHP_CodeSniffer\Standards\Nadybot\Sniffs\Files;

use const PATHINFO_EXTENSION;
use function array_fill_keys;
use function array_filter;
use function array_map;
use function array_shift;
use function array_unshift;
use function count;
use function explode;
use function implode;
use function in_array;
use function pathinfo;
use function strlen;
use function strtolower;
use function substr;

class FilepathNamespaceExtractor {
	/** @var array<string, string> */
	private $rootNamespaces;

	/** @var array<string, bool> dir(string) => true(bool) */
	private $skipDirs;

	/** @var list<string> */
	private $extensions;

	/**
	 * @param array<string, string> $rootNamespaces directory(string) => namespace
	 * @param list<string>          $skipDirs
	 * @param list<string>          $extensions     index(integer) => extension
	 */
	public function __construct(array $rootNamespaces, array $skipDirs, array $extensions) {
		$this->rootNamespaces = $rootNamespaces;
		$this->skipDirs = array_fill_keys($skipDirs, true);
		$this->extensions = array_map(static function (string $extension): string {
			return strtolower($extension);
		}, $extensions);
	}

	public function getTypeNameFromProjectPath(string $path): ?string {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!in_array($extension, $this->extensions, true)) {
			return null;
		}

		/** @var list<string> $pathParts */
		$pathParts = \Safe\preg_split('~[/\\\]~', $path);
		$rootNamespace = null;
		while (count($pathParts) > 0) {
			array_shift($pathParts);
			foreach ($this->rootNamespaces as $directory => $namespace) {
				if (!str_starts_with(implode('/', $pathParts) . '/', $directory . '/')) {
					continue;
				}

				$directoryPartsCount = count(explode('/', $directory));
				for ($i = 0; $i < $directoryPartsCount; $i++) {
					array_shift($pathParts);
				}

				$rootNamespace = $namespace;
				break 2;
			}
		}

		if ($rootNamespace === null) {
			return null;
		}

		array_unshift($pathParts, $rootNamespace);

		$typeName = implode('\\', array_filter($pathParts, function (string $pathPart): bool {
			return !isset($this->skipDirs[$pathPart]);
		}));

		return substr($typeName, 0, -strlen('.' . $extension));
	}
}
