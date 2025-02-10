<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Exceptions\UserException;
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
		#[NCA\Regexp('\w+', '&lt;treatment|ability&gt;')] string $typeName,
		int $startingValue
	): void {
		try {
			$type = LadderType::fromName($typeName);
		} catch (ValueError) {
			$context->reply("<highlight>{$typeName}<end> is no valid ability.");
			return;
		}

		$value = $startingValue;

		$blob = "Starting {$type->name}: {$value}\n\n-------------------\n\n";

		if ($type === LadderType::Skill) {
			if ($value < 11) {
				$context->reply('Base treatment must be at least <highlight>11<end>.');
				return;
			}

			$getMax = function (int $value): LadderRequirements {
				$reqs = $this->findMaxImplantQlByReqs(10_000, $value);
				if (!isset($reqs)) {
					throw new UserException('Your pathetic skills are too low to work with implants.');
				}
				return $reqs;
			};
		} else {
			if ($value < 6) {
				$context->reply('Base ability must be at least <highlight>6<end>.');
				return;
			}

			$getMax = function (int $value): LadderRequirements {
				$reqs = $this->findMaxImplantQlByReqs($value, 10_000);
				if (!isset($reqs)) {
					throw new UserException('Your pathetic skills are too low to work with implants.');
				}
				return $reqs;
			};
		}

		/** @var array<int,LadderRequirements> */
		$currentClusters = [];
		$added = true;

		// will continue to loop as long as at least one implant is added each loop
		while ($added) {
			$added = false;

			foreach (ClusterGrade::cases() as $grade) {
				$current = $currentClusters[$grade->getId()] ?? null;
				$tempValue = ($current instanceof LadderRequirements) ? $value - $current->get($grade, $type) : $value;
				$new = $getMax($tempValue);
				if ($current === null || $new->get($grade, $type) > $current->get($grade, $type)) {
					$added = true;
					if ($current !== null) {
						$value -= $current->get($grade, $type);
						$blob .= "Remove {$grade->value} QL {$current->ql}\n\n";
					}
					$current = $new;
					$value += $current->get($grade, $type);
					$lowest = $current->getLowest($grade, $type);
					$blob .= "<highlight>Add {$grade->value} QL {$current->ql}<end> ({$lowest}) - Treatment: {$current->treatment}, Ability: {$current->ability}\n\n";
					$currentClusters[$grade->getId()] = $current;
				}
			}
		}

		$blob .= "-------------------\n\nEnding {$type->name}: {$value}";
		$blob .= "\n\n<highlight>Inspired by a command written by Lucier of the same name<end>";
		$msg = Text::makeBlob("Laddering from {$startingValue} to {$value} {$type->name}", $blob);

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

		foreach (ClusterGrade::cases() as $grade) {
			foreach (LadderType::cases() as $type) {
				$this->setLowestQls($obj, $grade, $type);
			}
		}
	}

	public function setLowestQls(LadderRequirements $obj, ClusterGrade $grade, LadderType $type): void {
		$varValue = $obj->get($grade, $type);

		$min = $this->db->table(LadderRequirements::getTable())
			->where($type->value . $grade->name, $varValue)->min('ql');

		$obj->setLowest($grade, $type, (int)$min);
	}
}
