<?php declare(strict_types=1);

namespace Nadybot\Core;

enum Faction: string {
	public function getColor(): string {
		return "<" . strtolower($this->value) . ">";
	}

	case Neutral = "Neutral";
	case Omni = "Omni";
	case Clan = "Clan";
	case Unknown = "Unknown";
}
