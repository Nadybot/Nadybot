<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use AO\Utils;
use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Types\EventInterface;

/** We send a tell */
#[Event(mask: 'tradebot(*)')]
class TradebotMsgEvent implements EventInterface {
	/** Name of the tradebot sending the message */
	public readonly string $tradebot;

	/**
	 * @param string  $tradebot Name of the tradebot sending the message
	 * @param ?string $tag      The tag for which this message is, or `null` if untagged
	 * @param string  $message  The message itself
	 */
	public function __construct(
		string $tradebot,
		public readonly ?string $tag,
		public readonly string $message,
	) {
		$this->tradebot = Utils::normalizeCharacter($tradebot);
	}

	public function getEvent(): string {
		if (isset($this->tag)) {
			return "tradebot({$this->tradebot}-{$this->tag})";
		}
		return "tradebot({$this->tradebot})";
	}
}
