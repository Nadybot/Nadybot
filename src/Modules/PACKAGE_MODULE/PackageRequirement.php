<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

class PackageRequirement {
	/** Name of the module/extension that's required */
	public string $name;

	/** The required version */
	public string $version;
}
