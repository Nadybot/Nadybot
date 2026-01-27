<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Types\AOItem;

class AODBItem extends AODBEntry implements AOItem {
	public function __construct(
		public int $ql,
		int $lowid,
		int $highid,
		int $lowql,
		int $highql,
		string $name,
		int $icon,
		int $slot,
		int $flags,
		bool $in_game,
		AodbType $type,
		bool $froob_friendly,
		int $properties,
	) {
		parent::__construct(
			lowid: $lowid,
			highid: $highid,
			lowql: $lowql,
			highql: $highql,
			name: $name,
			icon: $icon,
			slot: $slot,
			flags: $flags,
			in_game: $in_game,
			type: $type,
			froob_friendly: $froob_friendly,
			properties: $properties,
		);
	}

	public function getQL(): int {
		return $this->ql;
	}

	public function setQL(int $ql): static {
		$this->ql = $ql;
		return $this;
	}

	public function getLink(?int $ql=null, ?string $text=null): string {
		return parent::getLink($ql??$this->getQL(), $text);
	}
}
