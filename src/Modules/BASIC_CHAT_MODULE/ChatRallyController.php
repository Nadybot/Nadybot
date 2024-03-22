<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Event\JoinMyPrivEvent;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandReply,
	EventManager,
	ModuleInstance,
	Nadybot,
	ParamClass\PWord,
	Safe,
	SettingManager,
	Text,
};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;

/**
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'rally',
		accessLevel: 'guest',
		description: 'Shows the rally waypoint',
	),
	NCA\DefineCommand(
		command: ChatRallyController::CMD_RALLY_SET,
		accessLevel: 'rl',
		description: 'Sets the rally waypoint',
	),
	NCA\ProvidesEvent(
		event: SyncRallySetEvent::class,
		desc: 'Triggered when a rally point is set',
	),
	NCA\ProvidesEvent(
		event: SyncRallyClearEvent::class,
		desc: 'Triggered when someone clears the rally point',
	)
]
class ChatRallyController extends ModuleInstance {
	public const CMD_RALLY_SET = 'rally set/clear';

	/** Rally waypoint for topic */
	#[NCA\Setting\Text(mode: 'noedit')]
	public string $rally = '';

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private PlayfieldController $playfieldController;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private ChatLeaderController $chatLeaderController;

	/** Display the current rally location */
	#[NCA\HandlesCommand('rally')]
	public function rallyCommand(CmdContext $context): void {
		$this->replyCurrentRally($context);
	}

	/** Clear the current rally location */
	#[NCA\HandlesCommand(self::CMD_RALLY_SET)]
	public function rallyClearCommand(
		CmdContext $context,
		#[NCA\Str('clear')] string $action
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$this->clear();
		$msg = 'Rally has been cleared.';
		$context->reply($msg);
		$rEvent = new SyncRallyClearEvent(
			owner: $context->char->name,
			forceSync: $context->forceSync,
		);
		$this->eventManager->fireEvent($rEvent);
	}

	/** Set the rally waypoint */
	#[NCA\HandlesCommand(self::CMD_RALLY_SET)]
	#[NCA\Help\Example('<symbol>rally 10.9 x 30 x 560')]
	#[NCA\Help\Example('<symbol>rally 10.9 . 30 . 4HO')]
	#[NCA\Help\Example('<symbol>rally 10.9, 30, 560')]
	public function rallySet2Command(
		CmdContext $context,
		#[NCA\Regexp("[0-9.]+\s*(?:[x,.]*)")] string $x,
		#[NCA\Regexp("[0-9.]+\s*(?:[x,.]*)")] string $y,
		PWord $playfield
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		$xCoords = (float)$x;
		$yCoords = (float)$y;

		$playfieldName = $playfield();
		if (is_numeric($playfield())) {
			$playfieldId = (int)$playfield();
			$playfieldName = (string)$playfieldId;

			$pfObj = $this->playfieldController->getPlayfieldById($playfieldId);
			if ($pfObj !== null && isset($pfObj->short_name)) {
				$playfieldName = $pfObj->short_name;
			}
		} else {
			$playfieldName = $playfield();
			$pfObj = $this->playfieldController->getPlayfieldByName($playfieldName);
			if ($pfObj === null) {
				$context->reply("Could not find playfield '{$playfieldName}'");
				return;
			}
			$playfieldId = $pfObj->id;
		}
		$this->set($playfieldName, $playfieldId, (string)$xCoords, (string)$yCoords);
		$this->replyCurrentRally($context);
		$rEvent = new SyncRallySetEvent(
			x: (int)round($xCoords),
			y: (int)round($yCoords),
			pf: $playfieldId,
			owner: $context->char->name,
			name: $playfieldName,
			forceSync: $context->forceSync,
		);
		$this->eventManager->fireEvent($rEvent);
	}

	/** Set the rally waypoint */
	#[NCA\HandlesCommand(self::CMD_RALLY_SET)]
	#[NCA\Help\Example('<symbol>rally (10.9 30.0 y 20.1 550)')]
	public function rallySet1Command(CmdContext $context, string $pasteFromF9): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		if (count($matches = Safe::pregMatch("/(\d+\.\d) (\d+\.\d) y \d+\.\d (\d+)/", $pasteFromF9)) === 4) {
			$xCoords = $matches[1];
			$yCoords = $matches[2];
			$playfieldId = (int)$matches[3];
		} else {
			$context->reply('This does not look like full coordinates. Please press shift+F9 and paste the first line starting with a dash.');
			return;
		}

		$name = (string)$playfieldId;
		$playfield = $this->playfieldController->getPlayfieldById($playfieldId);
		if ($playfield !== null && isset($playfield->short_name)) {
			$name = $playfield->short_name;
		}
		$this->set($name, $playfieldId, $xCoords, $yCoords);
		$this->replyCurrentRally($context);

		$rEvent = new SyncRallySetEvent(
			x: (int)round((float)$xCoords),
			y: (int)round((float)$yCoords),
			pf: $playfieldId,
			name: $name,
			owner: $context->char->name,
			forceSync: $context->forceSync,
		);
		$this->eventManager->fireEvent($rEvent);
	}

	#[NCA\Event(
		name: SyncRallySetEvent::EVENT_MASK,
		description: 'Handle synced rally sets'
	)]
	public function handleExtRallySet(SyncRallySetEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->set($event->name, $event->pf, (string)$event->x, (string)$event->y);
	}

	#[NCA\Event(
		name: SyncRallyClearEvent::EVENT_MASK,
		description: 'Handle synced rally clears'
	)]
	public function handleExtRallyClear(SyncRallyClearEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->clear();
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Sends rally to players joining the private channel'
	)]
	public function sendRally(JoinMyPrivEvent $eventObj): void {
		$sender = $eventObj->sender;

		$rally = $this->get();
		if ($rally !== '' && is_string($sender)) {
			$this->chatBot->sendMassTell($rally, $sender);
		}
	}

	public function set(string $name, int $playfieldId, string $xCoords, string $yCoords): string {
		$this->settingManager->save('rally', implode(':', array_map('strval', func_get_args())));

		return $this->get();
	}

	public function get(): string {
		$data = $this->rally;
		if (!str_contains($data, ':')) {
			return '';
		}
		[$name, $playfieldId, $xCoords, $yCoords] = explode(':', $data);
		$link = $this->text->makeChatcmd("Rally: {$xCoords}x{$yCoords} {$name}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use rally: {$link}";
		$blob .= "\n\n" . $this->text->makeChatcmd('Clear Rally', '/tell <myname> rally clear');
		return ((array)$this->text->makeBlob("Rally: {$xCoords}x{$yCoords} {$name}", $blob))[0];
	}

	public function clear(): void {
		$this->settingManager->save('rally', '');
	}

	public function replyCurrentRally(CommandReply $sendto): void {
		$rally = $this->get();
		if ($rally === '') {
			$msg = 'No rally set.';
			$sendto->reply($msg);
			return;
		}
		$sendto->reply($rally);
	}

	#[
		NCA\NewsTile(
			name: 'rally',
			description: 'Will show a waypoint-link to the current rally-point - if any',
			example: "<header2>Rally<end>\n".
				'<tab>We are rallying <u>here</u>'
		)
	]
	public function rallyTile(string $sender): ?string {
		$data = $this->rally;
		if (!str_contains($data, ':')) {
			return null;
		}
		[$name, $playfieldId, $xCoords, $yCoords] = explode(':', $data);
		$link = $this->text->makeChatcmd('here', "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		return "<header2>Rally<end>\n<tab>We are rallying {$link}";
	}
}
