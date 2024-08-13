<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};

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
		#[NCA\StrChoice('treatment', 'ability')] string $type,
		int $startingValue
	): void {
		$type = strtolower($type);

		if ($type === 'treat') {
			$type = 'treatment';
		}

		// allow treatment, ability, or any of the 6 abilities
		if ($type !== 'treatment' && $type !== 'ability') {
			$type = Util::getAbility($type, true);
			if ($type === null) {
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
		$added = true;

		// will continue to loop as long as at least one implant is added each loop
		while ($added) {
			$added = false;

			// add shiny
			// @phpstan-ignore-next-line
			$tempValue = $shiny === null ? $value : $value - $shiny->{$prefix . 'Shiny'};

			/** @var LadderRequirements */
			$newShiny = $getMax($tempValue);
			if ($shiny === null || $newShiny->{$prefix . 'Shiny'} > $shiny->{$prefix . 'Shiny'}) {
				$added = true;
				if ($shiny !== null) {
					$value -= $shiny->{$prefix . 'Shiny'};
					$blob .= "Remove shiny QL {$shiny->ql}\n\n";
				}
				$shiny = $newShiny;
				$value += $shiny->{$prefix . 'Shiny'};
				$lowest = $shiny->{'lowest' . ucfirst($prefix) . 'Shiny'};
				$blob .= "<highlight>Add shiny QL {$shiny->ql}<end> ({$lowest}) - Treatment: {$shiny->treatment}, Ability: {$shiny->ability}\n\n";
			}

			// add bright
			// @phpstan-ignore-next-line
			$tempValue = $bright === null ? $value : $value - $bright->{$prefix . 'Bright'};
			$newBright = $getMax($tempValue);
			if ($bright === null || $newBright->{$prefix . 'Bright'} > $bright->{$prefix . 'Bright'}) {
				$added = true;
				if ($bright !== null) {
					$value -= $bright->{$prefix . 'Bright'};
					$blob .= "Remove bright QL {$bright->ql}\n\n";
				}
				if (isset($newBright)) {
					$bright = $newBright;
					$value += $bright->{$prefix . 'Bright'};
					$lowest = $bright->{'lowest' . ucfirst($prefix) . 'Bright'};
					$blob .= "<highlight>Add bright QL {$bright->ql}<end> ({$lowest}) - Treatment: {$bright->treatment}, Ability: {$bright->ability}\n\n";
				}
			}

			// add faded
			// @phpstan-ignore-next-line
			$tempValue = $faded === null ? $value : $value - $faded->{$prefix . 'Faded'};
			$newFaded = $getMax($tempValue);
			if ($faded === null || $newFaded->{$prefix . 'Faded'} > $faded->{$prefix . 'Faded'}) {
				$added = true;
				if ($faded !== null) {
					$value -= $faded->{$prefix . 'Faded'};
					$blob .= "Remove faded QL {$faded->ql}\n\n";
				}
				if (isset($newFaded)) {
					$faded = $newFaded;
					$value += $faded->{$prefix . 'Faded'};
					$lowest = $faded->{'lowest' . ucfirst($prefix) . 'Faded'};
					$blob .= "<highlight>Add faded QL {$faded->ql}<end> ({$lowest}) - Treatment: {$faded->treatment}, Ability: {$faded->ability}\n\n";
				}
			}
		}

		$blob .= "-------------------\n\nEnding {$type}: {$value}";
		$blob .= "\n\n<highlight>Inspired by a command written by Lucier of the same name<end>";
		$msg = $this->text->makeBlob("Laddering from {$startingValue} to {$value} " . ucfirst($type), $blob);

		$context->reply($msg);
	}

	public function findMaxImplantQlByReqs(int $ability, int $treatment): ?LadderRequirements {
		/** @var ?LadderRequirements */
		$row = $this->db->table(LadderRequirements::getTable())
			->where('ability', '<=', $ability)
			->where('treatment', '<=', $treatment)
			->orderByDesc('ql')
			->limit(1)
			->asObj(LadderRequirements::class)->first();

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
