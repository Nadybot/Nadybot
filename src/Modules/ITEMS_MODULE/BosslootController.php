<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\WHEREIS_MODULE\WhereisController;
use Nadybot\Modules\WHEREIS_MODULE\WhereisResult;

/**
 * Bossloot Module Ver 1.1
 * Originally written By Jaqueme for Budabot
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Boss"),
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

	#[NCA\Inject]
	public WhereisController $whereisController;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ ."/boss_namedb.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ ."/boss_lootdb.csv");
	}

	/** @param Collection<BossLootdb> $data */
	private function addItemsToLoot(Collection $data): void {
		$itemsByName = $this->itemsController
			->getByNames(...$data->whereNull("aoid")->pluck("itemname")->toArray())
			->keyBy("name");
		$itemsByAoid = $this->itemsController
			->getByIDs(...$data->whereNotNull("aoid")->pluck("aoid")->toArray())
			->keyBy("aoid");
		$data->each(function (BossLootdb $loot) use ($itemsByName, $itemsByAoid): void {
			if (isset($loot->aoid)) {
				$loot->item = $itemsByAoid->get($loot->aoid);
			} else {
				$loot->item = $itemsByName->get($loot->itemname);
			}
		});
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

		$locations = $this->getBossLocations($row->bossname);
		if ($locations->isNotEmpty()) {
			$blob .= "<header2>Location<end>\n";
			$blob .= "<tab>" . $locations->join("\n<tab>") . "\n\n";
		}

		$blob .= "<header2>Loot<end>\n";

		/** @var Collection<BossLootdb> */
		$data = $this->db->table("boss_lootdb")
			->where("bossid", $row->bossid)
			->asObj(BossLootdb::class);
		$this->addItemsToLoot($data);
		foreach ($data as $row2) {
			if (!isset($row2->item)) {
				$this->logger->error("Missing item in AODB: {$row2->itemname}.");
				continue;
			}
			$blob .= "<tab>" . $this->text->makeImage($row2->item->icon) . "\n";
			$blob .= "<tab>" . $row2->item->getLink($row2->item->highql, $row2->itemname) . "\n\n";
		}
		$output = $this->text->makeBlob($row->bossname, $blob);
		$context->reply($output);
	}

	/** @return Collection<string> */
	private function getBossLocations(string $bossName): Collection {
		/** @var Collection<string> */
		$locations = $this->whereisController->getByName($bossName)
			->map(function (WhereisResult $npc): string {
				if ($npc->playfield_id === 0 || ($npc->xcoord === 0 && $npc->ycoord === 0)) {
					return $npc->answer;
				}
				return $this->text->makeChatcmd(
					$npc->answer,
					"/waypoint {$npc->xcoord} {$npc->ycoord} {$npc->playfield_id}"
				);
			});
		return $locations;
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
		$query = $this->db->table("boss_lootdb")
			->where("bossid", $row->bossid);
		if (isset($search)) {
			$this->db->addWhereFromParams($query, explode(' ', $search), 'itemname');
		}
		/** @var Collection<BossLootdb> */
		$data = $query->asObj(BossLootdb::class);
		$this->addItemsToLoot($data);

		$blob = "<pagebreak><header2>{$row->bossname} [" . $this->text->makeChatcmd("details", "/tell <myname> boss $row->bossname") . "]<end>\n";
		$locations = $this->getBossLocations($row->bossname);
		if ($locations->count()) {
			$blob .= "<tab>Location: " . $locations->join(", ") . "\n";
		}
		$blob .= "<tab>Loot: ";
		$lootItems = $data->map(function(BossLootdb $loot): ?string {
			return $loot->item?->getLink($loot->item->highql) ?? null;
		})->filter();
		if (isset($search)) {
			$blob .= $lootItems->join("\n<tab><black>Loot: <end>") . "\n\n";
		} else {
			$blob .= $lootItems->join(", ") . "\n\n";
		}
		return $blob;
	}
}
