<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use ValueError;

/** This represents one of the 3 factions in the game, as well es `'Unknown'` */
enum Faction: string implements EnumParameterInterface {
	/** Get the lower-cased name of this faction */
	public function lower(): string {
		return strtolower($this->value);
	}

	/** {@inheritDoc} */
	public static function getParamRegexp(): string {
		return 'neut|neutral|omni|clan';
	}

	/** Get a colorized version of the name of this faction */
	public function inColor(?string $text=null): string {
		$text ??= $this->value;
		return "<{$this->lower()}>{$text}<end>";
	}

	/** Create a new instance based on its name */
	public static function fromName(string $name): self {
		return match (strtolower($name)) {
			'neutral','neut' => self::Neutral,
			'omni' => self::Omni,
			'clan' => self::Clan,
			default => throw new ValueError("Invalid faction '{$name}'"),
		};
	}

	/** {@inheritDoc} */
	public static function fromParam(string $param): self {
		return self::fromName($param);
	}

	/** Try to create a new instance based on its name, or null if no match */
	public static function tryFromName(string $name): ?self {
		try {
			return self::fromName($name);
		} catch (\Throwable) {
			return null;
		}
	}

	case Neutral = 'Neutral';
	case Omni = 'Omni';
	case Clan = 'Clan';

	/** A special type of faction that's used when we know that we don't know */
	case Unknown = 'Unknown';
}
