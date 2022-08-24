<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use Nadybot\Core\DBRow;

class PackageFile extends DBRow {
	/** From which module/package is that file */
	public string $module;

	/** From which module/package version is that file */
	public string $version;

	/** Filename relative to extra module basedir */
	public string $file;
}
