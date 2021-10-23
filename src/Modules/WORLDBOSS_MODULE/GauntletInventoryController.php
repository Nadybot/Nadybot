<?php

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\{
	CmdContext,
	DB,
	Modules\ALTS\AltsController,
	Modules\PREFERENCES\Preferences,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	Text,
};

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *
 *	@DefineCommand(
 *		command     = 'gaulist',
 *		accessLevel = 'member',
 *		description = 'Manage the stuff u got and need',
 *		help        = 'gaulist.txt'
 *	)
 */
class GauntletInventoryController {
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
	public AltsController $altsController;

	/** @Inject */
	public Preferences $preferences;

	//(ref , image, need) 17 items without basic armor
	private $gaulisttab =   [
		[292507, 292793, 3], [292509, 292775, 1], [292508, 292776, 1], [292510, 292774, 1],
		[292514, 292764, 1], [292515, 292780, 1], [292516, 292792, 1], [292532, 292760, 3],
		[292533, 292788, 3], [292529, 292779, 3], [292530, 292759, 3], [292524, 292784, 3],
		[292538, 292772, 3], [292525, 292763, 3], [292526, 292777, 3], [292528, 292778, 3],
		[292517, 292762, 3]
	];

	/** @Setup */
	public function setup() {
		$this->db->loadMigrations($this->moduleName, __DIR__ . '/Migrations/Gauntlet');
	}

	public function getData(string $name): array {
		$data = $this->preferences->get($name, 'gauntlet');
		if (isset($data)) {
			return json_decode($data);
		} else {
			return array_fill(0, 17, 0);
		}
	}

	public function saveData(string $sender, array $inv): void {
		$this->preferences->save($sender, 'gauntlet', json_encode($inv));
	}

	private function renderBastionInventory(string $name, int $numArmors) {
		$data = $this->getData($name);
		if (($numArmors < 1) || ($numArmors > 3)) {
			$numArmors = 1;
		}
		//Do blob box
		$gauTradeCmd = $this->text->makeChatcmd("<symbol>gautrade", "/tell <myname> gautrade");
		$gauListMask = $this->text->makeChatcmd("%d Armor", "/tell <myname> gaulist {$name} %d");
		$list = "Tradeskill: [{$gauTradeCmd}]\n" .
			"Needed items for: [".
			sprintf($gauListMask, 1, 1) . "|" .
			sprintf($gauListMask, 2, 2) . "|" .
			sprintf($gauListMask, 3, 3) . "]\n";
		$list .= "Items needed for {$numArmors} Bastion armor parts.\n".
			"<green>[Amount you have]<end>|<red>[Amount you need]<end>\n".
			"[+]=increase Item      [-]=decrease Item\n\n";

		$incLink = $this->text->makeChatcmd(" + ", "/tell <myname> gaulist add {$name} %d");
		$decLink = $this->text->makeChatcmd(" - ", "/tell <myname> gaulist del {$name} %d");
		$headerLine = "";
		$line = "";
		for ($i = 0; $i <= 16; $i++) {
			$d = $this->gaulisttab[$i];
			$itemLink = $this->text->makeItem($d[0], $d[0], 300, $this->text->makeImage($d[1]));
			$headerLine .= "    {$itemLink}    ";
			$line .= "[".
				sprintf($incLink, $i).
				"|".
				"<green>" . ($data[$i]??0) . "<end>".
				"|".
				"<red>".max(0, ($numArmors*$d[2])-$data[$i])."<end>".
				"|".
				sprintf($decLink, $i).
				"] ";
			if ((($i+1) % 4) === 0 || $i === 16) {
				$list .= $headerLine . "\n" . $line . "\n\n";
				$headerLine = "";
				$line = "";
			}
		}
		$refreshLink = $this->text->makeChatcmd("Refresh", "/tell <myname> gaulist {$name} {$numArmors}");
		$list .= "\n                         [{$refreshLink}]";
		$link = $this->text->makeBlob("Bastion inventory for $name", $list);
		$blob = "Bastion inventory: ".$link;
		return $blob;
	}

	/**
	 * @HandlesCommand("gaulist")
	 */
	public function gaulistExtraCommand(CmdContext $context, ?PCharacter $name, ?int $numArmors): void {
		$name = isset($name) ? $name() : $context->char->name;
		$numArmors ??= 1;
		$msg = $this->renderBastionInventory($name, $numArmors);
		$context->reply($msg);
	}

	protected function altCheck(CmdContext $context, string $sender, string $name) {
		$altInfo = $this->altsController->getAltInfo($sender);
		if ($altInfo->main !== $name && !in_array($name, $altInfo->getAllValidatedAlts())) {
			$context->reply("Player \"{$name}\" is not your alt.");
			return false;
		}
		return true;
	}

	/**
	 * @HandlesCommand("gaulist")
	 */
	public function gaulistAddCommand(CmdContext $context, string $action="add", PCharacter $name, int $pos): void {
		$name = $name();
		// Check and increase item
		if ($this->altCheck($context, $context->char->name, $name) === false) {
			return;
		}
		if ($pos < 0 || $pos > 16) {
			$msg = "No valid itemID.";
			$context->reply($msg);
			return;
		}
		$items = $this->getData($name);
		++$items[$pos];
		$this->saveData($name, $items);
		$msg = "Item increased!";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("gaulist")
	 */
	public function gaulistDelCommand(CmdContext $context, PRemove $action, PCharacter $name, int $pos): void {
		$name = $name();
		// Check and increase item
		if ($this->altCheck($context, $context->char->name, $name) === false) {
			return;
		}
		if ($pos < 0 || $pos > 16) {
			$msg = "No valid itemID.";
			$context->reply($msg);
			return;
		}
		$items = $this->getData($name);
		if ($items[$pos] < 1) {
			$msg = "You cannot decrease any further";
		} else {
			--$items[$pos];
			$this->saveData($name, $items);
			$msg = "Item decreased!";
		}
		$context->reply($msg);
	}
}
