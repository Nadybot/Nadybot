<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	LoggerWrapper,
	ModuleInstance,
	ParamClass\PItem,
	ParamClass\PWord,
	Text,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

/**
 * @author Blackruby (RK2)
 * @author Mdkdoc420 (RK2)
 * @author Wolfbiter (RK1)
 * @author Gatester (RK2)
 * @author Marebone (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Weapons"),
	NCA\DefineCommand(
		command: "bio",
		accessLevel: "guest",
		description: "Identifies Solid Clump of Kyr'Ozch Bio-Material",
	),
	NCA\DefineCommand(
		command: "bioinfo",
		accessLevel: "guest",
		description: "Shows info about a particular bio type",
	)
]
class AlienBioController extends ModuleInstance {
	private const LE_ARMOR_TYPES  = [64, 295, 468, 935];
	private const LE_WEAPON_TYPES = [18, 34, 687, 812];
	private const AI_ARMOR_TYPES  = ['mutated', 'pristine'];
	private const AI_WEAPON_TYPES = [1, 2, 3, 4, 5, 12, 13, 48, 76, 112, 240, 880, 992];
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		// load database tables from .sql-files
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/alienweapons.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/alienweaponspecials.csv');
	}

	/** Identify a "Solid Clump of Kyr'Ozch Bio-Material" */
	#[NCA\HandlesCommand("bio")]
	#[NCA\Help\Epilogue("Just drag and drop biomaterials into the chat as parameters.")]
	public function bioCommand(CmdContext $context, #[NCA\SpaceOptional] PItem ...$clumps): void {
		$blob = '';
		$bioinfo = "";
		$name = "Unknown Bio-Material";
		foreach ($clumps as $clump) {
			switch ($clump->highID) {
				case 247707:
				case 247708:
					$bioinfo = "1";
					$name = "Kyr'Ozch Bio-Material - Type 1";
					break;
				case 247709:
				case 247710:
					$bioinfo = "2";
					$name = "Kyr'Ozch Bio-Material - Type 2";
					break;
				case 247717:
				case 247718:
					$bioinfo = "3";
					$name = "Kyr'Ozch Bio-Material - Type 3 ";
					break;
				case 247711:
				case 247712:
					$bioinfo = "4";
					$name = "Kyr'Ozch Bio-Material - Type 4";
					break;
				case 247713:
				case 247714:
					$bioinfo = "5";
					$name = "Kyr'Ozch Bio-Material - Type 5";
					break;
				case 247715:
				case 247716:
					$bioinfo = "12";
					$name = "Kyr'Ozch Bio-Material - Type 12 ";
					break;
				case 247719:
				case 247720:
					$bioinfo = "13";
					$name = "Kyr'Ozch Bio-Material - Type 13";
					break;
				case 288699:
				case 288700:
					$bioinfo = "48";
					$name = "Kyr'Ozch Bio-Material - Type 48";
					break;
				case 247697:
				case 247698:
					$bioinfo = "76";
					$name = "Kyr'Ozch Bio-Material - Type 76";
					break;
				case 247699:
				case 247700:
					$bioinfo = "112";
					$name = "Kyr'Ozch Bio-Material - Type 112";
					break;
				case 247701:
				case 247702:
					$bioinfo = "240";
					$name = "Kyr'Ozch Bio-Material - Type 240";
					break;
				case 247703:
				case 247704:
					$bioinfo = "880";
					$name = "Kyr'Ozch Bio-Material - Type 880";
					break;
				case 247705:
				case 247706:
					$bioinfo = "992";
					$name = "Kyr'Ozch Bio-Material - Type 992";
					break;
				case 247102:
				case 247103:
					$bioinfo = "pristine";
					$name = "Pristine Kyr'Ozch Bio-Material";
					break;
				case 247104:
				case 247105:
					$bioinfo = "mutated";
					$name = "Mutated Kyr'Ozch Bio-Material";
					break;
				case 247764:
				case 254804:
					$bioinfo = "serum";
					$name = "Kyr'Ozch Viral Serum";
					break;
				default:
					$bioinfo = "";
					if ($clump->name === "Solid Clump of Kyr'Ozch Bio-Material") {
						$name = "Unknown Bio-Material";
					} else {
						$context->reply("{$clump} is not bio material.");
						return;
					}
					break;
			}

			$biotypeLink = $name;
			if ($bioinfo !== "") {
				$biotypeLink = $this->text->makeChatcmd($name, "/tell <myname> bioinfo {$bioinfo} {$clump->ql}");
			}
			$blob .= "<header2>QL {$clump->ql} clump<end>\n".
				"<tab>{$biotypeLink} (QL {$clump->ql})\n\n";
		}

		if (count($clumps) === 1) {
			// if there is only one bio, show detailed info by calling !bioinfo command handler directly
			if (is_numeric($bioinfo)) {
				$this->bioinfoIDCommand($context, (int)$bioinfo, $clumps[0]->ql);
			} else {
				$this->bioinfoCommand($context, new PWord($bioinfo), $clumps[0]->ql);
			}
		} else {
			$msg = $this->text->makeBlob("Identified Bio-Materials", $blob);
			$context->reply($msg);
		}
	}

	/** See all bio material types */
	#[NCA\HandlesCommand("bioinfo")]
	public function bioinfoListCommand(CmdContext $context): void {
		$blob  = "<header2>OFAB Armor Types<end>\n";
		$blob .= $this->getTypeBlob(self::LE_ARMOR_TYPES);

		$blob .= "\n<header2>OFAB Weapon Types<end>\n";
		$blob .= $this->getTypeBlob(self::LE_WEAPON_TYPES);

		$blob .= "\n<header2>AI Armor Types<end>\n";
		$blob .= $this->getTypeBlob(self::AI_ARMOR_TYPES);

		$blob .= "\n<header2>AI Weapon Types<end>\n";
		$blob .= $this->getTypeBlob(self::AI_WEAPON_TYPES);

		$msg = $this->text->makeBlob("Bio-Material Types", $blob);
		$context->reply($msg);
	}

	/** @param int[]|string[] $types */
	public function getTypeBlob(array $types): string {
		$blob = '';
		foreach ($types as $type) {
			$blob .= "<tab>" . $this->text->makeChatcmd((string)$type, "/tell <myname> bioinfo {$type}") . "\n";
		}
		return $blob;
	}

	/** Show info about a particular bio type */
	#[NCA\HandlesCommand("bioinfo")]
	public function bioinfoIDCommand(CmdContext $context, int $bio, ?int $ql): void {
		$ql ??= 300;
		$ql = min(300, max(1, $ql));

		$msg = "Unknown Bio-Material";
		if (in_array($bio, self::LE_ARMOR_TYPES)) {
			$msg = $this->ofabArmorBio($ql, $bio);
		} elseif (in_array($bio, self::LE_WEAPON_TYPES)) {
			$msg = $this->ofabWeaponBio($ql, $bio);
		} elseif (in_array($bio, self::AI_WEAPON_TYPES)) {
			$msg = $this->alienWeaponBio($ql, $bio);
		}

		$context->reply($msg);
	}

	/** This command handler shows info about a particular bio type. */
	#[NCA\HandlesCommand("bioinfo")]
	public function bioinfoCommand(CmdContext $context, PWord $bio, ?int $ql): void {
		$bio = strtolower($bio());
		$ql ??= 300;
		$ql = min(300, max(1, $ql));

		$msg = "Unknown Bio-Material";
		if (in_array($bio, self::AI_ARMOR_TYPES)) {
			$msg = $this->alienArmorBio($ql, $bio);
		} elseif ($bio === 'serum') {
			$msg = $this->serumBio($ql);
		}

		$context->reply($msg);
	}

	/**
	 * Returns information of how much weapon of given $ql requires skills
	 * to upgrade it.
	 */
	private function getWeaponInfo(int $ql): string {
		$requiredMEandWS = (int)floor($ql * 6);
		$text = "\n\n<highlight>QL {$ql}<end> is the highest weapon this type will combine into.";
		if ($ql !== 300) {
			$text .= "\nNote: <highlight>The weapon can bump several QL's.<end>";
		}
		$text .= "\n\nIt will take <highlight>{$requiredMEandWS}<end> ME & WS (<highlight>6 * QL<end>) to combine with a <highlight>QL {$ql}<end> Kyr'ozch Weapon.";

		return $text;
	}

	/**
	 * Returns list of professions (in a blob) whose ofab armor given $type
	 * will upgrade.
	 */
	private function ofabArmorBio(int $ql, int $type): string {
		$name = "Kyr'Ozch Bio-Material - Type {$type}";
		$item = $this->itemsController->getItem($name, $ql);
		if ($item === null) {
			throw new Exception("Cannot find expected ofab bio material in database.");
		}

		/** @var OfabArmorType[] $data */
		$data = $this->db->table("ofabarmortype")
			->where("type", $type)
			->asObj(OfabArmorType::class)
			->toArray();

		$blob = $item . "\n\n";
		$blob .= "<highlight>Upgrades Ofab armor for:<end>\n";
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd($row->profession, "/tell <myname> ofabarmor {$row->profession}") . "\n";
		}

		return ((array)$this->text->makeBlob("{$name} (QL {$ql})", $blob))[0];
	}

	/**
	 * Returns list of professions (in a blob) whose ofab weapon given $type
	 * will upgrade.
	 */
	private function ofabWeaponBio(int $ql, int $type): string {
		$name = "Kyr'Ozch Bio-Material - Type {$type}";
		$item = $this->itemsController->getItem($name, $ql);
		if ($item === null) {
			throw new Exception("Cannot find expected ofab bio material in database.");
		}

		/** @var OfabWeapon[] $data */
		$data = $this->db->table("ofabweapons")
			->where("type", $type)
			->asObj(OfabWeapon::class)->toArray();

		$blob = $item . "\n\n";
		$blob .= "<highlight>Upgrades Ofab weapons:<end>\n";
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd("Ofab {$row->name} Mk 1", "/tell <myname> ofabweapons {$row->name}") . "\n";
		}

		return ((array)$this->text->makeBlob("{$name} (QL {$ql})", $blob))[0];
	}

	/**
	 * Returns what special attacks given bio type adds to each ofab weapon and
	 * tells how much skills analyzing the clump requires and how much skills
	 * is needed to upgrade the weapon.
	 */
	private function alienWeaponBio(int $ql, int $type): string {
		$name = "Kyr'Ozch Bio-Material - Type {$type}";
		$item = $this->itemsController->getItem($name, $ql);
		if ($item === null) {
			throw new Exception("Cannot find expected alien bio material in database.");
		}

		// Ensures that the maximum AI weapon that combines into doesn't go over QL 300 when the user presents a QL 271+ bio-material
		$maxAIType = (int)floor($ql / 0.9);
		if ($maxAIType > 300 || $maxAIType < 1) {
			$maxAIType = 300;
		}

		$requiredEEandCL = (int)floor($ql * 4.5);

		$specials = $this->db->table("alienweaponspecials")
			->where("type", $type)
			->select("specials")
			->limit(1)
			->pluckStrings("specials")
			->first();

		$blob = $item . "\n\n";
		$blob .= "It will take <highlight>{$requiredEEandCL}<end> EE & CL (<highlight>4.5 * QL<end>) to analyze the Bio-Material.\n\n";

		$blob .= "<highlight>Adds {$specials} to:<end>\n";

		/** @var AlienWeapon[] $data */
		$data = $this->db->table("alienweapons")
			->where("type", $type)
			->asObj(AlienWeapon::class)
			->toArray();
		foreach ($data as $row) {
			$item = $this->itemsController->getItem($row->name, $maxAIType);
			if (!isset($item)) {
				throw new Exception("Cannot find expected alien bio material in database.");
			}
			$blob .= "{$item}\n";
		}

		$blob .= $this->getWeaponInfo($maxAIType);
		$blob .= "\n\nTradeskilling info added by Mdkdoc420 (RK2)";

		return ((array)$this->text->makeBlob("{$name} (QL {$ql})", $blob))[0];
	}

	/**
	 * Returns what skills and how much is required for analyzing the bio
	 * material and building alien armor of it.
	 */
	private function alienArmorBio(int $ql, string $type): string {
		// All the min/max QL and tradeskill calcs for the mutated/pristine process
		$minQL = (int)floor($ql * 0.8);
		if ($minQL < 1) {
			$minQL = 1;
		}
		$maxQL = 300;
		if ($ql >= 1 && $ql <= 240) {
			$maxQL = (int)floor($ql / 0.8);
		}

		$requiredCL         = (int)floor($minQL * 4.5);
		$requiredPharma     = (int)floor($ql    * 6);
		$requiredNP         = (int)floor($minQL * 6);
		$requiredPsychology = (int)floor($ql    * 6);
		$max_psyco          = (int)floor($maxQL * 6);
		$requiredEEandCL    = (int)floor($ql    * 4.5);
		$name = "UNKNOWN";
		if (strtolower($type) == "mutated") {
			$name = "Mutated Kyr'Ozch Bio-Material";
			$reqiredChem = (int)floor($ql * 7);
			$chemMsg = "7 * QL";
			$extraInfo = "";
		} elseif (strtolower($type) == "pristine") {
			$name = "Pristine Kyr'Ozch Bio-Material";
			$reqiredChem = (int)floor($ql * 4.5);
			$chemMsg = "4.5 * QL";
			$extraInfo = "(<highlight>less tradeskill requirements than mutated.<end>)";
		} else {
			return "Unknown tradeskil process";
		}
		// End of tradeskill processes

		$item = $this->itemsController->getItem($name, $ql);
		if (!isset($item)) {
			throw new Exception("Cannot find expected alien bio material in database.");
		}

		$blob = $item . "\n\n";
		$blob .= "It will take <highlight>{$requiredEEandCL}<end> EE & CL (<highlight>4.5 * QL<end>) to analyze the Bio-Material.\n\n";

		$blob .= "Used to build Alien Armor {$extraInfo}\n\n" .
			"<highlight>The following tradeskill amounts are required to make<end> QL {$ql}<highlight>\n" .
			"strong/arithmetic/enduring/spiritual/supple/observant armor:<end>\n\n" .
			"Computer Literacy - <highlight>{$requiredCL}<end> (<highlight>4.5 * QL<end>)\n" .
			"Chemistry - <highlight>{$reqiredChem}<end> (<highlight>{$chemMsg}<end>)\n" .
			"Nano Programming - <highlight>{$requiredNP}<end> (<highlight>6 * QL<end>)\n" .
			"Pharma Tech - <highlight>{$requiredPharma}<end> (<highlight>6 * QL<end>)\n" .
			"Psychology - <highlight>{$requiredPsychology}<end> (<highlight>6 * QL<end>)\n\n" .
			"Note:<highlight> Tradeskill requirements are based off the lowest QL items needed throughout the entire process.<end>";

		$blob .= "\n\nFor Supple, Arithmetic, or Enduring:\n\n" .
			"<highlight>When completed, the armor piece can have as low as<end> QL {$minQL} <highlight>combined into it, depending on available tradeskill options.\n\n" .
			"Does not change QLs, therefore takes<end> {$requiredPsychology} <highlight>Psychology for available combinations.<end>\n\n" .
			"For Spiritual, Strong, or Observant:\n\n" .
			"<highlight>When completed, the armor piece can combine up to<end> QL {$maxQL}<highlight>, depending on available tradeskill options.\n\n" .
			"Changes QL depending on targets QL. The max combination is: (<end>QL {$maxQL}<highlight>) (<end>{$max_psyco} Psychology required for this combination<highlight>)<end>";

		$blob .= "\n\nTradeskilling info added by Mdkdoc420 (RK2)";

		return ((array)$this->text->makeBlob("{$name} (QL {$ql})", $blob))[0];
	}

	/**
	 * Tells how much skills is required to analyze serum bio material and how
	 * much skills are needed to to build buildings from it.
	 */
	private function serumBio(int $ql): string {
		$name = "Kyr'Ozch Viral Serum";
		$item = $this->itemsController->getItem($name, $ql);
		if (!isset($item)) {
			throw new Exception("Cannot find expected alien bio material in database.");
		}

		$requiredPharma    = (int)floor($ql * 3.5);
		$requiredChemAndME = (int)floor($ql * 4);
		$requiredEE        = (int)floor($ql * 4.5);
		$requiredCL        = (int)floor($ql * 5);
		$requiredEEandCL   = (int)floor($ql * 4.5);

		$blob = $item . "\n\n";
		$blob .= "It will take <highlight>{$requiredEEandCL}<end> EE & CL (<highlight>4.5 * QL<end>) to analyze the Bio-Material.\n\n";

		$blob .= "<highlight>Used to build city buildings<end>\n\n" .
			"<highlight>The following are the required skills throughout the process of making a building:<end>\n\n" .
			"Quantum FT - <highlight>400<end> (<highlight>Static<end>)\nPharma Tech - ";

		// Used to change dialog between minimum and actual requirements, for requirements that go under 400
		if ($requiredPharma < 400) {
			$blob .= "<highlight>400<end>";
		} else {
			$blob .= "<highlight>{$requiredPharma}<end>";
		}

		$blob .= " (<highlight>3.5 * QL<end>) 400 is minimum requirement\nChemistry - ";

		if ($requiredChemAndME < 400) {
			$blob .= "<highlight>400<end>";
		} else {
			$blob .= "<highlight>{$requiredChemAndME}<end>";
		}

		$blob .= " (<highlight>4 * QL<end>) 400 is minimum requirement\n" .
			"Mechanical Engineering - <highlight>{$requiredChemAndME}<end> (<highlight>4 * QL<end>)\n" .
			"Electrical Engineering - <highlight>{$requiredEE}<end> (<highlight>4.5 * QL<end>)\n" .
			"Comp Liter - <highlight>{$requiredCL}<end> (<highlight>5 * QL<end>)";

		$blob .= "\n\nTradeskilling info added by Mdkdoc420 (RK2)";

		return ((array)$this->text->makeBlob("{$name} (QL {$ql})", $blob))[0];
	}
}
