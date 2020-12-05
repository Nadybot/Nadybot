<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	CommandReply,
	Event,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;

/**
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'rally',
 *		accessLevel = 'all',
 *		description = 'Shows the rally waypoint',
 *		help        = 'rally.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'rally .+',
 *		accessLevel = 'rl',
 *		description = 'Sets the rally waypoint',
 *		help        = 'rally.txt'
 *	)
 */
class ChatRallyController {
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public PlayfieldController $playfieldController;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"rally",
			"Rally waypoint for topic",
			"noedit",
			"text",
			""
		);
	}

	/**
	 * This command handler ...
	 * @HandlesCommand("rally")
	 * @Matches("/^rally$/i")
	 */
	public function rallyCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->replyCurrentRally($sendto);
	}
	
	/**
	 * This command handler ...
	 * @HandlesCommand("rally .+")
	 * @Matches("/^rally clear$/i")
	 */
	public function rallyClearCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->clear();
		$msg = "Rally has been cleared.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler sets rally waypoint, using following example syntaxes:
	 *  - rally 10.9 x 30 x <playfield id/name>
	 *  - rally 10.9 . 30 . <playfield id/name>
	 *  - rally 10.9, 30, <playfield id/name>
	 *  - etc...
	 *
	 * @HandlesCommand("rally .+")
	 * @Matches("/^rally ([0-9\.]+)([x,. ]+)([0-9\.]+)([x,. ]+)([^ ]+)$/i")
	 */
	public function rallySet2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$xCoords = $args[1];
		$yCoords = $args[3];

		if (is_numeric($args[5])) {
			$playfieldId = (int)$args[5];
			$playfieldName = $playfieldId;

			$playfield = $this->playfieldController->getPlayfieldById((int)$playfieldId);
			if ($playfield !== null) {
				$playfieldName = $playfield->short_name;
			}
		} else {
			$playfieldName = $args[5];
			$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
			if ($playfield === null) {
				$sendto->reply("Could not find playfield '$playfieldName'");
				return;
			}
			$playfieldId = $playfield->id;
		}
		$this->set($playfieldName, $playfieldId, $xCoords, $yCoords);

		$this->replyCurrentRally($sendto);
	}
	
	/**
	 * This command handler sets rally waypoint, using following example syntaxes:
	 *  - rally (10.9 30 y 20 2434234)
	 *
	 * @HandlesCommand("rally .+")
	 * @Matches("/^rally (.+)$/i")
	 */
	public function rallySet1Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		if (preg_match("/(\d+\.\d) (\d+\.\d) y \d+\.\d (\d+)/", $args[1], $matches)) {
			$xCoords = $matches[1];
			$yCoords = $matches[2];
			$playfieldId = (int)$matches[3];
		} else {
			$sendto->reply("This does not look like full coordinates. Please press shift+F9 and paste the first line starting with a dash.");
			return;
		}

		$name = (string)$playfieldId;
		$playfield = $this->playfieldController->getPlayfieldById($playfieldId);
		if ($playfield !== null) {
			$name = $playfield->short_name;
		}
		$this->set($name, $playfieldId, $xCoords, $yCoords);

		$this->replyCurrentRally($sendto);
	}

	/**
	 * @Event("joinpriv")
	 * @Description("Sends rally to players joining the private channel")
	 */
	public function sendRally(Event $eventObj): void {
		$sender = $eventObj->sender;

		$rally = $this->get();
		if ($rally !== '') {
			$this->chatBot->sendMassTell($rally, $sender);
		}
	}

	public function set(string $name, int $playfieldId, string $xCoords, string $yCoords): string {
		$link = $this->text->makeChatcmd("Rally: {$xCoords}x{$yCoords} {$name}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use rally: $link";
		$blob .= "\n\n" . $this->text->makeChatcmd("Clear Rally", "/tell <myname> rally clear");
		$rally = $this->text->makeBlob("Rally: {$xCoords}x{$yCoords} {$name}", $blob);

		$this->settingManager->save("rally", $rally);

		return $rally;
	}

	public function get(): string {
		return $this->settingManager->get("rally");
	}

	public function clear(): void {
		$this->settingManager->save("rally", '');
	}
	
	public function replyCurrentRally(CommandReply $sendto): void {
		$rally = $this->get();
		if ($rally === '') {
			$msg = "No rally set.";
			$sendto->reply($msg);
			return;
		}
		$sendto->reply($rally);
	}
}
