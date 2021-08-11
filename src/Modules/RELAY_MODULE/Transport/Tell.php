<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Exception;
use Nadybot\Core\AOChatEvent;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Registry;
use Nadybot\Core\StopExecutionException;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayTransport("tell")
 * @Description("This is the Anarchy Online private message (tell) protocol.
 * 	You can use this to relay messages internally inside Anarchy Online
 * 	via sending tells. This is the simplest form of relaying messages.
 * 	Be aware though, that tells are rate-limited and will very likely
 * 	lag a lot. It is also not possible to setup a relay with more
 * 	then 2 bots this way.")
 * @Param(name='bot', description='The name of the other bot', type='string', required=true)
 */
class Tell implements TransportInterface {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public EventManager $eventManager;

	protected Relay $relay;

	protected string $bot;

	public function __construct(string $bot) {
		$bot = ucfirst(strtolower($bot));
		/** @var Nadybot */
		$chatBot = Registry::getInstance('chatBot');
		if ($chatBot->get_uid($bot) === false) {
			throw new Exception("Unknown user <highlight>{$bot}<end>.");
		}
		$this->bot = $bot;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function send(string $data): bool {
		return $this->chatBot->send_tell($this->bot, $data);
	}

	public function receiveMessage(AOChatEvent $event): void {
		if (strtolower($event->sender) !== strtolower($this->bot)) {
			return;
		}
		$this->relay->receiveFromTransport($event->message);
		throw new StopExecutionException();
	}

	public function init(?object $previous, callable $callback): void {
		$this->eventManager->subscribe("msg", [$this, "receiveMessage"]);
		$callback();
	}

	public function deinit(?object $previous, callable $callback): void {
		$callback();
	}
}
