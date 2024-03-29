<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	ModuleInstance,
	Nadybot,
	Text,
	Util,
};
use Nadybot\Modules\RAFFLE_MODULE\RaffleController;
use Nadybot\Modules\RAID_MODULE\AuctionController;
use Nadybot\Modules\{
	BASIC_CHAT_MODULE\ChatLeaderController,
	ITEMS_MODULE\ItemsController,
};

/**
 * @author Marinerecon (RK2)
 * @author Derroylo (RK2)
 * @author Tyrence (RK2)
 * @author Morgo (RK2)
 * @author Chachy (RK2)
 * @author Dare2005 (RK2)
 * @author Nadyita (RK5)
 * based on code for dbloot module by Chachy (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "alb",
		accessLevel: "guest",
		description: "Shows possible Albtraum loots",
	),
	NCA\DefineCommand(
		command: "db1",
		accessLevel: "guest",
		description: "Shows possible DB1 Armor/NCUs/Programs",
	),
	NCA\DefineCommand(
		command: "db2",
		accessLevel: "guest",
		description: "Shows possible DB2 Armor",
	),
	NCA\DefineCommand(
		command: "db3",
		accessLevel: "guest",
		description: "Shows possible DB3 Loot",
	),
	NCA\DefineCommand(
		command: "7",
		accessLevel: "guest",
		description: "Shows the Sector 7 loot list",
	),
	NCA\DefineCommand(
		command: "13",
		accessLevel: "rl",
		description: "Adds APF 13 loot to the loot list",
	),
	NCA\DefineCommand(
		command: "28",
		accessLevel: "rl",
		description: "Adds APF 28 loot to the loot list",
	),
	NCA\DefineCommand(
		command: "35",
		accessLevel: "rl",
		description: "Adds APF 35 loot to the loot list",
	),
	NCA\DefineCommand(
		command: "42",
		accessLevel: "rl",
		description: "Adds APF 42 loot to the loot list",
	),
	NCA\DefineCommand(
		command: "apf",
		accessLevel: "guest",
		description: "Shows what drops off APF Bosses",
	),
	NCA\DefineCommand(
		command: "beast",
		accessLevel: "guest",
		description: "Shows Beast loot",
	),
	NCA\DefineCommand(
		command: "pande",
		accessLevel: "guest",
		description: "Shows Pandemonium bosses and loot categories",
	),
	NCA\DefineCommand(
		command: "vortexx",
		accessLevel: "guest",
		description: "Shows possible Vortexx Loot",
	),
	NCA\DefineCommand(
		command: "mitaar",
		accessLevel: "guest",
		description: "Shows possible Mitaar Hero Loot",
	),
	NCA\DefineCommand(
		command: "12m",
		accessLevel: "guest",
		description: "Shows possible 12 man Loot",
		alias: ['12man', '12-man'],
	),
	NCA\DefineCommand(
		command: "poh",
		accessLevel: "guest",
		description: "Shows possible Pyramid of Home loot",
	),
	NCA\DefineCommand(
		command: "totw",
		accessLevel: "guest",
		description: "Shows possible TOTW 201+ loot",
	),
	NCA\DefineCommand(
		command: "halloween",
		accessLevel: "guest",
		description: "Shows possible Halloween loot",
	),
	NCA\DefineCommand(
		command: "subway",
		accessLevel: "guest",
		description: "Shows possible Subway 201+ loot",
	),
	NCA\DefineCommand(
		command: "lox",
		accessLevel: "guest",
		description: "Shows Legacy of the Xan loot categories",
		alias: 'xan',
	),
]
class LootListsController extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public LootController $lootController;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public ChatLeaderController $chatLeaderController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public CommandManager $commandManager;

	/** Show pictures in loot lists */
	#[NCA\Setting\Boolean]
	public bool $showRaidLootPics = false;

	/** Show "loot add" links */
	#[NCA\Setting\Boolean]
	public bool $showLootLootLinks = true;

	/** Show auction links */
	#[NCA\Setting\Boolean]
	public bool $showLootAuctionLinks = true;

	/** Show raffle links */
	#[NCA\Setting\Boolean]
	public bool $showLootRaffleLinks = true;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/raid_loot.csv");
		$aliases = [
			'beastarmor' => "pande Beast Armor",
			'beastweaps' => "pande Beast Weapons",
			'beastweapons' => "pande Beast Weapons",
			'beaststars' => "pande Stars",
			'tnh' => "pande The Night Heart",
			'sb' => "pande Shadowbreeds",
			'aries' => "pande Aries",
			'leo' => "pande Leo",
			'virgo' => "pande Virgo",
			'aquarius' => "pande Aquarius",
			'cancer' => "pande Cancer",
			'gemini' => "pande Gemini",
			'libra' => "pande Libra",
			'pisces' => "pande Pisces",
			'taurus' => "pande Taurus",
			'capricorn' => "pande Capricorn",
			'sagittarius' => "pande Sagittarius",
			'scorpio' => "pande Scorpio",
			'bastion' => "pande Bastion",
		];
		foreach ($aliases as $alias => $command) {
			$this->commandAlias->register($this->moduleName, $command, $alias);
		}
	}

	/**
	 * View the Albtraum loot list
	 *
	 * @author Dare2005 (RK2), based on code for dbloot module by
	 * @author Chachy (RK2)
	 */
	#[NCA\HandlesCommand("alb")]
	public function albCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('Albtraum', 'Crystals & Crystallised Memories', $context);
		$blob .= $this->findRaidLoot('Albtraum', 'Ancients', $context);
		$blob .= $this->findRaidLoot('Albtraum', 'Samples', $context);
		$blob .= $this->findRaidLoot('Albtraum', 'Rings and Preservation Units', $context);
		$blob .= $this->findRaidLoot('Albtraum', 'Pocket Boss Crystals', $context);
		$msg = $this->text->makeBlob("Albtraum Loot", $blob);
		$context->reply($msg);
	}

	/**
	 * View the Dust Brigade 1 (Old DB/Twister) loot list
	 *
	 * @author Chachy (RK2), based on code for Pande Loot Bot by Marinerecon (RK2)
	 */
	#[NCA\HandlesCommand("db1")]
	#[NCA\Help\Group("loot-db")]
	public function db1Command(CmdContext $context): void {
		$blob = $this->findRaidLoot('DustBrigade', 'Armor', $context);
		$blob .= $this->findRaidLoot('DustBrigade', 'DB1', $context);
		$msg = $this->text->makeBlob("DB1 Loot", $blob);
		$context->reply($msg);
	}

	/**
	 * View the Dust Brigade 2 (New DB/Inside the Machine) loot list
	 *
	 * @author Chachy (RK2), based on code for Pande Loot Bot by Marinerecon (RK2)
	 */
	#[NCA\HandlesCommand("db2")]
	#[NCA\Help\Group("loot-db")]
	public function db2Command(CmdContext $context): void {
		$blob = $this->findRaidLoot('DustBrigade', 'Armor', $context);
		$blob .= $this->findRaidLoot('DustBrigade', 'DB2', $context);
		$msg = $this->text->makeBlob("DB2 Loot", $blob);
		$context->reply($msg);
	}

	/**
	 * View the Dust Brigade 3 (The Facility) loot lists
	 *
	 * @author Nadyita (RK5)
	 */
	#[NCA\HandlesCommand("db3")]
	#[NCA\Help\Group("loot-db")]
	public function db3Command(CmdContext $context): void {
		$blob = $this->findRaidLoot('DustBrigade', 'DB3', $context);
		$msg = $this->text->makeBlob("DB3 Loot", $blob);
		$context->reply($msg);
	}

	/** Show the loot list for Sector 7 */
	#[NCA\HandlesCommand("7")]
	#[NCA\Help\Group("loot-apf")]
	public function apf7Command(CmdContext $context): void {
		$raid = "Sector 7";
		$blob = $this->findRaidLoot($raid, "Misc", $context);
		$blob .= $this->findRaidLoot($raid, "NCU", $context);
		$blob .= $this->findRaidLoot($raid, "Weapons", $context);
		$blob .= $this->findRaidLoot($raid, "Viralbots", $context);
		$msg = $this->text->makeBlob("{$raid} Loot", $blob);
		$context->reply($msg);
	}

	/** Add all loot from Sector 13 to the loot list */
	#[NCA\HandlesCommand("13")]
	#[NCA\Help\Group("loot-apf")]
	public function apf13Command(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList($context->char->name, 13);
	}

	/** Add all loot from Sector 28 to the loot list */
	#[NCA\HandlesCommand("28")]
	#[NCA\Help\Group("loot-apf")]
	public function apf28Command(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList($context->char->name, 28);
	}

	/** Add all loot from Sector 35 to the loot list */
	#[NCA\HandlesCommand("35")]
	#[NCA\Help\Group("loot-apf")]
	public function apf35Command(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList($context->char->name, 35);
	}

	/** Add all loot from Sector 42 to the loot list */
	#[NCA\HandlesCommand("42")]
	#[NCA\Help\Group("loot-apf")]
	public function apf42Command(
		CmdContext $context,
		#[NCA\StrChoice("west", "north", "east", "boss")] string $side,
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addAPFLootToList($context->char->name, 42, ucfirst(strtolower($side)));
	}

	public function addAPFLootToList(string $addedBy, int $sector, ?string $side=null): void {
		$sectorKey = "Sector {$sector}";
		if (isset($side)) {
			$sectorKey .= " {$side}";
		}
		// adding apf stuff
		$this->lootController->addRaidToLootList($addedBy, 'APF', $sectorKey);
		$msg = "Sector {$sectorKey} loot table was added to the loot list.";
		$this->chatBot->sendPrivate($msg);

		$msg = $this->lootController->getCurrentLootList();
		$this->chatBot->sendPrivate($msg);
	}

	/** Show the loot list for Sector 7 */
	#[NCA\HandlesCommand("apf")]
	#[NCA\Help\Group("loot-apf")]
	public function apfSevenCommand(CmdContext $context, #[NCA\Str("7")] string $sector): void {
		$this->apf7Command($context);
	}

	/** Show the loot list for Sector 13 */
	#[NCA\HandlesCommand("apf")]
	#[NCA\Help\Group("loot-apf")]
	public function apfThirteenCommand(CmdContext $context, #[NCA\Str("13")] string $sector): void {
		$itemlink = $this->getApfItems();
		$list = '';
		// CRU
		$list .= $this->text->makeImage(257196) . "\n";
		$list .= "Name: {$itemlink["ICE"]}\n";
		$list .= "Purpose: {$itemlink["ICEU"]}\n";
		$list .= "Note: <highlight>Drops on all Alien Playfield 5 times from the Boss.<end>\n\n";

		// Token Credit Items
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

		// Token Board
		$list .= $this->text->makeImage(230855) . "\n";
		$list .= "Name: {$itemlink["BOARD"]}\n";
		$list .= "Purpose: - {$itemlink["OTAE"]}\n";
		$list .= "<tab><tab>     - {$itemlink["CMP"]}\n";
		$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss.<end>\n\n";

		// Action Probability Estimator
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

		// Dynamic Gas Redistribution Valves
		$list .= $this->text->makeImage(205508) . "\n";
		$list .= "Name: {$itemlink["DGRV"]}\n";
		$list .= "Purpose: - {$itemlink["HLOA"]}\n";
		$list .= "<tab><tab>     - {$itemlink["SKR2"]}\n";
		$list .= "<tab><tab>     - {$itemlink["SKR3"]}\n";
		$list .= "<tab><tab>     - {$itemlink["ASC"]}\n\n";

		$msg = $this->text->makeBlob("Loot table for sector {$sector}", $list);

		$context->reply($msg);
	}

	/** Show the loot list for Sector 28 */
	#[NCA\HandlesCommand("apf")]
	#[NCA\Help\Group("loot-apf")]
	public function apfTwentyEightCommand(CmdContext $context, #[NCA\Str("28")] string $sector): void {
		$itemlink = $this->getApfItems();
		$list = '';
		// CRU
		$list .= $this->text->makeImage(257196) . "\n";
		$list .= "Name: {$itemlink["ICE"]}\n";
		$list .= "Purpose: {$itemlink["ICEU"]}\n";
		$list .= "Note: <highlight>Drops on all Alien Playfield 5 times from the Boss.<end>\n\n";

		// Token Credit Items
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

		// Token Board
		$list .= $this->text->makeImage(230855) . "\n";
		$list .= "Name: {$itemlink["BOARD"]}\n";
		$list .= "Purpose: - {$itemlink["OTAE"]}\n";
		$list .= "<tab><tab>     - {$itemlink["CMP"]}\n";
		$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss.<end>\n\n";

		// APF Belt
		$list .= $this->text->makeImage(11618) . "\n";
		$list .= "Name: {$itemlink["IAPU"]}\n";
		$list .= "Purpose: - {$itemlink["HVBCP"]}\n\n";

		// Notum coil
		$list .= $this->text->makeImage(257195) . "\n";
		$list .= "Name: {$itemlink["NAC"]}\n";
		$list .= "Purpose: - {$itemlink["TAHSC"]}\n";
		$list .= "<tab><tab>     - {$itemlink["ONC"]}\n";
		$list .= "<tab><tab>     - {$itemlink["AKC12"]}\n";
		$list .= "<tab><tab>     - {$itemlink["AKC13"]}\n";
		$list .= "<tab><tab>     - {$itemlink["AKC5"]}\n\n";

		$msg = $this->text->makeBlob("Loot table for sector {$sector}", $list);

		$context->reply($msg);
	}

	/** Show the loot list for Sector 35 */
	#[NCA\HandlesCommand("apf")]
	#[NCA\Help\Group("loot-apf")]
	public function apfThirtyFiveCommand(CmdContext $context, #[NCA\Str("35")] string $sector): void {
		$itemlink = $this->getApfItems();
		$list = '';

		// CRU
		$list .= $this->text->makeImage(257196) . "\n";
		$list .= "Name: {$itemlink["ICE"]}\n";
		$list .= "Purpose: {$itemlink["ICEU"]}\n";
		$list .= "Note: <highlight>Drops on all Alien Playfield 5 times from the Boss.<end>\n\n";

		// Token Credit Items
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

		// Token Board
		$list .= $this->text->makeImage(230855) . "\n";
		$list .= "Name:{$itemlink["BOARD"]}\n";
		$list .= "Purpose: - {$itemlink["OTAE"]}\n";
		$list .= "<tab><tab>     - {$itemlink["CMP"]}\n";
		$list .= "Note: <highlight>Drops on all Alien Playfield from the Boss.<end>\n\n";

		// Energy Redistribution Unit
		$list .= $this->text->makeImage(257197) . "\n";
		$list .= "Name: {$itemlink["ERU"]}\n";
		$list .= "Purpose: - {$itemlink["BOB"]}\n";
		$list .= "<tab><tab>     - {$itemlink["DVLPR"]}\n";
		$list .= "<tab><tab>     - {$itemlink["VNGW"]}\n\n";

		// Visible Light Remodulation Device
		$list .= $this->text->makeImage(235270) . "\n";
		$list .= "Name: {$itemlink["VLRD"]}\n";
		$list .= "Purpose: - {$itemlink["DVRPR"]}\n";
		$list .= "<tab><tab>     - {$itemlink["SSSS"]}\n";
		$list .= "<tab><tab>     - {$itemlink["EPP"]}\n\n";

		$msg = $this->text->makeBlob("Loot table for sector {$sector}", $list);

		$context->reply($msg);
	}

	/** Show the loot list for Sector 42 */
	#[NCA\HandlesCommand("apf")]
	#[NCA\Help\Group("loot-apf")]
	public function apfFortyTwoCommand(
		CmdContext $context,
		#[NCA\Str("42")] string $sector,
		#[NCA\StrChoice("west", "north", "east", "boss")] string $side,
	): void {
		$key = 'Sector 42 ' . ucfirst(strtolower($side));
		$blob = $this->findRaidLoot('APF', $key, $context);
		$msg = $this->text->makeBlob("{$key} Loot", $blob);
		$context->reply($msg);
	}

	/** Show the full Beast loot */
	#[NCA\HandlesCommand("beast")]
	#[NCA\Help\Group("loot-pande")]
	public function beastCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('Pande', 'Beast Armor', $context);
		$blob .= $this->findRaidLoot('Pande', 'Beast Weapons', $context);
		$blob .= $this->findRaidLoot('Pande', 'Stars', $context);
		$blob .= $this->findRaidLoot('Pande', 'Shadowbreeds', $context);
		$msg = $this->text->makeBlob("Beast Loot", $blob);
		$context->reply($msg);
	}

	/**
	 * Show the loot of a specific boss/zod in Pandemonium
	 *
	 * There are aliases for every &lt;mob&gt;, so '<symbol>cancer' works
	 * the same as '<symbol>pande cancer'
	 *
	 * @author Nadyita (RK5)
	 */
	#[NCA\HandlesCommand("pande")]
	#[NCA\Help\Group("loot-pande")]
	#[NCA\Help\Example("<symbol>pande cancer")]
	#[NCA\Help\Example("<symbol>cancer")]
	#[NCA\Help\Example("<symbol>tnh")]
	public function pandeSubCommand(CmdContext $context, string $mob): void {
		$msg = $this->getPandemoniumLoot('Pande', $mob, $context);
		if (empty($msg)) {
			$context->reply("No loot found for <highlight>{$mob}<end>.");
			return;
		}
		$context->reply($msg);
	}

	/** @return string[]|null */
	public function getPandemoniumLoot(string $raid, string $category, CmdContext $context): ?array {
		$category = ucwords(strtolower($category));
		try {
			$blob = $this->findRaidLoot($raid, $category, $context);
		} catch (Exception $e) {
			return null;
		}
		if (empty($blob)) {
			return null;
		}
		$blob .= "\n\nPande Loot By Marinerecon (RK2)";
		return (array)$this->text->makeBlob("{$raid} \"{$category}\" Loot", $blob);
	}

	/**
	 * Show a list of all bosses and zods in Pandemonium
	 *
	 * @author Marinerecon (RK2)
	 */
	#[NCA\HandlesCommand("pande")]
	#[NCA\Help\Group("loot-pande")]
	public function pandeCommand(CmdContext $context): void {
		$list  = "<header2>The Beast<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("All Beast Loot (long)", "/tell <myname> beast");
		$list .= "\n<tab>".$this->text->makeChatcmd("Beast Armor", "/tell <myname> beastarmor");
		$list .= "\n<tab>".$this->text->makeChatcmd("Beast Weapons", "/tell <myname> beastweaps");
		$list .= "\n<tab>".$this->text->makeChatcmd("Beast Stars", "/tell <myname> beaststars");
		$list .= "\n\n<header2>The Night Heart<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("TNH", "/tell <myname> tnh");
		$list .= "\n\n<header2>West Zodiacs<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("Aries", "/tell <myname> aries");
		$list .= "\n<tab>".$this->text->makeChatcmd("Leo", "/tell <myname> leo");
		$list .= "\n<tab>".$this->text->makeChatcmd("Virgo", "/tell <myname> virgo");
		$list .= "\n\n<header2>East Zodiacs<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("Aquarius", "/tell <myname> aquarius");
		$list .= "\n<tab>".$this->text->makeChatcmd("Cancer", "/tell <myname> cancer");
		$list .= "\n<tab>".$this->text->makeChatcmd("Gemini", "/tell <myname> gemini");
		$list .= "\n<header2>Middle Zodiacs<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("Libra", "/tell <myname> libra");
		$list .= "\n<tab>".$this->text->makeChatcmd("Pisces", "/tell <myname> pisces");
		$list .= "\n<tab>".$this->text->makeChatcmd("Taurus", "/tell <myname> taurus");
		$list .= "\n\n<header2>North Zodiacs<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("Capricorn", "/tell <myname> capricorn");
		$list .= "\n<tab>".$this->text->makeChatcmd("Sagittarius", "/tell <myname> sagittarius");
		$list .= "\n<tab>".$this->text->makeChatcmd("Scorpio", "/tell <myname> scorpio");
		$list .= "\n\n<header2>Other<end>";
		$list .= "\n<tab>".$this->text->makeChatcmd("Shadowbreeds", "/tell <myname> sb");
		$list .= "\n<tab>".$this->text->makeChatcmd("Bastion", "/tell <myname> bastion");

		$list .= "\n\nPandemonium Loot By Marinerecon (RK2)";

		$msg = $this->text->makeBlob("Pandemonium Loot", $list);
		$context->reply($msg);
	}

	/**
	 * Show the loot  list for Vortexx
	 *
	 * @author Morgo (RK2)
	 */
	#[NCA\HandlesCommand("vortexx")]
	#[NCA\Help\Group("loot-lox")]
	public function xanVortexxCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('Vortexx', 'General', $context);
		$blob .= $this->findRaidLoot('Vortexx', 'Symbiants', $context);
		$blob .= $this->findRaidLoot('Vortexx', 'Spirits', $context);
		$msg = $this->text->makeBlob("Vortexx loot", $blob);
		$context->reply($msg);
	}

	/**
	 * Show the loot list for Technomaster Sinuh (Mitaar)
	 *
	 * @author Morgo (RK2)
	 */
	#[NCA\HandlesCommand("mitaar")]
	#[NCA\Help\Group("loot-lox")]
	public function xanMitaarCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('Mitaar', 'General', $context);
		$blob .= $this->findRaidLoot('Mitaar', 'Symbiants', $context);
		$blob .= $this->findRaidLoot('Mitaar', 'Spirits', $context);
		$msg = $this->text->makeBlob("Mitaar loot", $blob);
		$context->reply($msg);
	}

	/**
	 * Show the loot list for 12 Man
	 *
	 * @author Morgo (RK2)
	 */
	#[NCA\HandlesCommand("12m")]
	#[NCA\Help\Group("loot-lox")]
	public function xan12mCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('12Man', 'General', $context);
		$blob .= $this->findRaidLoot('12Man', 'Symbiants', $context);
		$blob .= $this->findRaidLoot('12Man', 'Spirits', $context);
		$blob .= $this->findRaidLoot('12Man', 'Profession Gems', $context);
		$msg = $this->text->makeBlob("12-Man loot", $blob);
		$context->reply($msg);
	}

	/** Show the loot list for the Pyramid of Home */
	#[NCA\HandlesCommand("poh")]
	public function pohCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('Pyramid of Home', 'General', $context);
		$blob .= $this->findRaidLoot('Pyramid of Home', 'HUD/NCU', $context);
		$blob .= $this->findRaidLoot('Pyramid of Home', 'Weapons', $context);
		$msg = $this->text->makeBlob("Pyramid of Home Loot", $blob);

		$context->reply($msg);
	}

	/** Show the loot list for the 201+ Temple of Three Winds */
	#[NCA\HandlesCommand("totw")]
	public function totwCommand(CmdContext $context): void {
		$blob = $this->findRaidLoot('Temple of the Three Winds', 'Armor', $context);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Symbiants', $context);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Misc', $context);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'NCU', $context);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Weapons', $context);
		$blob .= $this->findRaidLoot('Temple of the Three Winds', 'Rings', $context);
		$msg = $this->text->makeBlob("Temple of the Three Winds Loot", $blob);

		$context->reply($msg);
	}

	/** Show the loot list for the 201+ Condemned Subway */
	#[NCA\HandlesCommand("subway")]
	public function subwayCommand(CmdContext $context): void {
		$blob  = $this->findRaidLoot('Subway', 'Armor', $context);
		$blob .= $this->findRaidLoot('Subway', 'Weapons', $context);
		$blob .= $this->findRaidLoot('Subway', 'Belt', $context);
		$blob .= $this->findRaidLoot('Subway', 'Rings', $context);
		$blob .= $this->findRaidLoot('Subway', 'HUD/Utils', $context);
		$msg = $this->text->makeBlob("Subway Loot", $blob);

		$context->reply($msg);
	}

	/** Show the loot list for Halloween */
	#[NCA\HandlesCommand("halloween")]
	public function halloweenCommand(CmdContext $context): void {
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
		$blob = preg_replace("/(<header2>.*?<end>\n)/", "$1\n{$guph}", $this->findRaidLoot('Halloween', 'Griefing Uncle Pumpkin-Head', $context));
		$blob .= "\n<pagebreak><header2>Ganking Uncle Pumpkin-Head<end>\n\n".
			"They drop the same loot as the GUPHs, but have a higher chance to drop the rare items.\n";
		$huph = "They are only spawned by ARKs on Halloween events ".
			"and cannot be found anywhere else.\n";
		$blob .= preg_replace("/(<header2>.*?<end>\n)/", "<pagebreak>$1\n{$huph}", $this->findRaidLoot('Halloween', 'Harvesting Uncle Pumpkin-Head', $context));
		$blob .= $this->findRaidLoot('Halloween', 'Solo Instance', $context);
		$msg = $this->text->makeBlob("Halloween loot", $blob);
		$context->reply($msg);
	}

	public function findRaidLoot(string $raid, string $category, CmdContext $context): string {
		$sender = $context->char->name;

		/** @var Collection<RaidLootSearch> */
		$loot = $this->db->table("raid_loot AS r")
					->whereIlike("r.raid", $raid)
					->whereIlike("r.category", $category)
					->asObj(RaidLootSearch::class);
		$aoids = $loot->whereNotNull("aoid")->pluck("aoid")->toArray();
		$itemsByID = $this->itemsController->getByIDs(...$aoids)->keyBy("highid");
		$names = $loot->whereNull("aoid")->pluck("name")->toArray();
		$itemsByName = $this->itemsController->getByNames(...$names)->keyBy("name");
		foreach ($loot as $item) {
			if (isset($item->aoid)) {
				$item->item = $itemsByID->get($item->aoid);
			} else {
				$item->item = $itemsByName->get($item->name);
			}
		}

		if ($loot->count() === 0) {
			throw new Exception("No loot for type {$raid} found in the database");
		}
		$auctionsEnabled = false;
		$rafflesEnabled = false;
		$lootEnabled = false;
		if (isset($context->permissionSet)) {
			$auctionsEnabled = $this->commandManager->cmdExecutable(AuctionController::CMD_BID_AUCTION, $sender, $context->permissionSet);
			$rafflesEnabled = $this->commandManager->cmdExecutable(RaffleController::CMD_RAFFLE_MANAGE, $sender, $context->permissionSet);
			$lootEnabled = $this->commandManager->cmdExecutable(LootController::CMD_LOOT_MANAGE, $sender, $context->permissionSet);
		}

		$blob = "\n<pagebreak><header2>{$category}<end>\n\n";
		$showLootPics = $this->showRaidLootPics;
		foreach ($loot as $row) {
			/** @var RaidLootSearch $row */
			$actions = [];
			if ($lootEnabled && $this->showLootLootLinks) {
				$actions []= $this->text->makeChatcmd(
					"loot",
					"/tell <myname> loot add {$row->id}"
				);
			}
			if ($lootEnabled && $auctionsEnabled && $this->showLootAuctionLinks) {
				$actions []= $this->text->makeChatcmd(
					"auction",
					"/tell <myname> loot auction {$row->id}"
				);
			}
			if ($lootEnabled && $rafflesEnabled && $this->showLootRaffleLinks) {
				$actions []= $this->text->makeChatcmd(
					"raffle",
					"/tell <myname> loot raffle {$row->id}"
				);
			}
			if (isset($row->item)) {
				if ($showLootPics) {
					$name = "<img src=rdb://{$row->item->icon}>";
				} else {
					$name = $row->name;
					if (count($actions)) {
						$blob .= "[" . join("] [", $actions) . "] - ";
					}
				}
				$blob .= $this->text->makeItem($row->item->lowid, $row->item->highid, $row->ql, $name);
			} else {
				if (count($actions)) {
					$blob .= "[" . join("] [", $actions) . "] - ";
				}
				$blob .= "<highlight>{$row->name}<end>";
			}
			if ($showLootPics && isset($row->item)) {
				$blob .= "\n<highlight>{$row->name}<end>";
			}
			if ($row->multiloot > 1) {
				$blob .= " x" . $row->multiloot;
			}
			if (!empty($row->comment)) {
				$blob .= " ({$row->comment})";
			}
			if ($showLootPics) {
				$blob .= "\n";
				$blob .= $this->text->makeChatcmd("To Loot", "/tell <myname> loot add {$row->id}");
				$blob .= "\n";
			}
			$blob .= "\n";
		}

		return $blob;
	}

	/**
	 * Show the LoX bosses and what symbiants they drop
	 *
	 * @author Nadyita
	 */
	#[NCA\HandlesCommand("lox")]
	#[NCA\Help\Group("loot-lox")]
	public function loxCommand(CmdContext $context): void {
		$list  = $this->text->makeChatcmd("Ground Chief Vortexx", "/tell <myname> vortexx");
		$list .= "\n<tab>- Eye\n";
		$list .= "<tab>- Left Arm\n";
		$list .= "<tab>- Right Wrist\n";
		$list .= "<tab>- Waist\n\n";
		$list .= $this->text->makeChatcmd("The Xan (aka 12-man)", "/tell <myname> 12m");
		$list .= "\n<tab>- Ear\n";
		$list .= "<tab>- Right Arm\n";
		$list .= "<tab>- Right Hand\n";
		$list .= "<tab>- Thigh\n";
		$list .= "<tab>- Feet\n\n";
		$list .= $this->text->makeChatcmd("The Alien Threat (aka Mitaar)", "/tell <myname> mitaar");
		$list .= "\n<tab>- Brain\n";
		$list .= "<tab>- Chest\n";
		$list .= "<tab>- Left Wrist\n";
		$list .= "<tab>- Left Hand\n";
		$msg = $this->text->makeBlob("LoX Hub Loot", $list);
		$context->reply($msg);
	}

	/** @return array<string,string> */
	protected function getApfItems(): array {
		$itemlink = [];
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
		return $itemlink;
	}
}
