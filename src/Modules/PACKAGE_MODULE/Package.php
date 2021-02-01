<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use stdClass;

class Package {
	/** Name of the package */
	public string $name;
	/** Long description of the package */
	public string $description;
	/** Short description of the package */
	public string $short_description;
	/** Version is semver notation */
	public string $version;
	/** Name of the author */
	public string $author;
	/** Required bot type (Nadybot, Budabot, Tyrbot, BeBot) */
	public string $bot_type;
	/** Semver range of required bot version(s) */
	public string $bot_version;
	/** If set, name of the GitHub repo from which to get updates */
	public ?string $github;
	/**
	 * Array of requirements to run the module
	 * @var PackageRequirement[]
	 */
	public array $requires;

	public bool $compatible = false;
	public int $state = 0;
}
