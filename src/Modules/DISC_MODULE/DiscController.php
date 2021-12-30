<?php declare(strict_types=1);

namespace Nadybot\Modules\DISC_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	DB,
	DBRow,
	Nadybot,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PItem;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "disc",
		accessLevel: "all",
		description: "Show which nano a disc will turn into",
		help: "disc.txt"
	)
]
class DiscController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Setup]
	public function setup(): void {
		// load database tables from .sql-files
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/discs.csv');
	}

	/**
	 * Get the instruction disc from its name and return an array with results
	 * @return Disc[] An array of database entries that matched
	 */
	public function getDiscsByName(string $discName): array {
		$query = $this->db->table("discs");
		$this->db->addWhereFromParams($query, explode(' ', $discName), 'disc_name');
		return $query->asObj(Disc::class)->toArray();
	}

	/**
	 * Get the instruction disc from its id and return the result or null
	 */
	public function getDiscById(int $discId): ?Disc {
		return $this->db->table("discs")
			->where("disc_id", $discId)
			->asObj(Disc::class)
			->first();
	}

	/**
	 * Command to show what nano a disc will turn into
	 */
	#[NCA\HandlesCommand("disc")]
	public function discByItemCommand(CmdContext $context, PItem $item): void {
		$disc = $this->getDiscById($item->lowID);
		if (!isset($disc)) {
			$msg = "Either <highlight>{$item}<end> is not an instruction disc, or it ".
				"cannot be turned into a nano anymore.";
			$context->reply($msg);
			return;
		}
		$this->discCommand($context, $disc);
	}

	/**
	 * Command to show what nano a disc will turn into
	 */
	#[NCA\HandlesCommand("disc")]
	public function discByNameCommand(CmdContext $context, string $item): void {
		// If only a name was given, lookup the disc's ID
		$discs = $this->getDiscsByName($item);
		// Not found? Cannot be made into a nano anymore or simply mistyped
		if (empty($discs)) {
			$msg = "Either <highlight>{$item}<end> was mistyped or it cannot be turned into a nano anymore.";
			$context->reply($msg);
			return;
		}
		// If there are multiple matches, present a list of discs to choose from
		if (count($discs) > 1) {
			$context->reply($this->getDiscChoiceDialogue($discs));
			return;
		}
		// Only one found, so pick this one
		$disc = $discs[0];
		$this->discCommand($context, $disc);
	}

	public function discCommand(CmdContext $context, Disc $disc): void {
		$discLink = $this->text->makeItem($disc->disc_id, $disc->disc_id, $disc->disc_ql, $disc->disc_name);
		$nanoLink = $this->text->makeItem($disc->crystal_id, $disc->crystal_id, $disc->crystal_ql, $disc->crystal_name);
		$nanoDetails = $this->getNanoDetails($disc);
		if (!isset($nanoDetails)) {
			$context->reply("Cannot find the nano details for {$disc->disc_name}.");
			return;
		}
		$msg = sprintf(
			"%s will turn into %s (%s, %s, <highlight>%s<end>).",
			$discLink,
			$nanoLink,
			implode(", ", explode(":", $nanoDetails->professions)),
			$nanoDetails->nanoline_name,
			$nanoDetails->location
		);
		if (strlen($disc->comment ?? "")) {
			$msg .= " <red>" . ($disc->comment??"") . "!<end>";
		}
		$context->reply($msg);
	}

	/**
	 * Get additional information about the nano of a disc
	 */
	public function getNanoDetails(Disc $disc): ?NanoDetails {
		return $this->db->table("nanos")
			->where("crystal_id", $disc->crystal_id)
			->select("location", "professions", "strain AS nanoline_name")
			->asObj(NanoDetails::class)
			->first();
	}

	/**
	 * Generate a choice dialogue if multiple discs match the search criteria
	 * @param Disc[] $discs The discs that matched the search
	 */
	public function getDiscChoiceDialogue(array $discs): array {
		$blob = [];
		foreach ($discs as $disc) {
			$text = $this->text->makeChatcmd($disc->disc_name, "/tell <myname> disc ".$disc->disc_name);
			$blob []= $text;
		}
		$msg = $this->text->makeBlob(
			count($discs). " matches matching your search",
			implode("\n<pagebreak>", $blob),
			"Multiple matches, please choose one"
		);
		if (is_array($msg)) {
			return array_map(
				function($blob) {
					return "Found ${blob}.";
				},
				$msg
			);
		}
		return ["Found ${msg}."];
	}
}
