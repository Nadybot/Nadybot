<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;

class MessageInfoCommandReply implements CommandReply {
	private CommandReply $sendto;
	private float $startTime;

	public function __construct(CommandReply $sendto) {
		$this->sendto = $sendto;
		$this->startTime = microtime(true);
	}

	public function reply($msg): void {
		$endTime = microtime(true);
		if (!is_array($msg)) {
			$msg = [$msg];
		}

		foreach ($msg as $page) {
			$elapsed = round(($endTime - $this->startTime)*1000, 2);
			$this->sendto->reply($page);
			$this->sendto->reply("Size: " . strlen($page) . " characters");
			$this->sendto->reply("Time: {$elapsed} ms");
		}
	}
}
