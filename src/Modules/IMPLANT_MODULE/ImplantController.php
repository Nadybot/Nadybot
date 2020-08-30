<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Exception;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 * @author Imoutochan (RK1)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'implant',
 *		accessLevel = 'all',
 *		description = 'Shows info about implants given a QL or stats',
 *		help        = 'implant.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'ladder',
 *		accessLevel = 'all',
 *		description = 'Show sequence of laddering implants for maximum ability or treatment',
 *		help        = 'ladder.txt'
 *	)
 */
class ImplantController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "implant_requirements");
		$this->db->loadSQLFile($this->moduleName, "premade_implant");
	}
	
	/**
	 * @HandlesCommand("implant")
	 * @Matches("/^implant (\d+)$/i")
	 */
	public function implantQlCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$ql = (int)$args[1];

		if (($ql < 1) || ($ql > 300)) {
			$msg = "You must enter a value between 1 and 300.";
			$sendto->reply($msg);
			return;
		}
		$req = $this->getRequirements($ql);
		$clusterInfo = $this->formatClusterBonuses($req);
		$link = $this->text->makeBlob("QL$req->ql", $clusterInfo, "Implant Info (QL $req->ql)");
		$msg = "QL $ql implants--Ability: {$req->ability}, Treatment: {$req->treatment} $link";

		$msg = "$link: <highlight>$req->ability<end> Ability, <highlight>$req->treatment<end> Treatment";

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("implant")
	 * @Matches("/^implant (\d+) (\d+)$/i")
	 */
	public function implantRequirementsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$ability = (int)$args[1];
		$treatment = (int)$args[2];

		if ($treatment < 11 || $ability < 6) {
			$msg = "You do not have enough treatment or ability to wear an implant.";
			$sendto->reply($msg);
			return;
		}
		$reqs = $this->findMaxImplantQlByReqs($ability, $treatment);
		$clusterInfo = $this->formatClusterBonuses($reqs);
		$link = $this->text->makeBlob("QL$reqs->ql", $clusterInfo, "Implant Info (QL $reqs->ql)");

		$msg = "$link: <highlight>$reqs->ability<end> Ability, <highlight>$reqs->treatment<end> Treatment";
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("ladder")
	 * @Matches("/^ladder (.+) (\d+)$/i")
	 */
	public function ladderCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = strtolower($args[1]);
		$startingValue = (int)$args[2];
		
		if ($type === 'treat') {
			$type = 'treatment';
		}
		
		// allow treatment, ability, or any of the 6 abilities
		if ($type !== 'treatment' && $type !== 'ability') {
			$type = $this->util->getAbility($type, true);
			if ($type === null) {
				return;
			}
			$type = strtolower($type);
		}

		$value = $startingValue;
		$prefix = $type == 'treatment' ? 'skill' : 'ability';
		
		$blob = "Starting $type: $value\n\n-------------------\n\n";
		
		if ($type == 'treatment') {
			if ($value < 11) {
				$sendto->reply("Base treatment must be at least <highlight>11<end>.");
				return;
			}
		
			$getMax = function(int $value): ImplantRequirements {
				return $this->findMaxImplantQlByReqs(10000, $value);
			};
		} else {
			if ($value < 6) {
				$sendto->reply("Base ability must be at least <highlight>6<end>.");
				return;
			}
		
			$getMax = function(int $value): ImplantRequirements {
				return $this->findMaxImplantQlByReqs($value, 10000);
			};
		}

		$shiny = null;
		$bright = null;
		$faded = null;
		$added = true;

		// will continue to loop as long as at least one implant is added each loop
		while ($added) {
			$added = false;
			
			// add shiny
			$tempValue = $shiny === null ? $value : $value - $shiny->{$prefix . 'Shiny'};
			/** @var ImplantRequirements */
			$newShiny = $getMax($tempValue);
			if ($shiny === null || $newShiny->{$prefix . 'Shiny'} > $shiny->{$prefix . 'Shiny'}) {
				$added = true;
				if ($shiny !== null) {
					$value -= $shiny->{$prefix . 'Shiny'};
					$blob .= "Remove shiny QL $shiny->ql\n\n";
				}
				$shiny = $newShiny;
				$value += $shiny->{$prefix . 'Shiny'};
				$lowest = $shiny->{'lowest' . ucfirst($prefix) . 'Shiny'};
				$blob .= "<highlight>Add shiny QL $shiny->ql<end> ($lowest) - Treatment: {$shiny->treatment}, Ability: {$shiny->ability}\n\n";
			}
			
			// add bright
			$tempValue = $bright === null ? $value : $value - $bright->{$prefix . 'Bright'};
			$newBright = $getMax($tempValue);
			if ($bright === null || $newBright->{$prefix . 'Bright'} > $bright->{$prefix . 'Bright'}) {
				$added = true;
				if ($bright !== null) {
					$value -= $bright->{$prefix . 'Bright'};
					$blob .= "Remove bright QL $bright->ql\n\n";
				}
				$bright = $newBright;
				$value += $bright->{$prefix . 'Bright'};
				$lowest = $bright->{'lowest' . ucfirst($prefix) . 'Bright'};
				$blob .= "<highlight>Add bright QL $bright->ql<end> ($lowest) - Treatment: {$bright->treatment}, Ability: {$bright->ability}\n\n";
			}
			
			// add faded
			$tempValue = $faded === null ? $value : $value - $faded->{$prefix . 'Faded'};
			$newFaded = $getMax($tempValue);
			if ($faded === null || $newFaded->{$prefix . 'Faded'} > $faded->{$prefix . 'Faded'}) {
				$added = true;
				if ($faded !== null) {
					$value -= $faded->{$prefix . 'Faded'};
					$blob .= "Remove faded QL $faded->ql\n\n";
				}
				$faded = $newFaded;
				$value += $faded->{$prefix . 'Faded'};
				$lowest = $faded->{'lowest' . ucfirst($prefix) . 'Faded'};
				$blob .= "<highlight>Add faded QL $faded->ql<end> ($lowest) - Treatment: {$faded->treatment}, Ability: {$faded->ability}\n\n";
			}
		}
		
		$blob .= "-------------------\n\nEnding $type: $value";
		$blob .= "\n\n<highlight>Inspired by a command written by Lucier of the same name<end>";
		$msg = $this->text->makeBlob("Laddering from $startingValue to $value " . ucfirst(strtolower($type)), $blob);
		
		$sendto->reply($msg);
	}

	// implant functions
	public function getRequirements(int $ql): ImplantRequirements {
		$sql = "SELECT * FROM implant_requirements WHERE ql = ?";

		/** @var ?ImplantRequirements */
		$row = $this->db->fetch(ImplantRequirements::class, $sql, $ql);

		$this->addClusterInfo($row);

		return $row;
	}

	public function findMaxImplantQlByReqs(int $ability, int $treatment): ?ImplantRequirements {
		$sql = "SELECT * FROM implant_requirements WHERE ability <= ? AND treatment <= ? ORDER BY ql DESC LIMIT 1";

		/** @var ?ImplantRequirements */
		$row = $this->db->fetch(ImplantRequirements::class, $sql, $ability, $treatment);

		$this->addClusterInfo($row);

		return $row;
	}

	public function formatClusterBonuses(ImplantRequirements $obj): string {
		$msg = "You will gain for most skills:\n" .
			"<tab>Shiny    <highlight>$obj->skillShiny<end> ($obj->lowestSkillShiny - $obj->highestSkillShiny)\n" .
			"<tab>Bright    <highlight>$obj->skillBright<end> ($obj->lowestSkillBright - $obj->highestSkillBright)\n" .
			"<tab>Faded   <highlight>$obj->skillFaded<end> ($obj->lowestSkillFaded - $obj->highestSkillFaded)\n" .
			"-----------------------\n" .
			"<tab>Total   $obj->skillTotal\n";

		$msg .= "\n\n";

		$msg .= "You will gain for abilities:\n" .
			"<tab>Shiny    <highlight>$obj->abilityShiny<end> ($obj->lowestAbilityShiny - $obj->highestAbilityShiny)\n" .
			"<tab>Bright    <highlight>$obj->abilityBright<end> ($obj->lowestAbilityBright - $obj->highestAbilityBright)\n" .
			"<tab>Faded   <highlight>$obj->abilityFaded<end> ($obj->lowestAbilityFaded - $obj->highestAbilityFaded)\n" .
			"-----------------------\n" .
			"<tab>Total   $obj->abilityTotal\n";


		if ($obj->ql > 250) {
			$msg .= "\n\nRequires Title Level 6";
		} elseif ($obj->ql > 200) {
			$msg .= "\n\nRequires Title Level 5";
		}

		$msg .= "\n\nMinimum QL for clusters:\n" .
			"<tab>Shiny: $obj->minShinyClusterQl\n" .
			"<tab>Bright: $obj->minBrightClusterQl\n" .
			"<tab>Faded: $obj->minFadedClusterQl\n";

		$msg .= "\n\nWritten by Tyrence (RK2)";

		return $msg;
	}

	public function addClusterInfo(?ImplantRequirements $obj): void {
		if ($obj === null) {
			return;
		}

		$this->setHighestAndLowestQls($obj, 'abilityShiny');
		$this->setHighestAndLowestQls($obj, 'abilityBright');
		$this->setHighestAndLowestQls($obj, 'abilityFaded');
		$this->setHighestAndLowestQls($obj, 'skillShiny');
		$this->setHighestAndLowestQls($obj, 'skillBright');
		$this->setHighestAndLowestQls($obj, 'skillFaded');

		$obj->abilityTotal = $obj->abilityShiny + $obj->abilityBright + $obj->abilityFaded;
		$obj->skillTotal = $obj->skillShiny + $obj->skillBright + $obj->skillFaded;

		$obj->minShinyClusterQl = $this->getClusterMinQl($obj->ql, 'shiny');
		$obj->minBrightClusterQl = $this->getClusterMinQl($obj->ql, 'bright');
		$obj->minFadedClusterQl = $this->getClusterMinQl($obj->ql, 'faded');

		// if implant QL is 201+, then clusters must be refined and must be QL 201+ also
		if ($obj->ql >= 201) {
			if ($obj->minShinyClusterQl < 201) {
				$obj->minShinyClusterQl = 201;
			}
			if ($obj->minBrightClusterQl < 201) {
				$obj->minBrightClusterQl = 201;
			}
			if ($obj->minFadedClusterQl < 201) {
				$obj->minFadedClusterQl = 201;
			}
		}
	}
	
	public function getClusterMinQl(int $ql, string $grade): int {
		if ($grade == 'shiny') {
			return (int)floor($ql * 0.86);
		} elseif ($grade == 'bright') {
			return (int)floor($ql * 0.84);
		} elseif ($grade == 'faded') {
			return (int)floor($ql * 0.82);
		} else {
			throw new Exception("Invalid grade: '$grade'.  Must be one of: 'shiny', 'bright', 'faded'");
		}
	}

	public function setHighestAndLowestQls(ImplantRequirements $obj, string $var): void {
		$varValue = $obj->$var;

		$sql = "SELECT MAX(ql) as max, MIN(ql) as min FROM implant_requirements WHERE $var = ?";
		$row = $this->db->queryRow($sql, $varValue);

		// camel case var name
		$tempNameVar = ucfirst($var);
		$tempHighestName = "highest$tempNameVar";
		$tempLowestName = "lowest$tempNameVar";

		$obj->$tempLowestName = $row->min;
		$obj->$tempHighestName = $row->max;
	}
}
