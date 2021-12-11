<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{
	CmdContext,
	DB,
	DBRow,
	ParamClass\PAttribute,
	Text,
	Util,
};
use stdClass;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'implantdesigner',
 *		accessLevel = 'all',
 *		description = 'Implant Designer',
 *		help        = 'implantdesigner.txt',
 *		alias       = 'impdesign'
 *	)
 */
class ImplantDesignerController {
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
	public Util $util;

	/** @Inject */
	public ImplantController $implantController;

	private array $slots = ['head', 'eye', 'ear', 'rarm', 'chest', 'larm', 'rwrist', 'waist', 'lwrist', 'rhand', 'legs', 'lhand', 'feet'];
	private array $grades = ['shiny', 'bright', 'faded'];

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Designer");
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

	/**
	 * @HandlesCommand("implantdesigner")
	 */
	public function implantdesignerCommand(CmdContext $context): void {
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	private function getImplantDesignerBuild(string $sender): string {
		$design = $this->getDesign($sender, '@');

		$blob = $this->text->makeChatcmd("Results", "/tell <myname> implantdesigner results");
		$blob .= "<tab>";
		$blob .= $this->text->makeChatcmd("Clear All", "/tell <myname> implantdesigner clear");
		$blob .= "\n-----------------\n\n";

		foreach ($this->slots as $slot) {
			$blob .= $this->text->makeChatcmd($slot, "/tell <myname> implantdesigner $slot");
			if (!empty($design->$slot)) {
				$blob .= $this->getImplantSummary($design->$slot);
			} else {
				$blob .= "\n";
			}
			$blob .= "\n";
		}

		return $blob;
	}

	private function getImplantSummary(object $slotObj): string {
		if ($slotObj->symb !== null) {
			$msg = " " . $slotObj->symb->name . "\n";
			return $msg;
		}
		$ql = empty($slotObj->ql) ? 300 : $slotObj->ql;
		$implant = $this->getImplantInfo($ql, $slotObj->shiny, $slotObj->bright, $slotObj->faded);
		$msg = " QL" . $ql;
		if ($implant !== null) {
			$msg .= " - Treatment: {$implant->Treatment} {$implant->AbilityName}: {$implant->Ability}";
		}
		$msg .= "\n";

		foreach ($this->grades as $grade) {
			if (empty($slotObj->$grade)) {
				$msg .= "<tab><highlight>-Empty-<end>\n";
			} else {
				$effectTypeIdName = ucfirst(strtolower($grade)) . 'EffectTypeID';
				$effectId = $implant->$effectTypeIdName;
				$msg .= "<tab><highlight>{$slotObj->$grade}<end> (" . $this->getClusterModAmount($ql, $grade, $effectId) . ")\n";
			}
		}
		return $msg;
	}

	private function getClusterModAmount(int $ql, string $grade, int $effectId): int {
		$row = $this->db->table("EffectTypeMatrix")
			->where("ID", $effectId)
			->select("ID", "Name", "MinValLow", "MaxValLow", "MinValHigh", "MaxValHigh")
			->asObj()->first();

		if ($ql < 201) {
			$minVal = $row->MinValLow;
			$maxVal = $row->MaxValLow;
			$minQl = 1;
			$maxQl = 200;
		} else {
			$minVal = $row->MinValHigh;
			$maxVal = $row->MaxValHigh;
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

	/**
	 * @HandlesCommand("implantdesigner")
	 * @Mask $action clear
	 */
	public function implantdesignerClearCommand(CmdContext $context, string $action): void {
		$this->saveDesign($context->char->name, '@', new stdClass());
		$msg = "Implant Designer has been cleared.";
		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("implantdesigner")
	 */
	public function implantdesignerSlotCommand(CmdContext $context, PImplantSlot $slot): void {
		$slot = $slot();

		$blob  = $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
		$blob .= "<tab>";
		$blob .= $this->text->makeChatcmd("Clear this slot", "/tell <myname> implantdesigner $slot clear");
		$blob .= "<tab>";
		$blob .= $this->text->makeChatcmd("Require Ability", "/tell <myname> implantdesigner $slot require");
		$blob .= "\n-------------------------\n";
		$blob .= "<header2>Implants<end>  ";
		foreach ([25, 50, 75, 100, 125, 150, 175, 200, 225, 250, 275, 300] as $ql) {
			$blob .= $this->text->makeChatcmd((string)$ql, "/tell <myname> implantdesigner $slot $ql") . " ";
		}
		$blob .= "\n\n" . $this->getSymbiantsLinks($slot);
		$blob .= "\n-------------------------\n\n";

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->$slot;

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
			$ql = empty($design->$slot->ql) ? 300 : $design->$slot->ql;
			$blob .= "<header2>QL<end> $ql";
			$implant = $this->getImplantInfo($ql, $design->$slot->shiny, $design->$slot->bright, $design->$slot->faded);
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

		$msg = $this->text->makeBlob("Implant Designer ($slot)", $blob);

		$context->reply($msg);
	}

	private function getSymbiantsLinks(string $slot): string {
		$artilleryLink = $this->text->makeChatcmd("Artillery", "/tell <myname> symb $slot artillery");
		$controlLink = $this->text->makeChatcmd("Control", "/tell <myname> symb $slot control");
		$exterminationLink = $this->text->makeChatcmd("Extermination", "/tell <myname> symb $slot extermination");
		$infantryLink = $this->text->makeChatcmd("Infantry", "/tell <myname> symb $slot infantry");
		$supportLink = $this->text->makeChatcmd("Support", "/tell <myname> symb $slot support");
		return "<header2>Symbiants<end>  $artilleryLink  $controlLink  $exterminationLink  $infantryLink  $supportLink";
	}

	private function showClusterChoices(object $design, string $slot, string $grade): string {
		$msg = '';
		if (!empty($design->$slot->$grade)) {
			$msg .= " - {$design->$slot->$grade}";
		}
		$msg .= "\n";
		$msg .= $this->text->makeChatcmd("-Empty-", "/tell <myname> implantdesigner $slot $grade clear") . "\n";
		$data = $this->getClustersForSlot($slot, $grade);
		foreach ($data as $row) {
			$msg .= $this->text->makeChatcmd($row->skill, "/tell <myname> implantdesigner $slot $grade $row->skill") . "\n";
		}
		$msg .= "\n\n";
		return $msg;
	}

	/**
	 * @HandlesCommand("implantdesigner")
	 */
	public function implantdesignerSlotAddClusterCommand(
		CmdContext $context,
		PImplantSlot $slot,
		PClusterSlot $type,
		string $item
	): void {
		$slot = $slot();
		$type = $type();
		$design = $this->getDesign($context->char->name, '@');
		$design->$slot ??= new stdClass();
		$slotObj = &$design->$slot;

		if ($type === 'symb') {
			$symbRow = $this->db->table("Symbiant AS s")
				->join("ImplantType AS i", "s.SlotID", "i.ImplantTypeID")
				->where("i.ShortName", $slot)
				->where("s.Name", $item)
				->asObj()->first();

			if ($symbRow === null) {
				$msg = "Could not find symbiant <highlight>$item<end>.";
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
					->select("a.Name", "s.Amount")
					->asObj()->toArray();

				// add mods
				$symb->mods = $this->db->table("SymbiantClusterMatrix AS s")
					->join("Cluster AS c", "s.ClusterID", "c.ClusterID")
					->where("SymbiantID", $symbRow->ID)
					->select("c.LongName AS Name", "s.Amount")
					->asObj()->toArray();

				$slotObj->symb = $symb;
				$msg = "<highlight>$slot(symb)<end> has been set to <highlight>$symb->name<end>.";
			}
		} else {
			if (strtolower($item) == 'clear') {
				if ($slotObj->$type === null) {
					$msg = "There is no cluster in <highlight>$slot($type)<end>.";
				} else {
					unset($slotObj->$type);
					$msg = "<highlight>$slot($type)<end> has been cleared.";
				}
			} else {
				unset($slotObj->$type);
				$slotObj->$type = $item;
				$msg = "<highlight>$slot($type)<end> has been set to <highlight>$item<end>.";
			}
		}

		$this->saveDesign($context->char->name, '@', $design);

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("implantdesigner")
	 */
	public function implantdesignerSlotQLCommand(
		CmdContext $context,
		PImplantSlot $slot,
		int $ql
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->$slot;
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

	/**
	 * @HandlesCommand("implantdesigner")
	 * @Mask $action clear
	 */
	public function implantdesignerSlotClearCommand(
		CmdContext $context,
		PImplantSlot $slot,
		string $action
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		unset($design->$slot);
		$this->saveDesign($context->char->name, '@', $design);

		$msg = "<highlight>$slot<end> has been cleared.";

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob("Implant Designer", $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("implantdesigner")
	 * @Mask $action require
	 */
	public function implantdesignerSlotRequireCommand(
		CmdContext $context,
		PImplantSlot $slot,
		string $action
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->$slot;
		if (empty($slotObj)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->symb)) {
			$msg = "You cannot require an ability for a symbiant.";
		} elseif (empty($slotObj->shiny) && empty($slotObj->bright) && empty($slotObj->faded)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->shiny) && !empty($slotObj->bright) && !empty($slotObj->faded)) {
			$msg = "You must have at least one empty cluster to require an ability.";
		} else {
			$blob  = $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
			$blob .= "<tab>";
			$blob .= $this->text->makeChatcmd("Clear this slot", "/tell <myname> implantdesigner $slot clear");
			$blob .= "\n-------------------------\n\n";
			$blob .= $this->text->makeChatcmd($slot, "/tell <myname> implantdesigner $slot");
			$blob .= $this->getImplantSummary($slotObj) . "\n";
			$blob .= "Which ability do you want to require for $slot?\n\n";
			$data = $this->db->table("Ability")->select("Name")
				->asObj()->toArray();
			foreach ($data as $row) {
				$blob .= $this->text->makeChatcmd($row->Name, "/tell <myname> implantdesigner $slot require $row->Name") . "\n";
			}
			$msg = $this->text->makeBlob("Implant Designer Require Ability ($slot)", $blob);
		}

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("implantdesigner")
	 * @Mask $action require
	 */
	public function implantdesignerSlotRequireAbilityCommand(
		CmdContext $context,
		PImplantSlot $slot,
		string $action,
		PAttribute $ability
	): void {
		$slot = $slot();
		$ability = $ability();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->$slot;
		if (empty($slotObj)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->symb)) {
			$msg = "You cannot require an ability for a symbiant.";
		} elseif (empty($slotObj->shiny) && empty($slotObj->bright) && empty($slotObj->faded)) {
			$msg = "You must have at least one cluster filled to require an ability.";
		} elseif (!empty($slotObj->shiny) && !empty($slotObj->bright) && !empty($slotObj->faded)) {
			$msg = "You must have at least one empty cluster to require an ability.";
		} else {
			$blob  = $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
			$blob .= "<tab>";
			$blob .= $this->text->makeChatcmd("Clear this slot", "/tell <myname> implantdesigner $slot clear");
			$blob .= "\n-------------------------\n\n";
			$blob .= $this->text->makeChatcmd($slot, "/tell <myname> implantdesigner $slot");
			$blob .= $this->getImplantSummary($slotObj) . "\n";
			$blob .= "Combinations for <highlight>$slot<end> that will require $ability:\n";
			$query = $this->db
				->table("ImplantMatrix AS i")
				->join("Cluster AS c1", "i.ShiningID", "c1.ClusterID")
				->join("Cluster AS c2", "i.BrightID", "c2.ClusterID")
				->join("Cluster AS c3", "i.FadedID", "c3.ClusterID")
				->join("Ability AS a", "i.AbilityID", "a.AbilityID")
				->where("a.Name", $ability)
				->select("i.AbilityQL1", "i.AbilityQL200", "i.AbilityQL201")
				->addSelect("i.AbilityQL300", "i.TreatQL1", "i.TreatQL200")
				->addSelect("i.TreatQL201", "i.TreatQL300")
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

			$data = $query->asObj()->toArray();
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
				$results = array_map(function($item) use ($slot) {
					return (empty($item[1]) ? '-Empty-' : $this->text->makeChatcmd($item[1], "/tell <myname> implantdesigner $slot {$item[0]} {$item[1]}"));
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
			$msg = $this->text->makeBlob("Implant Designer Require $ability ($slot) ($count)", $blob);
		}

		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("implantdesigner")
	 * @Mask $action (result|results)
	 */
	public function implantdesignerResultCommand(CmdContext $context, string $action): void {
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
			$slotObj = $design->$slot;

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
					$ql = $slotObj->ql;
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
					if (!empty($slotObj->$grade)) {
						$effectTypeIdName = ucfirst(strtolower($grade)) . 'EffectTypeID';
						$effectId = $implant->$effectTypeIdName;
						$mods[$slotObj->$grade] += $this->getClusterModAmount($ql, $grade, $effectId);

						// add cluster
						$obj = new stdClass();
						$obj->ql = $this->implantController->getClusterMinQl($ql, $grade);
						$obj->slot = $slot;
						$obj->grade = $grade;
						$obj->name = $slotObj->$grade;
						$clusters []= $obj;
					}
				}
			}
		}

		// sort mods by name alphabetically
		ksort($mods);

		// sort clusters by name alphabetically, and then by grade, shiny first
		$grades = $this->grades;
		usort($clusters, function(object $cluster1, object $cluster2) use ($grades): int {
			$val = strcmp($cluster1->name, $cluster2->name);
			if ($val === 0) {
				$val1 = array_search($cluster1->grade, $grades);
				$val2 = array_search($cluster2->grade, $grades);
				return $val1 <=> $val2;
			}
			return $val <=> 0;
		});

		$blob  = $this->text->makeChatcmd("See Build", "/tell <myname> implantdesigner");
		$blob .= "\n---------\n\n";

		$blob .= "<header2>Requirements to Equip<end>\n";
		foreach ($reqs as $requirement => $amount) {
			$blob .= "$requirement: <highlight>$amount<end>\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Skills Gained<end>\n";
		foreach ($mods as $skill => $amount) {
			$blob .= "$skill: <highlight>$amount<end>\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Basic Implants Needed<end>\n";
		foreach ($implants as $implant) {
			$blob .= "<highlight>$implant->slot<end> ($implant->ql)\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Clusters Needed<end>\n";
		foreach ($clusters as $cluster) {
			$blob .= "<highlight>{$cluster->name}<end>, {$cluster->grade} ({$cluster->ql}+)\n";
		}

		return $blob;
	}

	public function getImplantInfo(int $ql, ?string $shiny, ?string $bright, ?string $faded): ?object {
		$row = $this->db->table("ImplantMatrix AS i")
			->join("Cluster AS c1", "i.ShiningID", "c1.ClusterID")
			->join("Cluster AS c2", "i.BrightID", "c2.ClusterID")
			->join("Cluster AS c3", "i.FadedID", "c3.ClusterID")
			->join("Ability AS a", "i.AbilityID", "a.AbilityID")
			->where("c1.LongName", $shiny ?? "")
			->where("c2.LongName", $bright ?? "")
			->where("c3.LongName", $faded ?? "")
			->select("i.AbilityQL1", "i.AbilityQL200")
			->addSelect("i.AbilityQL201", "i.AbilityQL300", "i.TreatQL1")
			->addSelect("i.TreatQL200", "i.TreatQL201", "i.TreatQL300")
			->addSelect("c1.EffectTypeID as ShinyEffectTypeID")
			->addSelect("c2.EffectTypeID as BrightEffectTypeID")
			->addSelect("c3.EffectTypeID as FadedEffectTypeID")
			->addSelect("a.Name AS AbilityName")
			->limit(1)
			->asObj()
			->first();

		if ($row === null) {
			return null;
		}
		return $this->addImplantInfo($row, $ql);
	}

	private function addImplantInfo(DBRow $implantInfo, int $ql): object {
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

	public function getClustersForSlot(string $implantType, string $clusterType): array {
		return $this->db
			->table("Cluster AS c1")
			->join("ClusterImplantMap AS c2", "c1.ClusterID", "c2.ClusterID")
			->join("ClusterType AS c3", "c2.ClusterTypeID", "c3.ClusterTypeID")
			->join("ImplantType AS i", "c2.ImplantTypeID", "i.ImplantTypeID")
			->where("i.ShortName", strtolower($implantType))
			->where("c3.Name", strtolower($clusterType))
			->select("LongName AS skill")
			->asObj()
			->toArray();
	}

	public function getDesign(string $sender, string $name): stdClass {
		$row = $this->db->table("implant_design")
			->where("owner", $sender)
			->where("name", $name)
			->asObj()
			->first();
		if ($row === null) {
			return new stdClass();
		}
		return json_decode($row->design);
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
}
