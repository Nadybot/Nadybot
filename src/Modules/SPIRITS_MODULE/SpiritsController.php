<?php declare(strict_types=1);

namespace Nadybot\Modules\SPIRITS_MODULE;

use Nadybot\Core\{
	CmdContext,
	DB,
	Text,
};
use Nadybot\Core\ParamClass\PNonNumber;
use Nadybot\Core\ParamClass\PNumRange;
use Nadybot\Modules\IMPLANT_MODULE\PImplantSlot;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

/**
 * @author Tyrence (RK2)
 *
 * Originally Written for Budabot By Jaqueme
 * Database Adapted From One Originally Compiled by Wolfbiter For BeBot
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'spirits',
 *		accessLevel = 'all',
 *		description = 'Search for spirits',
 *		help        = 'spirits.txt'
 *	)
 */
class SpiritsController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/spiritsdb.csv');
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsSlotAndRangeCommand(CmdContext $context, PImplantSlot $slot, PNumRange $qlRange): void {
		$this->spiritsRangeAndSlotCommand($context, $qlRange, $slot);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsRangeAndSlotCommand(CmdContext $context, PNumRange $qlRange, PImplantSlot $slot): void {
		$lowQL = $qlRange->low;
		$highQL = $qlRange->high;
		$slot = ucfirst($slot());
		$title = "$slot Spirits QL $lowQL to $highQL";
		if ($lowQL < 1 or $highQL > 300 or $lowQL >= $highQL) {
			$msg = "Invalid Ql range specified.";
			$context->reply($msg);
			return;
		}
		/** @var Spirit[] */
		$data = $this->db->table("spiritsdb")
			->where("spot", $slot)
			->where("ql", ">=", $lowQL)
			->where("ql", "<=", $highQL)
			->orderBy("ql")
			->asObj(Spirit::class)
			->toArray();
		if (empty($data)) {
			$context->reply("No {$slot} spirits found in ql {$lowQL} to {$highQL}.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = $this->text->makeBlob("Spirits", $spirits, $title);
		$context->reply($spirits);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsCommandTypeAndSlot(CmdContext $context, PNonNumber $name, PImplantSlot $slot): void {
		$this->spiritsCommandSlotAndType($context, $slot, $name);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsCommandSlotAndType(CmdContext $context, PImplantSlot $slot, PNonNumber $name): void {
		$name = ucwords(strtolower($name()));
		$slot = ucfirst($slot());
		$title = "Spirits Database for $name $slot";
		/** @var Spirit[] */
		$data = $this->db->table("spiritsdb")
			->whereIlike("name", "%{$name}%")
			->where("spot", $slot)
			->orderBy("level")
			->asObj(Spirit::class)
			->toArray();
		if (empty($data)) {
			$context->reply("No {$slot} implants found matching '<highlight>{$name}<end>'.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = $this->text->makeBlob("Spirits (" . count($data) . ")", $spirits, $title);
		$context->reply($spirits);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsQLCommand(CmdContext $context, int $ql): void {
		if ($ql < 1 or $ql > 300) {
			$msg = "Invalid QL specified.";
			$context->reply($msg);
			return;
		}
		$title = "Spirits QL $ql";
		/** @var Spirit[] */
		$data = $this->db->table("spiritsdb")
			->where("ql", $ql)
			->asObj(Spirit::class)
			->toArray();
		if (empty($data)) {
			$context->reply("No spirits found in ql {$ql}.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = $this->text->makeBlob("Spirits (" . count($data) . ")", $spirits, $title);
		$context->reply($spirits);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsCommandQLRange(CmdContext $context, PNumRange $qlRange): void {
		$spirits = "";
		$lowQL = $qlRange->low;
		$highQL = $qlRange->high;
		if ($lowQL < 1 or $highQL > 300 or $lowQL >= $highQL) {
			$msg = "Invalid Ql range specified.";
			$context->reply($msg);
			return;
		}
		$title = "Spirits QL $lowQL to $highQL";
		/** @var Spirit[] */
		$data = $this->db->table("spiritsdb")
			->where("ql", ">=", $lowQL)
			->where("ql", "<=", $highQL)
			->orderBy("ql")
			->asObj(Spirit::class)
			->toArray();
		if (empty($data)) {
			$context->reply("No spirits found in ql {$lowQL} to {$highQL}.");
			return;
		}
		$spirits .= $this->formatSpiritOutput($data);
		$spirits = $this->text->makeBlob("Spirits (" . count($data) . ")", $spirits, $title);
		$context->reply($spirits);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsTypeAndQlCommand(CmdContext $context, PImplantSlot $slot, int $ql): void {
		$this->spiritsQlAndTypeCommand($context, $ql, $slot);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsQlAndTypeCommand(CmdContext $context, int $ql, PImplantSlot $slot): void {
		$slot = ucfirst($slot());
		$title = "$slot Spirits QL $ql";
		if ($ql < 1 or $ql > 300) {
			$msg = "Invalid Ql specified.";
			$context->reply($msg);
			return;
		}
		/** @var Spirit[] */
		$data = $this->db->table("spiritsdb")
			->where("spot", $slot)
			->where("ql", $ql)
			->asObj(Spirit::class)
			->toArray();
		if (empty($data)) {
			$context->reply("No {$slot} spirits found in ql {$ql}.");
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = $this->text->makeBlob("Spirits (" . count($data) . ")", $spirits, $title);
		$context->reply($spirits);
	}

	/**
	 * @HandlesCommand("spirits")
	 */
	public function spiritsCommandSearch(CmdContext $context, PNonNumber $search): void {
		$name = ucwords(strtolower($search()));
		$title = "Spirits Database for $name";
		/** @var Spirit[] */
		$data = $this->db->table("spiritsdb")
			->whereIlike("name", "%{$name}%")
			->orWhereIlike("spot", "%{$name}%")
			->orderBy("level")
			->asObj(Spirit::class)
			->toArray();
		if (count($data) === 0) {
			$msg = "There were no matches found for <highlight>$name<end>. ".
				"Try putting a comma between search values. ".
				$this->getValidSlotTypes();
			$context->reply($msg);
			return;
		}
		$spirits = $this->formatSpiritOutput($data);
		$spirits = $this->text->makeBlob("Spirits (" . count($data) . ")", $spirits, $title);
		$context->reply($spirits);
	}

	/**
	 * @param Spirit[] $spirits
	 * @return string
	 */
	public function formatSpiritOutput(array $spirits): string {
		if (count($spirits) === 0) {
			return "No matches found.";
		}

		$msg = '';
		foreach ($spirits as $spirit) {
			/** @var ?AODBEntry */
			$dbSpirit = $this->db->table("aodb")
				->where("lowid", $spirit->id)
				->union(
					$this->db->table("aodb")
						->where("highid", $spirit->id)
				)->limit(1)
				->asObj(AODBEntry::class)
				->first();
			if ($dbSpirit) {
				$msg .= $this->text->makeImage($dbSpirit->icon) . ' ';
				$msg .= $this->text->makeItem($dbSpirit->lowid, $dbSpirit->highid, $dbSpirit->highql, $dbSpirit->name) . "\n";
				$msg .= "Minimum Level=$spirit->level   Slot=$spirit->spot   Agility/Sense Needed=$spirit->agility\n\n";
			}
		}
		return $msg;
	}

	public function getValidSlotTypes(): string {
		$output = "Valid slots for spirits are: Head, Eye, Ear, Chest, Larm, ".
			"Rarm, Waist, Lwrist, Rwrist, Legs, Lhand, Rhand and Feet";

		return $output;
	}
}
