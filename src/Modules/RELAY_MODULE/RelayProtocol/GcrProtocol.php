<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Event;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\Events\Online;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Util;
use Nadybot\Core\Text;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;

/**
 * @RelayProtocol("gcr")
 * @Description("This is the protocol that BeBot speaks natively.
 * 	It supports sharing online lists and basic colorization.
 * 	Nadybot only support colorization of messages from the
 * 	org and guest chat and not the BeBot native encryption.")
 * @Param(name='command', description='The command we send with each packet', type='string', required=false)
 * @Param(name='prefix', description='The prefix we send with each packet, e.g. "!" or ""', type='string', required=false)
 * @Param(name='sync-online', description='Sync the online list with the other bots of this relay', type='bool', required=false)
 * @Param(name='send-logon', description='Send messages that people in your org go online or offline', type='bool', required=false)
 */
class GcrProtocol implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public OnlineController $onlineController;

	protected string $command = "gcr";
	protected string $prefix = "";
	protected bool $syncOnline = true;
	protected bool $spamOnline = true;

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
			/** @var Event $llEvent */
			$llEvent = $event->getData();
			if ($llEvent->type??null === Online::TYPE) {
				return $this->renderUserState($event);
			}
		}
		return [];
	}

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
				$this->text->formatMessage($event->getData()). "##end##"
		];
	}

	public function renderUserState(RoutableEvent $event): array {
		$character = $event->getData()->char ?? null;
		if (!isset($character) || !$this->util->isValidSender($character->name??-1)) {
			return [];
		}
		$this->playerManager->getByNameCallback(
			function(?Player $player) use ($event): void {
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
			},
			false,
			$character->name
		);
		return [];
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
			$player->faction . " " . $player->profession;
		if (strlen($player->guild??"")) {
			$msg .= ", ##logon_organization##{$player->guild_rank} ".
			"of {$player->guild}##end##";
		}
		$msg .= ") logged On##end##";
		return $msg;
	}

	public function receive(RelayMessage $msg): ?RoutableEvent {
		if (empty($msg->packages)) {
			return null;
		}
		$data = array_shift($msg->packages);
		$command = preg_quote($this->command, "/");
		if (!preg_match("/^.?{$command} (.+)/s", $data, $matches)) {
			if (preg_match("/^.?{$command}c (.+)/s", $data, $matches)) {
				return $this->handleOnlineCommands($msg->sender, $matches[1]);
			}
			return null;
		}
		if (preg_match("/##logon_log(on|off)_spam##/s", $data)) {
			return $this->handleLogonSpam($msg->sender, $data);
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
				$source = new Source(
					Source::PRIV,
					$msg->sender,
					"Guest"
				);
				$r->appendPath($source);
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
				$source = new Source(
					Source::PRIV,
					$msg->sender,
					"Guest"
				);
				$r->appendPath($source);
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
		$r->data = new Online();
		$r->data->online = $matches[1] === "on";
		$r->data->message = $this->replaceBeBotColors($matches[2]);
		$r->data->renderPath = false;
		return $r;
	}

	public function handleOnlineCommands(?string $sender, string $text): ?RoutableEvent {
		if (!isset($sender) || !$this->syncOnline) {
			return null;
		}
		if (preg_match("/^buddy (?<status>\d) (?<char>.+?) (?<where>[^ ]+)( \d+)?$/", $text, $matches)) {
			$callback = ($matches['status'] === '1')
				? [$this->relay, "setOnline"]
				: [$this->relay, "setOffline"];
			$this->playerManager->getByNameCallback(
				function(?Player $player) use ($matches, $callback): void {
					if (!isset($player)) {
						return;
					}
					$callback(
						$player->name,
						(!empty($player->guild))
							? ($matches['where'] === 'pg'
								? "{$player->guild} Guest"
								: "{$player->guild}")
							: "{$player->name}",
						$matches['char']
					);
				},
				false,
				$sender
			);
		} elseif (preg_match("/^online (.+)$/", $text, $matches)) {
			$this->playerManager->getByNameCallback(
				function(?Player $player) use ($matches): void {
					if (!isset($player)) {
						return;
					}
					$chars = explode(";", $matches[1]);
					foreach ($chars as $char) {
						[$name,$where,$rank] = [...explode(",", $char), null, null];
						$this->relay->setOnline(
							$player->name,
							(!empty($player->guild))
								? ($where === 'pg'
									? "{$player->guild} Guest"
									: "{$player->guild}")
								: "{$player->name}",
							$name
						);
					}
				},
				false,
				$sender
			);
		} elseif (preg_match("/^onlinereq$/", $text, $matches)) {
			$onlineList = $this->getOnlineList();
			if (isset($onlineList)) {
				$this->relay->receiveFromMember(
					$this,
					[$this->getOnlineList()]
				);
			}
		}
		return null;
	}

	public function getOnlineList(): ?string {
		$chunks = [];
		$onlineOrg = $this->onlineController->getPlayers('guild');
		foreach ($onlineOrg as $char) {
			$chunks []= "{$char->name},gc,{$char->guild_rank_id}";
		}
		$onlineOrg = $this->onlineController->getPlayers('priv');
		foreach ($onlineOrg as $char) {
			$chunks []= "{$char->name},pg";
		}
		if (empty($chunks)) {
			return null;
		}
		return $this->prefix.$this->command . "c online " . join(";", $chunks);
	}

	/**
	 * Parse and replace BeBot-style color-codes (##red##) with their actual colors (<font>)
	 */
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
		$hlColor = $this->settingManager->getString('default_highlight_color');
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
}
