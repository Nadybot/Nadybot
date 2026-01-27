<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\{DBTable, Types\AOItemSpec, Types\Profession, Types\WearSlot};
use Nadybot\Modules\ITEMS_MODULE\{AODBItem, AodbType};

#[Table(name: 'ofabarmor', shared: Shared::Yes)]
class OfabArmor extends DBTable implements AOItemSpec {
	public function __construct(
		public Profession $profession,
		public string $name,
		public string $slot,
		public int $lowid,
		public int $highid,
		public int $upgrade,
	) {
	}

	public function getLowID(): int {
		return $this->lowid;
	}

	public function getHighID(): int {
		return $this->highid;
	}

	public function getLowQL(): int {
		return 1;
	}

	public function getHighQL(): int {
		return 300;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getLink(?int $ql=null, ?string $text=null): string {
		$ql ??= 1;
		$text ??= $this->getName();
		return "<a href='itemref://{$this->getLowID()}/{$this->getHighID()}/{$ql}'>{$text}</a>";
	}

	public function atQL(int $ql): AODBItem {
		return new AODBItem(
			ql: $ql,
			lowid: $this->getLowID(),
			highid: $this->getHighID(),
			lowql: $this->getLowQL(),
			highql: $this->getHighQL(),
			name: $this->getName(),
			icon: 0,
			slot: WearSlot::fromName($this->slot)->toInt(),
			flags: 0,
			in_game: true,
			type: AodbType::Armor,
			properties: 0,
			froob_friendly: false,
		);
	}
}
