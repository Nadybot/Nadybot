<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

enum Endianness: string {
	case BigEndian = 'big-endian';
	case LittleEndian = 'little-endian';
}
