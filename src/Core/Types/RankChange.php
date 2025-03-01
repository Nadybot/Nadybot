<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** Represents a promotion, or demotion in rank */
enum RankChange: string {
	case Promotion = 'promoted';
	case Demotion = 'demoted';
}
