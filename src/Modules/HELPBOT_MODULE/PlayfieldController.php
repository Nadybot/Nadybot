<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Illuminate\Support\Collection;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Safe,
	Text,
	Types\Playfield as CorePlayfield,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Playfields'),
	NCA\DefineCommand(
		command: 'playfields',
		accessLevel: 'guest',
		description: 'Show playfield ids, long names, and short names',
		alias: 'playfield'
	),
	NCA\DefineCommand(
		command: 'waypoint',
		accessLevel: 'guest',
		description: 'Create a waypoint link',
	)
]
class PlayfieldController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/playfields.csv');
	}

	/** Show a list of playfields, including their id, short name, and long name */
	#[NCA\HandlesCommand('playfields')]
	public function playfieldListCommand(CmdContext $context): void {
		$blob = $this->db->table(Playfield::getTable())
			->orderBy('long_name')
			->asObj(Playfield::class)
			->reduce(static function (string $blob, Playfield $row): string {
				return "{$blob}[<highlight>{$row->id}<end>] {$row->long_name} ({$row->short_name})\n";
			}, '');

		$msg = $this->text->makeBlob('Playfields', $blob);
		$context->reply($msg);
	}

	/** Search for a playfields by its short or long name */
	#[NCA\HandlesCommand('playfields')]
	public function playfieldShowCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);
		$query = $this->db->table(Playfield::getTable());
		$this->db->addWhereFromParams($query, explode(' ', $search), 'long_name');
		$this->db->addWhereFromParams($query, explode(' ', $search), 'short_name', 'or');

		$data = $query->asObjArr(Playfield::class);

		$count = count($data);

		if ($count > 1) {
			$blob = "<header2>Result of Playfield Search for \"{$search}\"<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab>[<highlight>{$row->id}<end>] {$row->long_name}\n";
			}

			$msg = $this->text->makeBlob("Playfields ({$count})", $blob);
		} elseif ($count === 1) {
			$row = $data[0];
			$msg = "[<highlight>{$row->id}<end>] {$row->long_name}";
		} else {
			$msg = 'There were no matches for your search.';
		}
		$context->reply($msg);
	}

	/** Create a waypoint link in the chat */
	#[NCA\HandlesCommand('waypoint')]
	#[NCA\Help\Example('<symbol>waypoint Pos: 17.5, 28.1, 100.2, Area: Perpetual Wastelands')]
	public function waypoint1Command(CmdContext $context, #[NCA\Str('Pos:')] string $action, string $posString): void {
		if (!count($args = Safe::pregMatch('/^([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: ([a-zA-Z ]+)$/i', $posString))) {
			$context->reply('Wrong waypoint format.');
			return;
		}
		// Pos: ([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: (.+)
		$xCoords = $args[1];
		$yCoords = $args[2];

		$playfieldName = $args[4];

		$playfield = CorePlayfield::tryByName($playfieldName);
		if ($playfield === null) {
			$context->reply("Could not find playfield '{$playfieldName}'.");
			return;
		}
		$context->reply($this->processWaypointCommand($xCoords, $yCoords, $playfield->short(), $playfield->value));
	}

	/** Create a waypoint link in the chat */
	#[NCA\HandlesCommand('waypoint')]
	#[NCA\Help\Example('<symbol>waypoint 17 28 100 PW')]
	#[NCA\Help\Example('<symbol>waypoint (10.9 30.0 y 20.1 550)')]
	public function waypoint2Command(CmdContext $context, string $pasteFromF9): void {
		if (count($args = Safe::pregMatch("/^\(?([0-9.]+) ([0-9.]+) y ([0-9.]+) ([0-9]+)\)?$/i", $pasteFromF9))) {
			$xCoords = $args[1];
			$yCoords = $args[2];
			$playfieldId = (int)$args[4];
		} elseif (count($args = Safe::pregMatch('/^([0-9.]+)([x,. ]+)([0-9.]+)([x,. ]+)([0-9]+)$/i', $pasteFromF9))) {
			$xCoords = $args[1];
			$yCoords = $args[3];
			$playfieldId = (int)$args[5];
		} elseif (count($args = Safe::pregMatch('/^([0-9\\.]+)([x,. ]+)([0-9\\.]+)([x,. ]+)(.+)$/i', $pasteFromF9))) {
			$xCoords = $args[1];
			$yCoords = $args[3];
			$playfieldName = $args[5];
		} else {
			$context->reply('Wrong waypoint format.');
			return;
		}

		if (isset($playfieldId)) {
			$playfieldName = (string)$playfieldId;
			$playfield = CorePlayfield::tryFrom($playfieldId);
			if (isset($playfield)) {
				$playfieldName = $playfield->short();
			}
		} elseif (isset($playfieldName)) {
			$playfield = CorePlayfield::tryByName($playfieldName);
			if (!isset($playfield)) {
				$context->reply("Unknown playfield {$playfieldName}");
				return;
			}
			$playfieldId = $playfield->value;
			$playfieldName = $playfield->short();
		} else {
			$context->reply('Wrong waypoint format.');
			return;
		}

		/** @psalm-suppress PossiblyInvalidArgument */
		$reply = $this->processWaypointCommand($xCoords, $yCoords, $playfieldName, $playfieldId);
		$context->reply($reply);
	}

	/** @return Collection<int,Playfield> */
	public function searchPlayfieldsByName(string $playfieldName): Collection {
		return $this->db->table(Playfield::getTable())
			->whereIlike('long_name', $playfieldName)
			->orWhereIlike('short_name', $playfieldName)
			->asObj(Playfield::class);
	}

	private function processWaypointCommand(string $xCoords, string $yCoords, string $playfieldName, int $playfieldId): string {
		$link = Text::makeChatcmd("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use waypoint: {$link}";
		return $this->text->makeBlob("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", $blob);
	}
}
