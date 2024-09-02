<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Types\CommandReply;

class MessageInfoCommandReply implements CommandReply {
	private float $startTime;

	public function __construct(
		private CommandReply $sendto
	) {
		$this->startTime = microtime(true);
	}

	/** @inheritDoc */
	public function reply(string|array $msg): void {
		$endTime = microtime(true);
		if (!is_array($msg)) {
			$msg = [$msg];
		}

		foreach ($msg as $page) {
			$elapsed = round(($endTime - $this->startTime)*1_000, 2);
			$this->sendto->reply($page);
			$this->sendto->reply('Size: ' . strlen($page) . ' characters');
			$this->sendto->reply("Time: {$elapsed} ms");
		}
	}
}
