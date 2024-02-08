<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Join extends InPackage {
	public function __construct(
		string $type,
		public string $room,
		public string $user,
	) {
		parent::__construct($type);
	}
}
