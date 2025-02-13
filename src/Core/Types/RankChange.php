<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

enum RankChange: string {
	case Promotion = 'promoted';
	case Demotion = 'demoted';
}
