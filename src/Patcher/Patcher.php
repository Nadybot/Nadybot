<?php declare(strict_types=1);

namespace Nadybot\Patcher;

use Composer\DependencyResolver\Operation\{InstallOperation, UpdateOperation};
use Composer\Installer\PackageEvent;
use Composer\Package\Package;
use Exception;

/**
 * This class is used as a callback-provider when installing or updating
 * composer packages.
 *
 * - PHP Codesniffer gets a default config to use the Nadybot styleguide.
 *   deprecation warnings.
 */
class Patcher {
	/** Callback for composer install and update events */
	public static function patch(PackageEvent $event): void {
		$vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
		$operation = $event->getOperation();
		if ($operation instanceof InstallOperation) {
			$package = $operation->getPackage();
		} elseif ($operation instanceof UpdateOperation) {
			$package = $operation->getTargetPackage();
		} else {
			throw new Exception('You are using an unsupported composer version');
		}

		assert($package instanceof \Composer\Package\Package);

		if ($package->getName() === 'farafiri/php-parsing-tool') {
			static::patchParsingTool($vendorDir, $package);
		}
		if ($package->getName() === 'yosymfony/toml') {
			static::patchTomlParser($vendorDir, $package);
		}
	}

	/**
	 * Patch PHP Parsing tool to allow dynamic properties
	 *
	 * @param string                    $vendorDir The installation base path
	 * @param \Composer\Package\Package $package   The package being installed
	 */
	public static function patchParsingTool(string $vendorDir, Package $package): void {
		$file = $vendorDir . '/' . $package->getName() . '/src/SyntaxTreeNode/Base.php';
		$oldContent = file_get_contents($file); // @phpstan-ignore-line
		if ($oldContent === false) {
			return;
		}
		// @phpstan-ignore-next-line
		$newContent = \preg_replace(
			'/abstract class Base/s',
			"#[\\AllowDynamicProperties]\nabstract class Base",
			$oldContent
		);
		file_put_contents($file, $newContent); // @phpstan-ignore-line

		$file = $vendorDir . '/' . $package->getName() . '/src/GrammarNode/BaseNode.php';
		$oldContent = file_get_contents($file); // @phpstan-ignore-line
		if ($oldContent === false) {
			return;
		}
		// @phpstan-ignore-next-line
		$newContent = \preg_replace(
			'/abstract class BaseNode/s',
			"#[\\AllowDynamicProperties]\nabstract class BaseNode",
			$oldContent
		);
		file_put_contents($file, $newContent); // @phpstan-ignore-line
	}

	/**
	 * Patch TOML Parser to use explicit nullable types
	 *
	 * @param string                    $vendorDir The installation base path
	 * @param \Composer\Package\Package $package   The package being installed
	 */
	public static function patchTomlParser(string $vendorDir, Package $package): void {
		$file = $vendorDir . '/' . $package->getName() . '/src/Exception/ParseException.php';
		$oldContent = file_get_contents($file); // @phpstan-ignore-line
		if ($oldContent === false) {
			return;
		}
		$newContent = str_replace(
			', string $snippet = null, string $parsedFile = null, \\Exception $previous = null',
			', ?string $snippet = null, ?string $parsedFile = null, ?\\Exception $previous = null',
			$oldContent
		);
		file_put_contents($file, $newContent); // @phpstan-ignore-line

		$file = $vendorDir . '/' . $package->getName() . '/src/Parser.php';
		$oldContent = file_get_contents($file); // @phpstan-ignore-line
		if ($oldContent === false) {
			return;
		}
		$newContent = \str_replace(
			'private function syntaxError($msg, Token $token = null) : void',
			'private function syntaxError($msg, ?Token $token = null) : void',
			$oldContent
		);
		file_put_contents($file, $newContent); // @phpstan-ignore-line
	}
}
