<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
 * @author Jaqueme
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Research"),
	NCA\DefineCommand(
		command: "research",
		accessLevel: "all",
		description: "Show info on Research",
		help: "research.txt"
	)
]
class ResearchController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/research.csv');
	}

	#[NCA\HandlesCommand("research")]
	public function researchSingleCommand(CmdContext $context, int $level): void {
		if ($level < 1 || $level > 10) {
			$context->reply("Valid values are 1-10.");
			return;
		}
		/** @var Research */
		$row = $this->db->table("research")
			->where("level", $level)
			->asObj(Research::class)
			->first();

		$levelcap = $row->levelcap;
		$sk = $row->sk??0;
		$xp = $sk * 1000;
		$capXP = number_format(round($xp * .1));
		$capSK = number_format(round($sk * .1));
		$xp = number_format($xp);
		$sk = number_format($sk);

		$blob = "You must be <highlight>Level $levelcap<end> to reach <highlight>Research Level $level<end>.\n";
		$blob .= "You need <highlight>$sk SK<end> to reach <highlight>Research Level $level<end> per research line.\n\n";
		$blob .= "This equals <highlight>$xp XP<end>.\n\n";
		$blob .= "Your research will cap at <highlight>~$capXP XP<end> or <highlight>~$capSK SK<end>.";
		$msg = $this->text->makeBlob("Research Level $level", $blob);

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("research")]
	public function researchDoubleCommand(CmdContext $context, int $from, int $to): void {
		if ($from < 1 || $from > 10 || $to < 1 || $to > 10) {
			$context->reply("Valid values are 1-10.");
			return;
		}
		$loLevel = min($from, $to);
		$hiLevel = max($from, $to);
		$query = $this->db->table("research")
			->where("level", ">", $loLevel)
			->where("level", "<=", $hiLevel);
		$query->select($query->colFunc("SUM", "sk", "totalsk"));
		$query->addSelect($query->colFunc("MAX", "levelcap", "levelcap"));
		/** @var ?ResearchResult */
		$row = $query->asObj(ResearchResult::class)->first();
		if (!isset($row) || $loLevel === $hiLevel) {
			$msg = "That doesn't make any sense.";
			$context->reply($msg);
			return;
		}

		$xp = number_format($row->totalsk * 1000);
		$sk = number_format((int)$row->totalsk);

		$blob = "You must be <highlight>Level $row->levelcap<end> to reach Research Level <highlight>$hiLevel.<end>\n";
		$blob .= "It takes <highlight>$sk SK<end> to go from Research Level <highlight>$loLevel<end> to Research Level <highlight>$hiLevel<end> per research line.\n\n";
		$blob .= "This equals <highlight>$xp XP<end>.";
		$msg = $this->text->makeBlob("Research Levels $loLevel - $hiLevel", $blob);

		$context->reply($msg);
	}
}
