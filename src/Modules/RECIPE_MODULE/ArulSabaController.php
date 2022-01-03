<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Util;
use Nadybot\Core\Text;
use Nadybot\Modules\ITEMS_MODULE\AODBItem;
use Nadybot\Modules\ITEMS_MODULE\ItemFlag;
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\ITEMS_MODULE\ItemWithBuffs;
use Nadybot\Modules\ITEMS_MODULE\Skill;

/**
 * @author Nadyita
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/ArulSaba"),
	NCA\DefineCommand(
		command: "arulsaba",
		accessLevel: "all",
		description: "Get recipe for Arul Saba bracers",
		help: "arulsaba.txt",
		alias: "aruls"
	)
]
class ArulSabaController {
	public const ME = 125;
	public const EE = 126;
	public const AGI = 17;

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/arulsaba.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/arulsaba_buffs.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/ingredient.csv");
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'arulsaba_show_images',
			description: "Show images for the Arul Saba steps",
			mode: "edit",
			type: "options",
			value: "2",
			options: "yes, with links;yes;no",
			intoptions: "2;1;0"
		);
	}

	#[NCA\HandlesCommand("arulsaba")]
	public function arulSabaListCommand(CmdContext $context): void {
		$blob = "<header2>Choose the type of bracer<end>\n";
		$blob = $this->db->table("arulsaba")
			->asObj(ArulSaba::class)
			->reduce(function (string $blob, ArulSaba $type): string {
				$chooseLink = $this->text->makeChatcmd(
					"Choose QL",
					"/tell <myname> arulsaba {$type->name}"
				);
				return "{$blob}<tab>[{$chooseLink}] ".
					"{$type->lesser_prefix}/{$type->regular_prefix} ".
					"{$type->name}: <highlight>{$type->buffs}<end>\n";
			}, $blob);
		$msg = $this->text->makeBlob("Arul Saba - Choose type", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("arulsaba")]
	public function arulSabaChooseQLCommand(CmdContext $context, PWord $name): void {
		/** @var Collection<ArulSabaBuffs> */
		$aruls = $this->db->table("arulsaba_buffs")
			->where("name", ucfirst(strtolower($name())))
			->orderBy("min_level")
			->asObj(ArulSabaBuffs::class);
		if ($aruls->isEmpty()) {
			$context->reply("No Bracelet of Arul Saba ({$name}) found.");
			return;
		}
		$blob = '';
		$gems = 0;
		foreach ($aruls as $arul) {
			$item = $this->itemsController->findById($arul->left_aoid);
			if (!isset($item)) {
				$context->reply("Cannot find item #{$arul->left_aoid} in bot's item database.");
				return;
			}
			/** @var ItemWithBuffs */
			$item = $this->itemsController->addBuffs($item)->firstOrFail();
			$shortName = preg_replace("/^.*\((.+?) - Left\)$/", "$1", $item->name);
			$blob .= "<header2>{$shortName}<end>\n".
				"<tab>Min level: <highlight>{$arul->min_level}<end>\n";
			foreach ($item->buffs as $buff) {
				$blob .= "<tab>{$buff->skill->name}: <highlight>+{$buff->amount}{$buff->skill->unit}<end>\n";
			}
			$leftLink = $this->text->makeChatcmd("Left", "/tell <myname> arulsaba {$arul->name} {$gems} left");
			$rightLink = $this->text->makeChatcmd("Right", "/tell <myname> arulsaba {$arul->name} {$gems} right");
			$blob .= "<tab>Recipe: [{$leftLink}] [{$rightLink}]\n\n";
			$gems++;
		}
		$msg = $this->text->makeBlob("Types of a Arul Saba {$aruls[0]->name} bracelet", $blob);
		$context->reply($msg);
	}

	protected function enrichIngredient(Ingredient $ing, int $amount, ?int $ql=null, bool $qlCanBeHigher=false): Ingredient {
		$ing->qlCanBeHigher = $qlCanBeHigher;
		if (isset($ql)) {
			$ing->ql = $ql;
		}
		$ing->amount = $amount;
		if (!isset($ing->aoid)) {
			return $ing;
		}
		$ing->item = AODBItem::fromEntry($this->itemsController->findById($ing->aoid));
		if (isset($ing->item)) {
			$ql ??= $ing->item->lowql;
			$ing->item->ql = $ql;
		}
		return $ing;
	}

	public function readIngredientByAoid(int $aoid, int $amount=1, ?int $ql=null, bool $qlCanBeHigher=false): ?Ingredient {
		/** @var Ingredient|null */
		$ing = $this->db->table("ingredient")
			->where("aoid", $aoid)
			->asObj(Ingredient::class)
			->first();
		if (!isset($ing)) {
			throw new Exception("Cannot find ingredient #{$aoid} in the bot's database.");
		}
		return $this->enrichIngredient($ing, $amount, $ql, $qlCanBeHigher);
	}

	public function readIngredientByName(string $name, int $amount=1, ?int $ql=null, bool $qlCanBeHigher=false): Ingredient {
		/** @var Ingredient|null */
		$ing = $this->db->table("ingredient")
			->where("name", $name)
			->asObj(Ingredient::class)
			->first();
		if (!isset($ing)) {
			$query = $this->db->table("ingredient");
			$tmp = explode(" ", $name);
			$this->db->addWhereFromParams($query, $tmp, "name");
			$ing = $query->asObj(Ingredient::class)->first();
		}
		if (!isset($ing)) {
			throw new Exception("Cannot find ingredient {$name} in the bot's database.");
		}
		return $this->enrichIngredient($ing, $amount, $ql, $qlCanBeHigher);
	}

	#[NCA\HandlesCommand("arulsaba")]
	public function arulSabaRecipeCommand(CmdContext $context, PWord $type, int $numGems, #[NCA\Regexp("left|right")] string $side): void {
		$type = ucfirst(strtolower($type()));
		$reqGems = max(1, $numGems);
		$side = strtolower($side);

		$gemGrades = [
			["Arbiter Gem",     "Scheol",   288,  306],
			["Monarch Gem",     "Adonis",   528,  563],
			["Emperor Gem",     "Penumbra", 937, 1035],
			["Stellar Jewel",   "Inferno", 1665, 1775],
			["Galactic Jewel" , "Alappaa", 2100, 2270],
		];
		$blueprints = [
			[150871, 150870,  80, 150862, 150866, 150857, 150861],
			[150871, 150870,  80, 150862, 150866, 150857, 150861],
			[150870, 150869, 110, 150866, 150865, 150861, 150859],
			[150869, 150867, 150, 150865, 150863, 150859, 150858],
			[150867, 150868, 180, 150863, 150864, 150858, 150857],
			[150867, 150868, 200, 150863, 150864, 150858, 150857],
		];
		$unfinished = [
			0 => [
				"left"  => [150846],
				"right" => [150843],
			],
			1 => [
				"left"  => [150846],
				"right" => [150843],
			],
			2 => [
				"left"  => [150836, 150841],
				"right" => [150833, 150847],
			],
			3 => [
				"left"  => [150834,150832,150842],
				"right" => [150820,150818,150844],
			],
			4 => [
				"left"  => [150821,150828,150825,150840],
				"right" => [150831,150829,150826,150837],
			],
			5 => [
				"left"  => [150835,150830,150827,150824,150838],
				"right" => [150817,150819,150823,150822,150845],
			],
		];
		$finished = [
			0 => [
				"left"  => 150855,
				"right" => 150856,
			],
			1 => [
				"left"  => 150855,
				"right" => 150856,
			],
			2 => [
				"left"  => 150839,
				"right" => 150851,
			],
			3 => [
				"left"  => 150854,
				"right" => 150848,
			],
			4 => [
				"left"  => 150852,
				"right" => 150849,
			],
			5 => [
				"left"  => 150853,
				"right" => 150850,
			],
		];
		$icons = [
			151026,
			151023,
			151024,
			151025,
			151022
		];

		/** @var ArulSaba|null */
		$arul = $this->db->table("arulsaba")
			->where("name", $type)
			->asObj(ArulSaba::class)
			->first();
		if (!isset($arul) || ($numGems > 0 && !isset($blueprints[$numGems]))) {
			$context->reply("No Bracelet of Arul Saba ({$type} - {$numGems}/{$numGems}) found.");
			return;
		}
		$gems = [];
		$prefix = $numGems === 0 ? $arul->lesser_prefix : $arul->regular_prefix;
		$ingredients = new Ingredients();
		for ($i = 0; $i < $reqGems; $i++) {
			$name = $gemGrades[$i][0] . " {$prefix} {$arul->name}";
			$ingredient = $this->readIngredientByName($name);
			if (!isset($ingredient->item)) {
				$context->reply("Your bot's item database is missing information to illustrate the process.");
				return;
			}
			$ingredients->add($ingredient);
			$gems []= $ingredient->item;
		}
		// A lot of the items used in the TS process are simply missing in the AODB
		// so we have to work around this, because no one wants them in searches anyway

		// Blueprints
		$bpQL = $blueprints[$numGems][2];
		$balId = $side === "left" ? 3 : 5;
		$ingredient = $this->readIngredientByAoid($blueprints[$numGems][0], 1, $bpQL);
		if (!isset($ingredient) || !isset($ingredient->item)) {
			$context->reply("Item #{$blueprints[$numGems][0]} not found in bot's item database.");
			return;
		}
		$ingredients->add($ingredient);
		$bPrint = $ingredient->item;
		$bPrint->ql = $bpQL;
		$bbPrint = clone($bPrint);
		$bbPrint->lowid = $blueprints[$numGems][$balId];
		$bbPrint->highid = $blueprints[$numGems][$balId+1];
		$bbPrint->name = "Balanced Bracelet Blueprints";

		// Adjuster
		$ingredient = $this->readIngredientByName("Balance Adjuster - " . ucfirst($side));
		$ingredients->add($ingredient);
		$adjuster = $ingredient->item;
		// Ingots
		$minIngotQL = (int)ceil(0.7 * $bpQL);
		$ingredient = $this->readIngredientByName("Small Silver Ingot", $reqGems+1, $minIngotQL, true);
		$ingredients->add($ingredient);
		$ingot = $ingredient->item;
		// Furnace
		$ingredient = $this->readIngredientByName("Personal Furnace", $reqGems+1);
		$ingredients->add($ingredient);
		$furnace = $ingredient->item;
		// Robot Junk
		$minJunkQL = (int)ceil(0.53 * $bpQL);
		$ingredient = $this->readIngredientByName("Robot Junk", $reqGems, $minJunkQL, true);
		$ingredients->add($ingredient);
		$junk = $ingredient->item;
		// Wire
		$minWireQL = (int)ceil(0.35 * $bpQL);
		$ingredient = $this->readIngredientByName("Nano Circuitry Wire", $reqGems*2, $minWireQL, true);
		$ingredients->add($ingredient);
		$wire = $ingredient->item;
		// Wire Drawing Machine
		$ingredient = $this->readIngredientByName("Wire Drawing Machine", 1, 100, true);
		$ingredients->add($ingredient);
		$wireMachine = $ingredient->item;
		// Screwdriver
		$ingredient = $this->readIngredientByName("Screwdriver");
		$ingredients->add($ingredient);
		$screwdriver = $ingredient->item;

		if (!isset($adjuster)
			|| !isset($ingot)
			|| !isset($furnace)
			|| !isset($junk)
			|| !isset($wire)
			|| !isset($wireMachine)
			|| !isset($screwdriver)
		) {
			$context->reply("Your item database is missing some key items to illustrate the process.");
			return;
		}

		$blob = $this->renderIngredients($ingredients);

		$blob .= "<pagebreak><header2>Balancing the blueprint<end>\n".
			$this->renderStep($adjuster, $bPrint, $bbPrint, [static::ME => "*3", static::EE => "*3.2"]);
		$liqSilver         = AODBItem::fromEntry($this->itemsController->findByName("Liquid Silver", $ingot->ql));
		$silFilWire        = AODBItem::fromEntry($this->itemsController->findByName("Silver Filigree Wire", $ingot->ql));
		$silNaCircWire     = AODBItem::fromEntry($this->itemsController->findByName("Silver Nano Circuitry Filigree Wire", $ingot->ql));
		$nanoSensor        = AODBItem::fromEntry($this->itemsController->findById(150923));
		$intNanoSensor     = AODBItem::fromEntry($this->itemsController->findById(150926));
		$circuitry         = AODBItem::fromEntry($this->itemsController->findByName("Bracelet Circuitry", $ingot->ql));
		if (!isset($liqSilver)
			|| !isset($silFilWire)
			|| !isset($silNaCircWire)
			|| !isset($nanoSensor)
			|| !isset($intNanoSensor)
			|| !isset($circuitry)
		) {
			$context->reply("Your item database is missing some key items to illustrate the process.");
			return;
		}
		$liqSilver->ql     = $ingot->ql;
		$silFilWire->ql    = $liqSilver->ql;
		$silNaCircWire->ql = $silFilWire->ql;
		$nanoSensor->ql    = min(250, $junk->ql);
		$intNanoSensor->ql = $nanoSensor->ql;
		$circuitry->ql     = $silNaCircWire->ql;

		$blob .= "\n<pagebreak><header2>Bracelet circuitry ({$reqGems}x)<end>\n".
			$this->renderStep($furnace, $ingot, $liqSilver, [static::ME => "*3"]).
			$this->renderStep($wireMachine, $liqSilver, $silFilWire, [static::ME => "*4.5"]).
			$this->renderStep($wire, $silFilWire, $silNaCircWire, [static::ME => "*4",    static::AGI => "*1.7"]).
			$this->renderStep($screwdriver, $junk, $nanoSensor).
			$this->renderStep($wire, $nanoSensor, $intNanoSensor, [static::ME => "*3.5",  static::EE  => "*4.25"]).
			$this->renderStep($intNanoSensor, $silNaCircWire, $circuitry, [static::ME => "*4.25", static::EE  => "*4.8", static::AGI => "*1.8"]);

		$socket = ($reqGems > 1) ? "{$reqGems} sockets" : "a socket";
		$blob .= "\n<pagebreak><header2>Add {$socket} to the bracelet<end>\n";
		$target = $bbPrint;
		for ($i = 0; $i < $reqGems; $i++) {
			$result = clone($target);
			$result->highid = $result->lowid = $unfinished[$numGems][$side][$i];
			$result->icon = $icons[$i];
			$result->name = "Unfinished Bracelet of Arul Saba";

			$result->ql = $result->lowql;
			$blob .= $this->renderStep($circuitry, $target, $result, [static::ME => "*4", static::EE => "*4.2"]);
			$target = $result;
		}
		if (!isset($result)) {
			$context->reply("You managed to break the module. Great.");
			return;
		}

		/** @var AODBItem $result */
		$coated = clone($result);
		$coated->lowid = $coated->highid = $finished[$numGems][$side];
		$coated->name = "Bracelet of Arul Saba";
		$blob .= "\n<pagebreak><header2>Add silver coating<end>\n".
			$this->renderStep($furnace, $ingot, $liqSilver, [static::ME => "*3"]).
			$this->renderStep($liqSilver, $result, $coated);

		$blob .= "\n<pagebreak><header2>Add the gems<end>\n";
		$target = $coated;
		for ($i = 0; $i < $reqGems; $i++) {
			$gem = $gems[$i];
			$resultName = "Bracelet of Arul Saba ({$prefix} {$arul->name} - ".
				($i + 1) . "/{$reqGems} - ".
				ucfirst($side) . ")";
			$result = AODBItem::fromEntry($this->itemsController->findByName($resultName));
			if (!isset($result)) {
				$context->reply("Unable to find the item {$resultName} in your bot's item database.");
				return;
			}
			$result->ql = $result->lowql;
			$blob .= $this->renderStep($gem, $target, $result, [static::ME => $gemGrades[$i][2], static::EE => $gemGrades[$i][3]]);
			$target = $result;
		}

		$blob .= "\n\n<i>The number in brackets behind a skill requirement is ".
			"how many times the QL of the target item is actually required ".
			"to do the tradeskill. The example numbers listed are only correct ".
			"for the exact QLs shown in the equation</i>";

		$msg = $this->text->makeBlob(
			"Recipe for a Bracelet of Arul Saba ({$prefix} {$arul->name} - ".
			"{$reqGems}/{$reqGems} - " . ucfirst($side) . ")",
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * @param array<int,string|int> $skillReqs
	 */
	protected function renderStep(AODBItem $source, AODBItem $dest, AODBItem $result, array $skillReqs=[]): string {
		$showImages = $this->settingManager->getInt('arulsaba_show_images');
		$sLink = $source->getLink();
		$sIcon = $this->text->makeImage($source->icon);
		$sIconLink = $source->getLink(name: $sIcon);
		$dLink = $dest->getLink();
		$dIcon = $this->text->makeImage($dest->icon);
		$dIconLink = $dest->getLink(name: $dIcon);
		$rLink = $result->getLink();
		$rIcon = $this->text->makeImage($result->icon);
		$rIconLink = $result->getLink(name: $rIcon);

		$line = "";

		if ($showImages === 1) {
			$sIconLink = $sIcon;
			$dIconLink = $dIcon;
			$rIconLink = $rIcon;
		}
		if ($showImages) {
			$line = "<tab>".
				$sIconLink.
				"<tab><img src=tdb://id:GFX_GUI_CONTROLCENTER_BIGARROW_RIGHT_STATE1><tab>".
				$dIconLink.
				"<tab><img src=tdb://id:GFX_GUI_CONTROLCENTER_BIGARROW_RIGHT_STATE1><tab>".
				$rIconLink . "\n";
		}
		$line .= "<tab>{$sLink} + {$dLink} = {$rLink}";
		if (((($dest->flags??0) & ItemFlag::NO_DROP) === 0) && (($result->flags??0) & ItemFlag::NO_DROP)) {
			$line .= " (becomes <highlight>NODROP<end>)";
		}
		$line .= "\n";
		if (!count($skillReqs)) {
			$line .= "<tab><yellow>No skills required<end>\n\n";
			if ($showImages) {
				$line .= "\n";
			}
			return $line;
		}
		$requirements = [];
		foreach ($skillReqs as $skillID => $amount) {
			$amount = (string)$amount;
			$skill = $this->readSkill($skillID);
			if (!isset($skill)) {
				throw new Exception("Unable to find skill {$skillID}");
			}
			if (substr($amount, 0, 1) === "*") {
				$exAmount = (int)ceil((float)substr($amount, 1) * $dest->ql);
				$requirements []= "<yellow>{$skill->name}: {$exAmount}<end> (" . substr($amount, 1) . "x)";
			} else {
				$exAmount = (int)$amount;
				$requirements []= "<yellow>{$skill->name}: {$exAmount}<end>";
			}
		}
		$line .= "<tab>" . join(", ", $requirements) . "\n\n";
		if ($showImages) {
			$line .= "\n";
		}
		return $line;
	}

	protected function readSkill(int $id): ?Skill {
		return $this->db->table("skills")
			->where("id", $id)
			->asObj(Skill::class)
			->first();
	}

	/** Render the given ingredients to a blob */
	public function renderIngredients(Ingredients $ingredients): string {
		$blob = "<header2>Ingredients<end>\n";
		$maxAmount = $ingredients->getMaxAmount();
		foreach ($ingredients as $ing) {
			$ql = (string)($ing->ql ?: "");
			if (isset($ing->item)) {
				$item = $ing->item;
				$link = $this->text->makeItem($item->lowid, $item->highid, $ing->ql ?? $item->lowql, $item->name);
				if ($item->lowql === $item->highql) {
					$ql = "";
				}
			} else {
				$link = $ing->name;
			}
			if (strlen($ql)) {
				$ql = "QL{$ql}";
				if ($ing->qlCanBeHigher) {
					$ql .= "+";
				}
				$ql .= " ";
			}
			if ($maxAmount === 1) {
				$amount = "";
			} elseif ($ing->amount === 1) {
				$amount = "<black>" . str_repeat("0", strlen((string)$maxAmount)-1) . "1×<end> ";
			} else {
				$amount = $this->text->alignNumber($ing->amount, strlen((string)$maxAmount), "orange") . "× ";
			}
			$blob .= "<tab>{$amount}{$ql}{$link}";
			if (isset($ing->where)) {
				$blob .= " ({$ing->where})";
			}
			$blob .= "\n";
		}
		return "$blob\n";
	}
}
