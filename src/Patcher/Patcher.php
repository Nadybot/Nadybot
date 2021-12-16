<?php

namespace Nadybot\Patcher;

use Composer\Installer\PackageEvent;
use Composer\Package\Package;
use Exception;

/**
 * This class is used as a callback-provider when installing or updating
 * composer packages.
 *
 * - For Addendum, we need to patch it to support multi-line annotations
 *   and be PHP 8.1 compatible without deprecation warnings.
 * - PHP Codesniffer gets a default config to use the Nadybot styleguide.
 *   deprecation warnings.
 */
class Patcher {
	/**
	 * Callback for composer install and update events
	 *
	 * @param \Composer\Installer\PackageEvent $event
	 * @return void
	 */
	public static function patch(PackageEvent $event): void {
		$vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
		$operation = $event->getOperation();
		if (method_exists($operation, 'getJobType')) {
			$operationType = $operation->getJobType();
		} elseif (defined(get_class($operation) . '::TYPE')) {
			$operationType = $operation::TYPE;
		} else {
			throw new Exception('You are using an unsupported version of Composer');
		}
		if ($operationType === 'install') {
			/** @var \Composer\DependencyResolver\Operation\InstallOperation $operation */
			$package = $operation->getPackage();
		} else {
			/** @var \Composer\DependencyResolver\Operation\UpdateOperation $operation */
			$package = $operation->getTargetPackage();
		}
		/** @var \Composer\Package\Package $package */
		if ($package->getName() === 'squizlabs/php_codesniffer') {
			static::patchCodesniffer($vendorDir, $package);
		}
	}

	/**
	 * Patch PHP Codesniffer to use Nadybot style by default
	 *
	 * @param string $vendorDir The installation basepath
	 * @param \Composer\Package\Package $package The package being installed
	 * @return void
	 */
	public static function patchCodesniffer($vendorDir, Package $package): void {
		$file = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf.dist';
		$oldContent = file_get_contents($file);
		$newContent = "__DIR__.'/../../../style/Nadybot/ruleset.xml'";
		$data = preg_replace("/'PSR2'/", $newContent, $oldContent);
		$data = preg_replace("/(?<='show_warnings' => ')0/", "1", $data);
		$newFile = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf';
		file_put_contents($newFile, $data);
	}
}
