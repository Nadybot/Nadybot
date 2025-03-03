<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\Types\CommandReply;
use Revolt\EventLoop\Suspension;

class MockCommandReply implements CommandReply {
	/** @var list<string> */
	private array $output = [];

	/** @param Suspension<string> $suspension */
	public function __construct(
		private Suspension $suspension,
	) {
	}

	public function __destruct() {
		/** @param Suspension<string> $suspension */
		\Amp\async(static function (Suspension $suspension, string $output): void {
			$suspension->resume($output);
		}, $this->suspension, implode('', $this->output))->ignore();
	}

	/** @param string|list<string> $msg */
	public function reply(string|array $msg): void {
		foreach ((array)$msg as $result) {
			$this->output []= $result;
		}
	}
}
