<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/**
 * This class represents an async runner job that will check
 * whether a given PHP file contains compile/parsing errors.
 *
 * @template-implements Task<bool, never, never>
 */
class LintTask implements Task {
	/** @param string $filename The filename to check for parsing/compile errors */
	public function __construct(
		private readonly string $filename,
	) {
	}

	/** {@inheritDoc} */
	public function run(Channel $channel, Cancellation $cancellation): bool {
		require_once __DIR__ . '/../../vendor/autoload.php';
		include $this->filename;
		return true;
	}
}
