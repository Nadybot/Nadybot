<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Exception;
use Nadybot\Core\AOChatEvent;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Registry;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayTransport("private-channel")
 * @Description("This is the Anarchy Online private channel transport.
 * 	You can use this to relay messages internally inside Anarchy Online.
 * 	Be aware though, that the delay is based on the size of the message
 * 	being sent.
 * 	The bot must be invited into the private channel before it can
 * 	relay anything.")
 * @Param(name='channel', description='The private channel to join', type='string', required=true)
 */
class PrivateChannel implements TransportInterface {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public EventManager $eventManager;

	protected Relay $relay;

	protected string $channel;

	protected $initCallback;

	public function __construct(string $channel) {
		$this->channel = ucfirst(strtolower($channel));
		/** @var Nadybot */
		$chatBot = Registry::getInstance("chatBot");
		if ($chatBot->get_uid($this->channel) === false) {
			throw new Exception("Unknown user <highlight>{$this->channel}<end>.");
		}
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function send(array $data): array {
		$leftOver = [];
		foreach ($data as $chunk) {
			if (!$this->chatBot->send_privgroup($this->channel, $chunk)) {
				$leftOver []= $chunk;
			}
		}
		return $leftOver;
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function receiveMessage(AOChatEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->relay->receiveFromTransport($event->message);
	}

	public function receiveInvite(AOChatEvent $event): void {
		if (strtolower($event->sender) !== strtolower($this->channel)) {
			return;
		}
		$this->chatBot->privategroup_join($event->sender);
	}

	public function joinedPrivateChannel(AOChatEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		if (isset($this->initCallback)) {
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
		}
	}

	public function init(callable $callback): array {
		$this->eventManager->subscribe("extpriv", [$this, "receiveMessage"]);
		$this->eventManager->subscribe("extJoinPrivRequest", [$this, "receiveInvite"]);
		if (!isset($this->chatBot->privateChats[$this->channel])) {
			// In case we have a race condition and received the invite before
			$this->initCallback = $callback;
			$this->eventManager->subscribe("extJoinPriv", [$this, "joinedPrivateChannel"]);
			$this->chatBot->privategroup_join($this->channel);
		} else {
			$callback();
		}
		return [];
	}
}
