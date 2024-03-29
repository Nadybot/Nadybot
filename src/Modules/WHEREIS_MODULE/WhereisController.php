<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};
use Nadybot\Modules\HELPBOT_MODULE\PlayfieldController;

/**
 * @author Jaqueme
 * Database adapted from one originally compiled by Malosar for BeBot
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "whereis",
		accessLevel: "guest",
		description: "Shows where places and NPCs are",
	)
]
class WhereisController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public PlayfieldController $pfController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/whereis.csv");
	}

	/** @return Collection<WhereisResult> */
	public function getByName(string ...$name): Collection {
		return $this->db->table("whereis AS w")
			->whereIn("name", $name)
			->asObj(WhereisResult::class)
			->each(function (WhereisResult $wi): void {
				$wi->pf = $this->pfController->getPlayfieldById($wi->playfield_id);
			});
	}

	/** @return Collection<WhereisResult> */
	public function getAll(): Collection {
		return $this->db->table("whereis AS w")
			->asObj(WhereisResult::class)
			->each(function (WhereisResult $wi): void {
				$wi->pf = $this->pfController->getPlayfieldById($wi->playfield_id);
			});
	}

	/** Show the location of NPCs or places */
	#[NCA\HandlesCommand("whereis")]
	#[NCA\Help\Example("<symbol>whereis elmer ragg")]
	#[NCA\Help\Example("<symbol>whereis prisoner")]
	#[NCA\Help\Example("<symbol>whereis 12m")]
	public function whereisCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);
		$words = explode(' ', $search);
		$query = $this->db->table("whereis");
		$this->db->addWhereFromParams($query, $words, "name");
		$this->db->addWhereFromParams($query, $words, "keywords", "or");

		/** @var Collection<string> */
		$lines = $query->asObj(WhereisResult::class)
			->map(function (WhereisResult $npc): string {
				$npc->pf = $this->pfController->getPlayfieldById($npc->playfield_id);
				$line = "<pagebreak><header2>{$npc->name}<end>\n".
					"<tab>{$npc->answer}";
				if (isset($npc->pf) && $npc->xcoord !== 0 && $npc->ycoord !== 0) {
					$line .= " " . $this->text->makeChatcmd("{$npc->xcoord}x{$npc->ycoord} {$npc->pf->short_name}", "/waypoint {$npc->xcoord} {$npc->ycoord} {$npc->pf->id}");
				}
				return $line;
			});
		$count = $lines->count();

		if ($count === 0) {
			$msg = "There were no matches for your search.";
			$context->reply($msg);
			return;
		}
		$blob = $lines->join("\n\n");

		$msg = $this->text->makeBlob("Matches for \"{$search}\" ({$count})", $blob);
		$context->reply($msg);
	}
}
