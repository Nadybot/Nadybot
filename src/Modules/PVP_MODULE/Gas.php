<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

class Gas {
	public function __construct(public int $gas) {
	}

	public function __toString(): string {
		return (string)$this->gas;
	}

	public function color(): string {
		if ($this->gas === 75) {
			return '<red>';
		}
		return '<green>';
	}

	public function colored(): string {
		return $this->color() . $this->gas . '%<end>';
	}
}
