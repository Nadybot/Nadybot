<?php declare(strict_types=1);

namespace Nadybot\Core;

class PrivateMessageCommandReply implements CommandReply {
	private Nadybot $chatBot;
	private string $sender;
	private ?int $worker = null;

	public function __construct(Nadybot $chatBot, string $sender, ?int $worker=null) {
		$this->chatBot = $chatBot;
		$this->sender = $sender;
		$this->worker = $worker;
	}

	public function reply($msg): void {
		if (isset($this->worker)) {
			$this->chatBot->sendMassTell($msg, $this->sender, null, true, $this->worker);
		} else {
			$this->chatBot->sendTell($msg, $this->sender);
		}
	}
}
