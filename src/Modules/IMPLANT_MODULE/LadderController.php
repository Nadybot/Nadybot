<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use function Amp\delay;
use Nadybot\Core\Types\Ability;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
};

use ValueError;

/**
 * @author Tyrence (RK2)
 * @author Imoutochan (RK1)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Base'),
	NCA\DefineCommand(
		command: 'ladder',
		accessLevel: 'guest',
		description: 'Show sequence of laddering implants for maximum ability or treatment',
	)
]
class LadderController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/implant_requirements.csv');
	}

	/** Show sequence of laddering implants for an ability or treatment */
	#[NCA\HandlesCommand('ladder')]
	#[NCA\Help\Epilogue(
		'The base amount should be the treatment or ability you have with all nano buffs, '.
		'perks, and items-buffing equipment equipped, but minus any implants you have '.
		'equipped.'
	)]
	public function ladderCommand(
		CmdContext $context,
		#[NCA\Regexp('\w+', '&lt;treatment|ability&gt;')] string $type,
		int $startingValue
	): void {
		$type = strtolower($type);

		if ($type === 'treat') {
			$type = 'treatment';
		}

		// allow treatment, ability, or any of the 6 abilities
		if ($type !== 'treatment' && $type !== 'ability') {
			try {
				$type = Ability::fromShort($type)->name;
			} catch (ValueError) {
				$context->reply("<highlight>{$type}<end> is no valid ability.");
				return;
			}
			$type = strtolower($type);
		}

		$value = $startingValue;
		$prefix = $type === 'treatment' ? 'skill' : 'ability';

		$blob = "Starting {$type}: {$value}\n\n-------------------\n\n";

		if ($type === 'treatment') {
			if ($value < 11) {
				$context->reply('Base treatment must be at least <highlight>11<end>.');
				return;
			}

			$getMax = function (int $value): ?LadderRequirements {
				return $this->findMaxImplantQlByReqs(10_000, $value);
			};
		} else {
			if ($value < 6) {
				$context->reply('Base ability must be at least <highlight>6<end>.');
				return;
			}

			$getMax = function (int $value): ?LadderRequirements {
				return $this->findMaxImplantQlByReqs($value, 10_000);
			};
		}

		$shiny = null;
		$bright = null;
		$faded = null;
		$currentClusters = [];
		$added = true;

		// will continue to loop as long as at least one implant is added each loop
		while ($added) {
			$added = false;

			foreach (ClusterGrade::cases() as $grade) {
				$current = $currentClusters[$grade->getId()] ?? null;
				$tempValue = ($current instanceof LadderRequirements) ? $value - $current->get($grade, $prefix) : $value;
				$new = $getMax($tempValue);
				if ($current === null || $new->get($grade, $prefix) > $current->get($grade, $prefix)) {
					$added = true;
					if ($current !== null) {
						$value -= $current->get($grade, $prefix);
						$blob .= "Remove {$grade->value} QL {$current->ql}\n\n";
						echo("Remove {$grade->value} QL {$current->ql}\n");
					}
					$current = $new;
					$value += $current->get($grade, $prefix);
					$lowest = $current->getLowest($grade, $prefix);
					$blob .= "<highlight>Add {$grade->value} QL {$current->ql}<end> ({$lowest}) - Treatment: {$current->treatment}, Ability: {$current->ability}\n\n";
					echo("<highlight>Add {$grade->value} QL {$current->ql}<end> ({$lowest}) - Treatment: {$current->treatment}, Ability: {$current->ability}\n");
					$currentClusters[$grade->getId()] = $current;
				}
			}
			delay(0.2);
		}
		var_dump($currentClusters);

		$blob .= "-------------------\n\nEnding {$type}: {$value}";
		$blob .= "\n\n<highlight>Inspired by a command written by Lucier of the same name<end>";
		$msg = Text::makeBlob("Laddering from {$startingValue} to {$value} " . ucfirst($type), $blob);

		$context->reply($msg);
	}

	public function findMaxImplantQlByReqs(int $ability, int $treatment): ?LadderRequirements {
		$row = $this->db->table(LadderRequirements::getTable())
			->where('ability', '<=', $ability)
			->where('treatment', '<=', $treatment)
			->orderByDesc('ql')
			->firstObj(LadderRequirements::class);

		$this->addClusterInfo($row);

		return $row;
	}

	public function addClusterInfo(?LadderRequirements $obj): void {
		if ($obj === null) {
			return;
		}

		$this->setHighestAndLowestQls($obj, 'abilityShiny');
		$this->setHighestAndLowestQls($obj, 'abilityBright');
		$this->setHighestAndLowestQls($obj, 'abilityFaded');
		$this->setHighestAndLowestQls($obj, 'skillShiny');
		$this->setHighestAndLowestQls($obj, 'skillBright');
		$this->setHighestAndLowestQls($obj, 'skillFaded');
	}

	public function setHighestAndLowestQls(LadderRequirements $obj, string $var): void {
		$varValue = $obj->{$var};

		$min = $this->db->table(LadderRequirements::getTable())
			->where($var, $varValue)->min('ql');
		$max = $this->db->table(LadderRequirements::getTable())
			->where($var, $varValue)->max('ql');
		// camel case var name
		$tempNameVar = ucfirst($var);
		$tempHighestName = "highest{$tempNameVar}";
		$tempLowestName = "lowest{$tempNameVar}";

		$obj->{$tempLowestName} = (int)$min;
		$obj->{$tempHighestName} = (int)$max;
	}
}
