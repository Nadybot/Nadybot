<?php declare(strict_types=1);

namespace Nadybot\Core;

use ValueError;

/** A channel type that's supported by the exporter/importer */
enum ExportChannel: string {
	/** Get the Nadybot channel name */
	public function toNadybot(): string {
		return match ($this) {
			self::Org => 'guild',
			self::Tell => 'msg',
			self::Priv => 'priv',
			self::Discord => 'discord',
			self::IRC => 'irc',
			self::None => throw new ValueError('"none" is not a valid channel for Nadybot'),
		};
	}

	/** Create a new instance based on the Nadybot channel name */
	public static function fromNadybot(string $channel): self {
		return match (strtolower($channel)) {
			'guild' => self::Org,
			'msg' => self::Tell,
			'priv' => self::Priv,
			'discord' => self::Discord,
			'irc' => self::IRC,
			'none' => self::None,
			default => throw new ValueError("{$channel} is not a valid channel"),
		};
	}

	case Tell = 'tell';
	case Org = 'org';
	case Priv = 'priv';
	case Discord = 'discord';
	case IRC = 'irc';
	case None = 'none';
}
