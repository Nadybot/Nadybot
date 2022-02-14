<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	QueryBuilder,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\{
	Skill,
	WhatBuffsController,
};

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Premade"),
	NCA\DefineCommand(
		command: "premade",
		accessLevel: "guest",
		description: "Searches for implants out of the premade implants booths",
	)
]
class PremadeImplantController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public WhatBuffsController $whatBuffsController;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/premade_implant.csv");
	}

	/** Search for implants by profession, slot, or modifier in the premade implant booth */
	#[NCA\HandlesCommand("premade")]
	#[NCA\Help\Example("<symbol>premade agent")]
	#[NCA\Help\Example("<symbol>premade cl")]
	#[NCA\Help\Example("<symbol>premade rwrist")]
	public function premadeCommand(CmdContext $context, string $search): void {
		$searchTerms = strtolower($search);
		$results = null;

		$profession = $this->util->getProfessionName($searchTerms);
		if ($profession !== '') {
			$searchTerms = $profession;
			$results = $this->searchByProfession($profession);
		} elseif (PImplantSlot::matches($searchTerms)) {
			$results = $this->searchBySlot((new PImplantSlot($searchTerms))());
		} else {
			$results = $this->searchByModifier($searchTerms);
		}

		if (!empty($results)) {
			$blob = trim($this->formatResults($results));
			$msg = $this->text->makeBlob("Implant Search Results for '$searchTerms'", $blob);
		} else {
			$msg = "No results found.";
		}

		$context->reply($msg);
	}

	protected function getBaseQuery(): QueryBuilder {
		$query = $this->db->table("premade_implant AS p")
			->join("ImplantType AS i", "p.ImplantTypeID", "i.ImplantTypeID")
			->join("Profession AS p2", "p.ProfessionID", "p2.ID")
			->join("Ability AS a", "p.AbilityID", "a.AbilityID")
			->join("Cluster AS c1", "p.ShinyClusterID", "c1.ClusterID")
			->join("Cluster AS c2", "p.BrightClusterID", "c2.ClusterID")
			->join("Cluster AS c3", "p.FadedClusterID", "c3.ClusterID")
			->orderBy("slot")
			->select("i.Name AS slot", "p2.Name AS profession", "a.Name as ability");
		$query->selectRaw(
			"CASE WHEN " . $query->grammar->wrap("c1.ClusterID") . " = 0 ".
			"THEN ? ".
			"ELSE " .$query->grammar->wrap("c1.LongName"). " ".
			"END AS " . $query->grammar->wrap("shiny")
		)->addBinding('N/A', 'select');
		$query->selectRaw(
			"CASE WHEN " . $query->grammar->wrap("c2.ClusterID") . " = 0 ".
			"THEN ? ".
			"ELSE " .$query->grammar->wrap("c2.LongName"). " ".
			"END AS " . $query->grammar->wrap("bright")
		)->addBinding('N/A', 'select');
		$query->selectRaw(
			"CASE WHEN " . $query->grammar->wrap("c3.ClusterID") . " = 0 ".
			"THEN ? ".
			"ELSE " .$query->grammar->wrap("c3.LongName"). " ".
			"END AS " . $query->grammar->wrap("faded")
		)->addBinding('N/A', 'select');
		return $query;
	}

	/**
	 * @return PremadeSearchResult[]
	 */
	public function searchByProfession(string $profession): array {
		$query = $this->getBaseQuery()->where("p2.Name", $profession);
		return $query->asObj(PremadeSearchResult::class)->toArray();
	}

	/**
	 * @return PremadeSearchResult[]
	 */
	public function searchBySlot(string $slot): array {
		$query = $this->getBaseQuery()->where("i.ShortName", $slot);
		return $query->asObj(PremadeSearchResult::class)->toArray();
	}

	/**
	 * @return PremadeSearchResult[]
	 */
	public function searchByModifier(string $modifier): array {
		$skills = $this->whatBuffsController->searchForSkill($modifier);
		if (!count($skills)) {
			return [];
		}
		$skillIds = array_map(
			function(Skill $s): int {
				return $s->id;
			},
			$skills
		);
		$query = $this->getBaseQuery()
			->whereIn("c1.SkillID", $skillIds)
			->orWhereIn("c2.SkillID", $skillIds)
			->orWhereIn("c3.SkillID", $skillIds);

		return $query->asObj(PremadeSearchResult::class)->toArray();
	}

	/**
	 * @param PremadeSearchResult[] $implants
	 */
	public function formatResults(array $implants): string {
		$blob = "";
		$slotMap = [];
		foreach ($implants as $implant) {
			$slotMap[$implant->slot] ??= [];
			$slotMap[$implant->slot] []= $implant;
		}
		foreach ($slotMap as $slot => $implants) {
			$blob .= "<header2>{$slot}<end>\n";
			foreach ($implants as $implant) {
				$blob .= $this->getFormattedLine($implant);
			}
		}

		return $blob;
	}

	public function getFormattedLine(PremadeSearchResult $implant): string {
		return "<tab><highlight>{$implant->profession}<end> ({$implant->ability})\n".
			"<tab>S: $implant->shiny\n".
			"<tab>B: $implant->bright\n".
			"<tab>F: $implant->faded\n\n";
	}
}
