<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Routing\Source,
	Text,
	Util,
};

/**
 * @author Tyrence
 * @author Nadyita
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "quickrelay",
		accessLevel: "member",
		description: "Print commands to easily setup relays",
	)
]
class QuickRelayController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	/** List all supported quick relay types */
	#[NCA\HandlesCommand("quickrelay")]
	public function quickrelayListCommand(CmdContext $context): void {
		$types = [
			"nady" => "Use this to setup relaying between two or more ".
				"Nadybots.\n".
				"This relay type only works between Nadybots. It provides ".
				"near-instant messages,\n".
				"encryption and shared online lists.",
			"alliance" => "Use this to setup relaying between two or more ".
				"bots of mixed type.\n".
				"This relay type is supported by all bots, but it requires a ".
				"private channel for relaying.\n".
				"It provides Funcom-speed messages, but no encryption or ".
				"shared online lists.\n".
				"Colors usually cannot be configured on the other bots and ".
				"Nadybot can only detect\n".
				"org and guest chat for colorization.",
			"tyr" => "Use this to setup relaying between a mixed setup ".
				"of only Tyrbots and Nadybots.\n".
				"Currently, the only way to use this in practice is by ".
				"setting up your own local relay server\n".
				"from <a href='chatcmd:///start https://github.com/Budabot/Tyrbot/wiki/Websocket-Relay'>here</a> ".
				"and have all bots connect to it.\n".
				"It provides near-realtime-speed messages, encryption and ".
				"shared online lists.\n".
				"Customization of all colors is possible",
			"old" => "Use this to setup relaying between two or more ".
				"(older) bots of mixed type.\n".
				"This relay type is supported by all bots, except for ".
				"BeBot, and it requires\n".
				"a private channel for relaying.\n".
				"It provides Funcom-speed messages, but no encryption or ".
				"shared online lists.\n".
				"Colors can be configured on participating Nadybots, ".
				"but they can only detect\n".
				"org, guest and raidbot chat. Don't use it unless you have to.",
		];
		$blobs = [];
		foreach ($types as $type => $description) {
			$runLink = $this->text->makeChatcmd(
				"instructions",
				"/tell <myname> quickrelay {$type}"
			);
			$blobs []= "<header2>" . ucfirst($type) . " [{$runLink}]<end>\n".
				"<tab>" . join("\n<tab>", explode("\n", $description));
		}
		$msg = $this->text->makeBlob(
			count($types) . " relay-types found",
			join("\n\n", $blobs)
		);
		$context->reply($msg);
	}

	/** Show a quick setup for a Tyrbot-relay */
	#[NCA\HandlesCommand("quickrelay")]
	public function quickrelayTyrCommand(CmdContext $context, #[NCA\Str("tyr")] string $type): void {
		$password = $this->util->getPassword(16);
		$blob = "First, you have to run a local installation of <a href='chatcmd:///start https://github.com/Budabot/Tyrbot/wiki/Websocket-Relay'>".
			"Tyrence's Websocket relay</a>.\n".
			"This example assumes that we can connect to it at ws://127.0.0.1:8000.\n\n".
			"To setup a relay called \"{$type}\" between multiple Nadybots and Tyrbots, run this on all bots:\n".
			"<tab><highlight><symbol>relay add {$type} websocket(server=\"ws://127.0.0.1:8000/subscribe/relay\") ".
				"tyr-relay() ".
				"tyr-encryption(password=\"{$password}\") ".
				"tyrbot()<end>\n\n".
			$this->getRouteInformation($type, true).
			$this->getDisclaimer($type);
		$msg = "Instructions to setup the relay \"{$type}\"";
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	/** Show a quick setup for a Nadybot-relay */
	#[NCA\HandlesCommand("quickrelay")]
	public function quickrelayNadyCommand(CmdContext $context, #[NCA\Str("nady")] string $type): void {
		$password = $this->util->getPassword(16);
		$room = $this->util->createUUID();
		$blob = "To setup a relay called \"nady\" between multiple Nadybots, run this on all bots:\n".
			"<tab><highlight><symbol>relay add nady websocket(server=\"wss://ws.nadybot.org\") ".
				"highway(room=\"{$room}\") ".
				"aes-gcm-encryption(password=\"{$password}\") ".
				"nadynative()<end>\n\n".
			$this->getRouteInformation("nady", true).
			$this->getDisclaimer("nady");
		$msg = "Instructions to setup the relay \"nady\"";
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	/** Show a quick setup for an Alliance-relay */
	#[NCA\HandlesCommand("quickrelay")]
	public function quickrelayAllianceCommand(CmdContext $context, #[NCA\Str("alliance", "agcr")] string $type): void {
		$blob = "To setup a relay called \"alliance\" between multiple bots that use the agcr-protocol\n".
			"and relay via a private-channel called \"Privchannel\", run this on all bots:\n".
			"<tab><highlight><symbol>relay add alliance private-channel(channel=\"Privchannel\") ".
				"agcr()<end>\n\n".
			"For this to work, the bot \"Privchannel\" must invite <myname> to its ".
				"private channel.\n".
			"Contact the bot person of your alliance to take care of that.\n\n".
			$this->getRouteInformation("alliance").
			$this->getDisclaimer("alliance");
		$msg = "Instructions to setup the relay \"alliance\"";
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	/** Show a quick setup for an old GRC-relay */
	#[NCA\HandlesCommand("quickrelay")]
	public function quickrelayOldCommand(CmdContext $context, #[NCA\Str("old", "grc")] string $type): void {
		$blob = "To setup a relay called \"compat\" between multiple bots that use the grc-protocol\n".
			"and relay via a private-channel called \"Privchannel\", run this on all bots:\n".
			"<tab><highlight><symbol>relay add compat private-channel(channel=\"Privchannel\") ".
				"grcv2()<end>\n\n".
			"For this to work, the bot \"Privchannel\" must invite <myname> to its ".
				"private channel.\n".
			"Contact the bot person of your alliance to take care of that.\n\n".
			$this->getRouteInformation("compat").
			$this->getDisclaimer("compat");
		$msg = "Instructions to setup the relay \"compat\"";
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	public function getRouteInformation(string $name, bool $sharedOnline=false): string {
		$cmd1 = "route add relay({$name}) <-> " . Source::ORG;
		$cmd2 = "route add relay({$name}) <-> " . Source::PRIV;
		$cmd3 = "route add relay({$name}) <-> " . Source::ORG . ' if-has-prefix(prefix="-")';
		$cmd4 = "route add relay({$name}) <-> " . Source::PRIV . ' if-has-prefix(prefix="-")';
		$blob = "To relay all messages between your chats and the relay, run this on all Nadybots:\n".
			"<tab><highlight><symbol>" . htmlentities($cmd1) . "<end>\n".
			"<tab><highlight><symbol>" . htmlentities($cmd2) . "<end>\n\n".
			"Or, to only relay messages when they start with \"-\", run this on all Nadybots:\n".
			"<tab><highlight><symbol>" . htmlentities($cmd3) . "<end>\n".
			"<tab><highlight><symbol>" . htmlentities($cmd4) . "<end>";
		if (!$sharedOnline) {
			return $blob;
		}
		$blob .= "\n\nBy default, this will route online/offline/join/leave messages to\n".
			"and from the relay. If you only want to share online lists between the bots,\n".
			"but don't want to display these messages, add\n".
			"<tab><highlight>remove-online-messages()<end> to the end of all route commands.";
		return $blob;
	}

	protected function getDisclaimer(string $name): string {
		return "\n\n<i>".
			"This will create a relay named \"{$name}\".\n".
			"Feel free to change the name or any of the parameters to your needs.\n".
			"Except for the name, the <symbol>relay-command must be ".
			"executed exactly the same on all the bots.\n\n".
			"The Nadybot Wiki has a more detailed documentation of ".
			"<a href='chatcmd:///start https://github.com/Nadybot/Nadybot/wiki/Relaying-(form-5.2-onward)'>".
			"the relay stack</a> and ".
			"<a href='chatcmd:///start https://github.com/Nadybot/Nadybot/wiki/Routing'>".
			"how routing is configured</a>.".
			"</i>";
	}
}
