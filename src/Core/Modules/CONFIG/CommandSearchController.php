<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandManager,
	DB,
	ModuleInstance,
	Nadybot,
	SQLException,
	Text,
};
use Nadybot\Core\DBSchema\CommandSearchResult;

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "cmdsearch",
		accessLevel: "all",
		description: "Finds commands based on key words",
		help: "cmdsearch.txt",
		defaultStatus: 1,
		alias: "searchcmd"
	)
]
class CommandSearchController extends ModuleInstance {

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public AccessManager $accessManager;

	/** @var string[] */
	private array $searchWords;

	#[NCA\HandlesCommand("cmdsearch")]
	public function searchCommand(CmdContext $context, string $search): void {
		$this->searchWords = \Safe\preg_split("/\s+/", $search) ?: [];

		// if a mod or higher, show all commands, not just enabled commands
		$access = false;
		if ($this->accessManager->checkAccess($context->char->name, 'mod')) {
			$access = true;
		}

		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("cmd", $search)
			->select("module", "cmd", "help", "description", "admin")->distinct();
		if (!$access) {
			$query->where("status", 1);
		}
		$results = $query->asObj(CommandSearchResult::class)->toArray();
		$results = $this->filterResultsByAccessLevel($context->char->name, $results);

		$exactMatch = !empty($results);

		if (!$exactMatch) {
			$results = $this->findSimilarCommands($this->searchWords, $access);
			$results = $this->filterResultsByAccessLevel($context->char->name, $results);
			$results = array_slice($results, 0, 5);
		}

		$msg = $this->render($results, $access, $exactMatch);

		$context->reply($msg);
	}

	/**
	 * Remove all commands that we don't have access to
	 * @param string $sender
	 * @param CommandSearchResult[] $data
	 * @return CommandSearchResult[]
	 * @throws SQLException
	 * @throws Exception
	 */
	public function filterResultsByAccessLevel(string $sender, array $data): array {
		$results = [];
		$charAccessLevel = $this->accessManager->getSingleAccessLevel($sender);
		foreach ($data as $key => $row) {
			if ($this->accessManager->compareAccessLevels($charAccessLevel, $row->admin) >= 0) {
				$results []= $row;
			}
		}
		return $results;
	}

	/**
	 * @param string[] $wordArray
	 * @return CommandSearchResult[]
	 */
	public function findSimilarCommands(array $wordArray, bool $includeDisabled=false): array {
		$query = $this->db->table(CommandManager::DB_TABLE)
			->select("module", "cmd", "help", "description", "admin")->distinct();
		if (!$includeDisabled) {
			$query->where("status", 1);
		}
		/** @var CommandSearchResult[] $data */
		$data = $query->asObj(CommandSearchResult::class)->toArray();

		foreach ($data as $row) {
			$keywords = [$row->cmd];
			$keywords = array_unique($keywords);
			foreach ($wordArray as $searchWord) {
				$similarity = 0;
				$rowSimilarity = 0;
				foreach ($keywords as $keyword) {
					similar_text($keyword, $searchWord, $rowSimilarity);
					$similarity = max($similarity, $rowSimilarity);
				}
				$row->similarity_percent = $similarity;
			}
		}
		$results = $data;
		usort($results, [$this, 'sortBySimilarity']);

		return $results;
	}

	public function sortBySimilarity(CommandSearchResult $row1, CommandSearchResult $row2): int {
		return $row2->similarity_percent <=> $row1->similarity_percent;
	}

	/**
	 * @param CommandSearchResult[] $results
	 * @return string|string[]
	 */
	public function render(array $results, bool $hasAccess, bool $exactMatch): string|array {
		$blob = '';
		foreach ($results as $row) {
			$helpLink = "";
			if ($row->help !== null && $row->help !== '') {
				$helpLink = ' (' . $this->text->makeChatcmd("Help", "/tell <myname> help $row->cmd") . ')';
			}
			if ($hasAccess) {
				$module = $this->text->makeChatcmd($row->module, "/tell <myname> config {$row->module}");
			} else {
				$module = "{$row->module}";
			}

			$blob .= "<header2>{$row->cmd}<end>\n<tab>{$module} - {$row->description}{$helpLink}\n";
		}

		$count = count($results);
		if ($count === 0) {
			return "No results found.";
		}
		if ($exactMatch) {
			$msg = $this->text->makeBlob("Command Search Results ($count)", $blob);
		} else {
			$msg = $this->text->makeBlob("Possible Matches ($count)", $blob);
		}
		return $msg;
	}
}
