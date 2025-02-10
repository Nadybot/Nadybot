<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
	Types\MinMax,
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
	/**
	 * Find the highest implant QL you can equip with given attribute and treatment
	 *
	 * @param int $ability   How much of the implant's ability do you have?
	 * @param int $treatment How much treatment do you have?
	 *
	 * @return int The highest usable regular implant QL
	 *
	 * @psalm-return int<0,300> The highest usable regular implant QL
	 */
	public function findHighestEquippableImplant(int $ability, int $treatment, bool $jobe): int {
		$searchedQL = 1;
		for (; $searchedQL <= 300; $searchedQL++) {
			$abiReq = Implant::getRequirement(ImplantRequirement::Ability, $jobe, $searchedQL);
			$treatReq = Implant::getRequirement(ImplantRequirement::Treatment, $jobe, $searchedQL);
			if ($abiReq > $ability || $treatReq > $treatment) {
				break;
			}
		}
		return min(300, $searchedQL - 1);
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
		$regularQL = $this->findHighestEquippableImplant($attrib, $treatment, false);
		$jobeQL = $this->findHighestEquippableImplant($attrib, $treatment, true);

		if ($regularQL === 0) {
			$msg = "Your pathetic stats aren't even enough for a QL 1 implant.";
			$context->reply($msg);
			return;
		}

		$regularBlob = $this->renderBlob(false, $regularQL);

		$msg = "With <highlight>{$attrib}<end> Ability ".
			"and <highlight>{$treatment}<end> Treatment, ".
			"the highest possible {$regularBlob} is QL <highlight>{$regularQL}<end>";
		if ($jobeQL >= 100) {
			$jobeBlob = $this->renderBlob(true, $jobeQL);
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

		$regularBlob = $this->renderBlob(false, $ql);

		$msg = "QL <highlight>{$ql}<end> {$regularBlob} details";
		if ($ql >= 100) {
			$jobeBlob = $this->renderBlob(true, $ql);
			$msg .= " and {$jobeBlob} details";
		}

		$context->reply($msg . '.');
	}

	/**
	 * Render the popup-blob for a regular or jobe implant at a given QL
	 *
	 * @param int $ql The QL to render for
	 *
	 * @psalm-param int<1,300>    $ql   The QL to render for
	 */
	public function renderBlob(bool $jobe, int $ql): string {
		$specs = $this->getImplantQLSpecs($jobe, $ql);
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
			$indent.$this->renderBonusLine($specs->abilities->shiny, $jobe).
			$indent.$this->renderBonusLine($specs->abilities->bright, $jobe).
			$indent.$this->renderBonusLine($specs->abilities->faded, $jobe)."\n";

		$blob .= "<header2>Skill Clusters:<end>\n".
			$indent.$this->renderBonusLine($specs->skills->shiny, $jobe).
			$indent.$this->renderBonusLine($specs->skills->bright, $jobe).
			$indent.$this->renderBonusLine($specs->skills->faded, $jobe)."\n";

		$blob .= "\n\n";

		$blob .= "<header2>Requirements to build:<end>\n".
			$indent.Text::alignNumber(Implant::getRequiredNP($jobe, ClusterGrade::Shiny, $ql), 4, 'highlight').
			" NP for Shiny\n".
			$indent.Text::alignNumber(Implant::getRequiredNP($jobe, ClusterGrade::Bright, $ql), 4, 'highlight').
			" NP for Bright\n".
			$indent.Text::alignNumber(Implant::getRequiredNP($jobe, ClusterGrade::Faded, $ql), 4, 'highlight').
			" NP for Faded\n\n";

		$blob .= "<header2>Requirements to clean:<end>\n";
		if ($jobe) {
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
		$shinyQL = $this->getClusterMinQl($ql, ClusterGrade::Shiny);
		$brightQL = $this->getClusterMinQl($ql, ClusterGrade::Bright);
		$fadedQL = $this->getClusterMinQl($ql, ClusterGrade::Faded);
		$blob .= "<header2>Minimum Cluster QL:<end>\n".
			$indent.Text::alignNumber(max($minQL, $shinyQL), 3, 'highlight') . " Shiny\n".
			$indent.Text::alignNumber(max($minQL, $brightQL), 3, 'highlight') . " Bright\n".
			$indent.Text::alignNumber(max($minQL, $fadedQL), 3, 'highlight') . " Faded\n\n";

		$impName = 'Implant';
		if ($jobe) {
			if ($ql >= 201) {
				$impName = 'Implant with a shiny Jobe cluster and all other clusters filled';
			} else {
				$impName = 'Jobe Implant';
			}
		}
		return Text::makeBlob($impName, $blob, "QL {$ql} {$impName} Details");
	}

	/**
	 * @psalm-param int<1,300> $ql
	 *
	 * @psalm-return int<1,300>
	 */
	public function getClusterMinQl(int $ql, ClusterGrade $grade): int {
		$minQL = match ($grade) {
			ClusterGrade::Shiny => (int)floor($ql * 0.86),
			ClusterGrade::Bright => (int)floor($ql * 0.84),
			ClusterGrade::Faded => (int)floor($ql * 0.82),
		};
		return max(min($minQL, 300), 1);
	}

	/**
	 * Returns the min- and max-ql for an implant to return a bonus
	 *
	 * @param ImplantBuff  $type  The cluster type (Skill or Ability)
	 * @param ClusterGrade $grade The cluster slot type (Shiny, Bright, or Faded)
	 * @param int          $bonus The bonus for which to return the QL-range
	 *
	 * @return ?MinMax The min- and the max-ql
	 */
	public function getBonusQLRange(ImplantBuff $type, ClusterGrade $grade, int $bonus): ?MinMax {
		$foundMinQL = 0;
		$foundMaxQL = 300;
		$ql = 1;
		for (; $ql <= 300; $ql++) {
			$statBonus = Implant::getBuff($type, $grade, $ql);
			if ($statBonus > $bonus) {
				return new MinMax(min: $foundMinQL, max: $foundMaxQL);
			} elseif ($statBonus === $bonus) {
				$foundMaxQL = $ql;
				if ($foundMinQL === 0) {
					$foundMinQL = $ql;
				}
			}
		}
		if (isset($statBonus) && $statBonus === $bonus) {
			return new MinMax(min: $foundMinQL, max: $foundMaxQL);
		}
		return null;
	}

	/**
	 * Get all specs of an implant at a certain ql
	 *
	 * @param int $ql The QL of the implant you want to build
	 */
	public function getImplantQLSpecs(bool $jobe, int $ql): ImplantSpecs {
		return new ImplantSpecs(
			ql: $ql,
			requirements: new ImplantRequirements(
				treatment: Implant::getRequirement(ImplantRequirement::Treatment, $jobe, $ql),
				abilities: Implant::getRequirement(ImplantRequirement::Ability, $jobe, $ql),
				titleLevel: Implant::getRequirement(ImplantRequirement::TitleLevel, $jobe, $ql),
			),
			skills: new ImplantBonusTypes(
				faded: $this->getBonusStatsForType(ImplantBuff::Skill, ClusterGrade::Faded, $ql),
				bright: $this->getBonusStatsForType(ImplantBuff::Skill, ClusterGrade::Bright, $ql),
				shiny: $this->getBonusStatsForType(ImplantBuff::Skill, ClusterGrade::Shiny, $ql),
			),
			abilities: new ImplantBonusTypes(
				faded: $this->getBonusStatsForType(ImplantBuff::Ability, ClusterGrade::Faded, $ql),
				bright: $this->getBonusStatsForType(ImplantBuff::Ability, ClusterGrade::Bright, $ql),
				shiny: $this->getBonusStatsForType(ImplantBuff::Ability, ClusterGrade::Shiny, $ql),
			)
		);
	}

	/**
	 * Render a single bonus stat for a cluster type
	 * Roughly looks like this:
	 * 42 (QL 147 - QL 150) Shiny -> 306 / 720
	 *
	 * @param ImplantBonusStats $stats The stats to render
	 *
	 * @return string the rendered line including newline
	 */
	protected function renderBonusLine(ImplantBonusStats $stats, bool $jobe): string {
		$fromQL = Text::alignNumber($stats->range->min, 3, 'highlight');
		$toQL   = Text::alignNumber($stats->range->max, 3, 'highlight');

		$line = Text::alignNumber($stats->buff, 3, 'highlight').
			" (QL {$fromQL} - QL {$toQL}) {$stats->slot->name}";
		if ($stats->range->max < 300) {
			$nextBest = $this->getImplantQLSpecs($jobe, $stats->range->max+1);
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
	 * @param ImplantBuff  $type  Type of bonus (Skill or Ability)
	 * @param ClusterGrade $grade Shiny, Bright or Faded
	 * @param int          $ql    The QL of the implant
	 */
	protected function getBonusStatsForType(ImplantBuff $type, ClusterGrade $grade, int $ql): ImplantBonusStats {
		$buff = Implant::getBuff($type, $grade, $ql);
		$range = $this->getBonusQLRange($type, $grade, $buff);
		if (!isset($range)) {
			throw new Exception("Cannot calculate QL for giving +{$buff}");
		}
		return new ImplantBonusStats(
			slot: $grade,
			range: $range,
			buff: $buff,
		);
	}
}
