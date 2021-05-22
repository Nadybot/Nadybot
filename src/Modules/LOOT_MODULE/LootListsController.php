<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Illuminate\Database\Query\JoinClause;
use Nadybot\Core\{
	AccessManager,
	CommandAlias,
	CommandManager,
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatLeaderController;

/**
 * @author Marinerecon (RK2)
 * @author Derroylo (RK2)
 * @author Tyrence (RK2)
 * @author Morgo (RK2)
 * @author Chachy (RK2)
 * @author Dare2005 (RK2)
 * @author Nadyita (RK5)
 *
 * based on code for dbloot module by Chachy (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'alb',
 *		accessLevel = 'all',
 *		description = 'Shows possible Albtraum loots',
 *		help        = 'albloot.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'db1',
 *		accessLevel = 'all',
 *		description = 'Shows possible DB1 Armor/NCUs/Programs',
 *		help        = 'dbloot.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'db2',
 *		accessLevel = 'all',
 *		description = 'Shows possible DB2 Armor',
 *		help        = 'dbloot.txt'
 *	)
 *	@DefineCommand(
 *		command     = '7',
 *		accessLevel = 'all',
 *		description = 'Shows the Sector 7 loot list',
 *		help        = 'apf.txt'
 *	)
 *	@DefineCommand(
 *		command     = '13',
 *		accessLevel = 'rl',
 *		description = 'Adds APF 13 loot to the loot list',
 *		help        = 'apf.txt'
 *	)
 *	@DefineCommand(
 *		command     = '28',
 *		accessLevel = 'rl',
 *		description = 'Adds APF 28 loot to the loot list',
 *		help        = 'apf.txt'
 *	)
 *	@DefineCommand(
 *		command     = '35',
 *		accessLevel = 'rl',
 *		description = 'Adds APF 35 loot to the loot list',
 *		help        = 'apf.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'apf',
 *		accessLevel = 'all',
 *		description = 'Shows what drops off APF Bosses',
 *		help        = 'apf.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'beast',
 *		accessLevel = 'all',
 *		description = 'Shows Beast loot',
 *		help        = 'pande.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'pande',
 *		accessLevel = 'all',
 *		description = 'Shows Pandemonium bosses and loot categories',
 *		help        = 'pande.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'vortexx',
 *		accessLevel = 'all',
 *		description = 'Shows possible Vortexx Loot',
 *		help        = 'xan.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'mitaar',
 *		accessLevel = 'all',
 *		description = 'Shows possible Mitaar Hero Loot',
 *		help        = 'xan.txt'
 *	)
 *	@DefineCommand(
 *		command     = '12m',
 *		accessLevel = 'all',
 *		description = 'Shows possible 12 man Loot',
 *		help        = 'xan.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'poh',
 *		accessLevel = 'all',
 *		description = 'Shows possible Pyramid of Home loot',
 *		help        = 'poh.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'totw',
 *		accessLevel = 'all',
 *		description = 'Shows possible TOTW 201+ loot',
 *		help        = 'totw.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'halloween',
 *		accessLevel = 'all',
 *		description = 'Shows possible Halloween loot',
 *		help        = 'halloween.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'subway',
 *		accessLevel = 'all',
 *		description = 'Shows possible Subway 201+',
 *		help        = 'subway.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'lox',
 *		accessLevel = 'all',
 *		description = 'Shows Legacy of the Xan loot categories',
 *		help        = 'xan.txt'
 *	)
 */
class LootListsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public LootController $lootController;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/raid_loot.csv");
		$this->settingManager->add(
			$this->moduleName,
			'show_raid_loot_pics',
			'Show pictures in loot lists',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0'
		);
		$this->commandAlias->register($this->moduleName, "12m", '12man');
		$this->commandAlias->register($this->moduleName, "12m", '12-man');
		$this->commandAlias->register($this->moduleName, "lox", 'xan');
		$this->commandAlias->register($this->moduleName, "pande Beast Armor", 'beastarmor');
		$this->commandAlias->register($this->moduleName, "pande Beast Weapons", 'beastweaps');
		$this->commandAlias->register($this->moduleName, "pande Beast Weapons", 'beastweapons');
		$this->commandAlias->register($this->moduleName, "pande Stars", 'beaststars');
		$this->commandAlias->register($this->moduleName, "pande The Night Heart", 'tnh');
		$this->commandAlias->register($this->moduleName, "pande Shadowbreeds", 'sb');
		$this->commandAlias->register($this->moduleName, "pande Aries", 'aries');
		$this->commandAlias->register($this->moduleName, "pande Leo", 'leo');
		$this->commandAlias->register($this->moduleName, "pande Virgo", 'virgo');
		$this->commandAlias->register($this->moduleName, "pande Aquarius", 'aquarius');
		$this->commandAlias->register($this->moduleName, "pande Cancer", 'cancer');
		$this->commandAlias->register($this->moduleName, "pande Gemini", 'gemini');
		$this->commandAlias->register($this->moduleName, "pande Libra", 'libra');
		$this->commandAlias->register($this->moduleName, "pande Pisces", 'pisces');
		$this->commandAlias->register($this->moduleName, "pande Taurus", 'taurus');
		$this->commandAlias->register($this->moduleName, "pande Capricorn", 'capricorn');
		$this->commandAlias->register($this->moduleName, "pande Sagittarius", 'sagittarius');
		$this->commandAlias->register($this->moduleName, "pande Scorpio", 'scorpio');
		$this->commandAlias->register($this->moduleName, "pande Bastion", 'bastion');
	}

	/**
	 * @author Dare2005 (RK2), based on code for dbloot module by
	 * @author Chachy (RK2)
	 *
	 * @HandlesCommand("alb")
	 * @Matches("/^alb$/i")
	 */
	public function albCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('Albtraum', 'Crystals & Crystalised Memories', $sender);
		$blob .= $this->findRaidLoot('Albtraum', 'Ancients', $sender);
		$blob .= $this->findRaidLoot('Albtraum', 'Samples', $sender);
		$blob .= $this->findRaidLoot('Albtraum', 'Rings and Preservation Units', $sender);
		$blob .= $this->findRaidLoot('Albtraum', 'Pocket Boss Crystals', $sender);
		$msg = $this->text->makeBlob("Albtraum Loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @author Chachy (RK2), based on code for Pande Loot Bot by Marinerecon (RK2)
	 *
	 * @HandlesCommand("db1")
	 * @Matches("/^db1$/i")
	 */
	public function db1Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('DustBrigade', 'Armor', $sender);
		$blob .= $this->findRaidLoot('DustBrigade', 'DB1', $sender);
		$msg = $this->text->makeBlob("DB1 Loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @author Chachy (RK2), based on code for Pande Loot Bot by Marinerecon (RK2)
	 *
	 * @HandlesCommand("db2")
	 * @Matches("/^db2$/i")
	 */
	public function db2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('DustBrigade', 'Armor', $sender);
		$blob .= $this->findRaidLoot('DustBrigade', 'DB2', $sender);
		$msg = $this->text->makeBlob("DB2 Loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("7")
	 * @Matches("/^7$/i")
	 */
	public function apf7Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$raid = "Sector 7";
		$blob = $this->findRaidLoot($raid, "Misc", $sender);
		$blob .= $this->findRaidLoot($raid, "NCU", $sender);
		$blob .= $this->findRaidLoot($raid, "Weapons", $sender);
		$blob .= $this->findRaidLoot($raid, "Viralbots", $sender);
		$msg = $this->text->makeBlob("$raid Loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("13")
	 * @Matches("/^13$/i")
	 */
	public function apf13Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList(13);
	}

	/**
	 * @HandlesCommand("28")
	 * @Matches("/^28$/i")
	 */
	public function apf28Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList(28);
	}

	/**
	 * @HandlesCommand("35")
	 * @Matches("/^35$/i")
	 */
	public function apf35Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList(35);
	}

	public function addAPFLootToList(int $sector): void {
		// adding apf stuff
		$this->lootController->addRaidToLootList('APF', "Sector $sector");
		$msg = "Sector $sector loot table was added to the loot list.";
		$this->chatBot->sendPrivate($msg);

		$msg = $this->lootController->getCurrentLootList();
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * @HandlesCommand("apf")
	 * @Matches("/^apf (7|13|28|35)$/i")
	 */
	public function apfCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sector = (int)$args[1];

		$itemlink["ICE"] = $this->text->makeItem(257968, 257968, 1, "Hacker ICE-Breaker Source");
		$itemlink["BOARD"] = $this->text->makeItem(257706, 257706, 1, "Kyr'Ozch Helmet");
		$itemlink["APE"] = $this->text->makeItem(257960, 257960, 250, "Action Probability Estimator");
		$itemlink["DGRV"] = $this->text->makeItem(257962, 257962, 250, "Dynamic Gas Redistribution Valves");
		$itemlink["KBAP"] = $this->text->makeItem(257529, 257529, 1, "Kyr'Ozch Battlesuit Audio Processor");
		$itemlink["KVPU"] = $this->text->makeItem(257533, 257533, 1, "Kyr'Ozch Video Processing Unit");
		$itemlink["KRI"] = $this->text->makeItem(257531, 257531, 1, "Kyr'Ozch Rank Identification");
		$itemlink["ICEU"] = $this->text->makeItem(257110, 257110, 1, "Intrusion Countermeasure Electronics Upgrade");
		$itemlink["OTAE"] = $this->text->makeItem(257112, 257112, 1, "Omni-Tek Award - Exemplar");
		$itemlink["CMP"] = $this->text->makeItem(257113, 257113, 1, "Clan Merits - Paragon");
		$itemlink["EMCH"] = $this->text->makeItem(257379, 257379, 200, "Extruder's Molybdenum Crash Helmet");
		$itemlink["CKCNH"] = $this->text->makeItem(257115, 257115, 200, "Conscientious Knight Commander Nizno's Helmet");
		$itemlink["SKCGH"] = $this->text->makeItem(257114, 257114, 200, "Sworn Knight Commander Genevra's Helmet");
		$itemlink["BCOH"] = $this->text->makeItem(257383, 257383, 300, "Blackmane's Combined Officer's Headwear");
		$itemlink["GCCH"] = $this->text->makeItem(257381, 257381, 300, "Gannondorf's Combined Commando's Headwear");
		$itemlink["HCSH"] = $this->text->makeItem(257384, 257384, 300, "Haitte's Combined Sharpshooter's Headwear");
		$itemlink["OCPH"] = $this->text->makeItem(257377, 257377, 300, "Odum's Combined Paramedic's Headwear");
		$itemlink["SCMH"] = $this->text->makeItem(257380, 257380, 300, "Sillum's Combined Mercenary's Headwear");
		$itemlink["YCSH"] = $this->text->makeItem(257382, 257382, 300, "Yakomo's Combined Scout's Headwear");
		$itemlink["HLOA"] = $this->text->makeItem(257128, 257128, 300, "High Lord of Angst");
		$itemlink["SKR2"] = $this->text->makeItem(257967, 257967, 300, "Silenced Kyr'Ozch Rifle - Type 2");
		$itemlink["SKR3"] = $this->text->makeItem(257131, 257131, 300, "Silenced Kyr'Ozch Rifle - Type 3");
		$itemlink["ASC"] = $this->text->makeItem(257126, 257126, 300, "Amplified Sleek Cannon");
		$itemlink["IAPU"] = $this->text->makeItem(257959, 257959, 1, "Inertial Adjustment Processing Unit");
		$itemlink["HVBCP"] = $this->text->makeItem(257119, 257119, 300, "Hadrulf's Viral Belt Component Platform");
		$itemlink["NAC"] = $this->text->makeItem(257963, 257963, 250, "Notum Amplification Coil");
		$itemlink["TAHSC"] = $this->text->makeItem(257124, 257124, 300, "Twice Augmented Hellspinner Shock Cannon");
		$itemlink["ONC"] = $this->text->makeItem(257118, 257118, 250, "ObiTom's Nano Calculator");
		$itemlink["AKC12"] = $this->text->makeItem(257143, 257143, 300, "Amplified Kyr'Ozch Carbine - Type 12");
		$itemlink["AKC13"] = $this->text->makeItem(257142, 257142, 300, "Amplified Kyr'Ozch Carbine - Type 13");
		$itemlink["AKC5"] = $this->text->makeItem(257144, 257144, 300, "Amplified Kyr'Ozch Carbine - Type 5");
		$itemlink["ERU"] = $this->text->makeItem(257961, 257961, 250, "Energy Redistribution Unit");
		$itemlink["BOB"] = $this->text->makeItem(257147, 257147, 300, "Blades of Boltar");
		$itemlink["DVLPR"] = $this->text->makeItem(257116, 257116, 1, "De'Valos Lava Protection Ring");
		$itemlink["VLRD"] = $this->text->makeItem(257964, 257964, 250, "Visible Light Remodulation Device");
		$itemlink["DVRPR"] = $this->text->makeItem(257117, 257117, 1, "De'Valos Radiation Protection Ring");
		$itemlink["SSSS"] = $this->text->makeItem(257141, 257141, 300, "Scoped Salabim Shotgun Supremo");
		$itemlink["EPP"] = $this->text->makeItem(258345, 258345, 300, "Explosif's Polychromatic Pillows");
		$itemlink["VNGW"] = $this->text->makeItem(257123, 257123, 300, "Vektor ND Grand Wyrm");
		$list = '';

		switch ($sector) {
			case "7":
				$this->apf7Command($message, $channel, $sender, $sendto, $args);
				return;
			case "13":
				//CRU
				$list .= $this->text->makeImage(257196) . "\n";
				$list .= "Name: {$itemlink["ICE"]}\n";
				$list .= "Purpose: {$itemlink["ICEU"]}\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield 5 times from the Boss.<end>\n\n";

				//Token Credit Items
				$list .= $this->text->makeImage(218775) . "\n";
				$list .= "Name: {$itemlink["KBAP"]}\n";
				$list .= $this->text->makeImage(218758) . "\n";
				$list .= "Name: {$itemlink["KVPU"]}\n";
				$list .= $this->text->makeImage(218768) . "\n";
				$list .= "Name: {$itemlink["KRI"]}\n";
				$list .= "Purpose: ".
					"<highlight>Kyr'Ozch Rank Identification, ".
					"Kyr'Ozch Video Processing Unit ".
					"and Kyr'Ozch Battlesuit Audio Processor ".
					"can be traded at your faction vendor at the Alien Playfield Bar ".
					"for Tokens or Credits.<end>\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss (one from each type).<end>\n\n";

				//Token Board
				$list .= $this->text->makeImage(230855) . "\n";
				$list .= "Name: {$itemlink["BOARD"]}\n";
				$list .= "Purpose: - {$itemlink["OTAE"]}\n";
				$list .= "<tab><tab>     - {$itemlink["CMP"]}\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss.<end>\n\n";

				//Action Probability Estimator
				$list .= $this->text->makeImage(203502) . "\n";
				$list .= "Name: {$itemlink["APE"]}\n";
				$list .= "Purpose: - {$itemlink["EMCH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["CKCNH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["SKCGH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["BCOH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["GCCH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["HCSH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["OCPH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["SCMH"]}\n";
				$list .= "<tab><tab>     - {$itemlink["YCSH"]}\n\n";

				//Dynamic Gas Redistribution Valves
				$list .= $this->text->makeImage(205508) . "\n";
				$list .= "Name: {$itemlink["DGRV"]}\n";
				$list .= "Purpose: - {$itemlink["HLOA"]}\n";
				$list .= "<tab><tab>     - {$itemlink["SKR2"]}\n";
				$list .= "<tab><tab>     - {$itemlink["SKR3"]}\n";
				$list .= "<tab><tab>     - {$itemlink["ASC"]}\n\n";
				break;
			case "28":
				//CRU
				$list .= $this->text->makeImage(257196) . "\n";
				$list .= "Name: {$itemlink["ICE"]}\n";
				$list .= "Purpose: {$itemlink["ICEU"]}\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield 5 times from the Boss.<end>\n\n";

				//Token Credit Items
				$list .= $this->text->makeImage(218775) . "\n";
				$list .= "Name: {$itemlink["KBAP"]}\n";
				$list .= $this->text->makeImage(218758) . "\n";
				$list .= "Name: {$itemlink["KVPU"]}\n";
				$list .= $this->text->makeImage(218768) . "\n";
				$list .= "Name: {$itemlink["KRI"]}\n";
				$list .= "Purpose: ".
					"<highlight>Kyr'Ozch Rank Identification, ".
					"Kyr'Ozch Video Processing Unit ".
					"and Kyr'Ozch Battlesuit Audio Processor ".
					"can be traded at your faction vendor at the Alien Playfield Bar ".
					"for Tokens or Credits.<end>\n";
				$list .= "Note: <highlight>Drops on all Alien Playfields from the Boss (one from each type).<end>\n\n";

				//Token Board
				$list .= $this->text->makeImage(230855) . "\n";
				$list .= "Name: {$itemlink["BOARD"]}\n";
				$list .= "Purpose: - {$itemlink["OTAE"]}\n";
				$list .= "<tab><tab>     - {$itemlink["CMP"]}\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss.<end>\n\n";

				//APF Belt
				$list .= $this->text->makeImage(11618) . "\n";
				$list .= "Name: {$itemlink["IAPU"]}\n";
				$list .= "Purpose: - {$itemlink["HVBCP"]}\n\n";

				//Notum coil
				$list .= $this->text->makeImage(257195) . "\n";
				$list .= "Name: {$itemlink["NAC"]}\n";
				$list .= "Purpose: - {$itemlink["TAHSC"]}\n";
				$list .= "<tab><tab>     - {$itemlink["ONC"]}\n";
				$list .= "<tab><tab>     - {$itemlink["AKC12"]}\n";
				$list .= "<tab><tab>     - {$itemlink["AKC13"]}\n";
				$list .= "<tab><tab>     - {$itemlink["AKC5"]}\n\n";
				break;
			case "35":
				//CRU
				$list .= $this->text->makeImage(257196) . "\n";
				$list .= "Name: {$itemlink["ICE"]}\n";
				$list .= "Purpose: {$itemlink["ICEU"]}\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield 5 times from the Boss.<end>\n\n";

				//Token Credit Items
				$list .= $this->text->makeImage(218775) . "\n";
				$list .= "Name: {$itemlink["KBAP"]}\n";
				$list .= $this->text->makeImage(218758) . "\n";
				$list .= "Name: {$itemlink["KVPU"]}\n";
				$list .= $this->text->makeImage(218768) . "\n";
				$list .= "Name: {$itemlink["KRI"]}\n";
				$list .= "Purpose: ".
					"<highlight>Kyr'Ozch Rank Identification, ".
					"Kyr'Ozch Video Processing Unit ".
					"and Kyr'Ozch Battlesuit Audio Processor ".
					"can be traded at your faction vendor at the Alien Playfield Bar ".
					"for Tokens or Credits.<end>\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss (one from each type).<end>\n\n";

				//Token Board
				$list .= $this->text->makeImage(230855) . "\n";
				$list .= "Name:{$itemlink["BOARD"]}\n";
				$list .= "Purpose: - {$itemlink["OTAE"]}\n";
				$list .= "<tab><tab>     - {$itemlink["CMP"]}\n";
				$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss.<end>\n\n";

				//Energy Redistribution Unit
				$list .= $this->text->makeImage(257197) . "\n";
				$list .= "Name: {$itemlink["ERU"]}\n";
				$list .= "Purpose: - {$itemlink["BOB"]}\n";
				$list .= "<tab><tab>     - {$itemlink["DVLPR"]}\n";
				$list .= "<tab><tab>     - {$itemlink["VNGW"]}\n\n";

				//Visible Light Remodulation Device
				$list .= $this->text->makeImage(235270) . "\n";
				$list .= "Name: {$itemlink["VLRD"]}\n";
				$list .= "Purpose: - {$itemlink["DVRPR"]}\n";
				$list .= "<tab><tab>     - {$itemlink["SSSS"]}\n";
				$list .= "<tab><tab>     - {$itemlink["EPP"]}\n\n";
				break;
		}

		$msg = $this->text->makeBlob("Loot table for sector $sector", $list);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("beast")
	 * @Matches("/^beast$/i")
	 */
	public function beastCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('Pande', 'Beast Armor', $sender);
		$blob .= $this->findRaidLoot('Pande', 'Beast Weapons', $sender);
		$blob .= $this->findRaidLoot('Pande', 'Stars', $sender);
		$blob .= $this->findRaidLoot('Pande', 'Shadowbreeds', $sender);
		$msg = $this->text->makeBlob("Beast Loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @author Nadyita (RK5)
	 *
	 * @HandlesCommand("pande")
	 * @Matches("/^pande (.+)$/i")
	 */
	public function pandeSubCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getPandemoniumLoot('Pande', $args[1], $sender);
		if (empty($msg)) {
			$sendto->reply("No loot found for <highlight>{$args[1]}<end>.");
			return;
		}
		$sendto->reply($msg);
	}

	/**
	 * @return string|string[]|null
	 */
	public function getPandemoniumLoot(string $raid, string $category, string $sender) {
		$category = ucwords(strtolower($category));
		$blob = $this->findRaidLoot($raid, $category, $sender);
		if (empty($blob)) {
			return null;
		}
		$blob .= "\n\nPande Loot By Marinerecon (RK2)";
		return $this->text->makeBlob("$raid \"$category\" Loot", $blob);
	}

	/**
	 * @author Marinerecon (RK2)
	 *
	 * @HandlesCommand("pande")
	 * @Matches("/^pande$/i")
	 */
	public function pandeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$list  = "<header2>The Beast<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("All Beast Loot (long)\n", "/tell <myname> beast");
		$list .= "<tab>".$this->text->makeChatcmd("Beast Armor\n", "/tell <myname> beastarmor");
		$list .= "<tab>".$this->text->makeChatcmd("Beast Weapons\n", "/tell <myname> beastweaps");
		$list .= "<tab>".$this->text->makeChatcmd("Beast Stars\n", "/tell <myname> beaststars");
		$list .= "\n<header2>The Night Heart<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("TNH\n", "/tell <myname> tnh");
		$list .= "\n<header2>West Zodiacs<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("Aries\n", "/tell <myname> aries");
		$list .= "<tab>".$this->text->makeChatcmd("Leo\n", "/tell <myname> leo");
		$list .= "<tab>".$this->text->makeChatcmd("Virgo\n", "/tell <myname> virgo");
		$list .= "\n<header2>East Zodiacs<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("Aquarius\n", "/tell <myname> aquarius");
		$list .= "<tab>".$this->text->makeChatcmd("Cancer\n", "/tell <myname> cancer");
		$list .= "<tab>".$this->text->makeChatcmd("Gemini\n", "/tell <myname> gemini");
		$list .= "\n<header2>Middle Zodiacs<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("Libra\n", "/tell <myname> libra");
		$list .= "<tab>".$this->text->makeChatcmd("Pisces\n", "/tell <myname> pisces");
		$list .= "<tab>".$this->text->makeChatcmd("Taurus\n", "/tell <myname> taurus");
		$list .= "\n<header2>North Zodiacs<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("Capricorn\n", "/tell <myname> capricorn");
		$list .= "<tab>".$this->text->makeChatcmd("Sagittarius\n", "/tell <myname> sagittarius");
		$list .= "<tab>".$this->text->makeChatcmd("Scorpio\n", "/tell <myname> scorpio");
		$list .= "\n<header2>Other<end>\n";
		$list .= "<tab>".$this->text->makeChatcmd("Shadowbreeds\n", "/tell <myname> sb");
		$list .= "<tab>".$this->text->makeChatcmd("Bastion\n", "/tell <myname> bastion");

		$list .= "\n\nPandemonium Loot By Marinerecon (RK2)";

		$msg = $this->text->makeBlob("Pandemonium Loot", $list);
		$sendto->reply($msg);
	}

	/**
	 * @author Morgo (RK2)
	 *
	 * @HandlesCommand("vortexx")
	 * @Matches("/^vortexx$/i")
	 */
	public function xanVortexxCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('Vortexx', 'General', $sender);
		$blob .= $this->findRaidLoot('Vortexx', 'Symbiants', $sender);
		$blob .= $this->findRaidLoot('Vortexx', 'Spirits', $sender);
		$msg = $this->text->makeBlob("Vortexx loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @author Morgo (RK2)
	 *
	 * @HandlesCommand("mitaar")
	 * @Matches("/^mitaar$/i")
	 */
	public function xanMitaarCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('Mitaar', 'General', $sender);
		$blob .= $this->findRaidLoot('Mitaar', 'Symbiants', $sender);
		$blob .= $this->findRaidLoot('Mitaar', 'Spirits', $sender);
		$msg = $this->text->makeBlob("Mitaar loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @author Morgo (RK2)
	 *
	 * @HandlesCommand("12m")
	 * @Matches("/^12m$/i")
	 */
	public function xan12mCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('12Man', 'General', $sender);
		$blob .= $this->findRaidLoot('12Man', 'Symbiants', $sender);
		$blob .= $this->findRaidLoot('12Man', 'Spirits', $sender);
		$blob .= $this->findRaidLoot('12Man', 'Profession Gems', $sender);
		$msg = $this->text->makeBlob("12-Man loot", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("poh")
	 * @Matches("/^poh$/i")
	 */
	public function pohCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('Pyramid of Home', 'General', $sender);
		$blob .= $this->findRaidLoot('Pyramid of Home', 'HUD/NCU', $sender);
		$blob .= $this->findRaidLoot('Pyramid of Home', 'Weapons', $sender);
		$msg = $this->text->makeBlob("Pyramid of Home Loot", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("totw")
	 * @Matches("/^totw$/i")
	 */
	public function totwCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = $this->findRaidLoot('Temple of the Three Winds', 'Armor', $sender);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Symbiants', $sender);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Misc', $sender);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'NCU', $sender);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Weapons', $sender);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Rings', $sender);
		$msg = $this->text->makeBlob("Temple of the Three Winds Loot", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("subway")
	 * @Matches("/^subway$/i")
	 */
	public function subwayCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob  = $this->findRaidLoot('Subway', 'Armor', $sender);
		$blob .= $this->findRaidLoot('Subway', 'Weapons', $sender);
		$blob .= $this->findRaidLoot('Subway', 'Belt', $sender);
		$blob .= $this->findRaidLoot('Subway', 'Rings', $sender);
		$blob .= $this->findRaidLoot('Subway', 'HUD/Utils', $sender);
		$msg = $this->text->makeBlob("Subway Loot", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("halloween")
	 * @Matches("/^halloween$/i")
	 */
	public function halloweenCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$guph = "Griefing Uncle Pumpkin-Heads can be found at the following locations:\n".
			"<tab>- Level <black>0<end>10-<black>0<end>50: ".
			$this->text->makeChatcmd("Holes in the Wall", "/waypoint 504 306 791") . "\n".
			"<tab>- Level <black>0<end>50-100: ".
			$this->text->makeChatcmd("Stret West Bank", "/waypoint 1411 1428 790") . "\n".
			"<tab>- Level 100-150: ".
			$this->text->makeChatcmd("2HO east of the grid", "/waypoint 850 1460 635") . "\n".
			"<tab>- Level 125-180: ".
			$this->text->makeChatcmd("4 Holes at the ferry", "/waypoint 1960 942 760") . "\n".
			"<tab>- Level 150-200: ".
			$this->text->makeChatcmd("Upper Stret East Bank", "/waypoint 1770 2391 650") . "\n".
			"<tab>- Level 200-250: ".
			$this->text->makeChatcmd("Broken Shores along the river", "/waypoint 1266 1889 665") . "\n".
			"<tab>- Level <black>00<end>1-300: Notum Mining Area\n";
		$blob = preg_replace("/(<header2>.*?<end>\n)/", "$1\n$guph", $this->findRaidLoot('Halloween', 'Griefing Uncle Pumpkin-Head', $sender));
		$blob .= "\n<header2>Ganking Uncle Pumpkin-Head<end>\n\n".
			"They drop the same loot as the GUPHs, but have a higher chance to drop the rare items.\n";
		$huph = "They are only spawned by ARKs on Halloween events ".
			"and cannot be found anywhere else.\n";
		$blob .= preg_replace("/(<header2>.*?<end>\n)/", "$1\n$huph", $this->findRaidLoot('Halloween', 'Harvesting Uncle Pumpkin-Head', $sender));
		$blob .= $this->findRaidLoot('Halloween', 'Solo Instance', $sender);
		$msg = $this->text->makeBlob("Halloween loot", $blob);
		$sendto->reply($msg);
	}

	public function findRaidLoot(string $raid, string $category, string $sender): ?string {
		$query2 = $this->db->table("raid_loot AS r")
					->join("aodb AS a", "r.aoid", "a.highid")
					->whereNotNull("r.aoid")
					->whereIlike("r.raid", $raid)
					->whereIlike("r.category", $category);
		$query2->select("*", $query2->colFunc("COALESCE", ["a.name", "r.name"], "name"));
		$query = $this->db->table("raid_loot AS r")
			->leftJoin("aodb AS a", function (JoinClause $join) {
				$join->on("r.name", "a.name")
					->on("r.ql", ">=", "a.lowql")
					->on("r.ql", "<=", "a.highql");
			})
			->whereNull("r.aoid")
			->whereIlike("r.raid", $raid)
			->whereIlike("r.category", $category)
			->union($query2);
		$query->select("*", $query2->colFunc("COALESCE", ["a.name", "r.name"], "name"));
		$data = $query->asObj();

		if ($data->count() === 0) {
			return null;
		}
		$auctionsEnabled = true;
		$auctionCommands = $this->commandManager->get('auction', 'msg');
		// If the command is not available to the sender, don't render reminder-links
		if (empty($auctionCommands)
			|| !$auctionCommands[0]->status
			|| !$this->accessManager->checkAccess($sender, $auctionCommands[0]->admin)
		) {
			$auctionsEnabled = false;
		}
		$lootEnabled = $this->commandManager->isCommandActive('loot .+', 'msg');

		$blob = "\n<pagebreak><header2>{$category}<end>\n\n";
		$showLootPics = $this->settingManager->get('show_raid_loot_pics');
		foreach ($data as $row) {
			$actions = [];
			if ($lootEnabled) {
				$actions []= $this->text->makeChatcmd(
					"Loot",
					"/tell <myname> loot add $row->id"
				);
			}
			if ($lootEnabled && $auctionsEnabled) {
				$actions []= $this->text->makeChatcmd(
					"Auction",
					"/tell <myname> auction {$row->name}"
				);
			}
			if ($row->lowid) {
				if ($showLootPics) {
					$name = "<img src=rdb://{$row->icon}>";
				} else {
					$name = $row->name;
					if (count($actions)) {
						$blob .= "[" . join("] [", $actions) . "] - ";
					}
				}
				$blob .= $this->text->makeItem($row->lowid, $row->highid, $row->ql, $name);
			} else {
				if (count($actions)) {
					$blob .= "[" . join("] [", $actions) . "] - ";
				}
				$blob .= "<highlight>{$row->name}<end>";
			}
			if ($showLootPics && $row->lowid) {
				$blob .= "\n<highlight>{$row->name}<end>";
			}
			if ($row->multiloot > 1) {
				$blob .= " x" . $row->multiloot;
			}
			if (!empty($row->comment)) {
				$blob .= " ($row->comment)";
			}
			if ($showLootPics) {
				$blob .= "\n";
				$blob .= $this->text->makeChatcmd("To Loot", "/tell <myname> loot add $row->id");
				$blob .= "\n";
			}
			$blob .= "\n";
		}

		return $blob;
	}

	/**
	 * @author Nadyita
	 *
	 * @HandlesCommand("lox")
	 * @Matches("/^lox$/i")
	 */
	public function loxCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$list  = $this->text->makeChatcmd("Ground Chief Vortexx\n", "/tell <myname> vortexx");
		$list .= "<tab>- Eye\n";
		$list .= "<tab>- Left Arm\n";
		$list .= "<tab>- Right Wrist\n";
		$list .= "<tab>- Waist\n\n";
		$list .= $this->text->makeChatcmd("The Xan (aka 12-man)\n", "/tell <myname> 12m");
		$list .= "<tab>- Ear\n";
		$list .= "<tab>- Right Arm\n";
		$list .= "<tab>- Right Hand\n";
		$list .= "<tab>- Thigh\n";
		$list .= "<tab>- Feet\n\n";
		$list .= $this->text->makeChatcmd("The Alien Threat (aka Mitaar)\n", "/tell <myname> mitaar");
		$list .= "<tab>- Brain\n";
		$list .= "<tab>- Chest\n";
		$list .= "<tab>- Left Wrist\n";
		$list .= "<tab>- Left Hand\n";
		$msg = $this->text->makeBlob("LoX Hub Loot", $list);
		$sendto->reply($msg);
	}
}
