<?php declare(strict_types=1);

namespace Nadybot\Core;

interface Loggable {
	public function toLog(): string;
}
