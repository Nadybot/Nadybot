<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
 * @author Jaqueme
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'research',
 *		accessLevel = 'all',
 *		description = 'Show info on Research',
 *		help        = 'research.txt'
 *	)
 */
class ResearchController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'research');
	}

	/**
	 * @HandlesCommand("research")
	 * @Matches("/^research ([1-9]|10)$/i")
	 */
	public function researchSingleCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$level = (int)$args[1];
		$sql = "SELECT * FROM research WHERE level = ?";
		/** @var ?Research */
		$row = $this->db->fetch(Research::class, $sql, $level);

		$levelcap = $row->levelcap;
		$sk = $row->sk;
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

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("research")
	 * @Matches("/^research ([1-9]|10) ([1-9]|10)$/i")
	 */
	public function researchDoubleCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$loLevel = min((int)$args[1], (int)$args[2]);
		$hiLevel = max((int)$args[1], (int)$args[2]);
		$sql =
			"SELECT SUM(sk) totalsk, MAX(levelcap) levelcap ".
			"FROM research ".
			"WHERE level > ? AND level <= ?";
		$row = $this->db->queryRow($sql, $loLevel, $hiLevel);

		$xp = number_format($row->totalsk * 1000);
		$sk = number_format($row->totalsk);

		$blob = "You must be <highlight>Level $row->levelcap<end> to reach Research Level <highlight>$hiLevel.<end>\n";
		$blob .= "It takes <highlight>$sk SK<end> to go from Research Level <highlight>$loLevel<end> to Research Level <highlight>$hiLevel<end> per research line.\n\n";
		$blob .= "This equals <highlight>$xp XP<end>.";
		$msg = $this->text->makeBlob("Research Levels $loLevel - $hiLevel", $blob);

		$sendto->reply($msg);
	}
}
