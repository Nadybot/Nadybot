<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use function Safe\{json_decode, json_encode};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PAttribute,
	Text,
	Util,
};

use stdClass;

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Designer"),
	NCA\DefineCommand(
		command: "implantdesigner",
		accessLevel: "guest",
		description: "Implant Designer",
		alias: "impdesign"
	),
	NCA\DefineCommand(
		command: "implantshoppinglist",
		accessLevel: "guest",
		description: "Implant Designer Shopping List",
		alias: ["impshop", "implantshoplist", "impshoplist"],
	)
]
class ImplantDesignerController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private ImplantController $implantController;

	/** @var string[] */
	private array $slots = ['head', 'eye', 'ear', 'rarm', 'chest', 'larm', 'rwrist', 'waist', 'lwrist', 'rhand', 'legs', 'lhand', 'feet'];

	/** @var string[] */
	private array $grades = ['shiny', 'bright', 'faded'];

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/Ability.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/Cluster.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/ClusterImplantMap.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/ClusterType.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/EffectTypeMatrix.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/EffectValue.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/ImplantMatrix.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/ImplantType.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/Profession.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/Symbiant.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/SymbiantAbilityMatrix.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/SymbiantClusterMatrix.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/SymbiantProfessionMatrix.csv");
	}

	/** Show a shopping list for your current implant design */
	#[NCA\HandlesCommand("implantshoppinglist")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantShoplistCommand(CmdContext $context): void {
		$design = $this->getDesign($context->char->name, '@');
		$list = new ShoppingList();

		/** @var array<string,string> */
		$lookup = $this->db->table("Cluster")
			->asObj(Cluster::class)
			->reduce(
				function (array $lookup, Cluster $cluster): array {
					$lookup[$cluster->LongName] = $cluster->OfficialName;
					return $lookup;
				},
				[]
			);
		foreach ($this->slots as $slot) {
			if (!property_exists($design, $slot)) {
				continue;
			}
			$slotObj = $design->{$slot};
			// Symbiants are not part of the shopping list
			if (property_exists($slotObj, "symb") && $slotObj->symb !== null) {
				continue;
			}
			$ql = empty($slotObj->ql) ? 300 : (int)$slotObj->ql;
			$addImp = false;
			$refined = "";
			if ($ql > 200) {
				$refined = "Refined ";
			}
			foreach (["shiny", "bright", "faded"] as $grade) {
				if (empty($slotObj->{$grade})) {
					continue;
				}
				$name = $lookup[$slotObj->{$grade}];
				if (str_ends_with($name, "Jobe")) {
					$name = str_replace(" Jobe", " {$refined}Jobe Cluster", $name);
				} else {
					$name .= " {$refined}Cluster";
				}
				$clusterQL = $this->implantController->getClusterMinQl($ql, $grade);
				if ($ql > 200 && $clusterQL < 201) {
					$clusterQL = 201;
				}
				$name .= " (QL {$clusterQL}+)";
				$listGrade = "{$grade}Clusters";
				$list->{$listGrade} []= $name;
				$addImp = true;
			}
			if ($addImp) {
				/** @var string */
				$longName = $this->db->table("ImplantType")
					->where("ShortName", $slot)
					->pluckStrings("Name")
					->firstOrFail();
				if ($ql > 200) {
					$list->implants []= "{$longName} Implant Refined Empty (QL {$ql})";
				} else {
					$list->implants []= "Basic {$longName} Implant (QL {$ql})";
				}
			}
		}
		$blob = $this->renderShoppingList($list);
		if (empty($blob)) {
			$context->reply("Nothing to buy.");
			return;
		}
		$msg = $this->text->makeBlob("Implant Shopping List", $blob);
		$context->reply($msg);
	}

	/** Look at your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	#[NCA\Help\Epilogue(
		"<i>Slot can be any of head, eye, ear, rarm, chest, larm, rwrist, waist, ".
		"lwrist, rhand, legs, lhand, and feet.</i>"
	)]
	public function implantdesignerCommand(CmdContext $context): void {
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/** Remove all clusters from your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerClearCommand(CmdContext $context, #[NCA\Str("clear")] string $action): void {
		$this->saveDesign($context->char->name, '@', new stdClass());
		$msg = "Implant Designer has been cleared.";
		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/** See a specific slot in your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerSlotCommand(CmdContext $context, PImplantSlot $slot): void {
		$slot = $slot();

		$blob  = "[" . $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
		$blob .= "]<tab>[";
		$blob .= $this->text->makeChatcmd("Clear this slot", "/tell <myname> implantdesigner {$slot} clear");
		$blob .= "]<tab>[";
		$blob .= $this->text->makeChatcmd("Require Ability", "/tell <myname> implantdesigner {$slot} require");
		$blob .= "]\n\n\n";
		$blob .= "<header2>Implants<end>  ";
		foreach ([25, 50, 75, 100, 125, 150, 175, 200, 225, 250, 275, 300] as $ql) {
			$blob .= $this->text->makeChatcmd((string)$ql, "/tell <myname> implantdesigner {$slot} {$ql}") . " ";
		}
		$blob .= "\n\n" . $this->getSymbiantsLinks($slot);
		$blob .= "\n\n\n";

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->{$slot};

		if ($slotObj->symb !== null) {
			$symb = $slotObj->symb;
			$blob .= $symb->name ."\n\n";
			$blob .= "<header2>Requirements<end>\n";
			$blob .= "Treatment: {$symb->Treatment}\n";
			$blob .= "Level: {$symb->Level}\n";
			foreach ($symb->reqs as $req) {
				$blob .= "{$req->Name}: {$req->Amount}\n";
			}
			$blob .= "\n<header2>Modifications<end>\n";
			foreach ($symb->mods as $mod) {
				$blob .= "{$mod->Name}: {$mod->Amount}\n";
			}
			$blob .= "\n\n";
		} else {
			$ql = empty($design->{$slot}->ql) ? 300 : (int)$design->{$slot}->ql;
			$blob .= "<header2>QL<end> {$ql}";
			$implant = $this->getImplantInfo($ql, $design->{$slot}->shiny, $design->{$slot}->bright, $design->{$slot}->faded);
			if ($implant !== null) {
				$blob .= " - Treatment: {$implant->Treatment} {$implant->AbilityName}: {$implant->Ability}";
			}
			$blob .= "\n\n";

			$blob .= "<header2>Shiny<end>";
			$blob .= $this->showClusterChoices($design, $slot, 'shiny');

			$blob .= "<header2>Bright<end>";
			$blob .= $this->showClusterChoices($design, $slot, 'bright');

			$blob .= "<header2>Faded<end>";
			$blob .= $this->showClusterChoices($design, $slot, 'faded');
		}

		$msg = $this->text->makeBlob("Implant Designer ({$slot})", $blob);

		$context->reply($msg);
	}

	/** Add a cluster to a slot in your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerSlotAddClusterCommand(
		CmdContext $context,
		PImplantSlot $slot,
		PClusterSlot $grade,
		string $cluster
	): void {
		$slot = $slot();
		$grade = $grade();
		$design = $this->getDesign($context->char->name, '@');
		$design->{$slot} ??= new stdClass();

		/** @psalm-suppress UnsupportedReferenceUsage */
		$slotObj = &$design->{$slot};

		if ($grade === 'symb') {
			/** @var ?Symbiant */
			$symbRow = $this->db->table("Symbiant AS s")
				->join("ImplantType AS i", "s.SlotID", "i.ImplantTypeID")
				->where("i.ShortName", $slot)
				->where("s.Name", $cluster)
				->select("s.*")
				->asObj(Symbiant::class)->first();

			if ($symbRow === null) {
				$msg = "Could not find symbiant <highlight>{$cluster}<end>.";
			} else {
				// convert slot to symb
				unset($slotObj->shiny);
				unset($slotObj->bright);
				unset($slotObj->faded);
				unset($slotObj->ql);

				$symb = new stdClass();
				$symb->name = $symbRow->Name;
				$symb->Treatment = $symbRow->TreatmentReq;
				$symb->Level = $symbRow->LevelReq;

				// add requirements
				$symb->reqs = $this->db->table("SymbiantAbilityMatrix AS s")
					->join("Ability AS a", "s.AbilityID", "a.AbilityID")
					->where("SymbiantID", $symbRow->ID)
					->select(["a.Name", "s.Amount"])
					->asObj(AbilityAmount::class)->toArray();

				// add mods
				$symb->mods = $this->db->table("SymbiantClusterMatrix AS s")
					->join("Cluster AS c", "s.ClusterID", "c.ClusterID")
					->where("SymbiantID", $symbRow->ID)
					->select(["c.LongName AS Name", "s.Amount"])
					->asObj(AbilityAmount::class)->toArray();

				$slotObj->symb = $symb;
				$msg = "<highlight>{$slot}(symb)<end> has been set to <highlight>{$symb->name}<end>.";
			}
		} else {
			if (strtolower($cluster) == 'clear') {
				if ($slotObj->{$grade} === null) {
					$msg = "There is no cluster in <highlight>{$slot}({$grade})<end>.";
				} else {
					unset($slotObj->{$grade});
					$msg = "<highlight>{$slot}({$grade})<end> has been cleared.";
				}
			} else {
				unset($slotObj->{$grade});
				$slotObj->{$grade} = $cluster;
				$msg = "<highlight>{$slot}({$grade})<end> has been set to <highlight>{$cluster}<end>.";
			}
		}

		$this->saveDesign($context->char->name, '@', $design);

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/** Set the QL for a slot in your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerSlotQLCommand(
		CmdContext $context,
		PImplantSlot $slot,
		int $ql
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		if (!isset($design->{$slot})) {
			$design->{$slot} = new stdClass();
		}
		$slotObj = $design->{$slot};
		unset($slotObj->symb);
		$slotObj->ql = $ql;
		$this->saveDesign($context->char->name, '@', $design);

		$msg = "<highlight>{$slot}<end> has been set to QL <highlight>{$ql}<end>.";

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/** Clear all clusters from a slot in your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerSlotClearCommand(
		CmdContext $context,
		PImplantSlot $slot,
		#[NCA\Str("clear")]
		string $action
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		unset($design->{$slot});
		$this->saveDesign($context->char->name, '@', $design);

		$msg = "<highlight>{$slot}<end> has been cleared.";

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/** Show how to make a slot require a certain attribute in your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerSlotRequireCommand(
		CmdContext $context,
		PImplantSlot $slot,
		#[NCA\Str("require")]
		string $action
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->{$slot};
		if (empty($slotObj)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->symb)) {
			$msg = "You cannot require an ability for a symbiant.";
		} elseif (empty($slotObj->shiny) && empty($slotObj->bright) && empty($slotObj->faded)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->shiny) && !empty($slotObj->bright) && !empty($slotObj->faded)) {
			$msg = "You must have at least one empty cluster to require an ability.";
		} else {
			$blob  = "[" . $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
			$blob .= "]<tab>[";
			$blob .= $this->text->makeChatcmd("Clear this slot", "/tell <myname> implantdesigner {$slot} clear");
			$blob .= "]\n\n\n";
			$blob .= $this->text->makeChatcmd($slot, "/tell <myname> implantdesigner {$slot}");
			if ($slotObj instanceof stdClass) {
				$blob .= $this->getImplantSummary($slotObj) . "\n";
			}
			$blob .= "Which ability do you want to require for {$slot}?\n\n";
			$abilities = $this->db->table("Ability")->select("Name")
				->pluckStrings("Name")->toArray();
			foreach ($abilities as $ability) {
				$blob .= $this->text->makeChatcmd($ability, "/tell <myname> implantdesigner {$slot} require {$ability}") . "\n";
			}
			$msg = $this->text->makeBlob("Implant Designer Require Ability ({$slot})", $blob);
		}

		$context->reply($msg);
	}

	/** Show how to make a slot require a certain attribute in your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerSlotRequireAbilityCommand(
		CmdContext $context,
		PImplantSlot $slot,
		#[NCA\Str("require")]
		string $action,
		PAttribute $ability
	): void {
		$slot = $slot();
		$ability = $ability();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->{$slot};
		if (empty($slotObj)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->symb)) {
			$msg = "You cannot require an ability for a symbiant.";
		} elseif (empty($slotObj->shiny) && empty($slotObj->bright) && empty($slotObj->faded)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->shiny) && !empty($slotObj->bright) && !empty($slotObj->faded)) {
			$msg = "You must have at least one empty cluster to require an ability.";
		} else {
			$blob  = "[" . $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
			$blob .= "]<tab>[";
			$blob .= $this->text->makeChatcmd("Clear this slot", "/tell <myname> implantdesigner {$slot} clear");
			$blob .= "]\n\n\n";
			$blob .= $this->text->makeChatcmd($slot, "/tell <myname> implantdesigner {$slot}");
			if ($slotObj instanceof stdClass) {
				$blob .= $this->getImplantSummary($slotObj) . "\n";
			}
			$blob .= "Combinations for <highlight>{$slot}<end> that will require {$ability}:\n";
			$query = $this->db
				->table("ImplantMatrix AS i")
				->join("Cluster AS c1", "i.ShiningID", "c1.ClusterID")
				->join("Cluster AS c2", "i.BrightID", "c2.ClusterID")
				->join("Cluster AS c3", "i.FadedID", "c3.ClusterID")
				->join("Ability AS a", "i.AbilityID", "a.AbilityID")
				->where("a.Name", ucfirst($ability))
				->select(["i.AbilityQL1", "i.AbilityQL200", "i.AbilityQL201"])
				->addSelect(["i.AbilityQL300", "i.TreatQL1", "i.TreatQL200"])
				->addSelect(["i.TreatQL201", "i.TreatQL300"])
				->addSelect("c1.LongName as ShinyEffect")
				->addSelect("c2.LongName as BrightEffect")
				->addSelect("c3.LongName as FadedEffect")
				->orderBy("c1.LongName")
				->orderBy("c2.LongName")
				->orderBy("c3.LongName");

			if (!empty($slotObj->shiny)) {
				$query->where("c1.LongName", $slotObj->shiny);
			}
			if (!empty($slotObj->bright)) {
				$query->where("c2.LongName", $slotObj->bright);
			}
			if (!empty($slotObj->faded)) {
				$query->where("c3.LongName", $slotObj->faded);
			}

			/** @var ImplantLayout[] */
			$data = $query->asObj(ImplantLayout::class)->toArray();
			$primary = null;
			foreach ($data as $row) {
				$results = [];
				if (empty($slotObj->shiny)) {
					$results []= ['shiny', $row->ShinyEffect];
				}
				if (empty($slotObj->bright)) {
					$results []= ['bright', $row->BrightEffect];
				}
				if (empty($slotObj->faded)) {
					$results []= ['faded', $row->FadedEffect];
				}

				/** @var string[] */
				$results = array_map(function ($item) use ($slot) {
					return empty($item[1]) ? '-Empty-' : $this->text->makeChatcmd($item[1], "/tell <myname> implantdesigner {$slot} {$item[0]} {$item[1]}");
				}, $results);
				if ($results[0] != $primary) {
					$blob .= "\n" . $results[0] . "\n";
					$primary = $results[0];
				}
				if (isset($results[1])) {
					$blob .= "<tab>" . $results[1] . "\n";
				}
			}
			$count = count($data);
			$msg = $this->text->makeBlob("Implant Designer Require {$ability} ({$slot}) ({$count})", $blob);
		}

		$context->reply($msg);
	}

	/** Show the result of your current implant design */
	#[NCA\HandlesCommand("implantdesigner")]
	#[NCA\Help\Group("implantdesigner")]
	public function implantdesignerResultCommand(CmdContext $context, #[NCA\Str("result", "results")] string $action): void {
		$blob = $this->getImplantDesignerResults($context->char->name);

		$msg = $this->text->makeBlob("Implant Designer Results", $blob);

		$context->reply($msg);
	}

	public function getImplantDesignerResults(string $name): string {
		$design = $this->getDesign($name, '@');

		$mods = [];
		$reqs = ['Treatment' => 0, 'Level' => 1];  // force treatment and level to be shown first
		$implants = [];
		$clusters = [];

		foreach ($this->slots as $slot) {
			$slotObj = $design->{$slot};

			// skip empty slots
			if (empty($slotObj)) {
				continue;
			}

			if (!empty($slotObj->symb)) {
				$symb = $slotObj->symb;

				// add reqs
				if ($symb->Treatment > $reqs['Treatment']) {
					$reqs['Treatment'] = $symb->Treatment;
				}
				if ($symb->Level > $reqs['Level']) {
					$reqs['Level'] = $symb->Level;
				}
				foreach ($symb->reqs as $req) {
					if ($req->Amount > $reqs[$req->Name]) {
						$reqs[$req->Name] = $req->Amount;
					}
				}

				// add mods
				foreach ($symb->mods as $mod) {
					$mods[$mod->Name] += $mod->Amount;
				}
			} else {
				$ql = 300;
				if (!empty($slotObj->ql)) {
					$ql = (int)$slotObj->ql;
				}

				// add reqs
				$implant = $this->getImplantInfo($ql, $slotObj->shiny, $slotObj->bright, $slotObj->faded);
				if (isset($implant) && $implant->Treatment > $reqs['Treatment']) {
					$reqs['Treatment'] = $implant->Treatment;
				}
				if (isset($implant) && $implant->Ability > $reqs[$implant->AbilityName]) {
					$reqs[$implant->AbilityName] = $implant->Ability;
				}

				// add implant
				$obj = new stdClass();
				$obj->ql = $ql;
				$obj->slot = $slot;
				$implants []= $obj;

				// add mods
				foreach ($this->grades as $grade) {
					if (!empty($slotObj->{$grade})) {
						$effectTypeIdName = ucfirst(strtolower($grade)) . 'EffectTypeID';
						$effectId = $implant->{$effectTypeIdName};
						$mods[$slotObj->{$grade}] += $this->getClusterModAmount($ql, $grade, $effectId);

						// add cluster
						$obj = new stdClass();
						$obj->ql = $this->implantController->getClusterMinQl($ql, $grade);
						$obj->slot = $slot;
						$obj->grade = $grade;
						$obj->name = $slotObj->{$grade};
						$clusters []= $obj;
					}
				}
			}
		}

		// sort mods by name alphabetically
		ksort($mods);

		// sort clusters by name alphabetically, and then by grade, shiny first
		$grades = $this->grades;
		usort($clusters, function (object $cluster1, object $cluster2) use ($grades): int {
			$val = strcmp($cluster1->name, $cluster2->name);
			if ($val === 0) {
				$val1 = array_search($cluster1->grade, $grades);
				$val2 = array_search($cluster2->grade, $grades);
				return $val1 <=> $val2;
			}
			return $val <=> 0;
		});

		$blob  = "[" . $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
		$blob .= "]\n\n\n";

		$blob .= "<header2>Requirements to Equip<end>\n";
		foreach ($reqs as $requirement => $amount) {
			$blob .= "{$requirement}: <highlight>{$amount}<end>\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Skills Gained<end>\n";
		foreach ($mods as $skill => $amount) {
			$blob .= "{$skill}: <highlight>{$amount}<end>\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Basic Implants Needed<end>\n";
		foreach ($implants as $implant) {
			$blob .= "<highlight>{$implant->slot}<end> ({$implant->ql})\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Clusters Needed<end>\n";
		foreach ($clusters as $cluster) {
			$blob .= "<highlight>{$cluster->name}<end>, {$cluster->grade} ({$cluster->ql}+)\n";
		}

		return $blob;
	}

	public function getImplantInfo(int $ql, ?string $shiny, ?string $bright, ?string $faded): ?ImplantInfo {
		/** @var ?ImplantInfo */
		$row = $this->db->table("ImplantMatrix AS i")
			->join("Cluster AS c1", "i.ShiningID", "c1.ClusterID")
			->join("Cluster AS c2", "i.BrightID", "c2.ClusterID")
			->join("Cluster AS c3", "i.FadedID", "c3.ClusterID")
			->join("Ability AS a", "i.AbilityID", "a.AbilityID")
			->where("c1.LongName", $shiny ?? "")
			->where("c2.LongName", $bright ?? "")
			->where("c3.LongName", $faded ?? "")
			->select(["i.AbilityQL1", "i.AbilityQL200"])
			->addSelect(["i.AbilityQL201", "i.AbilityQL300", "i.TreatQL1"])
			->addSelect(["i.TreatQL200", "i.TreatQL201", "i.TreatQL300"])
			->addSelect("c1.EffectTypeID as ShinyEffectTypeID")
			->addSelect("c2.EffectTypeID as BrightEffectTypeID")
			->addSelect("c3.EffectTypeID as FadedEffectTypeID")
			->addSelect("a.Name AS AbilityName")
			->limit(1)
			->asObj(ImplantInfo::class)
			->first();

		if ($row === null) {
			return null;
		}
		return $this->addImplantInfo($row, $ql);
	}

	/** @return string[] */
	public function getClustersForSlot(string $implantType, string $clusterType): array {
		return $this->db
			->table("Cluster AS c1")
			->join("ClusterImplantMap AS c2", "c1.ClusterID", "c2.ClusterID")
			->join("ClusterType AS c3", "c2.ClusterTypeID", "c3.ClusterTypeID")
			->join("ImplantType AS i", "c2.ImplantTypeID", "i.ImplantTypeID")
			->where("i.ShortName", strtolower($implantType))
			->where("c3.Name", strtolower($clusterType))
			->select("LongName AS skill")
			->pluckStrings("skill")
			->toArray();
	}

	public function getDesign(string $sender, string $name): stdClass {
		$design = $this->db->table("implant_design")
			->where("owner", $sender)
			->where("name", $name)
			->pluckStrings("design")
			->first();
		if ($design === null) {
			return new stdClass();
		}
		return json_decode($design);
	}

	public function saveDesign(string $sender, string $name, object $design): void {
		$json = json_encode($design);
		$this->db->table("implant_design")
			->updateOrInsert(
				[
					"owner" => $sender,
					"name" => $name,
				],
				[
					"design" => $json,
					"dt" => time(),
				],
			);
	}

	private function renderShoppingList(ShoppingList $list): string {
		/** @var string[] */
		$parts = [];
		if (!empty($list->implants)) {
			$part = "<header2>Empty Implants<end>";
			sort($list->implants);
			foreach ($list->implants as $implant) {
				$part .= "\n<tab>- {$implant}";
			}
			$parts []= $part;
		}
		if (!empty($list->shinyClusters)) {
			$part = "<header2>Shiny Clusters<end>";
			sort($list->shinyClusters);
			foreach ($list->shinyClusters as $cluster) {
				$part .= "\n<tab>- {$cluster}";
			}
			$parts []= $part;
		}
		if (!empty($list->brightClusters)) {
			$part = "<header2>Bright Clusters<end>";
			sort($list->brightClusters);
			foreach ($list->brightClusters as $cluster) {
				$part .= "\n<tab>- {$cluster}";
			}
			$parts []= $part;
		}
		if (!empty($list->fadedClusters)) {
			$part = "<header2>Faded Clusters<end>";
			sort($list->fadedClusters);
			foreach ($list->fadedClusters as $cluster) {
				$part .= "\n<tab>- {$cluster}";
			}
			$parts []= $part;
		}
		return join("\n\n", $parts);
	}

	private function getImplantDesignerBuild(string $sender): string {
		$design = $this->getDesign($sender, '@');

		$blob = "[" . $this->text->makeChatcmd("Results", "/tell <myname> implantdesigner results");
		$blob .= "]<tab>[";
		$blob .= $this->text->makeChatcmd("Clear All", "/tell <myname> implantdesigner clear");
		$blob .= "]<tab>[";
		$blob .= $this->text->makeChatcmd("Shopping List", "/tell <myname> implantshoppinglist");
		$blob .= "]\n\n\n";

		foreach ($this->slots as $slot) {
			$blob .= $this->text->makeChatcmd($slot, "/tell <myname> implantdesigner {$slot}");
			if (!empty($design->{$slot})) {
				$blob .= $this->getImplantSummary($design->{$slot});
			} else {
				$blob .= "\n";
			}
			$blob .= "\n";
		}

		return $blob;
	}

	private function getImplantSummary(stdClass $slotObj): string {
		if ($slotObj->symb !== null) {
			$msg = " " . $slotObj->symb->name . "\n";
			return $msg;
		}
		$ql = empty($slotObj->ql) ? 300 : (int)$slotObj->ql;
		$implant = $this->getImplantInfo($ql, $slotObj->shiny, $slotObj->bright, $slotObj->faded);
		$msg = " QL" . $ql;
		if ($implant !== null) {
			$msg .= " - Treatment: {$implant->Treatment} {$implant->AbilityName}: {$implant->Ability}";
		}
		$msg .= "\n";

		foreach ($this->grades as $grade) {
			if (empty($slotObj->{$grade})) {
				$msg .= "<tab><highlight>-Empty-<end>\n";
			} else {
				$effectTypeIdName = ucfirst(strtolower($grade)) . 'EffectTypeID';
				$effectId = $implant->{$effectTypeIdName};
				$msg .= "<tab><highlight>{$slotObj->{$grade}}<end> (" . $this->getClusterModAmount($ql, $grade, $effectId) . ")\n";
			}
		}
		return $msg;
	}

	private function getClusterModAmount(int $ql, string $grade, int $effectId): int {
		/** @var EffectTypeMatrix */
		$etm = $this->db->table("EffectTypeMatrix")
			->where("ID", $effectId)
			->asObj(EffectTypeMatrix::class)->firstOrFail();

		if ($ql < 201) {
			$minVal = $etm->MinValLow;
			$maxVal = $etm->MaxValLow;
			$minQl = 1;
			$maxQl = 200;
		} else {
			$minVal = $etm->MinValHigh;
			$maxVal = $etm->MaxValHigh;
			$minQl = 201;
			$maxQl = 300;
		}

		$modAmount = $this->util->interpolate($minQl, $maxQl, $minVal, $maxVal, $ql);
		if ($grade == 'bright') {
			$modAmount = round($modAmount * 0.6, 0);
		} elseif ($grade == 'faded') {
			$modAmount = round($modAmount * 0.4, 0);
		}

		return (int)$modAmount;
	}

	private function getSymbiantsLinks(string $slot): string {
		$artilleryLink = $this->text->makeChatcmd("Artillery", "/tell <myname> symb {$slot} artillery");
		$controlLink = $this->text->makeChatcmd("Control", "/tell <myname> symb {$slot} control");
		$exterminationLink = $this->text->makeChatcmd("Extermination", "/tell <myname> symb {$slot} extermination");
		$infantryLink = $this->text->makeChatcmd("Infantry", "/tell <myname> symb {$slot} infantry");
		$supportLink = $this->text->makeChatcmd("Support", "/tell <myname> symb {$slot} support");
		return "<header2>Symbiants<end>  {$artilleryLink}  {$controlLink}  {$exterminationLink}  {$infantryLink}  {$supportLink}";
	}

	private function showClusterChoices(object $design, string $slot, string $grade): string {
		$msg = '';
		if (!empty($design->{$slot}->{$grade})) {
			$msg .= " - {$design->{$slot}->{$grade}}";
		}
		$msg .= "\n";
		$msg .= $this->text->makeChatcmd("-Empty-", "/tell <myname> implantdesigner {$slot} {$grade} clear") . "\n";
		$skills = $this->getClustersForSlot($slot, $grade);
		foreach ($skills as $skill) {
			$msg .= $this->text->makeChatcmd($skill, "/tell <myname> implantdesigner {$slot} {$grade} {$skill}") . "\n";
		}
		$msg .= "\n\n";
		return $msg;
	}

	private function addImplantInfo(ImplantInfo $implantInfo, int $ql): ImplantInfo {
		if ($ql < 201) {
			$minAbility = $implantInfo->AbilityQL1;
			$maxAbility = $implantInfo->AbilityQL200;
			$minTreatment = $implantInfo->TreatQL1;
			$maxTreatment = $implantInfo->TreatQL200;
			$minQl = 1;
			$maxQl = 200;
		} else {
			$minAbility = $implantInfo->AbilityQL201;
			$maxAbility = $implantInfo->AbilityQL300;
			$minTreatment = $implantInfo->TreatQL201;
			$maxTreatment = $implantInfo->TreatQL300;
			$minQl = 201;
			$maxQl = 300;
		}

		$implantInfo->Ability = $this->util->interpolate($minQl, $maxQl, $minAbility, $maxAbility, $ql);
		$implantInfo->Treatment = $this->util->interpolate($minQl, $maxQl, $minTreatment, $maxTreatment, $ql);

		return $implantInfo;
	}
}
