<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use function Safe\preg_match;
use Illuminate\Support\Collection;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Playfields"),
	NCA\DefineCommand(
		command: "playfields",
		accessLevel: "guest",
		description: "Show playfield ids, long names, and short names",
		alias: "playfield"
	),
	NCA\DefineCommand(
		command: "waypoint",
		accessLevel: "guest",
		description: "Create a waypoint link",
	)
]
class PlayfieldController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	/** @var array<int,Playfield> */
	private array $playfields = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/playfields.csv');

		$this->playfields = $this->db->table("playfields")
			->asObj(Playfield::class)
			->keyBy("id")
			->toArray();
	}

	/** Show a list of playfields, including their id, short name, and long name */
	#[NCA\HandlesCommand("playfields")]
	public function playfieldListCommand(CmdContext $context): void {
		$blob = $this->db->table("playfields")
			->orderBy("long_name")
			->asObj(Playfield::class)
			->reduce(function (string $blob, Playfield $row): string {
				return "{$blob}[<highlight>{$row->id}<end>] {$row->long_name} ({$row->short_name})\n";
			}, "");

		$msg = $this->text->makeBlob("Playfields", $blob);
		$context->reply($msg);
	}

	/** Search for a playfields by its short or long name */
	#[NCA\HandlesCommand("playfields")]
	public function playfieldShowCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);
		$query = $this->db->table("playfields");
		$this->db->addWhereFromParams($query, explode(' ', $search), 'long_name');
		$this->db->addWhereFromParams($query, explode(' ', $search), 'short_name', "or");

		/** @var Playfield[] */
		$data = $query->asObj(Playfield::class)->toArray();

		$count = count($data);

		if ($count > 1) {
			$blob = "<header2>Result of Playfield Search for \"{$search}\"<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab>[<highlight>{$row->id}<end>] {$row->long_name}\n";
			}

			$msg = $this->text->makeBlob("Playfields ({$count})", $blob);
		} elseif ($count == 1) {
			$row = $data[0];
			$msg = "[<highlight>{$row->id}<end>] {$row->long_name}";
		} else {
			$msg = "There were no matches for your search.";
		}
		$context->reply($msg);
	}

	/** Create a waypoint link in the chat */
	#[NCA\HandlesCommand("waypoint")]
	#[NCA\Help\Example("<symbol>waypoint Pos: 17.5, 28.1, 100.2, Area: Perpetual Wastelands")]
	public function waypoint1Command(CmdContext $context, #[NCA\Str("Pos:")] string $action, string $posString): void {
		if (!preg_match("/^([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: ([a-zA-Z ]+)$/i", $posString, $args)) {
			$context->reply("Wrong waypoint format.");
			return;
		}
		// Pos: ([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: (.+)
		$xCoords = $args[1];
		$yCoords = $args[2];

		$playfieldName = $args[4];

		$playfield = $this->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$context->reply("Could not find playfield '{$playfieldName}'.");
			return;
		}
		$context->reply($this->processWaypointCommand($xCoords, $yCoords, $playfield->short_name??"UNKNOWN", $playfield->id));
	}

	/** Create a waypoint link in the chat */
	#[NCA\HandlesCommand("waypoint")]
	#[NCA\Help\Example("<symbol>waypoint 17 28 100 PW")]
	#[NCA\Help\Example("<symbol>waypoint (10.9 30.0 y 20.1 550)")]
	public function waypoint2Command(CmdContext $context, string $pasteFromF9): void {
		if (preg_match("/^\(?([0-9.]+) ([0-9.]+) y ([0-9.]+) ([0-9]+)\)?$/i", $pasteFromF9, $args)) {
			$xCoords = $args[1];
			$yCoords = $args[2];
			$playfieldId = (int)$args[4];
		} elseif (preg_match("/^([0-9.]+)([x,. ]+)([0-9.]+)([x,. ]+)([0-9]+)$/i", $pasteFromF9, $args)) {
			$xCoords = $args[1];
			$yCoords = $args[3];
			$playfieldId = (int)$args[5];
		} elseif (preg_match("/^([0-9\\.]+)([x,. ]+)([0-9\\.]+)([x,. ]+)(.+)$/i", $pasteFromF9, $args)) {
			$xCoords = $args[1];
			$yCoords = $args[3];
			$playfieldName = $args[5];
		} else {
			$context->reply("Wrong waypoint format.");
			return;
		}

		if (isset($playfieldId)) {
			$playfield = $this->getPlayfieldById($playfieldId);
			if (isset($playfield)) {
				$playfieldName = $playfield->short_name;
			}
		} elseif (isset($playfieldName)) {
			$playfield = $this->getPlayfieldByName($playfieldName);
			if (!isset($playfield)) {
				$context->reply("Unknown playfield {$playfieldName}");
				return;
			}
			$playfieldId = $playfield->id;
			$playfieldName = $playfield->short_name;
		} else {
			$context->reply("Wrong waypoint format.");
			return;
		}

		$context->reply($this->processWaypointCommand($xCoords, $yCoords, $playfieldName??(string)$playfieldId, $playfieldId));
	}

	public function getPlayfieldByName(string $playfieldName): ?Playfield {
		return $this->db->table("playfields")
			->whereIlike("long_name", $playfieldName)
			->orWhereIlike("short_name", $playfieldName)
			->limit(1)
			->asObj(Playfield::class)
			->first();
	}

	public function getPlayfieldById(int $playfieldId): ?Playfield {
		return $this->playfields[$playfieldId] ?? null;
	}

	/** @return Collection<Playfield> */
	public function searchPlayfieldsByName(string $playfieldName): Collection {
		return $this->db->table("playfields")
			->whereIlike("long_name", $playfieldName)
			->orWhereIlike("short_name", $playfieldName)
			->asObj(Playfield::class);
	}

	/** @return Collection<Playfield> */
	public function searchPlayfieldsByIds(int ...$ids): Collection {
		return $this->db->table("playfields")
			->whereIn("id", $ids)
			->asObj(Playfield::class);
	}

	/** @return string[] */
	private function processWaypointCommand(string $xCoords, string $yCoords, string $playfieldName, int $playfieldId): array {
		$link = $this->text->makeChatcmd("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use waypoint: {$link}";
		return (array)$this->text->makeBlob("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", $blob);
	}
}
