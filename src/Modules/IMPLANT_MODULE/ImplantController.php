<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Exception;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'implant',
		accessLevel: 'guest',
		description: 'Get information about the QL of an implant',
	)
]
class ImplantController extends ModuleInstance {
	public const FADED = 0;
	public const BRIGHT = 1;
	public const SHINY = 2;

	public const ATTRIBUTE = 0;
	public const TREATMENT = 1;
	public const TITLE_LEVEL = 2;

	public const REGULAR = 'reqRegular';
	public const JOBE = 'reqJobe';

	/** @var array<string,array<int,list<int>>> */
	protected array $implantBreakpoints = [
		'skills' => [
			  1 => [2,  3,   6],
			200 => [42, 63, 105],
			201 => [42, 63, 106],
			300 => [57, 85, 141],
		],
		'abilities' => [
			  1 => [2,  3,  5],
			200 => [22, 33, 55],
			201 => [22, 33, 55],
			300 => [29, 44, 73],
		],
		'reqRegular' => [
			  1 => [6,   11, 0],
			200 => [404,  951, 0],
			201 => [426, 1_001, 0],
			300 => [1_095, 2_051, 0],
		],
		'reqJobe' => [
			  1 => [16,   11, 3],
			200 => [414, 1_005, 4],
			201 => [476, 1_001, 5],
			300 => [1_231, 2_051, 6],
		],
	];

	#[NCA\Inject]
	private Text $text;

	/**
	 * Try to determine the bonus for an interpolated QL
	 *
	 * @param array<int,int> $itemSpecs  An associative array [QLX => bonus X, QLY => bonus Y]
	 * @param int            $searchedQL The QL we want to interpolate to
	 *
	 * @return int|null The interpolated bonus at the given QL or null if out of range
	 */
	public function calcStatFromQL(array $itemSpecs, int $searchedQL): ?int {
		$lastSpec = null;
		foreach ($itemSpecs as $itemQL => $itemBonus) {
			if ($lastSpec === null) {
				$lastSpec = [$itemQL, $itemBonus];
			} else {
				if ($lastSpec[0] <= $searchedQL && $itemQL >= $searchedQL) {
					$multi = (1 / ($itemQL - $lastSpec[0]));
					return (int)round($lastSpec[1] + (($itemBonus-$lastSpec[1]) * ($multi *($searchedQL-($lastSpec[0]-1)-1))));
				}
				$lastSpec = [$itemQL, $itemBonus];
			}
		}
		return null;
	}

	/**
	 * Try to find the lowest QL that gives a bonus
	 *
	 * @param int            $bonus     The bonus you want to reach
	 * @param array<int,int> $itemSpecs An associative array with ql => bonus
	 *
	 * @return int The lowest QL that gives that bonus
	 */
	public function findBestQLForBonus(int $bonus, array $itemSpecs): int {
		if (!count($itemSpecs)) {
			throw new InvalidArgumentException('$itemSpecs to findBestQLForBonus() must not be empty');
		}
		for ($searchedQL = min(array_keys($itemSpecs)); $searchedQL <= max(array_keys($itemSpecs)); $searchedQL++) {
			$value = $this->calcStatFromQL($itemSpecs, $searchedQL);
			if ($value === null) {
				continue;
			}
			if ($value > $bonus) {
				return $searchedQL-1;
			}
		}
		assert(isset($searchedQL));
		return $searchedQL-1;
	}

	/**
	 * Find the highest implant QL you can equip with given attribute and treatment
	 *
	 * @param int    $attributeLevel How much of the implant's attribute do you have?
	 * @param int    $treatmentLevel How much treatment do you have?
	 * @param string $type           self::REGULAR or self::JOBE
	 *
	 * @return int The highest usable implant QL
	 *
	 * @psalm-return int<0,300> The highest usable implant QL
	 */
	public function findHighestImplantQL(int $attributeLevel, int $treatmentLevel, string $type): int {
		$attributeBreakpoints = $this->getBreakpoints($type, self::ATTRIBUTE);
		$treatmentBreakpoints = $this->getBreakpoints($type, self::TREATMENT);
		$bestAttribQL = $this->findBestQLForBonus($attributeLevel, $attributeBreakpoints);
		$bestTreatmentQL = $this->findBestQLForBonus($treatmentLevel, $treatmentBreakpoints);

		$ql = min($bestAttribQL, $bestTreatmentQL, 300);
		assert($ql >= 1);
		assert($ql <= 300);
		return $ql;
	}

	/**
	 * Find the highest regular implant QL you can equip with given attribute and treatment
	 *
	 * @param int $attributeLevel How much of the implant's attribute do you have?
	 * @param int $treatmentLevel How much treatment do you have?
	 *
	 * @return int The highest usable regular implant QL
	 *
	 * @psalm-return int<0,300> The highest usable regular implant QL
	 */
	public function findHighestRegularImplantQL(int $attributeLevel, int $treatmentLevel): int {
		return $this->findHighestImplantQL($attributeLevel, $treatmentLevel, 'reqRegular');
	}

	/**
	 * Find the highest Jobe Implant QL you can equip with given attribute and treatment
	 *
	 * @param int $attributeLevel How much of the implant's attribute do you have?
	 * @param int $treatmentLevel How much treatment do you have?
	 *
	 * @return int The highest usable Jobe Implant QL
	 *
	 * @psalm-return int<0,300> The highest usable Jobe Implant QL
	 */
	public function findHighestJobeImplantQL(int $attributeLevel, int $treatmentLevel): int {
		return $this->findHighestImplantQL($attributeLevel, $treatmentLevel, 'reqJobe');
	}

	/** Show the highest QL implant for a given ability and treatment */
	#[NCA\HandlesCommand('implant')]
	#[NCA\Help\Epilogue(
		"<header2>Explanation<end>\n\n".
		"If you had 404 agility and 951 treatment, you would do\n".
		"<highlight><tab><symbol>implant 404 951<end>\n".
		"And the bot would tell you the highest ql implant you could wear, but\n".
		"also the requirements to reach the next breakpoint for each slot.\n\n".
		"When you view more info on an implant ql, the range of numbers next to the modifier tells you the range of quality levels that will give you the same modifier.\n\n".
		"For instance,\n\n".
		"<tab>Faded   22 (196 - 208)\n\n".
		'means that you will get 22 points (of ability, in this case) from the faded cluster slot starting with ql 196 on up to ql 208.'
	)]
	public function impQlDetermineCommand(CmdContext $context, int $attrib, int $treatment): void {
		$regularQL = $this->findHighestRegularImplantQL($attrib, $treatment);
		$jobeQL = $this->findHighestJobeImplantQL($attrib, $treatment);

		if ($regularQL === 0) {
			$msg = "Your pathetic stats aren't even enough for a QL 1 implant.";
			$context->reply($msg);
			return;
		}

		$regularBlob = $this->renderBlob(self::REGULAR, $regularQL)[0];

		$msg = "With <highlight>{$attrib}<end> Ability ".
			"and <highlight>{$treatment}<end> Treatment, ".
			"the highest possible {$regularBlob} is QL <highlight>{$regularQL}<end>";
		if ($jobeQL >= 100) {
			$jobeBlob = $this->renderBlob(self::JOBE, $jobeQL)[0];
			$msg .= " and the highest possible {$jobeBlob} is QL <highlight>{$jobeQL}<end>";
		}

		$context->reply($msg . '.');
	}

	/** Show the stats for implants at a given QL */
	#[NCA\HandlesCommand('implant')]
	public function impQlCommand(CmdContext $context, int $ql): void {
		if ($ql < 1 || $ql > 300) {
			$msg = 'Implants only exist is QLs between 1 and 300.';
			$context->reply($msg);
			return;
		}

		$regularBlob = $this->renderBlob(self::REGULAR, $ql)[0];

		$msg = "QL <highlight>{$ql}<end> {$regularBlob} details";
		if ($ql >= 100) {
			$jobeBlob = $this->renderBlob(self::JOBE, $ql)[0];
			$msg .= " and {$jobeBlob} details";
		}

		$context->reply($msg . '.');
	}

	/**
	 * Render the popup-blob for a regular or jobe implant at a given QL
	 *
	 * @param string $type self::REGULAR or self::JOBE
	 * @param int    $ql   The QL to render for
	 *
	 * @psalm-param int<1,300>    $ql   The QL to render for
	 *
	 * @return list<string> the full link to the blob
	 */
	public function renderBlob(string $type, int $ql): array {
		$specs = $this->getImplantQLSpecs($type, $ql);
		$indent = '<tab>';

		$blob = "<header2>Requirements to wear:<end>\n".
			$indent.Text::alignNumber($specs->requirements->abilities, 4, 'highlight').
			" Ability\n".
			$indent.Text::alignNumber($specs->requirements->treatment, 4, 'highlight').
			" Treatment\n";

		if ($specs->requirements->titleLevel > 0) {
			$blob .= $indent.Text::alignNumber($specs->requirements->titleLevel, 4, 'highlight').
			" Title level\n";
		}

		$blob .= "\n<header2>Ability Clusters:<end>\n".
			$indent.$this->renderBonusLine($specs->abilities->shiny, $type).
			$indent.$this->renderBonusLine($specs->abilities->bright, $type).
			$indent.$this->renderBonusLine($specs->abilities->faded, $type)."\n";

		$blob .= "<header2>Skill Clusters:<end>\n".
			$indent.$this->renderBonusLine($specs->skills->shiny, $type).
			$indent.$this->renderBonusLine($specs->skills->bright, $type).
			$indent.$this->renderBonusLine($specs->skills->faded, $type)."\n";

		$blob .= "\n\n";

		$buildMultiplier = [4, 3, 2];
		if ($type === self::JOBE) {
			$buildMultiplier = [6.25, 4.75, 3.25];
			if ($ql > 200) {
				$buildMultiplier = [6.75, 5.25, 3.75];
			}
		} elseif ($ql > 200) {
			$buildMultiplier = [5.25, 4.35, 2.55];
		}
		$blob .= "<header2>Requirements to build:<end>\n".
			$indent.Text::alignNumber((int)floor($buildMultiplier[0] * $ql), 4, 'highlight').
			" NP for Shiny\n".
			$indent.Text::alignNumber((int)floor($buildMultiplier[1] * $ql), 4, 'highlight').
			" NP for Bright\n".
			$indent.Text::alignNumber((int)floor($buildMultiplier[2] * $ql), 4, 'highlight').
			" NP for Faded\n\n";

		$blob .= "<header2>Requirements to clean:<end>\n";
		if ($type === self::JOBE) {
			$blob .= $indent . "Jobe Implants cannot be cleaned.\n\n";
		} elseif ($ql > 200) {
			$blob .= $indent . "Refined Implants cannot be cleaned.\n\n";
		} else {
			$blob .= $indent.Text::alignNumber($ql, 4, 'highlight') . " NanoProgramming\n".
				$indent.Text::alignNumber((int)floor(4.75*$ql), 4, 'highlight') . " Break&Entry\n\n";
		}

		$minQL = 1;
		if ($ql >= 201) {
			$minQL = 201;
		}
		$shinyQL = $this->getClusterMinQl($ql, 'shiny');
		$brightQL = $this->getClusterMinQl($ql, 'bright');
		$fadedQL = $this->getClusterMinQl($ql, 'faded');
		$blob .= "<header2>Minimum Cluster QL:<end>\n".
			$indent.Text::alignNumber(max($minQL, $shinyQL), 3, 'highlight') . " Shiny\n".
			$indent.Text::alignNumber(max($minQL, $brightQL), 3, 'highlight') . " Bright\n".
			$indent.Text::alignNumber(max($minQL, $fadedQL), 3, 'highlight') . " Faded\n\n";

		$impName = 'Implant';
		if ($type === self::JOBE) {
			if ($ql >= 201) {
				$impName = 'Implant with a shiny Jobe cluster and all other clusters filled';
			} else {
				$impName = 'Jobe Implant';
			}
		}
		return (array)$this->text->makeBlob($impName, $blob, "QL {$ql} {$impName} Details");
	}

	/**
	 * @psalm-param int<1,300> $ql
	 *
	 * @psalm-return int<1,300>
	 */
	public function getClusterMinQl(int $ql, string $grade): int {
		if ($grade === 'shiny') {
			$minQL = (int)floor($ql * 0.86);
		} elseif ($grade === 'bright') {
			$minQL = (int)floor($ql * 0.84);
		} elseif ($grade === 'faded') {
			$minQL = (int)floor($ql * 0.82);
		} else {
			throw new Exception("Invalid grade: '{$grade}'.  Must be one of: 'shiny', 'bright', 'faded'");
		}
		assert($minQL >= 1);
		assert($minQL <= 300);
		return $minQL;
	}

	/**
	 * Returns the min- and max-ql for an implant to return a bonus
	 *
	 * @param string $type  The cluster type ("skill" or "abililities")
	 * @param int    $slot  The cluster slot type (0 => faded, 1 => bright, 2 => shiny)
	 * @param int    $bonus The bonus for which to return the QL-range
	 *
	 * @return ?list<int> An array with the min- and the max-ql
	 *
	 * @psalm-return ?list{int,int}
	 */
	public function getBonusQLRange(string $type, int $slot, int $bonus): ?array {
		$breakpoints = $this->getBreakpoints($type, $slot);

		/** @var int */
		$minQL = min(array_keys($breakpoints));

		/** @var int */
		$maxQL = max(array_keys($breakpoints));
		$foundMinQL = 0;
		$foundMaxQL = 300;
		for ($ql = $minQL; $ql <= $maxQL; $ql++) {
			$statBonus = $this->calcStatFromQL($breakpoints, $ql);
			if (($statBonus??0) > $bonus) {
				return [$foundMinQL, $foundMaxQL];
			} elseif ($statBonus === $bonus) {
				$foundMaxQL = $ql;
				if ($foundMinQL === 0) {
					$foundMinQL = $ql;
				}
			}
		}
		if (isset($statBonus) && $statBonus === $bonus) {
			return [$foundMinQL, $foundMaxQL];
		}
		return null;
	}

	/**
	 * Get all specs of an implant at a certain ql
	 *
	 * @param string $type self::JOBE or self::REGULAR
	 * @param int    $ql   The QL of the implant you want to build
	 */
	public function getImplantQLSpecs(string $type, int $ql): ImplantSpecs {

		$treatmentBreakpoints = $this->getBreakpoints($type, self::TREATMENT);
		$attributeBreakpoints = $this->getBreakpoints($type, self::ATTRIBUTE);
		$tlBreakpoints = $this->getBreakpoints($type, self::TITLE_LEVEL);

		return new ImplantSpecs(
			ql: $ql,
			requirements: new ImplantRequirements(
				treatment: $this->calcStatFromQL($treatmentBreakpoints, $ql)??0,
				abilities: $this->calcStatFromQL($attributeBreakpoints, $ql)??0,
				titleLevel: $this->calcStatFromQL($tlBreakpoints, $ql)??1,
			),
			skills: new ImplantBonusTypes(
				faded: $this->getBonusStatsForType('skills', self::FADED, $ql),
				bright: $this->getBonusStatsForType('skills', self::BRIGHT, $ql),
				shiny: $this->getBonusStatsForType('skills', self::SHINY, $ql),
			),
			abilities: new ImplantBonusTypes(
				faded: $this->getBonusStatsForType('abilities', self::FADED, $ql),
				bright: $this->getBonusStatsForType('abilities', self::BRIGHT, $ql),
				shiny: $this->getBonusStatsForType('abilities', self::SHINY, $ql),
			)
		);
	}

	/**
	 * Get a single breakpoint-spec from the internal breakpoint list
	 *
	 * @param string $type     The name of the breakpoint ("abilities", "reqRegular", ...)
	 * @param int    $position The position in the list (usually 0, 1 or 2)
	 *
	 * @return array<int,int> An associative array in the form [QL => bonus/requirement]
	 *
	 * @phpstan-return non-empty-array<int,int> An associative array in the form [QL => bonus/requirement]
	 */
	protected function getBreakpoints(string $type, int $position): array {
		/** @phpstan-var non-empty-array<int,int> */
		$breakPoints = array_map(
			static function (array $item) use ($position): int {
				return $item[$position];
			},
			$this->implantBreakpoints[$type]
		);
		return $breakPoints;
	}

	/**
	 * Render a single bonus stat for a cluster type
	 * Roughly looks like this:
	 * 42 (QL 147 - QL 150) Shiny -> 306 / 720
	 *
	 * @param ImplantBonusStats $stats The stats to render
	 * @param string            $type  "Shiny", "Bright" or "Faded"
	 *
	 * @return string the rendered line including newline
	 */
	protected function renderBonusLine(ImplantBonusStats $stats, string $type): string {
		$fromQL = Text::alignNumber($stats->range[0], 3, 'highlight');
		$toQL   = Text::alignNumber($stats->range[1], 3, 'highlight');

		$line = Text::alignNumber($stats->buff, 3, 'highlight').
			" (QL {$fromQL} - QL {$toQL}) " . $stats->slot;
		if ($stats->range[1] < 300) {
			$nextBest = $this->getImplantQLSpecs($type, $stats->range[1]+1);
			$line .= ' <header>-><end> '.
				'<highlight>' . $nextBest->requirements->abilities . '<end>'.
				' / '.
				'<highlight>' . $nextBest->requirements->treatment . '<end>';
		}
		return $line . "\n";
	}

	/**
	 * Get the bonus stats for an implant slot and ql
	 *
	 * @param string $type Type of bonus ("skills" or "abilities")
	 * @param int    $slot 0 => faded, 1 => bright, 2 => shiny
	 * @param int    $ql   The QL of the implant
	 */
	protected function getBonusStatsForType(string $type, int $slot, int $ql): ImplantBonusStats {
		$breakpoints = $this->getBreakpoints($type, $slot);
		$buff = $this->calcStatFromQL($breakpoints, $ql);
		if (!isset($buff)) {
			throw new Exception("Cannot calculate stats for ql {$ql}");
		}
		$range = $this->getBonusQLRange($type, $slot, $buff);
		if (!isset($range)) {
			throw new Exception("Cannot calculate QL for giving +{$buff}");
		}
		return new ImplantBonusStats(
			slot: $slot,
			range: $range,
			buff: $buff,
		);
	}
}
