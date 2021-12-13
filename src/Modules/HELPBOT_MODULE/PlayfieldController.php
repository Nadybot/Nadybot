<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\CmdContext;
use Nadybot\Core\CommandAlias;
use Nadybot\Core\DB;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "playfields",
		accessLevel: "all",
		description: "Show playfield ids, long names, and short names",
		help: "waypoint.txt"
	),
	NCA\DefineCommand(
		command: "waypoint",
		accessLevel: "all",
		description: "Create a waypoint link",
		help: "waypoint.txt"
	)
]
class PlayfieldController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Playfields");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/playfields.csv');

		$this->commandAlias->register($this->moduleName, "playfields", "playfield");
	}

	#[NCA\HandlesCommand("playfields")]
	public function playfieldListCommand(CmdContext $context): void {
		$blob = $this->db->table("playfields")
			->orderBy("long_name")
			->asObj(Playfield::class)
			->reduce(function(string $blob, Playfield $row): string {
				return"{$blob}[<highlight>{$row->id}<end>] {$row->long_name} ({$row->short_name})\n";
			}, "");

		$msg = $this->text->makeBlob("Playfields", $blob);
		$context->reply($msg);
	}

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
			$blob = "<header2>Result of Playfield Search for \"$search\"<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab>[<highlight>{$row->id}<end>] $row->long_name\n";
			}

			$msg = $this->text->makeBlob("Playfields ({$count})", $blob);
		} elseif ($count == 1) {
			$row = $data[0];
			$msg = "[<highlight>$row->id<end>] $row->long_name";
		} else {
			$msg = "There were no matches for your search.";
		}
		$context->reply($msg);
	}

	/**
	 * @Mask $action Pos:
	 */
	#[NCA\HandlesCommand("waypoint")]
	public function waypoint1Command(CmdContext $context, string $action, string $pos): void {
		if (!preg_match("/^([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: ([a-zA-Z ]+)$/i", $pos, $args)) {
			$context->reply("Wrong waypoint format.");
			return;
		}
		//Pos: ([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: (.+)
		$xCoords = $args[1];
		$yCoords = $args[2];

		$playfieldName = $args[4];

		$playfield = $this->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$context->reply("Could not find playfield '$playfieldName'.");
			return;
		}
		$context->reply($this->processWaypointCommand($xCoords, $yCoords, $playfield->short_name??"UNKNOWN", $playfield->id));
	}

	#[NCA\HandlesCommand("waypoint")]
	public function waypoint2Command(CmdContext $context, string $pos): void {
		if (preg_match("/^\(?([0-9.]+) ([0-9.]+) y ([0-9.]+) ([0-9]+)\)?$/i", $pos, $args)) {
			$xCoords = $args[1];
			$yCoords = $args[2];
			$playfieldId = (int)$args[4];
		} elseif (preg_match("/^([0-9.]+)([x,. ]+)([0-9.]+)([x,. ]+)([0-9]+)$/i", $pos, $args)) {
			$xCoords = $args[1];
			$yCoords = $args[3];
			$playfieldId = (int)$args[5];
		} elseif (preg_match("/^([0-9\\.]+)([x,. ]+)([0-9\\.]+)([x,. ]+)(.+)$/i", $pos, $args)) {
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

	private function processWaypointCommand(string $xCoords, string $yCoords, string $playfieldName, int $playfieldId): array {
		$link = $this->text->makeChatcmd("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use waypoint: $link";
		return (array)$this->text->makeBlob("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", $blob);
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
		return $this->db->table("playfields")
			->where("id", $playfieldId)
			->asObj(Playfield::class)
			->first();
	}
}
