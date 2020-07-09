<?php

namespace Budabot\Patcher;

use Composer\Installer\PackageEvent;
use Composer\Package\Package;

/**
 * This class is used as a callback-provider when installing or updating
 * composer packages.
 *
 * - For Addendum, we need to patch it to support multi-line annotations
 * - PHP Codesniffer gets a default config to use the Budabot styleguide
 */
class Patcher {
	/**
	 * Callback for composer install and update events
	 *
	 * @param \Composer\Installer\PackageEvent $event
	 * @return void
	 */
	public static function patch(PackageEvent $event) {
		$vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
		$operation = $event->getOperation();
		if ($operation->getJobType() === 'install') {
			/** @var \Composer\DependencyResolver\Operation\InstallOperation $operation */
			$package = $operation->getPackage();
		} else {
			/** @var \Composer\DependencyResolver\Operation\UpdateOperation $operation */
			$package = $operation->getTargetPackage();
		}
		/** @var \Composer\Package\Package $package */
		if ($package->getName() === 'niktux/addendum') {
			static::patchAddendum($vendorDir, $package);
		} elseif ($package->getName() === 'squizlabs/php_codesniffer') {
			static::patchCodesniffer($vendorDir, $package);
		}
	}

	/**
	 * Patch Addendum to support muilti-line annotations
	 *
	 * @param string $vendorDir The installation basepath
	 * @param \Composer\Package\Package $package The package being installed
	 * @return void
	 */
	public static function patchAddendum($vendorDir, Package $package) {
		$file = $vendorDir . '/' . $package->getName() . '/lib/Addendum/Addendum.php';
		$oldContent = file_get_contents($file);
		$newContent = <<<'EOD'
    public static function getDocComment($reflection) {
        $value = false;
        if(self::checkRawDocCommentParsingNeeded()) {
            $docComment = new DocComment();
            $value = $docComment->get($reflection);
        } else {
            $value = $reflection->getDocComment();
        }
        if ($value) {
            // get rid of useless '*' and white space from line's start
            // this will allow dividing of one annotation to multiple lines
            $value = preg_replace('/^[\\s*]*/m', '', $value);
        }
        return $value;
    }
EOD;
		$data = preg_replace(
			'/public static function getDocComment\(\$reflection\)'.
			'.+?return \$reflection->getDocComment\(\);'.
			'\s+}/s',
			trim($newContent),
			$oldContent
		);
		file_put_contents($file, $data);
	}

	/**
	 * Patch PHP Codesniffer to use Budabot style by default
	 *
	 * @param string $vendorDir The installation basepath
	 * @param \Composer\Package\Package $package The package being installed
	 * @return void
	 */
	public static function patchCodesniffer($vendorDir, Package $package) {
		$file = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf.dist';
		$oldContent = file_get_contents($file);
		$newContent = "__DIR__.'/../../../style/Budabot/ruleset.xml'";
		$data = preg_replace("/'PSR2'/", $newContent, $oldContent);
		$data = preg_replace("/(?<='show_warnings' => ')0/", "1", $data);
		$newFile = $vendorDir . '/' . $package->getName() . '/CodeSniffer.conf';
		file_put_contents($newFile, $data);
	}
}
