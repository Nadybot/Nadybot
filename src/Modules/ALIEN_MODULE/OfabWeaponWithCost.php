<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

class OfabWeaponWithCost extends OfabWeapon {
	public function __construct(
		int $type=0,
		string $name='',
		public int $ql=0,
		public int $vp=0,
	) {
		parent::__construct(type: $type, name: $name);
	}
}
