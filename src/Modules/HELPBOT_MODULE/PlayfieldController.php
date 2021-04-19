<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\CommandAlias;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'playfields',
 *		accessLevel = 'all',
 *		description = 'Show playfield ids, long names, and short names',
 *		help        = 'waypoint.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'waypoint',
 *		accessLevel = 'all',
 *		description = 'Create a waypoint link',
 *		help        = 'waypoint.txt'
 *	)
 */
class PlayfieldController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Playfields");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/playfields.csv');

		$this->commandAlias->register($this->moduleName, "playfields", "playfield");
	}

	/**
	 * @HandlesCommand("playfields")
	 * @Matches("/^playfields$/i")
	 */
	public function playfieldListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->db->table("playfields")
			->orderBy("long_name")
			->asObj(Playfield::class)
			->reduce(function(string $blob, Playfield $row): string {
				return"{$blob}[<highlight>{$row->id}<end>] {$row->long_name} ({$row->short_name})\n";
			}, "");

		$msg = $this->text->makeBlob("Playfields", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("playfields")
	 * @Matches("/^playfields (.+)$/i")
	 */
	public function playfieldShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = strtolower(trim($args[1]));
		$query = $this->db->table("playfields");
		$this->db->addWhereFromParams($query, explode(' ', $search), 'long_name');
		$this->db->addWhereFromParams($query, explode(' ', $search), 'short_name', "or");

		/** @var Playfield[] */
		$data = $query->asObj(Playfield::class)->toArray();

		$count = count($data);

		if ($count > 1) {
			$blob = "<header2>Result of Playfield Search for \"$search\"<end>\n";
			foreach ($data as $row) {
				$blob .= "<tab>[<highlight>$row->id<end>] $row->long_name\n";
			}

			$msg = $this->text->makeBlob("Playfields ($count)", $blob);
		} elseif ($count == 1) {
			$row = $data[0];
			$msg = "[<highlight>$row->id<end>] $row->long_name";
		} else {
			$msg = "There were no matches for your search.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("waypoint")
	 * @Matches("/^waypoint Pos: ([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: ([a-zA-Z ]+)/i")
	 */
	public function waypoint1Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		//Pos: ([0-9\\.]+), ([0-9\\.]+), ([0-9\\.]+), Area: (.+)
		$xCoords = $args[1];
		$yCoords = $args[2];

		$playfieldName = $args[4];

		$playfield = $this->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$sendto->reply("Could not find playfield '$playfieldName'.");
			return;
		}
		$sendto->reply($this->processWaypointCommand($xCoords, $yCoords, $playfield->short_name, $playfield->id));
	}

	/**
	 * @HandlesCommand("waypoint")
	 * @Matches("/^waypoint \(?([0-9.]+) ([0-9.]+) y ([0-9.]+) ([0-9]+)\)?$/i")
	 */
	public function waypoint2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$xCoords = $args[1];
		$yCoords = $args[2];
		$playfieldId = (int)$args[4];

		$playfield = $this->getPlayfieldById($playfieldId);
		if ($playfield === null) {
			$playfieldName = (string)$playfieldId;
		} else {
			$playfieldName = $playfield->short_name;
		}

		$sendto->reply($this->processWaypointCommand($xCoords, $yCoords, $playfieldName, $playfieldId));
	}

	/**
	 * @HandlesCommand("waypoint")
	 * @Matches("/^waypoint ([0-9.]+)([x,. ]+)([0-9.]+)([x,. ]+)([0-9]+)$/i")
	 */
	public function waypoint3Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$xCoords = $args[1];
		$yCoords = $args[3];
		$playfieldId = (int)$args[5];

		$playfield = $this->getPlayfieldById($playfieldId);
		if ($playfield === null) {
			$playfieldName = (string)$playfieldId;
		} else {
			$playfieldName = $playfield->short_name;
		}

		$sendto->reply($this->processWaypointCommand($xCoords, $yCoords, $playfieldName, $playfieldId));
	}

	/**
	 * @HandlesCommand("waypoint")
	 * @Matches("/^waypoint ([0-9\\.]+)([x,. ]+)([0-9\\.]+)([x,. ]+)(.+)$/i")
	 */
	public function waypoint4Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$xCoords = $args[1];
		$yCoords = $args[3];
		$playfieldName = $args[5];

		$playfield = $this->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$sendto->reply("Could not find playfield '$playfieldName'.");
		} else {
			$playfieldId = $playfield->id;
			$playfieldName = $playfield->short_name;
			$sendto->reply($this->processWaypointCommand($xCoords, $yCoords, $playfieldName, $playfieldId));
		}
	}

	private function processWaypointCommand(string $xCoords, string $yCoords, string $playfieldName, int $playfieldId): string {
		$link = $this->text->makeChatcmd("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", "/waypoint {$xCoords} {$yCoords} {$playfieldId}");
		$blob = "Click here to use waypoint: $link";
		return $this->text->makeBlob("waypoint: {$xCoords}x{$yCoords} {$playfieldName}", $blob);
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
