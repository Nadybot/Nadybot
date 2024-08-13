<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable, Types\AOItem};

#[NCA\DB\Table(name: 'symbiant', shared: NCA\DB\Shared::Yes)]
class Symbiant extends DBTable implements AOItem {
	public function __construct(
		#[NCA\DB\PK] public int $id,
		public string $name,
		public int $ql,
		public int $slot_id,
		public int $treatment_req,
		public int $level_req,
		public string $unit,
		#[NCA\DB\Ignore] public string $slot_name,
		#[NCA\DB\Ignore] public string $slot_long_name,
	) {
	}

	public function getLowID(): int {
		return $this->id;
	}

	public function getHighID(): int {
		return $this->id;
	}

	public function getLowQL(): int {
		return $this->ql;
	}

	public function getHighQL(): int {
		return $this->ql;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getLink(?int $ql=null, ?string $text=null): string {
		$ql ??= $this->getQL();
		$text ??= $this->getName();
		return "<a href='itemref://{$this->getLowID()}/{$this->getHighID()}/{$ql}'>{$text}</a>";
	}

	public function atQL(int $ql): static {
		$new = clone $this;
		$new->ql = $ql;
		return $new;
	}

	public function getQL(): int {
		return $this->ql;
	}

	public function setQL(int $ql): self {
		$this->ql = $ql;
		return $this;
	}
}
