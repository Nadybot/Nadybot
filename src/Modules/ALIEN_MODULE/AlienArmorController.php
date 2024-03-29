<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
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
	NCA\DefineCommand(
		command: "aiarmor",
		accessLevel: "guest",
		description: "Shows tradeskill process for Alien Armor",
	)
]
class AlienArmorController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ItemsController $itemsController;

	/** Get an overview of the supported alien armor types */
	#[NCA\HandlesCommand("aiarmor")]
	#[NCA\Help\Epilogue(
		"<header2>Allowed Types:<end>\n\n".
		"<tab><highlight>- cc<end>: Combined Commando's\n".
		"<tab><highlight>- cm<end>: Combined Mercenary's\n".
		"<tab><highlight>- co<end>: Combined Officer's\n".
		"<tab><highlight>- cp<end>: Combined Paramedic's\n".
		"<tab><highlight>- cs<end>: Combined Scout's\n".
		"<tab><highlight>- ss,css<end>: Combined Sharpshooter's\n\n".
		"<tab><highlight>- strong<end>: Strong Armor\n".
		"<tab><highlight>- supple<end>: Supple Armor\n".
		"<tab><highlight>- enduring<end>: Enduring Armor\n".
		"<tab><highlight>- observant<end>: Observant Armor\n".
		"<tab><highlight>- arithmetic<end>: Arithmetic Armor\n".
		"<tab><highlight>- spiritual<end>: Spiritual Armor"
	)]
	public function aiarmorListCommand(CmdContext $context): void {
		$list = "Please choose from the following which armor to view information on:";
		$list .= "\n\n<header2>Normal Armor<end>";
		$list .= "\n<tab>" . $this->text->makeChatcmd("Strong Armor", "/tell <myname> aiarmor Strong");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Supple Armor", "/tell <myname> aiarmor Supple");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Enduring Armor", "/tell <myname> aiarmor Enduring");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Observant Armor", "/tell <myname> aiarmor Observant");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Arithmetic Armor", "/tell <myname> aiarmor Arithmetic");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Spiritual Armor", "/tell <myname> aiarmor Spiritual");
		$list .= "\n\n<header2>Combined Armor<end>";
		$list .= "\n<tab>" . $this->text->makeChatcmd("Combined Commando's Armor", "/tell <myname> aiarmor cc");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Combined Mercenary's Armor", "/tell <myname> aiarmor cm");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Combined Officer's", "/tell <myname> aiarmor co");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Combined Paramedic's Armor", "/tell <myname> aiarmor cp");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Combined Scout's Armor", "/tell <myname> aiarmor cs");
		$list .= "\n<tab>" . $this->text->makeChatcmd("Combined Sharpshooter's Armor", "/tell <myname> aiarmor css");
		$msg = $this->text->makeBlob("Alien Armor List", $list);
		$context->reply($msg);
	}

	/** Show the tradeskill process for normal Alien Armor. */
	#[NCA\HandlesCommand("aiarmor")]
	public function aiarmorNormal2Command(CmdContext $context, PBotType $armortype, int $ql): void {
		$this->aiarmorNormalCommand($context, $ql, $armortype);
	}

	/** Show the tradeskill process for normal Alien Armor. */
	#[NCA\HandlesCommand("aiarmor")]
	public function aiarmorNormalCommand(CmdContext $context, ?int $ql, PBotType $armortype): void {
		$ql ??= 300;
		$armortype = $armortype();
		$miscQL = (int)floor($ql * 0.8);

		$list = "Note: All tradeskill processes are based on the lowest QL items usable.\n\n";
		$list .= "<header2>You need the following items to build {$armortype} Armor:<end>\n";
		$list .= "- Kyr'Ozch Viralbots (QL{$miscQL}+)\n";
		$list .= "- Kyr'Ozch Atomic Re-Structulazing Tool\n";
		$list .= "- Solid Clump of Kyr'Ozch Biomaterial (QL{$ql})\n";
		$list .= "- Arithmetic/Strong/Enduring/Spiritual/Observant/Supple Viralbots (QL{$miscQL}+)\n\n";

		$list .= "<header2>Step 1<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Viralbots", $miscQL);
		$list .= " QL{$miscQL}+ (<highlight>Drops from Alien City Generals<end>)\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Atomic Re-Structuralizing Tool", 100);
		$list .= " (<highlight>Drops from every Alien<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Memory-Wiped Kyr'Ozch Viralbots", $miscQL) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($miscQL * 4.5)." Computer Literacy\n";
		$list .= "- ".ceil($miscQL * 4.5)." Nano Programming\n\n";

		$list .= "<header2>Step 2<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Nano Programming Interface", 1);
		$list .= " (<highlight>Can be bought in General Shops<end>)\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Memory-Wiped Kyr'Ozch Viralbots", $miscQL) . "\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Kyr'Ozch Viralbots", $miscQL) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($miscQL * 4.5)." Computer Literacy\n";
		$list .= "- ".ceil($miscQL * 6)." Nano Programming\n\n";

		$list .= "<header2>Step 3<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Structural Analyzer", 100) . "\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Solid Clump of Kyr'Ozch Bio-Material", $ql) . " QL{$ql}";
		$list .= " (<highlight>Drops from every Alien<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Mutated Kyr'Ozch Bio-Material", $ql) . " QL{$ql}";
		$list .= "\n\nor\n\n<tab>" . $this->itemsController->getItemAndIcon("Pristine Kyr'Ozch Bio-Material", $ql) . " QL{$ql}\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($ql * 4.5)." Chemistry (Both require the same amount)\n\n";

		$list .= "<header2>Step 4<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Mutated Kyr'Ozch Bio-Material", $ql) . " QL{$ql}";
		$list .= "\n\nor\n\n<tab>" . $this->itemsController->getItemAndIcon("Pristine Kyr'Ozch Bio-Material", $ql) . " QL{$ql}\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Uncle Bazzit's Generic Nano-Solvent", 100);
		$list .= " (<highlight>Can be bought in Bazzit Shop in MMD<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Generic Kyr'Ozch DNA-Soup", $ql) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($ql * 4.5)." Chemistry(for Pristine)\n";
		$list .= "- ".ceil($ql * 7)." Chemistry(for Mutated)\n\n";

		$list .= "<header2>Step 5<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Generic Kyr'Ozch DNA-Soup", $ql) . "\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Essential Human DNA", 100);
		$list .= " (<highlight>Can be bought in Bazzit Shop in MMD<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("DNA Cocktail", $ql) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($ql * 6)." Pharma Tech\n\n";

		$list .= "<header2>Step 6<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Kyr'Ozch Viralbots", $miscQL) . "\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("DNA Cocktail", $ql) . "\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Formatted Viralbot Solution", $ql) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($ql * 6)." Pharma Tech\n\n";

		$list .= "<header2>Step 7<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Formatted Viralbot Solution", $ql) . "\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Basic Fashion Vest", 1) . " (<highlight>Can be obtained by the Basic Armor Quest<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Viralbot Vest", $ql) . "\n\n";

		$list .= "<header2>Step 8<end>\n";

		$vb_ql = (int)floor($ql * 0.8);
		switch ($armortype) {
			case "Arithmetic":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Arithmetic Lead Viralbots", $vb_ql) . " QL{$vb_ql}";
				break;
			case "Supple":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Supple Lead Viralbots", $vb_ql) . " QL{$vb_ql}";
				break;
			case "Enduring":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Enduring Lead Viralbots", $vb_ql) . " QL{$vb_ql}";
				break;
			case "Observant":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Observant Lead Viralbots", $vb_ql) . " QL{$vb_ql}";
				break;
			case "Strong":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Strong Lead Viralbots", $vb_ql) . " QL{$vb_ql}";
				break;
			case "Spiritual":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Spiritual Lead Viralbots", $vb_ql) . " QL{$vb_ql}";
				break;
		}
		$list .= " (<highlight>Rare Drop off Alien City Generals<end>)\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Viralbot Vest", $ql) . "\n";
		$list .= "<tab><tab>=\n";
		switch ($armortype) {
			case "Arithmetic":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Arithmetic Body Armor", $ql) . " QL{$ql}\n";
				break;
			case "Supple":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Supple Body Armor", $ql) . " QL{$ql}\n";
				break;
			case "Enduring":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Enduring Body Armor", $ql) . " QL{$ql}\n";
				break;
			case "Observant":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Observant Body Armor", $ql) . " QL{$ql}\n";
				break;
			case "Strong":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Strong Body Armor", $ql) . " QL{$ql}\n";
				break;
			case "Spiritual":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Spiritual Body Armor", $ql) . " QL{$ql}\n";
				break;
		}
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".floor($ql * 6)." Psychology\n\n";

		$msg = $this->text->makeBlob("Building process for {$ql} {$armortype}", $list);
		$context->reply($msg);
	}

	/** Show the tradeskill process for combined Alien Armor. */
	#[NCA\HandlesCommand("aiarmor")]
	public function aiarmorCombinedCommand2(
		CmdContext $context,
		#[NCA\Regexp("c[cmops]|c?ss", example: "cc|cm|co|cp|cs|css|ss")] string $type,
		int $ql
	): void {
		$this->aiarmorCombinedCommand($context, $ql, $type);
	}

	/** Show the tradeskill process for combined Alien Armor. */
	#[NCA\HandlesCommand("aiarmor")]
	public function aiarmorCombinedCommand(
		CmdContext $context,
		?int $ql,
		#[NCA\Regexp("c[cmops]|c?ss", example: "cc|cm|co|cp|cs|css|ss")] string $type,
	): void {
		$ql ??= 300;
		$armortype = strtolower($type);
		$targetQL = $ql;
		$sourceQL = (int)floor($targetQL * 0.8);

		switch ($armortype) {
			case 'cc':
				$nameArmorResult = "Combined Commando's Jacket";

				$nameArmorSource = "Strong Body Armor";
				$nameSrc = "strong";

				$nameArmorTarget = "Supple Body Armor";
				$nameTarget = "supple";
				break;

			case 'cm':
				$nameArmorResult = "Combined Mercenary's Jacket";

				$nameArmorSource = "Strong Body Armor";
				$nameSrc = "strong";

				$nameArmorTarget = "Enduring Body Armor";
				$nameTarget = "enduring";
				break;

			case 'co':
				$nameArmorResult = "Combined Officer's Jacket";

				$nameArmorSource = "Spiritual Body Armor";
				$nameSrc = "spiritual";

				$nameArmorTarget = "Arithmetic Body Armor";
				$nameTarget = "arithmetic";
				break;

			case 'cp':
				$nameArmorResult = "Combined Paramedic's Jacket";

				$nameArmorSource = "Spiritual Body Armor";
				$nameSrc = "spiritual";

				$nameArmorTarget = "Enduring Body Armor";
				$nameTarget = "enduring";
				break;

			case 'cs':
				$nameArmorResult = "Combined Scout's Jacket";

				$nameArmorSource = "Observant Body Armor";
				$nameSrc = "observant";

				$nameArmorTarget = "Arithmetic Body Armor";
				$nameTarget = "arithmetic";
				break;

			case 'css':
			case 'ss':
				$nameArmorResult = "Combined Sharpshooter's Jacket";

				$nameArmorSource = "Observant Body Armor";
				$nameSrc = "observant";

				$nameArmorTarget = "Supple Body Armor";
				$nameTarget = "supple";
				break;
			default:
				$context->reply("Unknown type selected.");
				return;
		}

		$list = "<header2>Result<end>\n";
		$list .= $this->itemsController->getItemAndIcon($nameArmorResult, $ql) . " QL{$ql}\n\n";

		$list .= "<header2>Source Armor<end>\n";
		$list .= $this->itemsController->getItemAndIcon($nameArmorSource, $sourceQL) . " QL{$sourceQL}";
		$list .= " (" . $this->text->makeChatcmd("Tradeskill process for this item", "/tell <myname> aiarmor {$nameSrc} {$sourceQL}") . ")\n\n";

		$list .= "<header2>Target Armor<end>\n";
		$list .= $this->itemsController->getItemAndIcon($nameArmorTarget, $targetQL) . " QL{$targetQL}";
		$list .= " (" . $this->text->makeChatcmd("Tradeskill process for this item", "/tell <myname> aiarmor {$nameTarget} {$targetQL}") . ")";
		$msg = $this->text->makeBlob("Building process for {$ql} {$nameArmorResult}", $list);
		$context->reply($msg);
	}
}
