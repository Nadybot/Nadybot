<?php declare(strict_types=1);

namespace Nadybot\Modules\LEVEL_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
	Types\AccessLevel,
	Types\AlienRank,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'axp',
		accessLevel: AccessLevel::Guest,
		description: 'Show axp needed for specified level(s)',
	)
]
class AXPController extends ModuleInstance {
	/**
	 * @var array<array<int|string>>
	 *
	 * @psalm-var array{0:int, 1:int, 2:string}[]
	 *
	 * @phpstan-var array{0:int, 1:int, 2:string}[]
	 */
	private array $aiRanks = AlienRank::LEVELS;

	/** See a list of all Alien Levels */
	#[NCA\HandlesCommand('axp')]
	#[NCA\Help\Group('xp')]
	public function axpListCommand(CmdContext $context): void {
		$blob = "<u>AI Lvl | Lvl Req |          AXP  |  Rank         </u>\n";
		for ($aiRank = 0; $aiRank < count($this->aiRanks); $aiRank++) {
			$rankInfo = $this->aiRanks[$aiRank];
			$blob .= Text::alignNumber($aiRank+1, 2).
				'     |     ' . Text::alignNumber($rankInfo[1], 3).
				'  |  ' . Text::alignNumber($rankInfo[0], 7, 'highlight', true).
				'  |  ' . $rankInfo[2] . "\n";
		}

		$msg = Text::makeBlob('Alien Experience', $blob);

		$context->reply($msg);
	}

	/** See needed XP to level up for a single Alien Level */
	#[NCA\HandlesCommand('axp')]
	#[NCA\Help\Group('xp')]
	public function axpSingleCommand(CmdContext $context, int $level): void {
		if ($level > 30) {
			$msg = 'AI level must be between 0 and 30.';
			$context->reply($msg);
			return;
		}
		$msg = "At AI level <highlight>{$level}<end> you need <highlight>".number_format($this->aiRanks[$level][0]).'<end> AXP to level up.';

		$context->reply($msg);
	}

	/** See how much AXP is needed from one level to another */
	#[NCA\HandlesCommand('axp')]
	#[NCA\Help\Group('xp')]
	public function axpDoubleCommand(CmdContext $context, int $startLevel, int $endLevel): void {
		if ($startLevel > 30 || $endLevel > 30) {
			$msg = 'AI level must be between 0 and 30.';
			$context->reply($msg);
			return;
		}
		if ($startLevel > $endLevel) {
			$msg = 'The start level cannot be higher than the end level.';
			$context->reply($msg);
			return;
		}

		$axpComp = 0;
		for ($i = $startLevel; $i < $endLevel; $i++) {
			$axpComp += $this->aiRanks[$i][0];
		}

		$msg = "From the beginning of AI level <highlight>{$startLevel}<end> you need <highlight>".number_format($axpComp)."<end> AXP to reach AI level <highlight>{$endLevel}<end>.";

		$context->reply($msg);
	}
}
