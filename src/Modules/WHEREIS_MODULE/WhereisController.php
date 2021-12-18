<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;

/**
 * @author Jaqueme
 *  Database adapted from one originally compiled by Malosar for BeBot
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "whereis",
		accessLevel: "all",
		description: "Shows where places and NPCs are",
		help: "whereis.txt"
	)
]
class WhereisController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public PlayfieldController $pfController;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/whereis.csv");
	}

	/** @return Collection<WhereisResult> */
	public function getByName(string $name): Collection {
		return $this->db->table("whereis AS w")
			->where("name", $name)
			->asObj(WhereisResult::class)
			->each(function (WhereisResult $wi): void {
				$wi->pf = $this->pfController->getPlayfieldById($wi->playfield_id);
			});
	}

	#[NCA\HandlesCommand("whereis")]
	public function whereisCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);
		$words = explode(' ', $search);
		$query = $this->db->table("whereis AS w")
			->leftJoin("playfields AS p", "w.playfield_id", "p.id");
		$this->db->addWhereFromParams($query, $words, "name");
		$this->db->addWhereFromParams($query, $words, "keywords", "or");
		/** @var Collection<WhereisResult> */
		$npcs = $query->asObj(WhereisResult::class);
		$count = $npcs->count();

		if ($count === 0) {
			$msg = "There were no matches for your search.";
			$context->reply($msg);
			return;
		}
		$blob = "";
		foreach ($npcs as $npc) {
			$blob .= "<pagebreak><header2>{$npc->name}<end>\n".
				"<tab>{$npc->answer}";
			if ($npc->playfield_id !== 0 && $npc->xcoord !== 0 && $npc->ycoord !== 0) {
				$blob .= " " . $this->text->makeChatcmd("waypoint: {$npc->xcoord}x{$npc->ycoord} {$npc->short_name}", "/waypoint {$npc->xcoord} {$npc->ycoord} {$npc->playfield_id}");
			}
			$blob .= "\n\n";
		}

		$msg = $this->text->makeBlob("Found $count matches for \"$search\".", $blob);
		$context->reply($msg);
	}
}
