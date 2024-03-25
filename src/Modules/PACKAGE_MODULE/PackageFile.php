<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use Nadybot\Core\DBRow;

class PackageFile extends DBRow {
	/**
	 * @param string $module  From which module/package is that file
	 * @param string $version From which module/package version is that file
	 * @param string $file    Filename relative to extra module basedir
	 */
	public function __construct(
		public string $module,
		public string $version,
		public string $file,
	) {
	}
}
