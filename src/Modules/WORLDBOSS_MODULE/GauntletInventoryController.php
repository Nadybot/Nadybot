<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	Modules\ALTS\AltsController,
	Modules\PREFERENCES\Preferences,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	Text,
};

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gaulist",
		accessLevel: "member",
		description: "Manage the stuff you got and need from the Gauntlet",
		help: "gaulist.txt"
	)
]
class GauntletInventoryController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public Preferences $preferences;

	/**
	 * (ref , image, need) 17 items without basic armor
	 * @var int[][]
	 * @psalm-var list<array{0: int, 1: int, 2: int}>
	 */
	private array $gaulisttab = [
		[292507, 292793, 3], [292509, 292775, 1], [292508, 292776, 1], [292510, 292774, 1],
		[292514, 292764, 1], [292515, 292780, 1], [292516, 292792, 1], [292532, 292760, 3],
		[292533, 292788, 3], [292529, 292779, 3], [292530, 292759, 3], [292524, 292784, 3],
		[292538, 292772, 3], [292525, 292763, 3], [292526, 292777, 3], [292528, 292778, 3],
		[292517, 292762, 3]
	];

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

	/**
	 * @return string[]
	 */
	private function renderBastionInventory(string $name, int $numArmors): array {
		$inventory = $this->getData($name);
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
			sprintf($gauListMask, 3, 3) . "]\n\n";
		$list .= "<header2>Items needed for {$numArmors} Bastion armor parts<end>\n".
			"<tab>[ + increase amount | <green>Amount you have<end> | <red>Amount you still need<end> | - decrease amount ]\n\n";

		$incLink = $this->text->makeChatcmd(" + ", "/tell <myname> gaulist add {$name} %d");
		$decLink = $this->text->makeChatcmd(" - ", "/tell <myname> gaulist del {$name} %d");
		$headerLine = "<tab>";
		$line = "<tab>";
		for ($i = 0; $i <= 16; $i++) {
			$data = $this->gaulisttab[$i];
			$itemLink = $this->text->makeItem($data[0], $data[0], 1, $this->text->makeImage($data[1]));
			$headerLine .= "    {$itemLink}    ";
			$line .= "[".
				sprintf($incLink, $i).
				"|".
				"<green>" . ($inventory[$i]??0) . "<end>".
				"|".
				"<red>".max(0, ($numArmors*$data[2])-$inventory[$i])."<end>".
				"|".
				sprintf($decLink, $i).
				"] ";
			if ((($i+1) % 4) === 0 || $i === 16) {
				$list .= $headerLine . "\n" . $line . "\n\n";
				$headerLine = "<tab>";
				$line = "<tab>";
			}
		}
		$refreshLink = $this->text->makeChatcmd("Refresh", "/tell <myname> gaulist {$name} {$numArmors}");
		$list .= "\n<tab>[{$refreshLink}]";
		$blob = (array)$this->text->makeBlob("Bastion inventory for $name", $list);
		foreach ($blob as &$page) {
			$page = "Bastion inventory: {$page}";
		}
		return $blob;
	}

	#[NCA\HandlesCommand("gaulist")]
	public function gaulistExtraCommand(CmdContext $context, ?PCharacter $name, ?int $numArmors): void {
		$name = isset($name) ? $name() : $context->char->name;
		$numArmors ??= 1;
		$msg = $this->renderBastionInventory($name, $numArmors);
		$context->reply($msg);
	}

	protected function altCheck(CmdContext $context, string $sender, string $name): bool {
		$altInfo = $this->altsController->getAltInfo($sender);
		if ($altInfo->main !== $name && !in_array($name, $altInfo->getAllValidatedAlts())) {
			$context->reply("Player \"{$name}\" is not your alt.");
			return false;
		}
		return true;
	}

	#[NCA\HandlesCommand("gaulist")]
	public function gaulistAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, PCharacter $name, int $pos): void {
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

	#[NCA\HandlesCommand("gaulist")]
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
