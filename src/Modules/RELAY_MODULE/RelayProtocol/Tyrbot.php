<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Attributes as NCA;
use JsonException;
use Nadybot\Core\{
	ConfigFile,
	LoggerWrapper,
	Nadybot,
	Routing\Character,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
};
use Nadybot\Core\Routing\Events\Online;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot\{
	BasePacket,
	Logoff,
	Logon,
	Message,
	OnlineList,
	OnlineListRequest,
};
use Throwable;

#[
	NCA\RelayProtocol(
		name: "tyrbot",
		description:
			"This is the enhanced protocol of Tyrbot. If your\n".
			"relay consists only of Nadybots and Tyrbots, use this one.\n".
			"It allows sharing of online users as well as fully customized\n".
			"colors."
	),
	NCA\Param(
		name: "sync-online",
		type: "bool",
		description: "Sync the online list with the other bots of this relay",
		required: false
	)
]
class Tyrbot implements RelayProtocolInterface {
	protected static int $supportedFeatures = self::F_ONLINE_SYNC;

	protected Relay $relay;

	/** Do we want to sync online users? */
	protected bool $syncOnline = true;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public OnlineController $onlineController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	public function __construct(bool $syncOnline=true) {
		$this->syncOnline = $syncOnline;
	}

	public function send(RoutableEvent $event): array {
		$this->logger->debug("Received event {type} on relay {relay}", [
			"relay" => $this->relay->getName(),
			"type" => $event->getType(),
			"event" => $event,
		]);
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->encodeMessage($event);
		} elseif ($event->data instanceof Online) {
			return [...$this->encodeUserStateChange($event, $event->data), ...$this->encodeMessage($event)];
		}
		return [];
	}

	/**
	 * @return string[]
	 * @psalm-return list<string>
	 */
	protected function encodeUserStateChange(RoutableEvent $r, Online $event): array {
		if (!$this->syncOnline) {
			return [];
		}
		$packet = [
			"type" => $event->online ? BasePacket::LOGON : BasePacket::LOGOFF,
			"user" => [
				"name" => $event->char->name,
				"id" => $event->char->id,
			],
			"source" => $this->nadyPathToTyr($r),
		];
		$statePacket = $event->online ? new Logon($packet) : new Logoff($packet);
		$data = $this->jsonEncode($statePacket);
		return [$data];
	}

	protected function nadyPathToTyr(RoutableEvent $event): array {
		$source = [
			"name" => $event->path[0]->name,
			"server" => $event->path[0]->server,
		];
		if (strlen($event->path[0]->label??"")) {
			$source['label'] = $event->path[0]->label;
		}
		$lastHop = $event->path[count($event->path)-1];
		$source['type'] = $this->nadyTypeToTyr($lastHop->type);
		if (count($event->path) > 1) {
			$source['channel'] = (strlen($lastHop->label??"")) ? $lastHop->label : $lastHop->name;
		}
		return $source;
	}

	/**
	 * @return string[]
	 * @psalm-return list<string>
	 */
	protected function encodeMessage(RoutableEvent $event): array {
		$this->logger->debug("Encoding message into Tyrbot format on relay {relay}", [
			"relay" => $this->relay->getName(),
			"message" => $event,
		]);
		$event = clone $event;
		if (is_string($event->data)) {
			$event->data = str_replace("<myname>", $this->chatBot->char->name, $event->data);
		} elseif (is_object($event->data) && is_string($event->data->message)) {
			$event->data = str_replace("<myname>", $this->chatBot->char->name, $event->data->message);
		} else {
			return [];
		}
		$packet = [
			"type" => "message",
			"message" => $event->data,
		];
		if (isset($event->char)
			&& ($event->char->id ?? null) !== $this->chatBot->char->id) {
			$packet['user'] = ["name" => $event->char->name];
			if (isset($event->char->id)) {
				$packet['user']['id'] = $event->char->id;
			}
		} else {
			$packet['user'] = null;
		}
		$packet['source'] = $this->nadyPathToTyr($event);
		try {
			$data = $this->jsonEncode($packet);
		} catch (JsonException $e) {
			$this->logger->error("Error ecoding Tyrbot message: " . $e->getMessage(), ["exception" => $e]);
			return [];
		}
		$this->logger->debug("Successfully encoded message into Tyrbot format on relay {relay}", [
			"relay" => $this->relay->getName(),
			"data" => $data,
		]);
		return [$data];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (empty($message->packages)) {
			return null;
		}
		$this->logger->debug("Received message on relay {relay}", [
			"relay" => $this->relay->getName(),
			"message" => $message,
		]);
		$serialized = array_shift($message->packages);
		try {
			$data = json_decode($serialized, true, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
			$identify = new BasePacket($data);
			return $this->decodeAndHandlePacket($message->sender, $identify, $data);
		} catch (JsonException $e) {
			$this->logger->error(
				"Invalid data received via Tyrbot protocol: {$serialized}",
				["exception" => $e]
			);
			return null;
		} catch (Throwable $e) {
			$this->logger->error(
				"Invalid Tyrbot-package received: {$serialized}",
				["exception" => $e]
			);
			return null;
		}
	}

	protected function decodeAndHandlePacket(?string $sender, BasePacket $identify, array $data): ?RoutableEvent {
		switch ($identify->type) {
			case $identify::MESSAGE:
				return $this->receiveMessage(new Message($data));
			case $identify::LOGON:
				$this->logger->debug("Logon event received on {relay}", [
					"relay" => $this->relay->getName(),
				]);
				$this->handleLogon($sender, new Logon($data));
				return null;
			case $identify::LOGOFF:
				$this->logger->debug("Logoff event received on {relay}", [
					"relay" => $this->relay->getName(),
				]);
				$this->handleLogoff($sender, new Logoff($data));
				return null;
			case $identify::ONLINE_LIST_REQUEST:
				$this->logger->debug("Online list request received on {relay}", [
					"relay" => $this->relay->getName(),
				]);
				$this->sendOnlineList();
				return null;
			case $identify::ONLINE_LIST:
				$this->logger->debug("Online list received on {relay}", [
					"relay" => $this->relay->getName(),
				]);
				$this->handleOnlineList($sender, new OnlineList($data));
				return null;
			default:
				$this->logger->notice("Received unknown Tyrbot packet type {type} on {relay}", [
					"type" => $identify->type,
					"relay" => $this->relay->getName(),
				]);
		}
		return null;
	}

	protected function handleOnlineList(?string $sender, OnlineList $list): void {
		if (!isset($sender)) {
			return;
		}
		foreach ($list->online as $block) {
			$key = $block->source->label ?? $block->source->name;
			if (isset($block->source->channel)) {
				$key .= " {$block->source->channel}";
			}
			$this->relay->clearOnline($key);
			foreach ($block->users as $user) {
				$this->relay->setOnline($sender, $key, $user->name, $user->id, $block->source->server);
			}
		}
	}

	protected function handleLogon(?string $sender, Logon $event): void {
		if (!isset($sender)) {
			return;
		}
		$key = $event->source->label ?? $event->source->name;
		if (isset($event->source->channel)) {
			$key .= " {$event->source->channel}";
		}
		$this->relay->setOnline($sender, $key, $event->user->name, $event->user->id, $event->source->server);
	}

	protected function handleLogoff(?string $sender, Logoff $event): void {
		if (!isset($sender)) {
			return;
		}
		$key = $event->source->label ?? $event->source->name;
		if (isset($event->source->channel)) {
			$key .= " {$event->source->channel}";
		}
		$this->relay->setOffline($sender, $key, $event->user->name, $event->user->id, $event->source->server);
	}

	protected function getOnlineList(): OnlineList {
		$onlineList = [
			"type" => "online_list",
			"online" => []
		];
		$onlineOrg = $this->onlineController->getPlayers('guild', $this->chatBot->char->name);
		/** @psalm-suppress DocblockTypeContradiction */
		if (strlen($this->config->orgName)) {
			$orgSource = [
				"name" => $this->config->orgName,
				"server" => $this->config->dimension,
			];
			$orgLabel = $this->settingManager->getString("relay_guild_abbreviation");
			if (strlen($orgLabel??"") && $orgLabel !== "none") {
				$orgSource['label'] = $orgLabel;
			}
			$orgSource['type'] = "org";
			$orgUsers = [];
			foreach ($onlineOrg as $player) {
				$orgUsers []= [
					"name" => $player->name,
					"id" => $player->charid,
				];
			}
			$onlineList["online"] []= [
				"source" => $orgSource,
				"users" => $orgUsers,
			];
		}

		$onlinePriv = $this->onlineController->getPlayers('priv', $this->chatBot->char->name);
		$privSource = [
			"name" => $this->chatBot->char->name,
			"server" => $this->config->dimension,
		];
		/** @psalm-suppress DocblockTypeContradiction */
		if (strlen($this->config->orgName)) {
			if (isset($orgLabel) && $orgLabel !== "none") {
				$privSource['label'] = $orgLabel;
			}
			$privSource['channel'] = "Guest";
		}
		$privSource['type'] = "priv";
		$privUsers = [];
		foreach ($onlinePriv as $player) {
			$privUsers []= [
				"name" => $player->name,
				"id" => $player->charid,
			];
		}
		$onlineList["online"] []= [
			"source" => $privSource,
			"users" => $privUsers,
		];
		return new OnlineList($onlineList);
	}

	protected function sendOnlineList(): void {
		$onlineList = $this->getOnlineList();
		$this->logger->debug("Sending online list on {relay}", [
			"relay" => $this->relay->getName(),
			"onlineList" => $onlineList,
		]);
		$this->relay->receiveFromMember(
			$this,
			[$this->jsonEncode($onlineList)]
		);
	}

	protected function receiveMessage(Message $packet): RoutableEvent {
		$event = new RoutableEvent();
		$event->type = $event::TYPE_MESSAGE;
		if (isset($packet->user)) {
			$event->setCharacter(new Character(
				$packet->user->name,
				$packet->user->id,
			));
		}
		if (isset($packet->source->channel)) {
			$event->prependPath(new Source(
				Source::ORG,
				$packet->source->name,
				$packet->source->label,
				$packet->source->server
			));
			$event->appendPath(new Source(
				$this->tyrTypeToNady($packet->source->type),
				$packet->source->channel
			));
		} else {
			$event->appendPath(new Source(
				$this->tyrTypeToNady($packet->source->type),
				$packet->source->name,
				$packet->source->label ?? null,
				$packet->source->server
			));
		}
		$event->setData($this->convertFromTyrColors($packet->message));
		$this->logger->debug("Decoded message on relay {relay}", [
			"relay" => $this->relay->getName(),
			"event" => $event,
		]);
		return $event;
	}

	protected function convertFromTyrColors(string $text): string {
		return preg_replace_callback(
			"/<\/(.*?)>/s",
			function (array $matches): string {
				$keep = ["font", "a", "img", "u", "i"];
				if (in_array($matches[1], $keep)) {
					return $matches[0];
				}
				return "<end>";
			},
			$text
		);
	}

	protected function nadyTypeToTyr(string $type): string {
		$map = [
			Source::ORG => "org",
			Source::PRIV => "priv",
			Source::PUB => "pub",
			Source::DISCORD_PRIV => "discord",
		];
		return $map[$type] ?? $type;
	}

	protected function tyrTypeToNady(string $type): string {
		$map = [
			"org" => Source::ORG,
			"priv" => Source::PRIV,
			"pub" => Source::PUB,
			"discord" => Source::DISCORD_PRIV,
		];
		return $map[$type] ?? $type;
	}

	public function init(callable $callback): array {
		$callback();
		if ($this->syncOnline) {
			$onlineList = $this->getOnlineList();
			return [
				$this->jsonEncode(new OnlineListRequest()),
				$this->jsonEncode($onlineList),
			];
		}
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	protected function jsonEncode($data): string {
		return json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
	}

	public static function supportsFeature(int $feature): bool {
		return (static::$supportedFeatures & $feature) === $feature;
	}
}
