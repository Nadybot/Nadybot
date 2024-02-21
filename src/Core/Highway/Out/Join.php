<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

class Join extends OutPackage {
	public function __construct(
		public string $room,
		null|int|string $id=null,
	) {
		$this->type = self::JOIN;
		parent::__construct($id);
	}
}
