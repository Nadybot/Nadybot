<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	AOChatEvent,
	CmdContext,
	CommandReply,
	Event,
	EventManager,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;

/**
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "rally",
		accessLevel: "all",
		description: "Shows the rally waypoint",
		help: "rally.txt"
	),
	NCA\DefineCommand(
		command: "rally .+",
		accessLevel: "rl",
		description: "Sets the rally waypoint",
		help: "rally.txt"
	),
	NCA\ProvidesEvent(
		desc: "Triggered when a rally point is set",
		value: "sync(rally-set)"
	),
	NCA\ProvidesEvent(
		desc: "Triggered when someone clears the rally point",
		value: "sync(rally-clear)"
	)
]
class ChatRallyController {
	public string $moduleName;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public PlayfieldController $playfieldController;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public ChatLeaderController $chatLeaderController;

	#[NCA\Setup]
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
	 * This command displays the current rally location
	 */
	#[NCA\HandlesCommand("rally")]
	public function rallyCommand(CmdContext $context): void {
		$this->replyCurrentRally($context);
	}

	/**
	 * This command handler clears the current rally location
	 * @Mask $action clear
	 */
	#[NCA\HandlesCommand("rally .+")]
	public function rallyClearCommand(CmdContext $context, string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->clear();
		$msg = "Rally has been cleared.";
		$context->reply($msg);
		$rEvent = new SyncRallyClearEvent();
		$rEvent->owner = $context->char->name;
		$rEvent->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($rEvent);
	}

	/**
	 * This command handler sets rally waypoint, using following example syntaxes:
	 *  - rally 10.9 x 30 x <playfield id/name>
	 *  - rally 10.9 . 30 . <playfield id/name>
	 *  - rally 10.9, 30, <playfield id/name>
	 *  - etc...
	 * @Mask $x ([0-9.]+\s*(?:[x,.]*))
	 * @Mask $y ([0-9.]+\s*(?:[x,.]*))
	 */
	#[NCA\HandlesCommand("rally .+")]
	public function rallySet2Command(CmdContext $context, string $x, string $y, PWord $pf): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$xCoords = (float)$x;
		$yCoords = (float)$y;

		$playfieldName = $pf();
		if (is_numeric($pf())) {
			$playfieldId = (int)$pf();
			$playfieldName = (string)$playfieldId;

			$playfield = $this->playfieldController->getPlayfieldById($playfieldId);
			if ($playfield !== null && isset($playfield->short_name)) {
				$playfieldName = $playfield->short_name;
			}
		} else {
			$playfieldName = $pf();
			$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
			if ($playfield === null) {
				$context->reply("Could not find playfield '{$playfieldName}'");
				return;
			}
			$playfieldId = $playfield->id;
		}
		$this->set($playfieldName, $playfieldId, (string)$xCoords, (string)$yCoords);
		$this->replyCurrentRally($context);
		$rEvent = new SyncRallySetEvent();
		$rEvent->x = (int)round($xCoords);
		$rEvent->y = (int)round($yCoords);
		$rEvent->pf = $playfieldId;
		$rEvent->owner = $context->char->name;
		$rEvent->name = $playfieldName;
		$rEvent->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($rEvent);
	}

	/**
	 * This command handler sets rally waypoint, using following example syntaxes:
	 *  - rally (10.9 30 y 20 2434234)
	 */
	#[NCA\HandlesCommand("rally .+")]
	public function rallySet1Command(CmdContext $context, string $input): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		if (preg_match("/(\d+\.\d) (\d+\.\d) y \d+\.\d (\d+)/", $input, $matches)) {
			$xCoords = $matches[1];
			$yCoords = $matches[2];
			$playfieldId = (int)$matches[3];
		} else {
			$context->reply("This does not look like full coordinates. Please press shift+F9 and paste the first line starting with a dash.");
			return;
		}

		$name = (string)$playfieldId;
		$playfield = $this->playfieldController->getPlayfieldById($playfieldId);
		if ($playfield !== null && isset($playfield->short_name)) {
			$name = $playfield->short_name;
		}
		$this->set($name, $playfieldId, $xCoords, $yCoords);
		$this->replyCurrentRally($context);

		$rEvent = new SyncRallySetEvent();
		$rEvent->x = (int)round((float)$xCoords);
		$rEvent->y = (int)round((float)$yCoords);
		$rEvent->pf = $playfieldId;
		$rEvent->name = $name;
		$rEvent->owner = $context->char->name;
		$rEvent->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($rEvent);
	}

	#[NCA\Event(
		name: "sync(rally-set)",
		description: "Handle synced rally sets"
	)]
	public function handleExtRallySet(SyncRallySetEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->set($event->name, $event->pf, (string)$event->x, (string)$event->y);
	}

	#[NCA\Event(
		name: "sync(rally-clear)",
		description: "Handle synced rally clears"
	)]
	public function handleExtRallyClear(SyncRallyClearEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->clear();
	}

	#[NCA\Event(
		name: "joinpriv",
		description: "Sends rally to players joining the private channel"
	)]
	public function sendRally(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;

		$rally = $this->get();
		if ($rally !== '' && is_string($sender)) {
			$this->chatBot->sendMassTell($rally, $sender);
		}
	}

	public function set(string $name, int $playfieldId, string $xCoords, string $yCoords): string {
		$this->settingManager->save("rally", join(":", array_map("strval", func_get_args())));

		return $this->get();
	}

	public function get(): string {
		$data = $this->settingManager->getString("rally")??"";
		if (strpos($data, ":") === false) {
			return "";
		}
		[$name, $playfieldId, $xCoords, $yCoords] = explode(":", $data);
		$link = $this->text->makeChatcmd("Rally: {$xCoords}x{$yCoords} {$name}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use rally: $link";
		$blob .= "\n\n" . $this->text->makeChatcmd("Clear Rally", "/tell <myname> rally clear");
		return ((array)$this->text->makeBlob("Rally: {$xCoords}x{$yCoords} {$name}", $blob))[0];
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

	#[
		NCA\NewsTile("rally"),
		NCA\Description("Will show a waypoint-link to the current rally-point - if any"),
		NCA\Example("<header2>Rally<end>\n".
			"<tab>We are rallying <u>here</u>")
	]
	public function rallyTile(string $sender, callable $callback): void {
		$data = $this->settingManager->getString("rally")??"";
		if (strpos($data, ":") === false) {
			$callback(null);
			return;
		}
		[$name, $playfieldId, $xCoords, $yCoords] = explode(":", $data);
		$link = $this->text->makeChatcmd("here", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$msg = "<header2>Rally<end>\n<tab>We are rallying {$link}";
		$callback($msg);
	}
}
