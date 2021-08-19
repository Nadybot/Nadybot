<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{
	CommandReply,
	Text,
	Util,
};
use Nadybot\Core\Routing\Source;

/**
 * @author Tyrence
 * @author Nadyita
 *
 * @Instance
 *
 * Commands this controller contains:
 *  @DefineCommand(
 *		command     = 'quickrelay',
 *		accessLevel = 'member',
 *		description = 'Print commands to easily setup relays',
 *		help        = 'quickrelay.txt'
 *	)
 */
class QuickRelayController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/**
	 * @HandlesCommand("quickrelay")
	 * @Matches("/^quickrelay$/i")
	 */
	public function quickrelayListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
				"Colors usually cannot be configured on the other bots.",
			"old" => "Use this to setup relaying between two or more ".
				"(older) bots of mixed type.\n".
				"This relay type is supported by all bots, except for ".
				"BeBot, and it requires\n".
				"a private channel for relaying.\n".
				"It provides Funcom-speed messages, but no encryption or ".
				"shared online lists.\n".
				"Colors can be configured on participating Nadybots",
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("quickrelay")
	 * @Matches("/^quickrelay nady$/i")
	 */
	public function quickrelayNadyCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$password = $this->util->getPassword(16);
		$salt = $this->util->getPassword(8);
		$room = $this->util->createUUID();
		$blob = "To setup a relay called \"nady\" between multiple Nadybots, run this on all bots:\n".
			"<tab><highlight><symbol>relay add nady websocket(server=\"wss://ws.nadybot.org\") ".
				"highway(room=\"{$room}\") ".
				"fernet-encryption(password=\"{$password}\", salt=\"{$salt}\") ".
				"nadynative()<end>\n\n".
			$this->getRouteInformation("nady").
			$this->getDisclaimer("nady");
		$msg = "Instructions to setup the relay \"nady\"";
		$msg = $this->text->makeBlob($msg, $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("quickrelay")
	 * @Matches("/^quickrelay alliance$/i")
	 * @Matches("/^quickrelay agcr$/i")
	 */
	public function quickrelayAllianceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("quickrelay")
	 * @Matches("/^quickrelay old$/i")
	 * @Matches("/^quickrelay grc$/i")
	 */
	public function quickrelayOldCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$sendto->reply($msg);
	}

	protected function getDisclaimer(string $name): string {
		return "\n\n<i>".
			"This will create a relay named \"{$name}\". Feel free to ".
			"change the name or any of the parameters to your needs.\n".
			"Except for the name, the <symbol>relay-command must be ".
			"executed exactly the same on all the bots.".
			"</i>";
	}

	public function getRouteInformation(string $name): string {
		$cmd1 = "route add relay({$name}) <-> " . Source::ORG;
		$cmd2 = "route add relay({$name}) <-> " . Source::PRIV;
		$cmd3 = "route add relay({$name}) <-> " . Source::ORG . ' if-has-prefix(prefix="-")';
		$cmd4 = "route add relay({$name}) <-> " . Source::PRIV . ' if-has-prefix(prefix="-")';
		return "To relay all messages between your chats and the relay, run this on all Nadybots:\n".
			"<tab><highlight><symbol>" . htmlentities($cmd1) . "<end>\n".
			"<tab><highlight><symbol>" . htmlentities($cmd2) . "<end>\n\n".
			"Or, to only relay messages when they start with \"-\", run this on all Nadybots:\n".
			"<tab><highlight><symbol>" . htmlentities($cmd3) . "<end>\n".
			"<tab><highlight><symbol>" . htmlentities($cmd4) . "<end>";
	}
}
