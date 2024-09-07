<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PWord,
	Text,
	Types\Profession,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Throwable;

/**
 * @author Blackruby (RK2)
 * @author Mdkdoc420 (RK2)
 * @author Wolfbiter (RK1)
 * @author Gatester (RK2)
 * @author Marebone (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Misc'),
	NCA\DefineCommand(
		command: 'leprocs',
		accessLevel: 'guest',
		description: "Shows each profession's LE procs",
		alias: 'leproc'
	),
	NCA\DefineCommand(
		command: 'ofabarmor',
		accessLevel: 'guest',
		description: 'Shows ofab armors available to a given profession and their VP cost',
	),
	NCA\DefineCommand(
		command: 'ofabweapons',
		accessLevel: 'guest',
		description: 'Shows Ofab weapons, their marks, and VP cost',
	),
	NCA\DefineCommand(
		command: 'aigen',
		accessLevel: 'guest',
		description: 'Shows info about Alien City Generals',
	)
]
class AlienMiscController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Setup]
	public function setup(): void {
		// load database tables from .sql-files
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/leprocs.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabarmor.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabarmortype.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabarmorcost.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabweapons.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabweaponscost.csv');
	}

	/** See a list of professions that have LE procs */
	#[NCA\HandlesCommand('leprocs')]
	public function leprocsCommand(CmdContext $context): void {
		$blob = "<header2>Choose a profession<end>\n";
		$blob = $this->db->table(LEProc::getTable())
			->orderBy('profession')
			->select('profession')
			->distinct()
			->pluckStrings('profession')
			->reduce(
				static function (string $blob, string $profession): string {
					$professionLink = Text::makeChatcmd($profession, "/tell <myname> leprocs {$profession}");
					return "{$blob}<tab>{$professionLink}\n";
				},
				$blob
			);

		$msg = Text::makeBlob('LE Procs (Choose profession)', $blob);
		$context->reply($msg);
	}

	/** Shows the LE procs for a specific profession */
	#[NCA\HandlesCommand('leprocs')]
	public function leprocsInfoCommand(CmdContext $context, string $prof): void {
		try {
			$profession = Profession::byName($prof);
		} catch (Throwable) {
			$msg = "<highlight>{$prof}<end> is not a valid profession.";
			$context->reply($msg);
			return;
		}

		$data = $this->db->table(LEProc::getTable())
			->whereIlike('profession', $profession->value)
			->orderBy('proc_type')
			->orderByDesc('research_lvl')
			->asObj(LEProc::class);
		if ($data->count() === 0) {
			$msg = "No procs found for profession <highlight>{$profession->value}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		$type = '';
		foreach ($data as $row) {
			if ($type !== $row->proc_type) {
				$type = $row->proc_type;
				$blob .= "\n<img src=rdb://" . ($type === 1 ? 84_789 : 84_310) . "><header2>Type {$type}<end>\n";
			}

			$procTrigger = "<green>{$row->proc_trigger}<end>";
			$blob .= '<tab>'.
				Text::alignNumber($row->research_lvl, 2).
				" - {$row->name} <orange>{$row->modifiers}<end> {$row->duration} {$procTrigger}\n";
		}
		$blob .= "\n".
			"\n<i>Offensive procs have a 5% chance of firing every time you attack</i>".
			"\n<i>Defensive procs have a 10% chance of firing every time something attacks you.</i>";

		$msg = Text::makeBlob("{$profession->value} LE Procs", $blob);
		$context->reply($msg);
	}

	/** Show a list of professions and their LE bio types */
	#[NCA\HandlesCommand('ofabarmor')]
	#[NCA\Help\Epilogue(
		"Valid QLs are:\n".
		'<tab>1, 25, 50, 75, 100, 125, 150, 175, 200, 225, 250, 275, and 300.'
	)]
	public function ofabarmorCommand(CmdContext $context): void {
		$qls = $this->db->table(OfabArmorCost::getTable())
			->orderBy('ql')
			->select('ql')
			->distinct()
			->pluckInts('ql');
		$blob = $this->db->table(OfabArmorType::getTable())
			->orderBy('profession')
			->asObj(OfabArmorType::class)
			->reduce(
				static function (string $blob, OfabArmorType $row) use ($qls): string {
					$blob .= "<pagebreak>{$row->profession->value} - Type {$row->type}\n";
					foreach ($qls as $ql) {
						$qlLink = Text::makeChatcmd((string)$ql, "/tell <myname> ofabarmor {$row->profession->short()} {$ql}");
						$blob .= "[{$qlLink}] ";
					}
					return $blob . "\n\n";
				},
				''
			);

		$msg = Text::makeBlob('Ofab Armor Bio-Material Types', $blob);
		$context->reply($msg);
	}

	/** Show Ofab armor for a specific profession at a certain ql */
	#[NCA\HandlesCommand('ofabarmor')]
	public function ofabarmorInfoCommand2(CmdContext $context, string $prof, int $ql): void {
		$this->ofabarmorInfoCommand($context, $ql, $prof);
	}

	/** Show Ofab armor for a specific profession at a certain ql */
	#[NCA\HandlesCommand('ofabarmor')]
	public function ofabarmorInfoCommand(CmdContext $context, ?int $ql, string $prof): void {
		$ql ??= 300;

		try {
			$profession = Profession::byName($prof);
		} catch (Throwable) {
			$msg = 'Please choose one of these professions: ' . Text::enumerateOr(...Profession::shortNames());
			$context->reply($msg);
			return;
		}

		$type = $this->db->table(OfabArmorType::getTable())
			->where('profession', $profession->value)
			->pluckInts('type')
			->first();

		$armors = $this->db->table(OfabArmor::getTable())
			->where('profession', $profession->value)
			->orderBy('upgrade')
			->orderBy('name')
			->asObj(OfabArmor::class);

		$costBySlot = $this->db->table(OfabArmorCost::getTable())
			->where('ql', $ql)
			->asObj(OfabArmorCost::class)
			->keyBy('slot');

		if ($armors->count() === 0 || $costBySlot->count() === 0) {
			$msg = "Could not find any OFAB armor for {$profession->value} in QL {$ql}.";
			$context->reply($msg);
			return;
		}

		$blob = '';
		$typeLink = Text::makeChatcmd("Kyr'Ozch Bio-Material - Type {$type}", "/tell <myname> bioinfo {$type}");
		$typeQl = round(.8 * $ql);
		$blob .= "Upgrade with {$typeLink} (minimum QL {$typeQl})\n\n";

		$qls = $this->db->table(OfabArmorCost::getTable())
			->orderBy('ql')
			->select('ql')->distinct()
			->pluckInts('ql');
		foreach ($qls as $currQL) {
			if ($currQL === $ql) {
				$blob .= "<yellow>[<end>{$currQL}<yellow>]<end> ";
			} else {
				$qlLink = Text::makeChatcmd((string)$currQL, "/tell <myname> ofabarmor {$profession->short()} {$currQL}");
				$blob .= "[{$qlLink}] ";
			}
		}
		$blob .= "\n";

		$currentUpgrade = null;
		$fullSetVP = 0;
		foreach ($armors as $row) {
			/** @var OfabArmor $row */
			if (!$costBySlot->has($row->slot)) {
				continue;
			}
			$vp = $costBySlot->get($row->slot)->vp ?? 0;
			if ($currentUpgrade !== $row->upgrade) {
				$currentUpgrade = $row->upgrade;
				$blob .= "\n<header2>";
				if ($currentUpgrade === 0) {
					$blob .= 'No upgrades';
				} elseif ($currentUpgrade === 1) {
					$blob .= '1 upgrade';
				} else {
					$blob .= "{$currentUpgrade} upgrades";
				}
				$blob .= "<end>\n";
			}
			$blob .= '<tab>' . $row->getLink(ql: $ql);

			if ($row->upgrade === 0 || $row->upgrade === 3) {
				$blob .= "  (<highlight>{$vp}<end> VP)";
				$fullSetVP += $vp;
			}
			$blob .= "\n";
		}
		$blob .= "\nCost for full set: <highlight>{$fullSetVP}<end> VP";

		$msg = Text::makeBlob("{$profession->value} Ofab Armor (QL {$ql})", $blob);
		$context->reply($msg);
	}

	/** Show a list of Ofab weapons and the type needed to upgrade */
	#[NCA\HandlesCommand('ofabweapons')]
	#[NCA\Help\Epilogue(
		"Valid QLs are:\n".
		'<tab>1, 25, 50, 75, 100, 125, 150, 175, 200, 225, 250, 275, and 300.'
	)]
	public function ofabweaponsCommand(CmdContext $context): void {
		$qls = $this->db->table(OfabWeaponCost::getTable())
			->orderBy('ql')
			->select('ql')->distinct()
			->pluckInts('ql');
		$blob = $this->db->table(OfabWeapon::getTable())
			->orderBy('name')
			->asObj(OfabWeapon::class)
			->reduce(
				static function (string $blob, OfabWeapon $weapon) use ($qls): string {
					$blob .= "<pagebreak>{$weapon->name} - Type {$weapon->type}\n";
					foreach ($qls as $ql) {
						$qlLink = Text::makeChatcmd(
							(string)$ql,
							"/tell <myname> ofabweapons {$weapon->name} {$ql}"
						);
						$blob .= "[{$qlLink}] ";
					}
					return "{$blob}\n\n";
				},
				''
			);

		$msg = Text::makeBlob('Ofab Weapons', $blob);
		$context->reply($msg);
	}

	/** Show all 6 marks for a particular Ofab weapon at ql 300, or &lt;search ql&gt; */
	#[NCA\HandlesCommand('ofabweapons')]
	public function ofabweaponsInfoCommand(CmdContext $context, PWord $weapon, ?int $searchQL): void {
		$weapon = ucfirst($weapon());
		$searchQL ??= 300;

		$row = $this->db->table(OfabWeapon::getTable(), 'w')
			->crossJoin('ofabweaponscost AS c')
			->where('w.name', $weapon)
			->where('c.ql', $searchQL)
			->firstObj(OfabWeaponWithCost::class);
		if ($row === null) {
			$msg = "Could not find any OFAB weapon <highlight>{$weapon}<end> in QL <highlight>{$searchQL}<end>.";
			$context->reply($msg);
			return;
		}

		$blob = '';
		$typeQl = round(.8 * $searchQL);
		$typeLink = Text::makeChatcmd("Kyr'Ozch Bio-Material - Type {$row->type}", "/tell <myname> bioinfo {$row->type} {$typeQl}");
		$blob .= "Upgrade with {$typeLink} (minimum QL {$typeQl})\n\n";

		$blob = $this->db->table(OfabWeaponCost::getTable())
			->orderBy('ql')
			->select('ql')->distinct()
			->pluckInts('ql')
			->reduce(
				static function (string $blob, int $ql) use ($searchQL, $weapon): string {
					if ($ql === $searchQL) {
						return "{$blob}<yellow>[<end>{$ql}<yellow>]<end> ";
					}
					$qlLink = Text::makeChatcmd(
						(string)$ql,
						"/tell <myname> ofabweapons {$weapon} {$ql}"
					);
					return "{$blob}[{$qlLink}] ";
				},
				$blob
			);
		$blob .= "\n\n<header2>Upgrades<end>\n";

		for ($i = 1; $i <= 6; $i++) {
			$item = $this->itemsController->getItem("Ofab {$weapon} Mk {$i}", $searchQL);
			if (isset($item)) {
				$blob .= "<tab>{$item}";
				if ($i === 1) {
					$blob .= "  (<highlight>{$row->vp}<end> VP)";
				}
				$blob .= "\n";
			}
		}

		$msg = Text::makeBlob("Ofab {$weapon} (QL {$searchQL})", $blob);
		$context->reply($msg);
	}

	/** Show info about the Alien City Generals */
	#[NCA\HandlesCommand('aigen')]
	public function aigenCommand(
		CmdContext $context,
		#[NCA\StrChoice('ankari', 'ilari', 'rimah', 'jaax', 'xoch', 'cha')] string $general
	): void {
		$gen = ucfirst(strtolower($general));

		$blob = '';
		switch ($gen) {
			case 'Ankari':
				$blob .= "Low Evade/Dodge, Low AR, Casts Viral/Virral nukes\n\n";
				$blob .= $this->itemsController->getItemAndIcon('Arithmetic Lead Viralbots') . "\n";
				$blob .= "(Nanoskill / Tradeskill)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 1") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 2") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 48");
				break;
			case 'Ilari':
				$blob .= "Low Evade/Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon('Spiritual Lead Viralbots') . "\n";
				$blob .= "(Nanocost / Nanopool / Max Nano)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 992") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 880");
				break;
			case 'Rimah':
				$blob .= "Low Evade/Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon('Observant Lead Viralbots') . "\n";
				$blob .= "(Init / Evades)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 112") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 240");
				break;
			case 'Jaax':
				$blob .= "High Evade, Low Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon('Strong Lead Viralbots') . "\n";
				$blob .= "(Melee / Spec Melee / Add All Def / Add Damage)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 3") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 4");
				break;
			case 'Xoch':
				$blob .= "High Evade/Dodge, Casts Ilari Biorejuvenation heals\n\n";
				$blob .= $this->itemsController->getItemAndIcon('Enduring Lead Viralbots') . "\n";
				$blob .= "(Max Health / Body Dev)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 5") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 12");
				break;
			case 'Cha':
				$blob .= "High Evade/NR, Low Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon('Supple Lead Viralbots') . "\n";
				$blob .= "(Ranged / Spec Ranged / Add All Off)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 13") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 76");
				break;
		}

		$msg = Text::makeBlob("General {$gen}", $blob);
		$context->reply($msg);
	}
}
