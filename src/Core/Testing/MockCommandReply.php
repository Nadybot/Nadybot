<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\Types\CommandReply;

class MockCommandReply implements CommandReply {
	/** @var list<string> */
	private array $output = [];

	/** @param string|list<string> $msg */
	public function reply(string|array $msg): void {
		foreach ((array)$msg as $result) {
			$this->output []= $result;
		}
	}

	public function getOutput(): string {
		return implode('', $this->output);
	}
}
