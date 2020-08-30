<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\CommandReply;

class Raffle {
	/** @var RaffleSlot[] */
	public array $slots = [];
	public int $start;
	public CommandReply $sendto;
	public string $raffler;
	public ?int $end = null;
	public ?int $lastAnnounce = null;

	public function __construct() {
		$this->start = $this->lastAnnounce = time();
	}

	public function toString(string $prefix=""): string {
		$list = $this->toList();
		$items = [];
		for ($i = 0; $i < count($list); $i++) {
			$items []= "Item " . ($i + 1) . ": <highlight>{$list[$i]}<end>";
		}
		return $prefix . join("\n$prefix", $items);
	}

	public function fromString(string $text): void {
		$text = preg_replace("/>\s*</", ">,<", $text);
		// Items with "," in their name get this escaped
		$text = preg_replace_callback(
			"/(['\"]?itemref:\/\/\d+\/\d+\/\d+['\"]?>)(.+?)(<\/a>)/",
			function (array $matches): string {
				return $matches[1] .  str_replace(",", "&#44;", $matches[2]) . $matches[3];
			},
			$text
		);
		$parts = preg_split("/\s*,\s*/", $text);
		foreach ($parts as $part) {
			$slot = new RaffleSlot();
			$slot->fromString($part);
			$this->slots []= $slot;
		}
	}

	/**
	 * @return string[]
	 */
	public function toList(): array {
		$slots = [];
		foreach ($this->slots as $slot) {
			$slots []= $slot->toString();
		}
		return $slots;
	}

	/**
	 * @return string[]
	 */
	public function getParticipantNames(): array {
		return array_reduce(
			$this->slots,
			function(array $carry, RaffleSlot $slot): array {
				return array_unique([...$carry, ...$slot->participants]);
			},
			[]
		);
	}

	public function isInRaffle(string $player, ?int $slot=null): ?bool {
		if ($slot !== null) {
			if (!isset($this->slots[$slot])) {
				return null;
			}
			$participants = $this->slots[$slot]->participants;
		} else {
			$participants = $this->getParticipantNames();
		}
		return in_array($player, $participants);
	}

	public function getWinnerNames(): array {
		return array_merge(
			...array_map(
				function(RaffleSlot $slot) {
					return $slot->getWinnerNames();
				},
				$this->slots
			)
		);
	}
}
