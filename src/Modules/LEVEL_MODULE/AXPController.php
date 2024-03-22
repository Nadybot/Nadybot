<?php declare(strict_types=1);

namespace Nadybot\Modules\LEVEL_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'axp',
		accessLevel: 'guest',
		description: 'Show axp needed for specified level(s)',
	)
]
class AXPController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	/**
	 * @var array<array<int|string>>
	 *
	 * @psalm-var array{0:int, 1:int, 2:string}[]
	 *
	 * @phpstan-var array{0:int, 1:int, 2:string}[]
	 */
	private array $aiRanks = [
		[1_500,   5, 'Fledgling'],
		[9_000,  15, 'Amateur'],
		[22_500,  25, 'Beginner'],
		[42_000,  35, 'Starter'],
		[67_500,  45, 'Newcomer'],
		[99_000,  55, 'Student'],
		[136_500,  65, 'Common'],
		[180_000,  75, 'Intermediate'],
		[229_500,  85, 'Mediocre'],
		[285_000,  95, 'Fair'],
		[346_500, 105, 'Able'],
		[414_000, 110, 'Accomplished'],
		[487_500, 115, 'Adept'],
		[567_000, 120, 'Qualified'],
		[697_410, 125, 'Competent'],
		[857_814, 130, 'Suited'],
		[1_055_112, 135, 'Talented'],
		[1_297_787, 140, 'Trustworthy'],
		[1_596_278, 145, 'Supporter'],
		[1_931_497, 150, 'Backer'],
		[2_298_481, 155, 'Defender'],
		[2_689_223, 160, 'Challenger'],
		[3_092_606, 165, 'Patron'],
		[3_494_645, 170, 'Protector'],
		[3_879_056, 175, 'Medalist'],
		[4_228_171, 180, 'Champ'],
		[4_608_707, 185, 'Hero'],
		[5_023_490, 190, 'Guardian'],
		[5_475_604, 195, 'Vanquisher'],
		[5_968_409, 200, 'Vindicator'],
	];

	/** See a list of all Alien Levels */
	#[NCA\HandlesCommand('axp')]
	#[NCA\Help\Group('xp')]
	public function axpListCommand(CmdContext $context): void {
		$blob = "<u>AI Lvl | Lvl Req |          AXP  |  Rank         </u>\n";
		for ($aiRank = 0; $aiRank < count($this->aiRanks); $aiRank++) {
			$rankInfo = $this->aiRanks[$aiRank];
			$blob .= $this->text->alignNumber($aiRank+1, 2).
				'     |     ' . $this->text->alignNumber($rankInfo[1], 3).
				'  |  ' . $this->text->alignNumber($rankInfo[0], 7, 'highlight', true).
				'  |  ' . $rankInfo[2] . "\n";
		}

		$msg = $this->text->makeBlob('Alien Experience', $blob);

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

		$axp_comp = 0;
		for ($i = $startLevel; $i < $endLevel; $i++) {
			$axp_comp += $this->aiRanks[$i][0];
		}

		$msg = "From the beginning of AI level <highlight>{$startLevel}<end> you need <highlight>".number_format($axp_comp)."<end> AXP to reach AI level <highlight>{$endLevel}<end>.";

		$context->reply($msg);
	}
}
