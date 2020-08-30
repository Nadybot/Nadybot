<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

class RaffleItem {
	public int $amount = 1;
	public string $item;

	public function fromString(string $text): void {
		if (preg_match("/^(\d+)x?\s*[^\d]/", $text, $matches)) {
			$this->amount = (int)$matches[1];
			$text = preg_replace("/^(\d+)x?\s*/", "", $text);
		}
		$text = preg_replace("/['\"](itemref:\/\/\d+\/\d+\/\d+)['\"]/", "$1", $text);
		$this->item = $text;
	}

	public function toString(): string {
		$item = $this->item;
		if (preg_match("/itemref:\/\/\d+\/\d+\/(\d+)/", $item, $matches)) {
			$item = "QL{$matches[1]} {$item}";
		}
		if ($this->amount === 1) {
			return $item;
		}
		return $this->amount . "x " . $item;
	}
}
