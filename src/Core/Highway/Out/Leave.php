<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

class Leave extends OutPackage {
	public function __construct(
		public string $room,
		?int $id=null,
	) {
		parent::__construct(self::LEAVE, $id);
	}
}
