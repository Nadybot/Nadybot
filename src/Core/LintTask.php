<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

// @phpstan-ignore-next-line
class LintTask implements Task {
	public function __construct(
		private readonly string $filename,
	) {
	}

	public function run(Channel $channel, Cancellation $cancellation): bool {
		require_once $this->filename;
		return true;
	}
}
