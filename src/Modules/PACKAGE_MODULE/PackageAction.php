<?php declare(strict_types=1);

namespace Nadybot\Modules\PACKAGE_MODULE;

use Nadybot\Core\{SemanticVersion, Types\CommandReply};

class PackageAction {
	public const int INSTALL = 1;
	public const int UPGRADE = 2;

	public function __construct(
		public string $package,
		public string $sender,
		public CommandReply $sendto,
		public int $action=self::INSTALL,
		public ?SemanticVersion $oldVersion=null,
		public ?SemanticVersion $version=null,
	) {
	}
}
