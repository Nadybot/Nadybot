<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

class RaffleSlot {
	public int $amount = 1;
	/** @var RaffleItem[] */
	public array $items = [];
	/** @var string[] */
	public array $participants = [];
	/** @var RaffleResultItem[] */
	public array $result = [];

	public function fromString(string $text): void {
		if (preg_match("/^(\d+)x?\s*[^\d]/", $text, $matches)) {
			$this->amount = (int)$matches[1];
			$text = preg_replace("/^(\d+)x?\s*/", "", $text);
		}
		$items = preg_split("/\s*\+\s*/", $text);
		foreach ($items as $item) {
			$itemObj = new RaffleItem();
			$itemObj->fromString($item);
			$this->items []= $itemObj;
		}
	}

	public function toString(): string {
		$items = array_map(
			function(RaffleItem $item): string {
				return $item->toString();
			},
			$this->items
		);
		if ($this->amount === 1) {
			return join(", ", $items);
		}
		return $this->amount . "x " . join(", ", $items);
	}

	public function removeParticipant(string $player): bool {
		if (!in_array($player, $this->participants)) {
			return false;
		}
		$this->participants = array_values(
			array_diff($this->participants, [$player])
		);
		return true;
	}

	/**
	 * @return string[];
	 */
	public function getWinnerNames(): array {
		return array_values(
			array_map(
				function (RaffleResultItem $res): string {
					return $res->player;
				},
				array_filter(
					$this->result??[],
					function(RaffleResultItem $res): bool {
						return $res->won;
					}
				)
			)
		);
	}
}
