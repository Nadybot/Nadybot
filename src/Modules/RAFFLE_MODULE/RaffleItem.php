<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Registry;

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
		if (preg_match_all("/itemref:\/\/(\d+)\/(\d+)\/(\d+)/", $item, $matches)) {
			for ($i = 0; $i < count($matches[0]); $i++) {
				$ql = null;
				if ($matches[1][$i] !== $matches[2][$i]) {
					$ql = $matches[3][$i];
				} else {
					$itemGroup = Registry::getInstance('db')->queryRow(
						"SELECT * FROM item_groups WHERE item_id=?",
						$matches[1][$i]
					);
					if ($itemGroup !== null) {
						$ql = $matches[3][$i];
					}
				}
				if (isset($ql)) {
					$item = preg_replace("/(<a [^>]*?".preg_quote($matches[0][$i], "/")."\E.*?>)/", "QL{$ql} $1", $item);
				}
			}
		}
		if ($this->amount === 1) {
			return $item;
		}
		return $this->amount . "x " . $item;
	}
}
