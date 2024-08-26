<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

class BanResult {
	/**
	 * @param string[] $messages
	 *
	 * @psalm-param list<string> $messages
	 */
	public function __construct(
		public bool $success,
		public array $messages,
	) {
	}
}
