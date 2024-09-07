<?php declare(strict_types=1);

namespace Nadybot\Modules\SPIRITS_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PNonNumber,
	ParamClass\PNumRange,
	Text,
};
use Nadybot\Modules\{
	IMPLANT_MODULE\PImplantSlot,
	ITEMS_MODULE\AODBEntry,
};

/**
 * @author Tyrence (RK2)
 * Originally Written for Budabot By Jaqueme
 * Database Adapted From One Originally Compiled by Wolfbiter For BeBot
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'spirits',
		accessLevel: 'guest',
		description: 'Search for spirits',
	)
]
class SpiritsController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/spiritsdb.csv');
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits head 60-70')]
	public function spiritsSlotAndRangeCommand(CmdContext $context, PImplantSlot $slot, PNumRange $qlRange): void {
		$this->spiritsRangeAndSlotCommand($context, $qlRange, $slot);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits 60-70 feet')]
	public function spiritsRangeAndSlotCommand(CmdContext $context, PNumRange $qlRange, PImplantSlot $slot): void {
		$lowQL = $qlRange->low;
		$highQL = $qlRange->high;
		$slot = $slot();
		$title = "{$slot->longName()} Spirits QL {$lowQL} to {$highQL}";
		if ($lowQL < 1 or $highQL > 300 or $lowQL >= $highQL) {
			$msg = 'Invalid Ql range specified.';
			$context->reply($msg);
			return;
		}

		$data = $this->db->table(Spirit::getTable())
			->where('spot', ucfirst($slot->designSlotName()))
			->where('ql', '>=', $lowQL)
			->where('ql', '<=', $highQL)
			->orderBy('ql')
			->asObj(Spirit::class);
		if ($data->isEmpty()) {
			$context->reply("No {$slot->longName()} spirits found in ql {$lowQL} to {$highQL}.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = Text::makeBlob('Spirits', $spirits, $title);
		$context->reply($spirits);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits grave feet')]
	public function spiritsCommandTypeAndSlot(CmdContext $context, PNonNumber $name, PImplantSlot $slot): void {
		$this->spiritsCommandSlotAndType($context, $slot, $name);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits feet grave')]
	public function spiritsCommandSlotAndType(CmdContext $context, PImplantSlot $slot, PNonNumber $name): void {
		$name = ucwords(strtolower($name()));
		$slot = $slot();
		$title = "Spirits Database for {$name} {$slot->longName()}";

		$data = $this->db->table(Spirit::getTable())
			->whereIlike('name', "%{$name}%")
			->where('spot', ucfirst($slot->designSlotName()))
			->orderBy('level')
			->asObj(Spirit::class);
		if ($data->isEmpty()) {
			$context->reply("No {$slot->longName()} implants found matching '<highlight>{$name}<end>'.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = Text::makeBlob("Spirits ({$data->count()})", $spirits, $title);
		$context->reply($spirits);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits 220')]
	public function spiritsQLCommand(CmdContext $context, int $ql): void {
		if ($ql < 1 or $ql > 300) {
			$msg = 'Invalid QL specified.';
			$context->reply($msg);
			return;
		}
		$title = "Spirits QL {$ql}";

		$data = $this->db->table(Spirit::getTable())
			->where('ql', $ql)
			->asObj(Spirit::class);
		if ($data->isEmpty()) {
			$context->reply("No spirits found in ql {$ql}.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = Text::makeBlob("Spirits ({$data->count()})", $spirits, $title);
		$context->reply($spirits);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits 210-230')]
	public function spiritsCommandQLRange(CmdContext $context, PNumRange $qlRange): void {
		$spirits = '';
		$lowQL = $qlRange->low;
		$highQL = $qlRange->high;
		if ($lowQL < 1 or $highQL > 300 or $lowQL >= $highQL) {
			$msg = 'Invalid Ql range specified.';
			$context->reply($msg);
			return;
		}
		$title = "Spirits QL {$lowQL} to {$highQL}";

		$data = $this->db->table(Spirit::getTable())
			->where('ql', '>=', $lowQL)
			->where('ql', '<=', $highQL)
			->orderBy('ql')
			->asObj(Spirit::class);
		if ($data->isEmpty()) {
			$context->reply("No spirits found in ql {$lowQL} to {$highQL}.");
			return;
		}
		$spirits .= $this->formatSpiritOutput($data);
		$spirits = Text::makeBlob("Spirits ({$data->count()})", $spirits, $title);
		$context->reply($spirits);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits chest 210')]
	public function spiritsTypeAndQlCommand(CmdContext $context, PImplantSlot $slot, int $ql): void {
		$this->spiritsQlAndTypeCommand($context, $ql, $slot);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits 210 chest')]
	public function spiritsQlAndTypeCommand(CmdContext $context, int $ql, PImplantSlot $slot): void {
		$slot = $slot();
		$title = "{$slot->longName()} Spirits QL {$ql}";
		if ($ql < 1 or $ql > 300) {
			$msg = 'Invalid Ql specified.';
			$context->reply($msg);
			return;
		}

		$data = $this->db->table(Spirit::getTable())
			->where('spot', ucfirst($slot->designSlotName()))
			->where('ql', $ql)
			->asObj(Spirit::class);
		if ($data->isEmpty()) {
			$context->reply("No {$slot->longName()} spirits found in ql {$ql}.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = Text::makeBlob("Spirits ({$data->count()})", $spirits, $title);
		$context->reply($spirits);
	}

	/** Search for spirits by a variety of attributes */
	#[NCA\HandlesCommand('spirits')]
	#[NCA\Help\Example('<symbol>spirits beta')]
	public function spiritsCommandSearch(CmdContext $context, PNonNumber $search): void {
		$name = ucwords(strtolower($search()));
		$title = "Spirits Database for {$name}";
		if (PImplantSlot::matches($name)) {
			$name = (new PImplantSlot($name))()->designSlotName();
		}

		$data = $this->db->table(Spirit::getTable())
			->whereIlike('name', "%{$name}%")
			->orWhereIlike('spot', "%{$name}%")
			->orderBy('level')
			->asObj(Spirit::class);
		if ($data->isEmpty()) {
			$msg = "There were no matches found for <highlight>{$name}<end>. ".
				'Try putting a comma between search values. '.
				$this->getValidSlotTypes();
			$context->reply($msg);
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = Text::makeBlob("Spirits ({$data->count()})", $spirits, $title);
		$context->reply($spirits);
	}

	/** @param iterable<Spirit> $spirits */
	public function formatSpiritOutput(iterable $spirits): string {
		$msg = '';
		foreach ($spirits as $spirit) {
			$dbSpirit = $this->db->table(AODBEntry::getTable())
				->where('lowid', $spirit->id)
				->union(
					$this->db->table(AODBEntry::getTable())
						->where('highid', $spirit->id)
				)->firstObj(AODBEntry::class);
			if ($dbSpirit) {
				$msg .= Text::makeImage($dbSpirit->icon) . ' ';
				$msg .= $dbSpirit->getLink(ql: $dbSpirit->highql) . "\n";
				$msg .= "Minimum Level={$spirit->level}   Slot={$spirit->spot->longName()}   Agility/Sense Needed={$spirit->agility}\n\n";
			}
		}
		if ($msg === '') {
			return 'No matches found.';
		}
		return $msg;
	}

	public function getValidSlotTypes(): string {
		$output = 'Valid slots for spirits are: Head, Eye, Ear, Chest, Larm, '.
			'Rarm, Waist, Lwrist, Rwrist, Legs, Lhand, Rhand and Feet';

		return $output;
	}

	/** Check if a given aoid is a spirit */
	public function isSpirit(int $aoid): bool {
		return $this->db->table(Spirit::getTable())
			->where('id', $aoid)
			->exists();
	}
}
