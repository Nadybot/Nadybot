<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\preg_replace;

class Safe {
	public static function pregReplace(string $pattern, string $replacement, string $subject, int $limit=-1, ?int &$count=null): string {
		return preg_replace($pattern, $replacement, $subject, $limit, $count);
	}
}
