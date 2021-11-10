<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Text;
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
 *		command     = 'aiarmor',
 *		accessLevel = 'all',
 *		description = 'Shows tradeskill process for Alien Armor',
 *		help        = 'aiarmor.txt'
 *	)
 */
class AlienArmorController {

	/** @Inject */
	public Text $text;

	/** @Inject */
	public ItemsController $itemsController;

	/**
	 * @HandlesCommand("aiarmor")
	 * @Matches("/^aiarmor$/i")
	 */
	public function aiarmorListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows tradeskill process for normal Alien Armor.
	 *
	 * @HandlesCommand("aiarmor")
	 * @Matches("/^aiarmor (strong|supple|enduring|observant|arithmetic|spiritual)$/i")
	 * @Matches("/^aiarmor (strong|supple|enduring|observant|arithmetic|spiritual) (\d+)$/i")
	 * @Matches("/^aiarmor (\d+) (strong|supple|enduring|observant|arithmetic|spiritual)$/i")
	 */
	public function aiarmorNormalCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		[$armortype, $ql] = $this->extractArgs($args);
		$armortype = ucfirst($armortype);
		$misc_ql = (int)floor($ql * 0.8);

		$list = "Note: All tradeskill processes are based on the lowest QL items usable.\n\n";
		$list .= "<header2>You need the following items to build $armortype Armor:<end>\n";
		$list .= "- Kyr'Ozch Viralbots (QL$misc_ql+)\n";
		$list .= "- Kyr'Ozch Atomic Re-Structulazing Tool\n";
		$list .= "- Solid Clump of Kyr'Ozch Biomaterial (QL$ql)\n";
		$list .= "- Arithmetic/Strong/Enduring/Spiritual/Observant/Supple Viralbots (QL$misc_ql+)\n\n";

		$list .= "<header2>Step 1<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Viralbots", $misc_ql);
		$list .= " QL$misc_ql+ (<highlight>Drops from Alien City Generals<end>)\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Atomic Re-Structuralizing Tool", 100);
		$list .= " (<highlight>Drops from every Alien<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Memory-Wiped Kyr'Ozch Viralbots", $misc_ql) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($misc_ql * 4.5)." Computer Literacy\n";
		$list .= "- ".ceil($misc_ql * 4.5)." Nano Programming\n\n";

		$list .= "<header2>Step 2<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Nano Programming Interface", 1);
		$list .= " (<highlight>Can be bought in General Shops<end>)\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Memory-Wiped Kyr'Ozch Viralbots", $misc_ql) . "\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Kyr'Ozch Viralbots", $misc_ql) . "\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($misc_ql * 4.5)." Computer Literacy\n";
		$list .= "- ".ceil($misc_ql * 6)." Nano Programming\n\n";

		$list .= "<header2>Step 3<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Kyr'Ozch Structural Analyzer", 100) . "\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Solid Clump of Kyr'Ozch Bio-Material", $ql) . " QL$ql";
		$list .= " (<highlight>Drops from every Alien<end>)\n";
		$list .= "<tab><tab>=\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Mutated Kyr'Ozch Bio-Material", $ql) . " QL$ql";
		$list .= "\n\nor\n\n<tab>" . $this->itemsController->getItemAndIcon("Pristine Kyr'Ozch Bio-Material", $ql) . " QL$ql\n";
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".ceil($ql * 4.5)." Chemistry (Both require the same amount)\n\n";

		$list .= "<header2>Step 4<end>\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Mutated Kyr'Ozch Bio-Material", $ql) . " QL$ql";
		$list .= "\n\nor\n\n<tab>" . $this->itemsController->getItemAndIcon("Pristine Kyr'Ozch Bio-Material", $ql) . " QL$ql\n";
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
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Kyr'Ozch Viralbots", $misc_ql) . "\n";
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
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Arithmetic Lead Viralbots", $vb_ql) . " QL$vb_ql";
				break;
			case "Supple":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Supple Lead Viralbots", $vb_ql) . " QL$vb_ql";
				break;
			case "Enduring":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Enduring Lead Viralbots", $vb_ql) . " QL$vb_ql";
				break;
			case "Observant":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Observant Lead Viralbots", $vb_ql) . " QL$vb_ql";
				break;
			case "Strong":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Strong Lead Viralbots", $vb_ql) . " QL$vb_ql";
				break;
			case "Spiritual":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Spiritual Lead Viralbots", $vb_ql) . " QL$vb_ql";
				break;
		}
		$list .= " (<highlight>Rare Drop off Alien City Generals<end>)\n";
		$list .= "<tab><tab>+\n";
		$list .= "<tab>" . $this->itemsController->getItemAndIcon("Formatted Viralbot Vest", $ql) . "\n";
		$list .= "<tab><tab>=\n";
		switch ($armortype) {
			case "Arithmetic":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Arithmetic Body Armor", $ql) . " QL$ql\n";
				break;
			case "Supple":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Supple Body Armor", $ql) . " QL$ql\n";
				break;
			case "Enduring":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Enduring Body Armor", $ql) . " QL$ql\n";
				break;
			case "Observant":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Observant Body Armor", $ql) . " QL$ql\n";
				break;
			case "Strong":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Strong Body Armor", $ql) . " QL$ql\n";
				break;
			case "Spiritual":
				$list .= "<tab>" . $this->itemsController->getItemAndIcon("Spiritual Body Armor", $ql) . " QL$ql\n";
				break;
		}
		$list .= "<highlight>Required Skills:<end>\n";
		$list .= "- ".floor($ql * 6)." Psychology\n\n";

		$msg = $this->text->makeBlob("Building process for $ql $armortype", $list);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows tradeskill process for combined Alien Armor.
	 *
	 * @HandlesCommand("aiarmor")
	 * @Matches("/^aiarmor (cc|cm|co|cp|cs|css|ss)$/i")
	 * @Matches("/^aiarmor (cc|cm|co|cp|cs|css|ss) (\d+)$/i")
	 * @Matches("/^aiarmor (\d+) (cc|cm|co|cp|cs|css|ss)$/i")
	 */
	public function aiarmorCombinedCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		[$armortype, $ql] = $this->extractArgs($args);
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
				$sendto->reply("Unknown type selected.");
				return;
		}

		$list = "<header2>Result<end>\n";
		$list .= $this->itemsController->getItemAndIcon($nameArmorResult, $ql) . " QL$ql\n\n";

		$list .= "<header2>Source Armor<end>\n";
		$list .= $this->itemsController->getItemAndIcon($nameArmorSource, $sourceQL) . " QL$sourceQL";
		$list .= " (" . $this->text->makeChatcmd("Tradeskill process for this item", "/tell <myname> aiarmor $nameSrc $sourceQL") . ")\n\n";

		$list .= "<header2>Target Armor<end>\n";
		$list .= $this->itemsController->getItemAndIcon($nameArmorTarget, $targetQL) . " QL$targetQL";
		$list .= " (" . $this->text->makeChatcmd("Tradeskill process for this item", "/tell <myname> aiarmor $nameTarget $targetQL") . ")";
		$msg = $this->text->makeBlob("Building process for $ql $nameArmorResult", $list);
		$sendto->reply($msg);
	}

	/**
	 * Extracts armor type and quality from given $args regexp matches.
	 * @return array[string,int]
	 * @psalm-return array{0: string, 1: int}
	 */
	private function extractArgs(array $args): array {
		$armortype = '';
		$ql = 300;
		// get ql and armor type from command arguments
		for ($i = 1; $i < count($args); $i++) {
			$value = $args[$i];
			if (is_numeric($value)) {
				if ($value >= 1 && $value <= 300) {
					$ql = intval($value);
				}
			} else {
				$armortype = strtolower($value);
			}
		}
		return  [$armortype, $ql];
	}
}
