<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Safe;

class RaffleSlot {
	public int $amount = 1;

	/** @var list<RaffleItem> */
	public array $items = [];

	/** @var list<string> */
	public array $participants = [];

	/** @var list<RaffleResultItem> */
	public array $result = [];

	public function fromString(string $text): void {
		if (count($matches = Safe::pregMatch("/^(?<count>\d+)x?\s*[^\d]|\btop\s*(?<count>\d+)\b/J", $text))) {
			$this->amount = (int)$matches['count'];
			$text = Safe::pregReplace("/^(\d+)x?\s*/", '', $text);
		} elseif (Safe::pregMatches("/loot\s*order/i", $text)) {
			$this->amount = 0;
		}
		$items = Safe::pregSplit("/\s*\+\s*/", $text);
		foreach ($items as $item) {
			$this->items []= RaffleItem::fromString($item);
		}
	}

	public function toString(): string {
		$items = array_map(
			static function (RaffleItem $item): string {
				return $item->toString();
			},
			$this->items
		);
		if ($this->amount <= 1) {
			return implode(', ', $items);
		}
		return "<orange>{$this->amount}×</font> " . implode(', ', $items);
	}

	public function isSameAs(RaffleSlot $slot): bool {
		/** @var array<string,int> */
		$items = [];
		$remaining = 0;
		foreach ($slot->items as $item) {
			$name = $item->amount . chr(0) . $item->item;
			if (!isset($items[$name])) {
				$items[$name] = 0;
			}
			$items[$name]++;
			$remaining++;
		}
		foreach ($this->items as $check) {
			$name = $check->amount . chr(0) . $check->item;
			if (!isset($items[$name]) || ($items[$name] === 0)) {
				return false;
			}
			$items[$name]--;
			$remaining--;
		}
		return $remaining === 0;
	}

	public function removeParticipant(string $player): bool {
		if (!in_array($player, $this->participants, true)) {
			return false;
		}
		$this->participants = array_values(
			array_diff($this->participants, [$player])
		);
		return true;
	}

	/** @return list<string> */
	public function getWinnerNames(): array {
		return array_values(
			array_map(
				static function (RaffleResultItem $res): string {
					return $res->player;
				},
				array_filter(
					$this->result??[],
					static function (RaffleResultItem $res): bool {
						return $res->won;
					}
				)
			)
		);
	}
}
