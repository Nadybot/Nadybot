<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use ValueError;

enum SymbiantType: string {
	public static function fromName(string $name): self {
		return match (strtolower(substr($name, 0, 3))) {
			'art' => self::Artillery,
			'sup' => self::Support,
			'inf' => self::Infantry,
			'ext' => self::Extermination,
			'con' => self::Control,
			default => throw new ValueError("Unknown symbiant type {$name}"),
		};
	}

	case Artillery = 'Artillery';
	case Extermination = 'Extermination';
	case Infantry  = 'Infantry';
	case Support = 'Support';
	case Control = 'Control';
}
