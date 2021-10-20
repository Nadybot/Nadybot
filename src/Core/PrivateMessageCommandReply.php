<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Source;

class PrivateMessageCommandReply implements CommandReply, MessageEmitter {
	private Nadybot $chatBot;
	private string $sender;
	private ?int $worker = null;

	public function __construct(Nadybot $chatBot, string $sender, ?int $worker=null) {
		$this->chatBot = $chatBot;
		$this->sender = $sender;
		$this->worker = $worker;
	}

	public function getChannelName(): string {
		return Source::TELL . "({$this->sender})";
	}

	public function reply($msg): void {
		if (isset($this->worker)) {
			$this->chatBot->sendMassTell($msg, $this->sender, null, true, $this->worker);
		} else {
			$this->chatBot->sendTell($msg, $this->sender);
		}
	}
}
