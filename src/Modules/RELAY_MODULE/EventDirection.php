<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\Types\{EnumExampleInterface, EnumParameterInterface};

enum EventDirection: string implements EnumParameterInterface, EnumExampleInterface {
	public static function getParamRegexp(): string {
		return 'incoming|outgoing';
	}

	public static function fromParam(string $param): self {
		return self::from($param);
	}

	public static function getExample(): string {
		return 'incoming|outgoing';
	}

	case Incoming = 'incoming';
	case Outgoing = 'outgoing';
}
