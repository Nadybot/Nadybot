<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\MinMax;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
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
	private ImplantController $impCtr;

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
			$type = ImplantBuff::fromName($typeName);
		} catch (ValueError) {
			$context->reply("<highlight>{$typeName}<end> is no valid ability.");
			return;
		}

		$value = $startingValue;

		$blob = "Starting {$type->name}: {$value}\n\n-------------------\n\n";

		if ($type === ImplantBuff::Skill) {
			if ($value < 11) {
				$context->reply('Base treatment must be at least <highlight>11<end>.');
				return;
			}

			$getMax = function (int $value): int {
				return $this->impCtr->findHighestEquippableImplant(10_000, $value, false);
			};
		} else {
			if ($value < 6) {
				$context->reply('Base ability must be at least <highlight>6<end>.');
				return;
			}

			$getMax = function (int $value): int {
				return $this->impCtr->findHighestEquippableImplant($value, 10_000, false);
			};
		}

		/** @var array<int,int> */
		$currentClusters = [];
		$added = true;

		// will continue to loop as long as at least one implant is added each loop
		while ($added) {
			$added = false;

			foreach (ClusterGrade::cases() as $grade) {
				$current = $currentClusters[$grade->id()] ?? null;
				$tempValue = isset($current) ? $value - Implant::getBuff($type, $grade, $current) : $value;
				$new = $getMax($tempValue);
				$newBuff = Implant::getBuff($type, $grade, $new);
				if ($current === null || $newBuff > Implant::getBuff($type, $grade, $current)) {
					$added = true;
					if ($current !== null) {
						$value -= Implant::getBuff($type, $grade, $current);
						$blob .= "Remove {$grade->value} QL {$current}\n\n";
					}
					$current = $new;
					$value += $newBuff;
					$range = $this->impCtr->getBonusQLRange($type, $grade, $newBuff) ?? new MinMax(min: $current, max: $current);
					$treatmentReq = Implant::getRequirement(ImplantRequirement::Treatment, false, $current);
					$abilityReq = Implant::getRequirement(ImplantRequirement::Ability, false, $current);
					$blob .= "<highlight>Add {$grade->value} QL {$current}<end> ({$range->min}) - Treatment: {$treatmentReq}, Ability: {$abilityReq}\n\n";
					$currentClusters[$grade->id()] = $current;
				}
			}
		}

		$blob .= "-------------------\n\nEnding {$type->name}: {$value}";
		$blob .= "\n\n<highlight>Inspired by a command written by Lucier of the same name<end>";
		$msg = Text::makeBlob("Laddering from {$startingValue} to {$value} {$type->name}", $blob);

		$context->reply($msg);
	}
}
