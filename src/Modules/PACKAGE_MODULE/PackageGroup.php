<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

class PackageGroup {
	public string $name;

	public ?Package $highest_supported = null;
	public ?Package $highest = null;
}
