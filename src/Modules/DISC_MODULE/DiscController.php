<?php declare(strict_types=1);

namespace Nadybot\Modules\DISC_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	DBRow,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'disc',
 *		accessLevel = 'all',
 *		description = 'Show which nano a disc will turn into',
 *		help        = 'disc.txt'
 *	)
 */
class DiscController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Setup This handler is called on bot startup.
	 */
	public function setup(): void {
		// load database tables from .sql-files
		$this->db->loadSQLFile($this->moduleName, 'discs');
	}

	/**
	 * Get the instruction disc from its name and return an array with results
	 *
	 * @return Disc[] An array of database entries that matched
	 */
	public function getDiscsByName(string $discName): array {
		[$where, $params] = $this->util->generateQueryFromParams(explode(' ', $discName), 'disc_name');
		$sql = "SELECT * FROM discs WHERE $where";
		/** @var Disc[] */
		return $this->db->fetchAll(Disc::class, $sql, ...$params);
	}

	/**
	 * Get the instruction disc from its id and return the result or null
	 */
	public function getDiscById(int $discId): ?Disc {
		$sql = "SELECT * FROM discs WHERE disc_id = ?";
		return $this->db->fetch(Disc::class, $sql, $discId);
	}

	/**
	 * Command to show what nano a disc will turn into
	 *
	 * @HandlesCommand("disc")
	 * @Matches("/^disc (.+)$/i")
	 */
	public function discCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$disc = null;
		// Check if a disc was pasted into the chat and extract its ID
		if (preg_match("|<a href=['\"]itemref://(?<lowId>\d+)/(?<highId>\d+)/(?<ql>\d+)['\"]>(?<name>.+?)</a>|", $args[1], $matches)) {
			$discId = (int)$matches['lowId'];
			// If there is a DiscID deducted, get the nano crystal ID and name
			$disc = $this->getDiscById($discId);
			// None found? Cannot be made into a nano anymore
			if ($disc === null) {
				if (!preg_match('|instruction\s*dis[ck]|i', $matches["name"])) {
					$msg = $args[1] . " is not an instruction disc.";
				} else {
					$msg = $args[1] . " cannot be made into a nano anymore.";
				}
				$sendto->reply($msg);
				return;
			}
		} else {
			// If only a name was given, lookup the disc's ID
			$discs = $this->getDiscsByName($args[1]);
			// Not found? Cannot be made into a nano anymore or simply mistyped
			if (empty($discs)) {
				$msg = "Either <highlight>" . $args[1] . "<end> was mistyped or it cannot be turned into a nano anymore.";
				$sendto->reply($msg);
				return;
			}
			// If there are multiple matches, present a list of discs to choose from
			if (count($discs) > 1) {
				$sendto->reply($this->getDiscChoiceDialogue($discs));
				return;
			}
			// Only one found, so pick this one
			$disc = $discs[0];
		}

		// Now we have exactly one nano. Show it to the user
		$discLink = $this->text->makeItem($disc->disc_id, $disc->disc_id, $disc->disc_ql, $disc->disc_name);
		$nanoLink = $this->text->makeItem($disc->crystal_id, $disc->crystal_id, $disc->crystal_ql, $disc->crystal_name);
		$nanoDetails = $this->getNanoDetails($disc);
		$msg = sprintf(
			"%s will turn into %s (%s, %s, <highlight>%s<end>).",
			$discLink,
			$nanoLink,
			implode(", ", explode(":", $nanoDetails->professions)),
			$nanoDetails->nanoline_name,
			$nanoDetails->location
		);
		if (strlen($disc->comment ?? "")) {
			$msg .= " <red>" . $disc->comment . "!<end>";
		}
		$sendto->reply($msg);
	}

	/**
	 * Get additional information about the nano of a disc
	 */
	public function getNanoDetails(Disc $disc): ?DBRow {
		$sql = "SELECT ".
					"location, ".
					"professions, ".
					"strain AS nanoline_name ".
				"FROM nanos n ".
				"WHERE crystal_id = ?";
		return $this->db->queryRow($sql, $disc->crystal_id);
	}

	/**
	 * Generate a choice dialogue if multiple discs match the search criteria
	 *
	 * @param Disc[] $discs The discs that matched the search
	 */
	public function getDiscChoiceDialogue(array $discs): string {
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
		return "Found ${msg}.";
	}
}
