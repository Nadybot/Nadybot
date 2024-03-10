<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use function Amp\async;
use function Safe\{preg_match, preg_replace};
use Closure;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\Player,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Routing\Character,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	RELAY_MODULE\Relay,
	RELAY_MODULE\RelayMessage,
};

#[
	NCA\RelayProtocol(
		name: "gcr",
		description: "This is the protocol that BeBot speaks natively.\n".
			"It supports sharing online lists and basic colorization.\n".
			"Nadybot only support colorization of messages from the\n".
			"org and guest chat and not the BeBot native encryption."
	),
	NCA\Param(
		name: "command",
		type: "string",
		description: "The command we send with each packet",
		required: false
	),
	NCA\Param(
		name: "prefix",
		type: "string",
		description: "The prefix we send with each packet, e.g. \"!\" or \"\"",
		required: false
	),
	NCA\Param(
		name: "sync-online",
		type: "bool",
		description: "Sync the online list with the other bots of this relay",
		required: false
	),
	NCA\Param(
		name: "send-logon",
		type: "bool",
		description: "Send messages that people in your org go online or offline",
		required: false
	)
]
class GcrProtocol implements RelayProtocolInterface {
	protected static int $supportedFeatures = self::F_ONLINE_SYNC;

	protected Relay $relay;

	protected string $command = "gcr";
	protected string $prefix = "";
	protected bool $syncOnline = true;
	protected bool $spamOnline = true;
	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private OnlineController $onlineController;

	#[NCA\Inject]
	private BotConfig $config;

	public function __construct(string $command="gcr", string $prefix="", bool $syncOnline=true, bool $spamOnline=false) {
		$this->command = $command;
		$this->prefix = $prefix;
		$this->syncOnline = $syncOnline;
		$this->spamOnline = $spamOnline;
	}

	public function send(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->renderMessage($event);
		}
		if ($event->getType() === RoutableEvent::TYPE_EVENT) {
			/** @var object $llEvent */
			$llEvent = $event->getData();
			if (isset($llEvent->type) && ($llEvent->type === Online::TYPE)) {
				return $this->renderUserState($event);
			}
		}
		return [];
	}

	/** @return string[] */
	public function renderMessage(RoutableEvent $event): array {
		$path = $event->getPath();
		$hops = [];
		$lastHop = null;
		foreach ($path as $hop) {
			$hopText = $hop->render($lastHop);
			if (isset($hopText)) {
				$hops []= "##relay_channel##[{$hopText}]##end##";
			}
		}
		$senderLink = "";
		$character = $event->getCharacter();
		if (isset($character) && $this->util->isValidSender($character->name)) {
			$senderLink = "##relay_name##{$character->name}:##end##";
		}
		return [
			$this->prefix.$this->command . " ".
				join(" ", $hops) . " {$senderLink} ". "##relay_message##".
				$this->text->formatMessage($event->getData()). "##end##",
		];
	}

	/** @return string[] */
	public function renderUserState(RoutableEvent $event): array {
		$character = $event->getData()->char ?? null;
		if (!isset($character) || !$this->util->isValidSender($character->name??-1)) {
			return [];
		}
		async(function () use ($character, $event): void {
			$player = $this->playerManager->byName($character->name);
			if (!isset($player)) {
				return;
			}
			$send = [];
			if (($msg = $this->getBeBotLogonOffMsg($player, $event)) !== null) {
				$send []= $msg;
			}
			if (($msg = $this->getBeBotLogonOffStatus($player, $event)) !== null) {
				$send []= $msg;
			}
			if (count($send)) {
				$this->relay->receiveFromMember($this, $send);
			}
		});
		return [];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (empty($message->packages)) {
			return null;
		}
		$data = array_shift($message->packages);
		$command = preg_quote($this->command, "/");
		if (!preg_match("/^.?{$command} (.+)/s", $data, $matches)) {
			if (preg_match("/^.?{$command}c (.+)/s", $data, $matches)) {
				return $this->handleOnlineCommands($message->sender, $matches[1]);
			}
			return null;
		}
		if (preg_match("/##logon_log(on|off)_spam##/s", $data)) {
			return $this->handleLogonSpam($message->sender, $data);
		}
		$data = $matches[1];
		$r = new RoutableMessage($data);
		while (preg_match("/^\s*\[##relay_channel##(.*?)##end##\]\s*/s", $data, $matches)) {
			if (preg_match("/ Guest$/", $matches[1])) {
				$source = new Source(
					Source::ORG,
					substr($matches[1], 0, -6)
				);
				$r->appendPath($source);
				if (isset($message->sender)) {
					$source = new Source(
						Source::PRIV,
						$message->sender,
						"Guest"
					);
					$r->appendPath($source);
				}
			} else {
				$source = new Source(
					count($r->path) ? Source::PRIV : Source::ORG,
					$matches[1]
				);
				$r->appendPath($source);
			}
			$data = preg_replace("/^\s*\[##relay_channel##(.*?)##end##\]\s*/s", "", $data);
		}
		while (preg_match("/^\s*##relay_channel##\[(.*?)\]##end##\s*/s", $data, $matches)) {
			if (preg_match("/ Guest$/", $matches[1])) {
				$source = new Source(
					Source::ORG,
					substr($matches[1], 0, -6)
				);
				$r->appendPath($source);
				if (isset($message->sender)) {
					$source = new Source(
						Source::PRIV,
						$message->sender,
						"Guest"
					);
					$r->appendPath($source);
				}
			} else {
				$source = new Source(
					count($r->path) ? Source::PRIV : Source::ORG,
					$matches[1]
				);
				$r->appendPath($source);
			}
			$data = preg_replace("/^\s*##relay_channel##\[(.*?)\]##end##\s*/s", "", $data);
		}
		if (preg_match("/\s*##relay_name##([a-zA-Z0-9_-]+)(.*?)##end##\s*/s", $data, $matches)) {
			$r->setCharacter(new Character($matches[1]));
			$data = preg_replace("/\s*##relay_name##([a-zA-Z0-9_-]+)(.*?)##end##\s*/", "", $data);
		}
		if (preg_match("/\s*##relay_message##(.*)##end##$/s", $data, $matches)) {
			$r->setData($this->replaceBeBotColors($matches[1]));
		}
		return $r;
	}

	public function handleLogonSpam(?string $sender, string $text): ?RoutableEvent {
		if (!preg_match("/##logon_log(off|on)_spam##(.+)##end##$/s", $text, $matches)) {
			return null;
		}
		$r = new RoutableEvent();
		$r->type = RoutableEvent::TYPE_EVENT;
		$r->path = [];
		$online = new Online();
		$online->online = $matches[1] === "on";
		$online->message = $this->replaceBeBotColors($matches[2]);
		$online->renderPath = false;
		$r->data = $online;
		return $r;
	}

	public function handleOnlineCommands(?string $sender, string $text): ?RoutableEvent {
		if (!isset($sender) || !$this->syncOnline) {
			return null;
		}
		if (preg_match("/^buddy (?<status>\d) (?<char>.+?) (?<where>[^ ]+)( \d+)?$/", $text, $matches)) {
			$callback = ($matches['status'] === '1')
				? Closure::fromCallable([$this->relay, "setOnline"])
				: Closure::fromCallable([$this->relay, "setOffline"]);
			async(function () use ($matches, $callback, $sender): void {
				$player = $this->playerManager->byName($sender);
				if (!isset($player)) {
					return;
				}
				$channel = (isset($player->guild) && strlen($player->guild))
						? ($matches['where'] === 'pg'
							? "{$player->guild} Guest"
							: "{$player->guild}")
						: "{$player->name}";
				$callback($player->name, $channel, $matches['char']);
			});
		} elseif (preg_match("/^online (.+)$/", $text, $matches)) {
			async(function () use ($matches, $sender): void {
				$player = $this->playerManager->byName($sender);
				if (!isset($player)) {
					return;
				}
				$chars = explode(";", $matches[1]);
				foreach ($chars as $char) {
					[$name, $where, $rank] = [...explode(",", $char), null, null];

					/** @psalm-suppress DocblockTypeContradiction */
					if (!isset($name)) {
						continue;
					}
					$this->relay->setOnline(
						$player->name,
						(!isset($player->guild) || !strlen($player->guild))
							? ($where === 'pg'
								? "{$player->guild} Guest"
								: "{$player->guild}")
							: "{$player->name}",
						$name
					);
				}
			});
		} elseif (preg_match("/^onlinereq$/", $text, $matches)) {
			$onlineList = $this->getOnlineList();
			if (isset($onlineList)) {
				$data = $this->getOnlineList();
				if (isset($data)) {
					$this->relay->receiveFromMember(
						$this,
						[$data]
					);
				}
			}
		}
		return null;
	}

	public function getOnlineList(): ?string {
		$chunks = [];
		$onlineOrg = $this->onlineController->getPlayers('guild', $this->config->main->character);
		foreach ($onlineOrg as $char) {
			$chunks []= "{$char->name},gc,{$char->guild_rank_id}";
		}
		$onlineOrg = $this->onlineController->getPlayers('priv', $this->config->main->character);
		foreach ($onlineOrg as $char) {
			$chunks []= "{$char->name},pg";
		}
		if (empty($chunks)) {
			return null;
		}
		return $this->prefix.$this->command . "c online " . join(";", $chunks);
	}

	/** Parse and replace BeBot-style color-codes (##red##) with their actual colors (<font>) */
	public function replaceBeBotColors(string $text): string {
		$colors = [
			"aqua"         => "#00FFFF",
			"beige"        => "#FFE3A1",
			"black"        => "#000000",
			"blue"         => "#0000FF",
			"bluegray"     => "#8CB6FF",
			"bluesilver"   => "#9AD5D9",
			"brown"        => "#999926",
			"darkaqua"     => "#2299FF",
			"darklime"     => "#00A651",
			"darkorange"   => "#DF6718",
			"darkpink"     => "#FF0099",
			"forestgreen"  => "#66AA66",
			"fuchsia"      => "#FF00FF",
			"gold"         => "#CCAA44",
			"gray"         => "#808080",
			"green"        => "#008000",
			"lightbeige"   => "#FFFFC9",
			"lightfuchsia" => "#FF63FF",
			"lightgray"    => "#D9D9D2",
			"lightgreen"   => "#00DD44",
			"brightgreen"  => "#00F000",
			"lightmaroon"  => "#FF0040",
			"lightteal"    => "#15E0A0",
			"dullteal"     => "#30D2FF",
			"lightyellow"  => "#DEDE42",
			"lime"         => "#00FF00",
			"maroon"       => "#800000",
			"navy"         => "#000080",
			"olive"        => "#808000",
			"orange"       => "#FF7718",
			"pink"         => "#FF8CFC",
			"purple"       => "#800080",
			"red"          => "#FF0000",
			"redpink"      => "#FF61A6",
			"seablue"      => "#6699FF",
			"seagreen"     => "#66FF99",
			"silver"       => "#C0C0C0",
			"tan"          => "#DDDD44",
			"teal"         => "#008080",
			"white"        => "#FFFFFF",
			"yellow"       => "#FFFF00",
			"omni"         => "#00FFFF",
			"clan"         => "#FF9933",
			"neutral"      => "#FFFFFF",
		];
		$hlColor = $this->settingManager->getString('default_highlight_color') ?? "";
		if (preg_match("/(#[A-F0-9]{6})/i", $hlColor, $matches)) {
			$colors["highlight"] = $matches[1];
		}

		$colorAliases = [
			"admin"          => "pink",
			"cash"           => "gold",
			"ccheader"       => "white",
			"cctext"         => "lightgray",
			"clan"           => "brightgreen",
			"emote"          => "darkpink",
			"error"          => "red",
			"feedback"       => "yellow",
			"gm"             => "redpink",
			"infoheader"     => "lightgreen",
			"infoheadline"   => "tan",
			"infotext"       => "forestgreen",
			"infotextbold"   => "white",
			"megotxp"        => "yellow",
			"meheald"        => "bluegray",
			"mehitbynano"    => "white",
			"mehitother"     => "lightgray",
			"menubar"        => "lightteal",
			"misc"           => "white",
			"monsterhitme"   => "red",
			"mypet"          => "orange",
			"newbie"         => "seagreen",
			"news"           => "brightgreen",
			"none"           => "fuchsia",
			"npcchat"        => "bluesilver",
			"npcdescription" => "yellow",
			"npcemote"       => "lightbeige",
			"npcooc"         => "lightbeige",
			"npcquestion"    => "lightgreen",
			"npcsystem"      => "red",
			"npctrade"       => "lightbeige",
			"otherhitbynano" => "bluesilver",
			"otherpet"       => "darkorange",
			"pgroup"         => "white",
			"playerhitme"    => "red",
			"seekingteam"    => "seablue",
			"shout"          => "lightbeige",
			"skillcolor"     => "beige",
			"system"         => "white",
			"team"           => "seagreen",
			"tell"           => "aqua",
			"tooltip"        => "black",
			"tower"          => "lightfuchsia",
			"vicinity"       => "lightyellow",
			"whisper"        => "dullteal",
			"logon_level"    => "highlight",
			"logon_ailevel"  => "lightgreen",
			"logon_organization" => "highlight",
		];
		$colorizedText = preg_replace_callback(
			"/##([a-zA-Z_]+)##/",
			function (array $matches) use ($colorAliases, $colors): string {
				$color = strtolower($matches[1]);
				if (isset($colorAliases[$color])) {
					$color = $colorAliases[$color];
				}
				if (isset($colors[$color])) {
					return "<font color={$colors[$color]}>";
				} elseif ($color === "end") {
					return "</font>";
				}
				return $matches[0];
			},
			$text
		);
		return $colorizedText;
	}

	public function init(callable $callback): array {
		$callback();
		if ($this->syncOnline) {
			return array_values(array_filter([
				$this->getOnlineList(),
				$this->prefix.$this->command . "c onlinereq",
			]));
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

	public static function supportsFeature(int $feature): bool {
		return (static::$supportedFeatures & $feature) === $feature;
	}

	protected function getBeBotLogonOffStatus(Player $player, RoutableEvent $event): ?string {
		if (!$this->syncOnline) {
			return null;
		}
		$path = $event->getPath();
		$lastHop = $path[count($path)-1] ?? null;
		if (!isset($lastHop)) {
			return null;
		}
		$onlineUpdate = $this->prefix.$this->command . "c buddy ".
			(int)$event->getData()->online . " {$player->name} ";
		if ($lastHop->type === Source::ORG) {
			return $onlineUpdate . "gc {$player->guild_rank_id}";
		} elseif ($lastHop->type === Source::PRIV) {
			return $onlineUpdate . "pg";
		}
		return null;
	}

	protected function getBeBotLogonOffMsg(Player $player, RoutableEvent $event): ?string {
		if (!$this->spamOnline) {
			return null;
		}
		$path = $event->getPath();
		$lastHop = $path[count($path)-1] ?? null;
		if (!isset($lastHop) || $lastHop->type !== Source::ORG) {
			return null;
		}
		if (!$event->getData()->online) {
			return $this->prefix.$this->command . " ".
				"##logon_logoff_spam##{$player->name} logged off##end##";
		}
		$msg = $this->prefix.$this->command . " ".
			"##logon_logon_spam##".
			"##highlight##{$player->name}##end## ".
			"(Lvl ##logon_level##{$player->level}##end##/".
			"##logon_ailevel##{$player->ai_level}##end## ".
			$player->faction;
		if (isset($player->profession)) {
			$msg .= " " . $player->profession;
		}
		if (strlen($player->guild??"")) {
			$msg .= ", ##logon_organization##{$player->guild_rank} ".
			"of {$player->guild}##end##";
		}
		$msg .= ") logged On##end##";
		return $msg;
	}
}
