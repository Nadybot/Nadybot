<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandReply,
	DB,
	DBRow,
	LoggerWrapper,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

/**
 * @author Blackruby (RK2)
 * @author Mdkdoc420 (RK2)
 * @author Wolfbiter (RK1)
 * @author Gatester (RK2)
 * @author Marebone (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'leprocs',
 *		accessLevel = 'all',
 *		description = "Shows each profession's LE procs",
 *		help        = 'leprocs.txt',
 *		alias       = 'leproc'
 *	)
 *	@DefineCommand(
 *		command     = 'ofabarmor',
 *		accessLevel = 'all',
 *		description = 'Shows ofab armors available to a given profession and their VP cost',
 *		help        = 'ofabarmor.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'ofabweapons',
 *		accessLevel = 'all',
 *		description = 'Shows Ofab weapons, their marks, and VP cost',
 *		help        = 'ofabweapons.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'aigen',
 *		accessLevel = 'all',
 *		description = 'Shows info about Alien City Generals',
 *		help        = 'aigen.txt'
 *	)
 */
class AlienMiscController {

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

	/** @Inject */
	public ItemsController $itemsController;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 */
	public function setup() {
		// load database tables from .sql-files
		$this->db->loadMigrations($this->moduleName, __DIR__ . '/Migrations/Misc');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/leprocs.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabarmor.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabarmortype.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabarmorcost.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabweapons.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ofabweaponscost.csv');
	}

	/**
	 * This command handler shows menu of each profession's LE procs.
	 *
	 * @HandlesCommand("leprocs")
	 * @Matches("/^leprocs$/i")
	 */
	public function leprocsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = "<header2>Choose a profession<end>\n";
		$blob = $this->db->table("leprocs")
			->orderBy("profession")
			->select("profession")
			->distinct()
			->asObj()
			->reduce(
				function (string $blob, DBRow $row) {
					$professionLink = $this->text->makeChatcmd($row->profession, "/tell <myname> leprocs $row->profession");
					return "{$blob}<tab>$professionLink\n";
				},
				$blob
			);

		$msg = $this->text->makeBlob("LE Procs (Choose profession)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows the LE procs for a particular profession.
	 *
	 * @HandlesCommand("leprocs")
	 * @Matches("/^leprocs (.+)$/i")
	 */
	public function leprocsInfoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profession = $this->util->getProfessionName($args[1]);
		if (empty($profession)) {
			$msg = "<highlight>{$args[1]}<end> is not a valid profession.";
			$sendto->reply($msg);
			return;
		}

		/** @var Collection<LEProc> */
		$data = $this->db->table("leprocs")
			->whereIlike("profession", $profession)
			->orderBy("proc_type")
			->orderByDesc("research_lvl")
			->asObj(LEProc::class);
		if ($data->count() === 0) {
			$msg = "No procs found for profession <highlight>$profession<end>.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		$type = '';
		foreach ($data as $row) {
			if ($type !== $row->proc_type) {
				$type = $row->proc_type;
				$blob .= "\n<img src=rdb://" . ($type === 1 ? 84789 : 84310) . "><header2>Type $type<end>\n";
			}

			$proc_trigger = "<green>$row->proc_trigger<end>";
			$blob .= "<tab>".
				$this->text->alignNumber($row->research_lvl, 2).
				" - $row->name <orange>$row->modifiers<end> $row->duration $proc_trigger\n";
		}
		$blob .= "\n".
			"\n<i>Offensive procs have a 5% chance of firing every time you attack</i>".
			"\n<i>Defensive procs have a 10% chance of firing every time something attacks you.</i>";

		$msg = $this->text->makeBlob("$profession LE Procs", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows Ofab armors and VP cost.
	 *
	 * @HandlesCommand("ofabarmor")
	 * @Matches("/^ofabarmor$/i")
	 */
	public function ofabarmorCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var DBRow[] */
		$qls = $this->db->table("ofabarmorcost")
			->orderBy("ql")
			->select("ql")
			->distinct()
			->asObj()->pluck("ql")->toArray();
		$blob = $this->db->table("ofabarmortype")
			->orderBy("profession")
			->asObj(OfabArmorType::class)
			->reduce(
				function (string $blob, OfabArmorType $row) use ($qls): string {
					$blob .= "<pagebreak>{$row->profession} - Type {$row->type}\n";
					foreach ($qls as $ql) {
						$ql_link = $this->text->makeChatcmd((string)$ql, "/tell <myname> ofabarmor {$row->profession} {$ql}");
						$blob .= "[{$ql_link}] ";
					}
					return $blob . "\n\n";
				},
				""
			);

		$msg = $this->text->makeBlob("Ofab Armor Bio-Material Types", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows list of ofab armors available to a given profession.
	 *
	 * @HandlesCommand("ofabarmor")
	 * @Matches("/^ofabarmor (?<prof>.+) (?<ql>\d+)$/i")
	 * @Matches("/^ofabarmor (?<ql>\d+) (?<prof>.+)$/i")
	 * @Matches("/^ofabarmor (?<prof>.+)$/i")
	 */
	public function ofabarmorInfoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$ql = intval($args['ql'] ?? 300);

		$profession = $this->util->getProfessionName($args['prof']);

		if ($profession === '') {
			$msg = "Please choose one of these professions: adv, agent, crat, doc, enf, eng, fix, keep, ma, mp, nt, sol, shade, or trader";
			$sendto->reply($msg);
			return;
		}

		$type = $this->db->table("ofabarmortype")
			->where("profession", $profession)
			->asObj()
			->first()->type;

		$data = $this->db->table("ofabarmor AS o1")
			->leftJoin("ofabarmorcost AS o2", "o1.slot", "o2.slot")
			->where("o1.profession", $profession)
			->where("o2.ql", $ql)
			->orderBy("upgrade")
			->orderBy("name")
			->asObj();
		if ($data->count() === 0) {
			$msg = "Could not find any OFAB armor for {$profession} in QL {$ql}.";
			$sendto->reply($msg);
			return;
		}

		$blob = '';
		$typeLink = $this->text->makeChatcmd("Kyr'Ozch Bio-Material - Type {$type}", "/tell <myname> bioinfo {$type}");
		$typeQl = round(.8 * $ql);
		$blob .= "Upgrade with $typeLink (minimum QL {$typeQl})\n\n";

		/** @var Collection<DBRow> */
		$qls = $this->db->table("ofabarmorcost")
			->orderBy("ql")
			->select("ql")->distinct()
			->asObj();
		foreach ($qls as $row2) {
			if ($row2->ql === $ql) {
				$blob .= "<yellow>[<end>{$row2->ql}<yellow>]<end> ";
			} else {
				$ql_link = $this->text->makeChatcmd((string)$row2->ql, "/tell <myname> ofabarmor {$profession} {$row2->ql}");
				$blob .= "[{$ql_link}] ";
			}
		}
		$blob .= "\n";

		$currentUpgrade = null;
		$fullSetVP = 0;
		foreach ($data as $row) {
			if ($currentUpgrade !== $row->upgrade) {
				$currentUpgrade = $row->upgrade;
				$blob .= "\n<header2>";
				if ($currentUpgrade === 0) {
					$blob .= "No upgrades";
				} elseif ($currentUpgrade === 1) {
					$blob .= "1 upgrade";
				} else {
					$blob .= "$currentUpgrade upgrades";
				}
				$blob .= "<end>\n";
			}
			$blob .= "<tab>" . $this->text->makeItem($row->lowid, $row->highid, $ql, $row->name);

			if ($row->upgrade === 0 || $row->upgrade === 3) {
				$blob .= "  (<highlight>{$row->vp}<end> VP)";
				$fullSetVP += $row->vp;
			}
			$blob .= "\n";
		}
		$blob .= "\nCost for full set: <highlight>$fullSetVP<end> VP";

		$msg = $this->text->makeBlob("$profession Ofab Armor (QL $ql)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows Ofab weapons and VP cost.
	 *
	 * @HandlesCommand("ofabweapons")
	 * @Matches("/^ofabweapons$/i")
	 */
	public function ofabweaponsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var DBRow[] */
		$qls = $this->db->table("ofabweaponscost")
			->orderBy("ql")
			->select("ql")->distinct()
			->asObj()->pluck("ql");
		$blob = $this->db->table("ofabweapons")
			->orderBy("name")
			->asObj(OfabWeapon::class)
			->reduce(
				function (string $blob, OfabWeapon $weapon) use ($qls): string {
					$blob .= "<pagebreak>{$weapon->name} - Type {$weapon->type}\n";
					foreach ($qls as $ql) {
						$ql_link = $this->text->makeChatcmd(
							(string)$ql,
							"/tell <myname> ofabweapons {$weapon->name} {$ql}"
						);
						$blob .= "[{$ql_link}] ";
					}
					return"{$blob}\n\n";
				},
				""
			);

		$msg = $this->text->makeBlob("Ofab Weapons", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows all six marks of the Ofab weapon.
	 *
	 * @HandlesCommand("ofabweapons")
	 * @Matches("/^ofabweapons (\S+)$/i")
	 * @Matches("/^ofabweapons (\S+) (\d+)$/i")
	 */
	public function ofabweaponsInfoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$weapon = ucfirst($args[1]);
		$searchQL = isset($args[2])? intval($args[2]): 300;

		/** @var DBRow|null */
		$row = $this->db->table("ofabweapons AS w")
			->crossJoin("ofabweaponscost AS c")
			->where("w.name", $weapon)
			->where("c.ql", $searchQL)
			->asObj()->first();
		if ($row === null) {
			$msg = "Could not find any OFAB weapon <highlight>$weapon<end> in QL <highlight>{$searchQL}<end>.";
			$sendto->reply($msg);
			return;
		}

		$blob = '';
		$typeQl = round(.8 * $searchQL);
		$typeLink = $this->text->makeChatcmd("Kyr'Ozch Bio-Material - Type {$row->type}", "/tell <myname> bioinfo {$row->type} {$typeQl}");
		$blob .= "Upgrade with $typeLink (minimum QL {$typeQl})\n\n";

		$blob = $this->db->table("ofabweaponscost")
			->orderBy("ql")
			->select("ql")->distinct()
			->asObj()->pluck("ql")
			->reduce(
				function(string $blob, int $ql) use ($searchQL, $weapon): string {
					if ($ql === $searchQL) {
						return "{$blob}<yellow>[<end>{$ql}<yellow>]<end> ";
					}
					$ql_link = $this->text->makeChatcmd(
						(string)$ql,
						"/tell <myname> ofabweapons {$weapon} {$ql}"
					);
					return "{$blob}[{$ql_link}] ";
				},
				$blob
			);
		$blob .= "\n\n<header2>Upgrades<end>\n";

		for ($i = 1; $i <= 6; $i++) {
			$blob .= "<tab>" . $this->itemsController->getItem("Ofab {$weapon} Mk {$i}", $searchQL);
			if ($i === 1) {
				$blob .= "  (<highlight>{$row->vp}<end> VP)";
			}
			$blob .= "\n";
		}

		$msg = $this->text->makeBlob("Ofab $weapon (QL $searchQL)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows info about Alien City Generals.
	 *
	 * @HandlesCommand("aigen")
	 * @Matches("/^aigen (ankari|ilari|rimah|jaax|xoch|cha)$/i")
	 */
	public function aigenCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$gen = ucfirst(strtolower($args[1]));

		$blob = '';
		switch ($gen) {
			case "Ankari":
				$blob .= "Low Evade/Dodge, Low AR, Casts Viral/Virral nukes\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Arithmetic Lead Viralbots") . "\n";
				$blob .= "(Nanoskill / Tradeskill)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 1") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 2") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 48");
				break;
			case "Ilari":
				$blob .= "Low Evade/Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Spiritual Lead Viralbots") . "\n";
				$blob .= "(Nanocost / Nanopool / Max Nano)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 992") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 880");
				break;
			case "Rimah":
				$blob .= "Low Evade/Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Observant Lead Viralbots") . "\n";
				$blob .= "(Init / Evades)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 112") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 240");
				break;
			case "Jaax":
				$blob .= "High Evade, Low Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Strong Lead Viralbots") . "\n";
				$blob .= "(Melee / Spec Melee / Add All Def / Add Damage)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 3") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 4");
				break;
			case "Xoch":
				$blob .= "High Evade/Dodge, Casts Ilari Biorejuvenation heals\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Enduring Lead Viralbots") . "\n";
				$blob .= "(Max Health / Body Dev)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 5") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 12");
				break;
			case "Cha":
				$blob .= "High Evade/NR, Low Dodge\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Supple Lead Viralbots") . "\n";
				$blob .= "(Ranged / Spec Ranged / Add All Off)\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 13") . "\n\n";
				$blob .= $this->itemsController->getItemAndIcon("Kyr'Ozch Bio-Material - Type 76");
				break;
		}

		$msg = $this->text->makeBlob("General $gen", $blob);
		$sendto->reply($msg);
	}
}
