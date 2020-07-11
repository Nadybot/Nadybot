<?php

namespace Budabot\Modules\LEVEL_MODULE;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'axp',
 *		accessLevel = 'all',
 *		description = 'Show axp needed for specified level(s)',
 *		help        = 'xp.txt'
 *	)
 */
class AXPController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	private $aiRanks = [
		[1500,      5, "Fledgling"],
		[9000,     15, "Amateur"],
		[22500,    25, "Beginner"],
		[42000,    35, "Starter"],
		[67500,    45, "Newcomer"],
		[99000,    55, "Student"],
		[136500,   65, "Common"],
		[180000,   75, "Intermediate"],
		[229500,   85, "Mediocre"],
		[285000,   95, "Fair"],
		[346500,  105, "Able"],
		[414000,  110, "Accomplished"],
		[487500,  115, "Adept"],
		[567000,  120, "Qualified"],
		[697410,  125, "Competent"],
		[857814,  130, "Suited"],
		[1055112, 135, "Talented"],
		[1297787, 140, "Trustworthy"],
		[1596278, 145, "Supporter"],
		[1931497, 150, "Backer"],
		[2298481, 155, "Defender"],
		[2689223, 160, "Challenger"],
		[3092606, 165, "Patron"],
		[3494645, 170, "Protector"],
		[3879056, 175, "Medalist"],
		[4228171, 180, "Champ"],
		[4608707, 185, "Hero"],
		[5023490, 190, "Guardian"],
		[5475604, 195, "Vanquisher"],
		[5968409, 200, "Vindicator"],
	];
	
	/**
	 * @HandlesCommand("axp")
	 * @Matches("/^axp$/i")
	 */
	public function axpListCommand($message, $channel, $sender, $sendto, $args) {
		$blob = "<u>AI Lvl | Lvl Req |          AXP  |  Rank         </u>\n";
		for ($aiRank = 0; $aiRank < count($this->aiRanks); $aiRank++) {
			$rankInfo = $this->aiRanks[$aiRank];
			$blob .= $this->text->alignNumber($aiRank+1, 2).
				"     |     " . $this->text->alignNumber($rankInfo[1], 3).
				"  |  " . $this->text->alignNumber($rankInfo[0], 7, "highlight", true).
				"  |  " . $rankInfo[2] . "\n";
		}

		$msg = $this->text->makeBlob("Alien Experience", $blob);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("axp")
	 * @Matches("/^axp ([0-9]+)$/i")
	 */
	public function axpSingleCommand($message, $channel, $sender, $sendto, $args) {
		$level = $args[1];
		if ($level > 30) {
			$msg = "AI level must be between 0 and 30.";
			$sendto->reply($msg);
			return;
		}
		$msg = "At AI level <highlight>$level<end> you need <highlight>".number_format($this->aiRanks[$level][0])."<end> AXP to level up.";

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("axp")
	 * @Matches("/^axp ([0-9]+)\s+([0-9]+)$/i")
	 */
	public function axpDoubleCommand($message, $channel, $sender, $sendto, $args) {
		$startLevel = $args[1];
		$endLevel = $args[2];
		if ($startLevel > 30 || $endLevel > 30) {
			$msg = "AI level must be between 0 and 30.";
			$sendto->reply($msg);
			return;
		}
		if ($startLevel > $endLevel) {
			$msg = "The start level cannot be higher than the end level.";
			$sendto->reply($msg);
			return;
		}

		$axp_comp = 0;
		for ($i = $startLevel; $i < $endLevel; $i++) {
			$axp_comp += $this->aiRanks[$i][0];
		}

		$msg = "From the beginning of AI level <highlight>$startLevel<end> you need <highlight>".number_format($axp_comp)."<end> AXP to reach AI level <highlight>$endLevel<end>.";

		$sendto->reply($msg);
	}
}
