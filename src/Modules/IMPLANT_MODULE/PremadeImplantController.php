<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\Skill;
use Nadybot\Modules\ITEMS_MODULE\WhatBuffsController;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'premade',
 *		accessLevel = 'all',
 *		description = 'Searches for implants out of the premade implants booths',
 *		help        = 'premade.txt'
 *	)
 */
class PremadeImplantController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public WhatBuffsController $whatBuffsController;

	/** @Inject */
	public Util $util;
	
	private $slots = ['head', 'eye', 'ear', 'rarm', 'chest', 'larm', 'rwrist', 'waist', 'lwrist', 'rhand', 'legs', 'lhand', 'feet'];
	
	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "premade_implant");
	}

	/**
	 * @HandlesCommand("premade")
	 * @Matches("/^premade (.*)$/i")
	 */
	public function premadeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$searchTerms = strtolower($args[1]);
		$results = null;

		$profession = $this->util->getProfessionName($searchTerms);
		if ($profession !== '') {
			$searchTerms = $profession;
			$results = $this->searchByProfession($profession);
		} elseif (in_array($searchTerms, $this->slots)) {
			$results = $this->searchBySlot($searchTerms);
		} else {
			$results = $this->searchByModifier($searchTerms);
		}

		if (!empty($results)) {
			$blob = $this->formatResults($results);
			$blob .= "\n\nWritten by Tyrence (RK2)";
			$msg = $this->text->makeBlob("Implant Search Results for '$searchTerms'", $blob);
		} else {
			$msg = "No results found.";
		}

		$sendto->reply($msg);
	}

	/**
	 * @return PremadeSearchResult[]
	 */
	public function searchByProfession(string $profession): array {
		$sql = "SELECT i.Name AS slot, ".
				"p2.Name AS profession, ".
				"a.Name AS ability, ".
				"CASE WHEN c1.ClusterID = 0 THEN 'N/A' ELSE c1.LongName END AS shiny, ".
				"CASE WHEN c2.ClusterID = 0 THEN 'N/A' ELSE c2.LongName END AS bright, ".
				"CASE WHEN c3.ClusterID = 0 THEN 'N/A' ELSE c3.LongName END AS faded ".
			"FROM premade_implant p ".
			"JOIN ImplantType i ON p.ImplantTypeID = i.ImplantTypeID ".
			"JOIN Profession p2 ON p.ProfessionID = p2.ID ".
			"JOIN Ability a ON p.AbilityID = a.AbilityID ".
			"JOIN Cluster c1 ON p.ShinyClusterID = c1.ClusterID ".
			"JOIN Cluster c2 ON p.BrightClusterID = c2.ClusterID ".
			"JOIN Cluster c3 ON p.FadedClusterID = c3.ClusterID ".
			"WHERE p2.Name = ? ".
			"ORDER BY slot";
		return $this->db->fetchAll(PremadeSearchResult::class, $sql, $profession);
	}

	/**
	 * @return PremadeSearchResult[]
	 */
	public function searchBySlot(string $slot): array {
		$sql = "SELECT i.Name AS slot, ".
				"p2.Name AS profession, ".
				"a.Name AS ability, ".
				"CASE WHEN c1.ClusterID = 0 THEN 'N/A' ELSE c1.LongName END AS shiny, ".
				"CASE WHEN c2.ClusterID = 0 THEN 'N/A' ELSE c2.LongName END AS bright, ".
				"CASE WHEN c3.ClusterID = 0 THEN 'N/A' ELSE c3.LongName END AS faded ".
			"FROM premade_implant p ".
			"JOIN ImplantType i ON p.ImplantTypeID = i.ImplantTypeID ".
			"JOIN Profession p2 ON p.ProfessionID = p2.ID ".
			"JOIN Ability a ON p.AbilityID = a.AbilityID ".
			"JOIN Cluster c1 ON p.ShinyClusterID = c1.ClusterID ".
			"JOIN Cluster c2 ON p.BrightClusterID = c2.ClusterID ".
			"JOIN Cluster c3 ON p.FadedClusterID = c3.ClusterID ".
			"WHERE i.ShortName = ? ".
			"ORDER BY shiny, bright, faded";
		return $this->db->fetchAll(PremadeSearchResult::class, $sql, $slot);
	}

	/**
	 * @return PremadeSearchResult[]
	 */
	public function searchByModifier(string $modifier): array {
		$skills = $this->whatBuffsController->searchForSkill($modifier);
		$skillIds = array_map(
			function(Skill $s): int {
				return $s->id;
			},
			$skills
		);
		$placeHolder = join(",", array_fill(0, count($skillIds), "?"));
		
		$sql = "SELECT i.Name AS slot, ".
				"p2.Name AS profession, ".
				"a.Name AS ability, ".
				"CASE WHEN c1.ClusterID = 0 THEN '-' ELSE c1.LongName END AS shiny, ".
				"CASE WHEN c2.ClusterID = 0 THEN '-' ELSE c2.LongName END AS bright, ".
				"CASE WHEN c3.ClusterID = 0 THEN '-' ELSE c3.LongName END AS faded ".
			"FROM premade_implant p ".
			"JOIN ImplantType i ON p.ImplantTypeID = i.ImplantTypeID ".
			"JOIN Profession p2 ON p.ProfessionID = p2.ID ".
			"JOIN Ability a ON p.AbilityID = a.AbilityID ".
			"JOIN Cluster c1 ON p.ShinyClusterID = c1.ClusterID ".
			"JOIN Cluster c2 ON p.BrightClusterID = c2.ClusterID ".
			"JOIN Cluster c3 ON p.FadedClusterID = c3.ClusterID ".
			"WHERE c1.SkillID IN ($placeHolder) ".
			"OR c2.SkillID IN ($placeHolder) ".
			"OR c3.SkillID IN ($placeHolder)";

		return $this->db->fetchAll(PremadeSearchResult::class, $sql, ...[...$skillIds, ...$skillIds, ...$skillIds]);
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
