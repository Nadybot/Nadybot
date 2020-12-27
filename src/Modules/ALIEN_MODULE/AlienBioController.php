<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	LoggerWrapper,
	Text,
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
 *		command     = 'bio',
 *		accessLevel = 'all',
 *		description = "Identifies Solid Clump of Kyr'Ozch Bio-Material",
 *		help        = 'bio.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'bioinfo',
 *      alias       = 'biotype',
 *		accessLevel = 'all',
 *		description = 'Shows info about a particular bio type',
 *		help        = 'bioinfo.txt'
 *	)
 */
class AlienBioController {

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
	public ItemsController $itemsController;

	/** @Logger */
	public LoggerWrapper $logger;

	private const LE_ARMOR_TYPES  = ['64', '295', '468', '935'];
	private const LE_WEAPON_TYPES = ['18', '34', '687', '812'];
	private const AI_ARMOR_TYPES  = ['mutated', 'pristine'];
	private const AI_WEAPON_TYPES = ['1', '2', '3', '4', '5', '12', '13', '48', '76', '112', '240', '880', '992'];

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup() {
		// load database tables from .sql-files
		$this->db->loadSQLFile($this->moduleName, 'alienweapons');
	}

	/**
	 * This command handler identifies Solid Clump of Kyr'Ozch Bio-Material.
	 *
	 * @HandlesCommand("bio")
	 * @Matches("/^bio (.+)$/i")
	 */
	public function bioCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$bio_regex = "<a href=[\"']itemref://(\\d+)/(\\d+)/(\\d+)[\"']>Solid Clump of Kyr\'Ozch Bio-Material</a>";

		if (!preg_match("|^(( *${bio_regex})+)$|i", $args[1], $arr)) {
			$msg = "<highlight>{$args[1]}<end> is not an unidentified clump.";
			$sendto->reply($msg);
			return;
		}

		$bios = preg_split("/(?<=>)\s*(?=<)/", $arr[1]);
		$blob = '';
		foreach ($bios as $bio) {
			preg_match("|^${bio_regex}$|i", trim($bio), $arr2);
			$highid = (int)$arr2[2];
			$ql = (int)$arr2[3];
			switch ($highid) {
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
					$name = "Unknown Bio-Material";
					break;
			}

			$biotypeLink = $this->text->makeChatcmd($name, "/tell <myname> bioinfo $bioinfo $ql");
			$blob .= "<header2>QL $ql clump<end>\n<tab>{$biotypeLink} (QL $ql)\n\n";
		}

		if (count($bios) === 1) {
			// if there is only one bio, show detailed info by calling !bioinfo command handler directly
			$this->bioinfoCommand("", $channel, $sender, $sendto, ["bioinfo $bioinfo $ql", $bioinfo, $ql]);
		} else {
			$msg = $this->text->makeBlob("Identified Bio-Materials", $blob);
			$sendto->reply($msg);
		}
	}

	/**
	 * @HandlesCommand("bioinfo")
	 * @Matches("/^bioinfo$/i")
	 */
	public function bioinfoListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob  = "<header2>OFAB Armor Types<end>\n";
		$blob .= $this->getTypeBlob(self::LE_ARMOR_TYPES);

		$blob .= "\n<header2>OFAB Weapon Types<end>\n";
		$blob .= $this->getTypeBlob(self::LE_WEAPON_TYPES);

		$blob .= "\n<header2>AI Armor Types<end>\n";
		$blob .= $this->getTypeBlob(self::AI_ARMOR_TYPES);

		$blob .= "\n<header2>AI Weapon Types<end>\n";
		$blob .= $this->getTypeBlob(self::AI_WEAPON_TYPES);

		$msg = $this->text->makeBlob("Bio-Material Types", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @param string[] $types
	 */
	public function getTypeBlob(array $types): string {
		$blob = '';
		foreach ($types as $type) {
			$blob .= "<tab>" . $this->text->makeChatcmd($type, "/tell <myname> bioinfo $type") . "\n";
		}
		return $blob;
	}

	/**
	 * This command handler shows info about a particular bio type.
	 * @HandlesCommand("bioinfo")
	 * @Matches("/^bioinfo (.+) (\d+)$/i")
	 * @Matches("/^bioinfo (.+)$/i")
	 */
	public function bioinfoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$bio = strtolower($args[1]);
		$ql = 300;
		if ($args[2]) {
			$ql = (int)$args[2];
		}
		if ($ql < 1) {
			$ql = 1;
		} elseif ($ql > 300) {
			$ql = 300;
		}

		$msg = "Unknown Bio-Material";
		if (in_array($bio, self::LE_ARMOR_TYPES)) {
			$msg = $this->ofabArmorBio($ql, (int)$bio);
		} elseif (in_array($bio, self::LE_WEAPON_TYPES)) {
			$msg = $this->ofabWeaponBio($ql, (int)$bio);
		} elseif (in_array($bio, self::AI_ARMOR_TYPES)) {
			$msg = $this->alienArmorBio($ql, $bio);
		} elseif (in_array($bio, self::AI_WEAPON_TYPES)) {
			$msg = $this->alienWeaponBio($ql, (int)$bio);
		} elseif ($bio === 'serum') {
			$msg = $this->serumBio($ql);
		}

		$sendto->reply($msg);
	}

	/**
	 * Returns information of how much weapon of given $ql requires skills
	 * to upgrade it.
	 */
	private function getWeaponInfo(int $ql): string {
		$requiredMEandWS = (int)floor($ql * 6);
		$text = "\n\n<highlight>QL $ql<end> is the highest weapon this type will combine into.";
		if ($ql !== 300) {
			$text .= "\nNote: <highlight>The weapon can bump several QL's.<end>";
		}
		$text .= "\n\nIt will take <highlight>$requiredMEandWS<end> ME & WS (<highlight>6 * QL<end>) to combine with a <highlight>QL $ql<end> Kyr'ozch Weapon.";

		return $text;
	}

	/**
	 * Returns list of professions (in a blob) whose ofab armor given $type
	 * will upgrade.
	 */
	private function ofabArmorBio(int $ql, int $type): string {
		$name = "Kyr'Ozch Bio-Material - Type $type";
		$item = $this->itemsController->getItem($name, $ql);

		/** @var OfabArmorType[] $data */
		$data = $this->db->fetchAll(OfabArmorType::class, "SELECT * FROM ofabarmortype WHERE type = ?", $type);

		$blob = $item . "\n\n";
		$blob .= "<highlight>Upgrades Ofab armor for:<end>\n";
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd($row->profession, "/tell <myname> ofabarmor {$row->profession}") . "\n";
		}

		return $this->text->makeBlob("$name (QL $ql)", $blob);
	}

	/**
	 * Returns list of professions (in a blob) whose ofab weapon given $type
	 * will upgrade.
	 */
	private function ofabWeaponBio(int $ql, int $type): string {
		$name = "Kyr'Ozch Bio-Material - Type $type";
		$item = $this->itemsController->getItem($name, $ql);

		/** @var OfabWeapon[] $data */
		$data = $this->db->fetchAll(OfabWeapon::class, "SELECT * FROM ofabweapons WHERE type = ?", $type);

		$blob = $item . "\n\n";
		$blob .= "<highlight>Upgrades Ofab weapons:<end>\n";
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd("Ofab {$row->name} Mk 1", "/tell <myname> ofabweapons {$row->name}") . "\n";
		}

		return $this->text->makeBlob("$name (QL $ql)", $blob);
	}

	/**
	 * Returns what special attacks given bio type adds to each ofab weapon and
	 * tells how much skills analyzing the clump requires and how much skills
	 * is needed to upgrade the weapon.
	 */
	private function alienWeaponBio(int $ql, int $type): string {
		$name = "Kyr'Ozch Bio-Material - Type $type";
		$item = $this->itemsController->getItem($name, $ql);

		// Ensures that the maximum AI weapon that combines into doesn't go over QL 300 when the user presents a QL 271+ bio-material
		$maxAIType = (int)floor($ql / 0.9);
		if ($maxAIType > 300 || $maxAIType < 1) {
			$maxAIType = 300;
		}

		$requiredEEandCL = (int)floor($ql * 4.5);

		$row = $this->db->queryRow("SELECT specials FROM alienweaponspecials WHERE type = ?", $type);
		$specials = $row->specials;

		$blob = $item . "\n\n";
		$blob .= "It will take <highlight>$requiredEEandCL<end> EE & CL (<highlight>4.5 * QL<end>) to analyze the Bio-Material.\n\n";

		$blob .= "<highlight>Adds {$specials} to:<end>\n";

		/** @var AlienWeapon[] $data */
		$data = $this->db->fetchAll(AlienWeapon::class, "SELECT * FROM alienweapons WHERE type = ?", $type);
		foreach ($data as $row) {
			$blob .= $this->itemsController->getItem($row->name, $maxAIType) . "\n";
		}

		$blob .= $this->getWeaponInfo($maxAIType);
		$blob .= "\n\nTradeskilling info added by Mdkdoc420 (RK2)";

		return $this->text->makeBlob("$name (QL $ql)", $blob);
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
		} elseif (strtolower($type) == "pristine") {
			$name = "Pristine Kyr'Ozch Bio-Material";
			$reqiredChem = (int)floor($ql * 4.5);
			$chemMsg = "4.5 * QL";
			$extraInfo = "(<highlight>less tradeskill requirements than mutated.<end>)";
		}
		//End of tradeskill processes

		$item = $this->itemsController->getItem($name, $ql);

		$blob = $item . "\n\n";
		$blob .= "It will take <highlight>$requiredEEandCL<end> EE & CL (<highlight>4.5 * QL<end>) to analyze the Bio-Material.\n\n";

		$blob .= "Used to build Alien Armor $extraInfo\n\n" .
			"<highlight>The following tradeskill amounts are required to make<end> QL $ql<highlight>\n" .
			"strong/arithmetic/enduring/spiritual/supple/observant armor:<end>\n\n" .
			"Computer Literacy - <highlight>$requiredCL<end> (<highlight>4.5 * QL<end>)\n" .
			"Chemistry - <highlight>$reqiredChem<end> (<highlight>$chemMsg<end>)\n" .
			"Nano Programming - <highlight>$requiredNP<end> (<highlight>6 * QL<end>)\n" .
			"Pharma Tech - <highlight>$requiredPharma<end> (<highlight>6 * QL<end>)\n" .
			"Psychology - <highlight>$requiredPsychology<end> (<highlight>6 * QL<end>)\n\n" .
			"Note:<highlight> Tradeskill requirements are based off the lowest QL items needed throughout the entire process.<end>";

		$blob .= "\n\nFor Supple, Arithmetic, or Enduring:\n\n" .
			"<highlight>When completed, the armor piece can have as low as<end> QL $minQL <highlight>combined into it, depending on available tradeskill options.\n\n" .
			"Does not change QL's, therefore takes<end> $requiredPsychology <highlight>Psychology for available combinations.<end>\n\n" .
			"For Spiritual, Strong, or Observant:\n\n" .
			"<highlight>When completed, the armor piece can combine upto<end> QL $maxQL<highlight>, depending on available tradeskill options.\n\n" .
			"Changes QL depending on targets QL. The max combination is: (<end>QL $maxQL<highlight>) (<end>$max_psyco Psychology required for this combination<highlight>)<end>";

		$blob .= "\n\nTradeskilling info added by Mdkdoc420 (RK2)";

		return $this->text->makeBlob("$name (QL $ql)", $blob);
	}

	/**
	 * Tells how much skills is required to analyze serum bio material and how
	 * much skills are needed to to build buildings from it.
	 */
	private function serumBio(int $ql): string {
		$name = "Kyr'Ozch Viral Serum";
		$item = $this->itemsController->getItem($name, $ql);

		$requiredPharma    = (int)floor($ql * 3.5);
		$requiredChemAndME = (int)floor($ql * 4);
		$requiredEE        = (int)floor($ql * 4.5);
		$requiredCL        = (int)floor($ql * 5);
		$requiredEEandCL   = (int)floor($ql * 4.5);

		$blob = $item . "\n\n";
		$blob .= "It will take <highlight>$requiredEEandCL<end> EE & CL (<highlight>4.5 * QL<end>) to analyze the Bio-Material.\n\n";

		$blob .= "<highlight>Used to build city buildings<end>\n\n" .
			"<highlight>The following are the required skills throughout the process of making a building:<end>\n\n" .
			"Quantum FT - <highlight>400<end> (<highlight>Static<end>)\nPharma Tech - ";

		//Used to change dialog between minimum and actual requirements, for requirements that go under 400
		if ($requiredPharma < 400) {
			$blob .= "<highlight>400<end>";
		} else {
			$blob .= "<highlight>$requiredPharma<end>";
		}

		$blob .= " (<highlight>3.5 * QL<end>) 400 is minimum requirement\nChemistry - ";

		if ($requiredChemAndME < 400) {
			$blob .= "<highlight>400<end>";
		} else {
			$blob .= "<highlight>$requiredChemAndME<end>";
		}

		$blob .= " (<highlight>4 * QL<end>) 400 is minimum requirement\n" .
			"Mechanical Engineering - <highlight>$requiredChemAndME<end> (<highlight>4 * QL<end>)\n" .
			"Electrical Engineering - <highlight>$requiredEE<end> (<highlight>4.5 * QL<end>)\n" .
			"Comp Liter - <highlight>$requiredCL<end> (<highlight>5 * QL<end>)";

		$blob .= "\n\nTradeskilling info added by Mdkdoc420 (RK2)";

		return $this->text->makeBlob("$name (QL $ql)", $blob);
	}
}
