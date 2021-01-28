<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

class Package {
	public string $name;
	public string $description;
	public string $short_description;
	public string $version;
	public string $author;
	public string $bot_type;
	public string $bot_version;

	public bool $compatible = false;
	public int $state = 0;
}
