<?php declare(strict_types=1);

namespace Nadybot\Core;

enum Faction: string {
	public function lower(): string {
		return strtolower($this->value);
	}

	public function getColor(): string {
		return '<' . strtolower($this->value) . '>';
	}

	public function inColor(?string $text=null): string {
		$text ??= $this->name;
		return "<{$this->lower()}>{$text}<end>";
	}

	case Neutral = 'Neutral';
	case Omni = 'Omni';
	case Clan = 'Clan';
	case Unknown = 'Unknown';
}
