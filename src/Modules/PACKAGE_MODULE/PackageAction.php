<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use Nadybot\Core\{CommandReply, SemanticVersion};

class PackageAction {
	public const INSTALL = 1;
	public const UPGRADE = 2;

	public int $action = self::INSTALL;

	public string $package;

	public ?SemanticVersion $oldVersion;

	public ?SemanticVersion $version;

	public string $sender;

	public CommandReply $sendto;

	public function __construct(string $module, int $action) {
		$this->package = $module;
		$this->action = $action;
	}
}
