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
			throw new Exception("You are using an unsupported composer version");
		}

		assert($package instanceof \Composer\Package\Package);
		if ($package->getName() === 'squizlabs/php_codesniffer') {
			static::patchCodesniffer($vendorDir, $package);
		}

		if ($package->getName() === 'farafiri/php-parsing-tool') {
			static::patchParsingTool($vendorDir, $package);
		}
	}

	/**
	 * Patch PHP Codesniffer to use Nadybot style by default
	 *
	 * @param string                    $vendorDir The installation basepath
	 * @param \Composer\Package\Package $package   The package being installed
	 */
	public static function patchCodesniffer($vendorDir, Package $package): void {
		$file = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf.dist';
		$oldContent = file_get_contents($file);
		if ($oldContent === false) {
			return;
		}
		$newContent = "__DIR__.'/../../../style/Nadybot/ruleset.xml'";
		$data = preg_replace("/'PSR2'/", $newContent, $oldContent);
		$data = preg_replace("/(?<='show_warnings' => ')0/", "1", $data);
		$newFile = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf';
		file_put_contents($newFile, $data);
	}

	/**
	 * Patch PHP Parsing tool to allow fynamic properties
	 *
	 * @param string                    $vendorDir The installation basepath
	 * @param \Composer\Package\Package $package   The package being installed
	 */
	public static function patchParsingTool($vendorDir, Package $package): void {
		$file = $vendorDir . '/' . $package->getName() . '/src/SyntaxTreeNode/Base.php';
		$oldContent = file_get_contents($file);
		if ($oldContent === false) {
			return;
		}
		$newContent = preg_replace(
			"/abstract class Base/s",
			"#[\\AllowDynamicProperties]\nabstract class Base",
			$oldContent
		);
		file_put_contents($file, $newContent);

		$file = $vendorDir . '/' . $package->getName() . '/src/GrammarNode/BaseNode.php';
		$oldContent = file_get_contents($file);
		if ($oldContent === false) {
			return;
		}
		$newContent = preg_replace(
			"/abstract class BaseNode/s",
			"#[\\AllowDynamicProperties]\nabstract class BaseNode",
			$oldContent
		);
		file_put_contents($file, $newContent);
	}
}
