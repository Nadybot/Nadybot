<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\WHEREIS_MODULE\WhereisResult;

/**
 * Bossloot Module Ver 1.1
 * Written By Jaqueme
 * For Budabot
 * Database Adapted From One Originally Compiled by Malosar For BeBot
 * Boss Drop Table Database Module
 * Written 5/11/07
 * Last Modified 5/14/07
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "boss",
		accessLevel: "all",
		description: "Shows bosses and their loot",
		help: "boss.txt"
	),
	NCA\DefineCommand(
		command: "bossloot",
		accessLevel: "all",
		description: "Finds which boss drops certain loot",
		help: "boss.txt"
	)
]
class BosslootController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Boss");
		$this->db->loadCSVFile($this->moduleName, __DIR__ ."/boss_namedb.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ ."/boss_lootdb.csv");
	}

	/**
	 * This command handler shows bosses and their loot.
	 */
	#[NCA\HandlesCommand("boss")]
	public function bossCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);

		$query = $this->db->table("boss_namedb");
		$this->db->addWhereFromParams($query, explode(' ', $search), 'bossname');

		/** @var Collection<BossNamedb> */
		$bosses = $query->asObj(BossNamedb::class);
		$count = $bosses->count();

		if ($count === 0) {
			$output = "There were no matches for your search.";
			$context->reply($output);
			return;
		}
		if ($count > 1) {
			$blob = "Results of Search for '$search'\n\n";
			//If multiple matches found output list of bosses
			foreach ($bosses as $row) {
				$blob .= $this->getBossLootOutput($row);
			}
			$output = $this->text->makeBlob("Boss Search Results ($count)", $blob);
			$context->reply($output);
			return;
		}
		//If single match found, output full loot table
		$row = $bosses[0];
		$blob = "";

		$locations = $this->db->table("whereis AS w")
			->leftJoin("playfields AS p", "w.playfield_id", "p.id")
			->where("name", $row->bossname)
			->asObj(WhereisResult::class)
			->map(function (WhereisResult $npc): string {
				if ($npc->playfield_id === 0 || ($npc->xcoord === 0 && $npc->ycoord === 0)) {
					return $npc->answer;
				}
				return $this->text->makeChatcmd(
					$npc->answer,
					"/waypoint {$npc->xcoord} {$npc->ycoord} {$npc->playfield_id}"
				);
			})->toArray();
		if (count($locations)) {
			$blob .= "<header2>Location<end>\n";
			$blob .= "<tab>" . join("\n<tab>", $locations) . "\n\n";
		}

		$blob .= "<header2>Loot<end>\n";

		/** @var Collection<BossLootdb> */
		$data = $this->db->table("boss_lootdb AS b")
			->leftJoin("aodb AS a", function (JoinClause $join): void {
				$join->on("b.aoid", "a.lowid")
					->orOn(function (JoinClause $join): void {
						$join->whereNull("b.aoid")
							->whereColumn("b.itemname", "a.name");
					});
			})
			->where("b.bossid", $row->bossid)
			->asObj(BossLootdb::class);
		foreach ($data as $row2) {
			if (!isset($row2->icon)) {
				$this->logger->error("Missing item in AODB: {$row2->itemname}.");
				continue;
			}
			$blob .= "<tab>" . $this->text->makeImage($row2->icon) . "\n";
			$blob .= "<tab>" . $this->text->makeItem($row2->lowid, $row2->highid, $row2->highql, $row2->itemname) . "\n\n";
		}
		$output = $this->text->makeBlob($row->bossname, $blob);
		$context->reply($output);
	}

	/**
	 * This command handler finds which boss drops certain loot.
	 */
	#[NCA\HandlesCommand("bossloot")]
	public function bosslootCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);

		$blob = "Bosses that drop items matching '$search':\n\n";

		$query = $this->db->table("boss_lootdb AS b1")
			->join("boss_namedb AS b2", "b2.bossid", "b1.bossid")
			->select("b2.bossid", "b2.bossname")->distinct();
		$this->db->addWhereFromParams($query, explode(' ', $search), 'b1.itemname');

		/** @var Collection<BossNamedb> */
		$loot = $query->asObj(BossNamedb::class);
		$count = $loot->count();

		$output = "There were no matches for your search.";
		if ($count !== 0) {
			foreach ($loot as $row) {
				$blob .= $this->getBossLootOutput($row, $search);
			}
			$output = $this->text->makeBlob("Bossloot Search Results ($count)", $blob);
		}
		$context->reply($output);
	}

	public function getBossLootOutput(BossNamedb $row, ?string $search=null): string {
		$query = $this->db->table("boss_lootdb AS b")
			->leftJoin("aodb AS a", "b.itemname", "a.name")
			->where("b.bossid", $row->bossid);
		if (isset($search)) {
			$this->db->addWhereFromParams($query, explode(' ', $search), 'b.itemname');
		}
		/** @var Collection<BossLootdb> */
		$data = $query->asObj(BossLootdb::class);

		$blob = "<pagebreak><header2>{$row->bossname} [" . $this->text->makeChatcmd("details", "/tell <myname> boss $row->bossname") . "]<end>\n";
		$locations = $this->db->table("whereis AS w")
			->leftJoin("playfields AS p", "w.playfield_id", "p.id")
			->where("name", $row->bossname)
			->asObj(WhereisResult::class)
			->map(function (WhereisResult $npc): string {
				if ($npc->playfield_id === 0 || ($npc->xcoord === 0 && $npc->ycoord === 0)) {
					return "<highlight>{$npc->answer}<end>";
				}
				return $this->text->makeChatcmd(
					$npc->answer,
					"/waypoint {$npc->xcoord} {$npc->ycoord} {$npc->playfield_id}"
				);
			});
		if ($locations->count()) {
			$blob .= "<tab>Location: " . $locations->join(", ") . "\n";
		}
		$blob .= "<tab>Loot: ";
		$lootItems = [];
		foreach ($data as $row2) {
			$item = $this->text->makeItem($row2->lowid, $row2->highid, $row2->highql, $row2->itemname);
			$lootItems []= $item;
		}
		if (isset($search)) {
			$blob .= join("\n<tab><black>Loot: <end>", $lootItems) . "\n\n";
		} else {
			$blob .= join(", ", $lootItems) . "\n\n";
		}
		return $blob;
	}
}
