<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Util;
use Nadybot\Core\Text;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;
use Nadybot\Modules\ITEMS_MODULE\ItemFlag;
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\ITEMS_MODULE\Skill;

/**
 * @author Nadyita
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'arulsaba',
 *		accessLevel = 'all',
 *		description = 'Get recipe for Arul Saba bracers',
 *		help        = 'arulsaba.txt',
 *		alias       = 'aruls'
 *	)
 */
class ArulSabaController {
	public const ME = 125;
	public const EE = 126;
	public const AGI = 17;

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public ItemsController $itemsController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/ArulSaba");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/arulsaba.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/arulsaba_buffs.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/ingredient.csv");
		$this->settingManager->add(
			$this->moduleName,
			'arulsaba_show_images',
			"Show images for the Arul Saba steps",
			"edit",
			"options",
			"2",
			"yes, with links;yes;no",
			"2;1;0"
		);
	}

	/**
	 * @HandlesCommand("arulsaba")
	 * @Matches("/^arulsaba$/i")
	 */
	public function arulSabaListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("arulsaba")
	 * @Matches("/^arulsaba ([^ ]+)$/i")
	 */
	public function arulSabaChooseQLCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Collection<ArulSabaBuffs> */
		$aruls = $this->db->table("arulsaba_buffs")
			->where("name", ucfirst(strtolower($args[1])))
			->orderBy("min_level")
			->asObj(ArulSabaBuffs::class);
		if ($aruls->isEmpty()) {
			$sendto->reply("No Bracelet of Arul Saba ({$args[1]}) found.");
			return;
		}
		$blob = '';
		$gems = 0;
		foreach ($aruls as $arul) {
			$buffs = $this->db->table("item_buffs AS ib")
				->join("skills AS s", "ib.attribute_id", "s.id")
				->where("ib.item_id", $arul->left_aoid)
				->select("s.name", "ib.amount", "s.unit")
				->asObj();
			$item = $this->itemsController->findById($arul->left_aoid);
			$shortName = preg_replace("/^.*\((.+?) - Left\)$/", "$1", $item->name);
			$blob .= "<header2>{$shortName}<end>\n".
				"<tab>Min level: <highlight>{$arul->min_level}<end>\n";
			foreach ($buffs as $buff) {
				$blob .= "<tab>{$buff->name}: <highlight>+{$buff->amount}{$buff->unit}<end>\n";
			}
			$leftLink = $this->text->makeChatcmd("Left", "/tell <myname> arulsaba {$arul->name} {$gems} left");
			$rightLink = $this->text->makeChatcmd("Right", "/tell <myname> arulsaba {$arul->name} {$gems} right");
			$blob .= "<tab>Recipe: [{$leftLink}] [{$rightLink}]\n\n";
			$gems++;
		}
		$msg = $this->text->makeBlob("Types of a Arul Saba {$aruls[0]->name} bracelet", $blob);
		$sendto->reply($msg);
	}

	protected function enrichIngredient(?Ingredient $ing, int $amount, ?int $ql=null, bool $qlCanBeHigher=false): ?Ingredient {
		if (!isset($ing)) {
			return null;
		}
		$ing->qlCanBeHigher = $qlCanBeHigher;
		if (isset($ql)) {
			$ing->ql = $ql;
		}
		$ing->amount = $amount;
		if (!isset($ing->aoid)) {
			return $ing;
		}
		$ing->item = $this->itemsController->findById($ing->aoid);
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
		return $this->enrichIngredient($ing, $amount, $ql, $qlCanBeHigher);
	}

	public function readIngredientByName(string $name, int $amount=1, ?int $ql=null, bool $qlCanBeHigher=false): ?Ingredient {
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
		return $this->enrichIngredient($ing, $amount, $ql, $qlCanBeHigher);
	}

	/**
	 * @HandlesCommand("arulsaba")
	 * @Matches("/^arulsaba ([^ ]+) (\d+) (left|right)$/i")
	 */
	public function arulSabaRecipeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$type = ucfirst(strtolower($args[1]));
		$numGems = (int)$args[2];
		$reqGems = max(1, $numGems);
		$side = strtolower($args[3]);

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
		if (!isset($arul)) {
			$sendto->reply("No Bracelet of Arul Saba ({$type}) found.");
			return;
		}
		$gems = [];
		$prefix = $numGems === 0 ? $arul->lesser_prefix : $arul->regular_prefix;
		$ingredients = new Ingredients();
		for ($i = 0; $i < $reqGems; $i++) {
			$name = $gemGrades[$i][0] . " {$prefix} {$arul->name}";
			$ingredients->add($this->readIngredientByName($name));
			$gems []= $ingredients->last()->item;
		}
		// A lot of the items used in the TS process are simply missing in the AODB
		// so we have to work around this, because no one wants them in searches anyway

		// Blueprints
		$bpQL = $blueprints[$numGems][2];
		$balId = $side === "left" ? 3 : 5;
		$ingredients->add($this->readIngredientByAoid($blueprints[$numGems][0], 1, $bpQL));
		$bPrint = $ingredients->last()->item;
		$bPrint->ql = $bpQL;
		$bbPrint = clone($bPrint);
		$bbPrint->lowid = $blueprints[$numGems][$balId];
		$bbPrint->highid = $blueprints[$numGems][$balId+1];
		$bbPrint->name = "Balanced Bracelet Blueprints";

		// Adjuster
		$ingredients->add($this->readIngredientByName("Balance Adjuster - " . ucfirst($side)));
		$adjuster = $ingredients->last()->item;
		// Ingots
		$minIngotQL = (int)ceil(0.7 * $bpQL);
		$ingredients->add($this->readIngredientByName("Small Silver Ingot", $reqGems+1, $minIngotQL, true));
		$ingot = $ingredients->last()->item;
		// Furnace
		$ingredients->add($this->readIngredientByName("Personal Furnace", $reqGems+1));
		$furnace = $ingredients->last()->item;
		// Robot Junk
		$minJunkQL = (int)ceil(0.53 * $bpQL);
		$ingredients->add($this->readIngredientByName("Robot Junk", $reqGems, $minJunkQL, true));
		$junk = $ingredients->last()->item;
		// Wire
		$minWireQL = (int)ceil(0.35 * $bpQL);
		$ingredients->add($this->readIngredientByName("Nano Circuitry Wire", $reqGems*2, $minWireQL, true));
		$wire = $ingredients->last()->item;
		// Wire Drawing Machine
		$ingredients->add($this->readIngredientByName("Wire Drawing Machine", 1, 100, true));
		$wireMachine = $ingredients->last()->item;
		// Screwdriver
		$ingredients->add($this->readIngredientByName("Screwdriver"));
		$screwdriver = $ingredients->last()->item;

		$blob = $this->renderIngredients($ingredients);

		$blob .= "<pagebreak><header2>Balancing the blueprint<end>\n".
			$this->renderStep($adjuster, $bPrint, $bbPrint, [static::ME => "*3", static::EE => "*3.2"]);
		$liqSilver         = $this->itemsController->findByName("Liquid Silver", $ingot->ql);
		$liqSilver->ql     = $ingot->ql;
		$silFilWire        = $this->itemsController->findByName("Silver Filigree Wire", $liqSilver->ql);
		$silFilWire->ql    = $liqSilver->ql;
		$silNaCircWire     = $this->itemsController->findByName("Silver Nano Circuitry Filigree Wire", $silFilWire->ql);
		$silNaCircWire->ql = $silFilWire->ql;
		$nanoSensor        = $this->itemsController->findById(150923);
		$nanoSensor->ql    = min(250, $junk->ql);
		$intNanoSensor     = $this->itemsController->findById(150926);
		$intNanoSensor->ql = $nanoSensor->ql;
		$circuitry         = $this->itemsController->findByName("Bracelet Circuitry", $silNaCircWire->ql);
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
			$sendto->reply("You managed to break the module. Great.");
			return;
		}

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
			$result = $this->itemsController->findByName($resultName);
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
		$sendto->reply($msg);
	}

	protected function renderStep(AODBEntry $source, AODBEntry $dest, AODBEntry $result, array $skillReqs=[]): string {
		$showImages = $this->settingManager->getInt('arulsaba_show_images');
		$sLink = $this->text->makeItem($source->lowid, $source->highid, $source->ql, $source->name);
		$sIcon = $this->text->makeImage($source->icon);
		$sIconLink = $this->text->makeItem($source->lowid, $source->highid, $source->ql, $sIcon);
		$dLink = $this->text->makeItem($dest->lowid, $dest->highid, $dest->ql, $dest->name);
		$dIcon = $this->text->makeImage($dest->icon);
		$dIconLink = $this->text->makeItem($dest->lowid, $dest->highid, $dest->ql, $dIcon);
		$rLink = $this->text->makeItem($result->lowid, $result->highid, $result->ql, $result->name);
		$rIcon = $this->text->makeImage($result->icon);
		$rIconLink = $this->text->makeItem($result->lowid, $result->highid, $result->ql, $rIcon);

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
