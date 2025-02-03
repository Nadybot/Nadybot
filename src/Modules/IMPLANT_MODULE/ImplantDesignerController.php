<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\Str;
use Nadybot\Core\Types\{Ability as TAbility, ImplantSlot};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\{Skill, WhatBuffsController};

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Designer'),
	NCA\DefineCommand(
		command: 'implantdesigner',
		accessLevel: 'guest',
		description: 'Implant Designer',
		alias: 'impdesign'
	),
	NCA\DefineCommand(
		command: 'implantshoppinglist',
		accessLevel: 'guest',
		description: 'Implant Designer Shopping List',
		alias: ['impshop', 'implantshoplist', 'impshoplist'],
	)
]
class ImplantDesignerController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private WhatBuffsController $whatBuffsController;

	#[NCA\Inject]
	private ImplantController $implantController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/Ability.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/Cluster.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ClusterImplantMap.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ClusterType.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/EffectTypeMatrix.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/EffectValue.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ImplantMatrix.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/ImplantType.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/Symbiant.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/SymbiantAbilityMatrix.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/SymbiantClusterMatrix.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/SymbiantProfessionMatrix.csv');
	}

	/** Show a shopping list for your current implant design */
	#[NCA\HandlesCommand('implantshoppinglist')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantShoplistCommand(CmdContext $context): void {
		$design = $this->getDesign($context->char->name);
		$list = new ShoppingList();

		/** @var array<string,string> */
		$lookup = $this->db->table(Cluster::getTable())
			->asObj(Cluster::class)
			->reduce(
				/**
				 * @param array<string,string> $lookup
				 *
				 * @return array<string,string>
				 */
				static function (array $lookup, Cluster $cluster): array {
					$lookup[$cluster->long_name] = $cluster->official_name;
					return $lookup;
				},
				[]
			);
		foreach (get_object_vars($design) as $slot => $slotObj) {
			if (!($slotObj instanceof SlotConfig)) {
				continue;
			}
			// Symbiants are not part of the shopping list
			if ($slotObj->symb !== null) {
				continue;
			}
			$ql = $slotObj->ql ?? 300;
			$addImp = false;
			$refined = '';
			if ($ql > 200) {
				$refined = 'Refined ';
			}
			foreach (ClusterGrade::cases() as $grade) {
				$cluster = $slotObj->get($grade);
				if (!isset($cluster)) {
					continue;
				}
				$name = $lookup[$cluster];
				if (str_ends_with($name, 'Jobe')) {
					$name = str_replace(' Jobe', " {$refined}Jobe Cluster", $name);
				} else {
					$name .= " {$refined}Cluster";
				}
				$clusterQL = $this->implantController->getClusterMinQl($ql, $grade);
				if ($ql > 200 && $clusterQL < 201) {
					$clusterQL = 201;
				}
				$name .= " (QL {$clusterQL}+)";
				$list->addCluster($grade, $name);
				$addImp = true;
			}
			if ($addImp) {
				$longName = $this->db->table(ImplantType::getTable())
					->where('short_name', $slot)
					->pluckStrings('name')
					->firstOrFail();
				if ($ql > 200) {
					$list->implants []= "{$longName} Implant Refined Empty (QL {$ql})";
				} else {
					$list->implants []= "Basic {$longName} Implant (QL {$ql})";
				}
			}
		}
		$blob = $this->renderShoppingList($list);
		if ($blob === '') {
			$context->reply('Nothing to buy.');
			return;
		}
		$msg = Text::makeBlob('Implant Shopping List', $blob);
		$context->reply($msg);
	}

	/** Look at your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	#[NCA\Help\Epilogue(
		'<i>Slot can be any of head, eye, ear, rarm, chest, larm, rwrist, waist, '.
		'lwrist, rhand, legs, lhand, and feet.</i>'
	)]
	public function implantdesignerCommand(CmdContext $context): void {
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = Text::makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Remove all clusters from your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerClearCommand(CmdContext $context, #[NCA\Str('clear')] string $action): void {
		$this->saveDesign($context->char->name, new ImplantConfig());
		$msg = 'Implant Designer has been cleared.';
		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = Text::makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** See a specific slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotCommand(
		CmdContext $context,
		#[NCA\ImplantSlotStr] string $slot
	): void {
		$slot = ImplantSlot::byName($slot);
		$slotName = $slot->designSlotName();

		$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Clear this slot', "/tell <myname> implantdesigner {$slotName} clear");
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Require Ability', "/tell <myname> implantdesigner {$slotName} require");
		$blob .= "]\n\n\n";
		$blob .= "<header2>Implants<end>\n<tab>";
		foreach ([25, 50, 75, 100, 125, 150, 175, 200, 225, 250, 275, 300] as $ql) {
			$blob .= '[' . Text::makeChatcmd((string)$ql, "/tell <myname> implantdesigner {$slotName} {$ql}") . '] ';
		}
		$blob .= "\n\n" . $this->getSymbiantsLinks($slot);
		$blob .= "\n\n";

		$design = $this->getDesign($context->char->name);

		$slotObj = $design->getSlot($slot);

		if (isset($slotObj, $slotObj->symb)) {
			$symb = $slotObj->symb;
			$blob .= $symb->name ."\n\n";
			$blob .= "<header2>Requirements<end>\n";
			$blob .= "Treatment: {$symb->treatment}\n";
			$blob .= "Level: {$symb->level}\n";
			foreach ($symb->reqs as $req) {
				$blob .= "{$req->name}: {$req->amount}\n";
			}
			$blob .= "\n<header2>Modifications<end>\n";
			foreach ($symb->mods as $mod) {
				$blob .= "{$mod->name}: {$mod->amount}\n";
			}
			$blob .= "\n";
		} else {
			$ql = $slotObj->ql ?? 300;
			$blob .= "<header2>Specs<end>\n<tab>QL: {$ql}\n";
			$implant = $this->getImplantInfo($ql, $slotObj);
			if ($implant !== null) {
				$blob .= "<tab>Treatment: {$implant->treatment} {$implant->ability_name}: {$implant->ability}\n";
			}
			$blob .= "\n";

			foreach (ClusterGrade::cases() as $grade) {
				$blob .= "<header2>{$grade->name}<end>";
				$blob .= $this->showClusterChoices($design, $slot, $grade, $ql);
			}
		}

		$msg = Text::makeBlob("Implant Designer ({$slot->longName()})", $blob);

		$context->reply($msg);
	}

	/** Add a cluster to a slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotAddClusterCommand(
		CmdContext $context,
		#[NCA\ImplantSlotStr] string $slot,
		#[NCA\ClusterGradeStr] #[Str('symbiant', 'symb')] string $grade,
		string $cluster
	): void {
		$slot = ImplantSlot::byName($slot);
		$design = $this->getDesign($context->char->name);
		$slotObj = $design->setSlotIfUnset($slot, new SlotConfig());

		if (in_array($grade, ['symb', 'symbiant'], true)) {
			$symbRow = $this->db->table(Symbiant::getTable(), 's')
				->join(ImplantType::getTable(as: 'i'), 's.slot_id', 'i.implant_type_id')
				->where('i.short_name', $slot->designSlotName())
				->where('s.name', $cluster)
				->select('s.*')
				->addSelect('i.short_name AS slot_name')
				->addSelect('i.name AS slot_long_name')
				->firstObj(Symbiant::class);

			if ($symbRow === null) {
				$msg = "Could not find symbiant <highlight>{$cluster}<end>.";
			} else {
				// convert slot to symb
				$slotObj->shiny = null;
				$slotObj->bright = null;
				$slotObj->faded = null;
				$slotObj->ql = null;

				$symb = new SymbiantSlot(
					name: $symbRow->name,
					treatment: $symbRow->treatment_req,
					level: $symbRow->level_req,
					reqs: $this->db->table(SymbiantAbilityMatrix::getTable(), 's')
						->join(Ability::getTable(as: 'a'), 's.ability_id', 'a.ability_id')
						->where('s.symbiant_id', $symbRow->id)
						->select(['a.name', 's.amount'])
						->asObjArr(AbilityAmount::class),
					mods: $this->db->table(SymbiantClusterMatrix::getTable(), 's')
						->join(Cluster::getTable(as: 'c'), 's.cluster_id', 'c.cluster_id')
						->where('s.symbiant_id', $symbRow->id)
						->select(['c.long_name AS name', 's.amount'])
						->asObjArr(AbilityAmount::class)
				);

				$slotObj->symb = $symb;
				$msg = "<highlight>{$slot->longName()}(symb)<end> has been set to <highlight>{$symb->name}<end>.";
			}
		} else {
			$grade = ClusterGrade::from($grade);
			if (strtolower($cluster) === 'clear') {
				if (!$slotObj->has($grade)) {
					$msg = "There is no cluster in <highlight>{$slot->longName()} ({$grade->value})<end>.";
				} else {
					$slotObj->set($grade, null);
					$msg = "<highlight>{$slot->longName()} ({$grade->value})<end> has been cleared.";
				}
			} else {
				$clusterObj = $this->db->table(Cluster::getTable())
					->whereIlike('long_name', strtolower($cluster))
					->firstObj(Cluster::class);
				if (!isset($clusterObj)) {
					$matches = $this->whatBuffsController->searchForSkill($cluster);
					if (count($matches) !== 1) {
						$context->reply("Unknown skill <highlight>{$cluster}<end>.");
						return;
					}
					$match = $matches[0];
					$clusterObj = $this->db->table(Cluster::getTable())
						->where('skill_id', $match->id)
						->firstObj(Cluster::class);
					if (!isset($clusterObj)) {
						$context->reply("There is no cluster for <highlight>{$cluster}<end>.");
						return;
					}
				}
				$valid = $this->db
					->table(ClusterImplantMap::getTable(), 'cim')
					->join(ImplantType::getTable(as: 'it'), 'cim.implant_type_id', 'it.implant_type_id')
					->join(ClusterType::getTable(as: 'ct'), 'cim.cluster_type_id', 'ct.cluster_type_id')
					->where('cim.cluster_id', $clusterObj->cluster_id)
					->where('ct.name', $grade->value)
					->where('it.short_name', $slot->designSlotName())
					->exists();
				if (!$valid) {
					$context->reply("There is no {$grade->value} {$clusterObj->long_name} cluster for the {$slot->longName()}.");
					return;
				}
				$slotObj->set($grade, $clusterObj->long_name);
				$msg = "<highlight>{$slot->longName()}({$grade->value})<end> has been set to <highlight>{$clusterObj->long_name}<end>.";
			}
		}

		$this->saveDesign($context->char->name, $design);

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = Text::makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Set the QL for a slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotQLCommand(
		CmdContext $context,
		#[NCA\ImplantSlotStr] #[NCA\Str('all')] string $slot,
		int $ql
	): void {
		if ($ql < 1 || $ql > 300) {
			$context->reply('Invalid ql given. Allowed ranges are 1 to 300');
			return;
		}
		$design = $this->getDesign($context->char->name);

		if ($slot === 'all') {
			$slots = ImplantSlot::cases();
			$msg = "<highlight>All slots<end> have been set to QL <highlight>{$ql}<end>.";
		} else {
			$slots = [ImplantSlot::byName($slot)];
			$msg = "<highlight>{$slots[0]->longName()}<end> has been set to QL <highlight>{$ql}<end>.";
		}
		foreach ($slots as $impSlot) {
			$slotObj = $design->setSlotIfUnset($impSlot, new SlotConfig());
			$slotObj->symb = null;
			$slotObj->ql = $ql;
		}
		$this->saveDesign($context->char->name, $design);


		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = Text::makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Clear all clusters from a slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotClearCommand(
		CmdContext $context,
		#[NCA\ImplantSlotStr] string $slot,
		#[NCA\Str('clear')] string $action
	): void {
		$slot = ImplantSlot::byName($slot);

		$design = $this->getDesign($context->char->name);
		$design->setSlot($slot, null);
		$this->saveDesign($context->char->name, $design);

		$msg = "<highlight>{$slot->longName()}<end> has been cleared.";

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = Text::makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Show how to make a slot require a certain attribute in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotRequireCommand(
		CmdContext $context,
		#[NCA\ImplantSlotStr] string $slot,
		#[NCA\Str('require')] string $action
	): void {
		$slot = ImplantSlot::byName($slot);

		$design = $this->getDesign($context->char->name);

		$slotObj = $design->getSlot($slot);
		if (!isset($slotObj)) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->symb)) {
			$msg = 'You cannot require an ability for a symbiant.';
		} elseif ($slotObj->isEmpty()) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->shiny, $slotObj->bright, $slotObj->faded)) {
			$msg = 'You must have at least one empty cluster to require an ability.';
		} else {
			$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
			$blob .= ']<tab>[';
			$blob .= Text::makeChatcmd('Clear this slot', "/tell <myname> implantdesigner {$slot->designSlotName()} clear");
			$blob .= "]\n\n\n";
			$blob .= Text::makeChatcmd($slot->longName(), "/tell <myname> implantdesigner {$slot->designSlotName()}");
			$blob .= $this->getImplantSummary($slotObj) . "\n";
			$blob .= "Which ability do you want to require for {$slot->longName()}?\n\n";
			$abilities = $this->db->table(Ability::getTable())->select('name')
				->pluckStrings('name')->toArray();
			foreach ($abilities as $ability) {
				$blob .= Text::makeChatcmd($ability, "/tell <myname> implantdesigner {$slot->designSlotName()} require {$ability}") . "\n";
			}
			$msg = Text::makeBlob("Implant Designer Require Ability ({$slot->longName()})", $blob);
		}

		$context->reply($msg);
	}

	/** Show how to make a slot require a certain attribute in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotRequireAbilityCommand(
		CmdContext $context,
		#[NCA\ImplantSlotStr] string $slot,
		#[NCA\Str('require')] string $action,
		#[NCA\AbilityStr] string $ability
	): void {
		$slot = ImplantSlot::byName($slot);
		$ability = TAbility::fromShort($ability);

		$design = $this->getDesign($context->char->name);

		$slotObj = $design->getSlot($slot);
		if (!isset($slotObj)) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->symb)) {
			$msg = 'You cannot require an ability for a symbiant.';
		} elseif ($slotObj->isEmpty()) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->shiny, $slotObj->bright, $slotObj->faded)) {
			$msg = 'You must have at least one empty cluster to require an ability.';
		} else {
			$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
			$blob .= ']<tab>[';
			$blob .= Text::makeChatcmd('Clear this slot', "/tell <myname> implantdesigner {$slot->designSlotName()} clear");
			$blob .= "]\n\n\n";
			$blob .= Text::makeChatcmd($slot->longName(), "/tell <myname> implantdesigner {$slot->designSlotName()}");
			$blob .= $this->getImplantSummary($slotObj) . "\n";
			$blob .= "Combinations for <highlight>{$slot->longName()}<end> that will require {$ability->name}:\n";
			$query = $this->db
				->table(ImplantMatrix::getTable(), 'i')
				->join(Cluster::getTable(as: 'c1'), 'i.shining_id', 'c1.cluster_id')
				->join(Cluster::getTable(as: 'c2'), 'i.bright_id', 'c2.cluster_id')
				->join(Cluster::getTable(as: 'c3'), 'i.faded_id', 'c3.cluster_id')
				->join(Ability::getTable(as: 'a'), 'i.ability_id', 'a.ability_id')
				->where('a.name', $ability->name)
				->select(['i.ability_ql1', 'i.ability_ql200', 'i.ability_ql201'])
				->addSelect(['i.ability_ql300', 'i.treat_ql1', 'i.treat_ql200'])
				->addSelect(['i.treat_ql201', 'i.treat_ql300'])
				->addSelect('c1.long_name as shiny_effect')
				->addSelect('c2.long_name as bright_effect')
				->addSelect('c3.long_name as faded_effect')
				->orderBy('c1.long_name')
				->orderBy('c2.long_name')
				->orderBy('c3.long_name');

			if (isset($slotObj->shiny)) {
				$query->where('c1.long_name', $slotObj->shiny);
			}
			if (isset($slotObj->bright)) {
				$query->where('c2.long_name', $slotObj->bright);
			}
			if (isset($slotObj->faded)) {
				$query->where('c3.long_name', $slotObj->faded);
			}

			$data = $query->asObj(ImplantLayout::class);
			$primary = null;
			foreach ($data as $row) {
				$results = [];
				if (!isset($slotObj->shiny)) {
					$results []= [ClusterGrade::Shiny, $row->shiny_effect];
				}
				if (!isset($slotObj->bright)) {
					$results []= [ClusterGrade::Bright, $row->bright_effect];
				}
				if (!isset($slotObj->faded)) {
					$results []= [ClusterGrade::Faded, $row->faded_effect];
				}

				/** @var list<string> $results */
				$results = array_map(
					/**
					 * @param list<string> $item
					 *
					 * @psalm-param list{ClusterGrade,string} $item
					 */
					static function (array $item) use ($slot): string {
						if ($item[1] === '') {
							return '-Empty-';
						}
						return Text::makeChatcmd(
							$item[1],
							"/tell <myname> implantdesigner {$slot->designSlotName()} {$item[0]->value} {$item[1]}"
						);
					},
					$results
				);
				if ($results[0] !== $primary) {
					$blob .= "\n" . $results[0] . "\n";
					$primary = $results[0];
				}
				if (isset($results[1])) {
					$blob .= '<tab>' . $results[1] . "\n";
				}
			}
			$count = count($data);
			$msg = Text::makeBlob("Implant Designer Require {$ability->name} ({$slot->longName()}) ({$count})", $blob);
		}

		$context->reply($msg);
	}

	/** Show the result of your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerResultCommand(CmdContext $context, #[NCA\Str('result', 'results')] string $action): void {
		$blob = $this->getImplantDesignerResults($context->char->name);

		$msg = Text::makeBlob('Implant Designer Results', $blob);

		$context->reply($msg);
	}

	public function getImplantDesignerResults(string $name): string {
		$design = $this->getDesign($name);

		$mods = [];
		$reqs = ['Treatment' => 0, 'Level' => 1];  // force treatment and level to be shown first

		/**
		 * @var ShoppingImplant[]
		 *
		 * @psalm-var list<ShoppingImplant>
		 */
		$implants = [];

		/**
		 * @var ShoppingCluster[]
		 *
		 * @psalm-var list<ShoppingCluster>
		 */
		$clusters = [];

		foreach (ImplantSlot::cases() as $slot) {
			$slotObj = $design->getSlot($slot);

			// skip empty slots
			if ($slotObj === null) {
				continue;
			}

			if (isset($slotObj->symb)) {
				$symb = $slotObj->symb;

				// add reqs
				if ($symb->treatment > $reqs['Treatment']) {
					$reqs['Treatment'] = $symb->treatment;
				}
				if ($symb->level > $reqs['Level']) {
					$reqs['Level'] = $symb->level;
				}
				foreach ($symb->reqs as $req) {
					if ($req->amount > $reqs[$req->name]) {
						$reqs[$req->name] = $req->amount;
					}
				}

				// add mods
				foreach ($symb->mods as $mod) {
					$mods[$mod->name] += $mod->amount;
				}
			} else {
				$ql = $slotObj->ql ?? 300;

				// add reqs
				$implant = $this->getImplantInfo($ql, $slotObj);
				if (isset($implant) && $implant->treatment > $reqs['Treatment']) {
					$reqs['Treatment'] = $implant->treatment;
				}
				if (isset($implant) && $implant->ability > $reqs[$implant->ability_name]) {
					$reqs[$implant->ability_name] = $implant->ability;
				}

				// add implant
				$implants []= new ShoppingImplant(
					ql: $ql,
					slot: $slot,
				);

				// add mods
				if (isset($implant)) {
					foreach (ClusterGrade::cases() as $grade) {
						$cluster = $slotObj->get($grade);
						if (isset($cluster)) {
							$effectId = $implant->getEffectTypeId($grade);
							$mods[$cluster] += $this->getClusterModAmount($ql, $grade, $effectId);

							// add cluster
							$clusters []= new ShoppingCluster(
								ql: $this->implantController->getClusterMinQl($ql, $grade),
								slot: $slot,
								grade: $grade,
								name: $cluster,
							);
						}
					}
				}
			}
		}

		// sort mods by name alphabetically
		ksort($mods);

		// sort clusters by name alphabetically, and then by grade, shiny first
		usort($clusters, static function (ShoppingCluster $cluster1, ShoppingCluster $cluster2): int {
			$val = strcmp($cluster1->name, $cluster2->name);
			if ($val !== 0) {
				return $val;
			}
			return $cluster1->grade->cmp($cluster2->grade);
		});

		$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
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
			$blob .= "<highlight>{$implant->slot->longName()}<end> ({$implant->ql})\n";
		}
		$blob .= "\n";

		$blob .= "<header2>Clusters Needed<end>\n";
		foreach ($clusters as $cluster) {
			$blob .= "<highlight>{$cluster->name}<end>, {$cluster->grade->value} ({$cluster->ql}+)\n";
		}

		return $blob;
	}

	public function getImplantInfo(int $ql, ?SlotConfig $slot): ?ImplantInfo {
		if (!isset($slot)) {
			return null;
		}
		$row = $this->db->table(ImplantMatrix::getTable(), 'i')
			->join(Cluster::getTable(as: 'cs'), 'i.shining_id', 'cs.cluster_id')
			->join(Cluster::getTable(as: 'cb'), 'i.bright_id', 'cb.cluster_id')
			->join(Cluster::getTable(as: 'cf'), 'i.faded_id', 'cf.cluster_id')
			->join(Ability::getTable(as: 'a'), 'i.ability_id', 'a.ability_id')
			->whereIlike('cs.long_name', strtolower($slot->shiny ?? ''))
			->whereIlike('cb.long_name', strtolower($slot->bright ?? ''))
			->whereIlike('cf.long_name', strtolower($slot->faded ?? ''))
			->select(['i.ability_ql1', 'i.ability_ql200'])
			->addSelect(['i.ability_ql201', 'i.ability_ql300', 'i.treat_ql1'])
			->addSelect(['i.treat_ql200', 'i.treat_ql201', 'i.treat_ql300'])
			->addSelect('cs.effect_type_id as shiny_effect_type_id')
			->addSelect('cb.effect_type_id as bright_effect_type_id')
			->addSelect('cf.effect_type_id as faded_effect_type_id')
			->addSelect('a.name AS ability_name')
			->firstObj(ImplantInfo::class);

		if ($row === null) {
			return null;
		}
		return $row->atQL($ql);
	}

	/** @return list<string> */
	public function getClustersForSlot(ImplantSlot $implantType, ClusterGrade $clusterType): array {
		return $this->db
			->table(Cluster::getTable(), 'c')
			->join(ClusterImplantMap::getTable(as: 'cim'), 'c.cluster_id', 'cim.cluster_id')
			->join(ClusterType::getTable(as: 'ct'), 'cim.cluster_type_id', 'ct.cluster_type_id')
			->join(ImplantType::getTable(as: 'i'), 'cim.implant_type_id', 'i.implant_type_id')
			->where('i.short_name', $implantType->designSlotName())
			->where('ct.name', $clusterType->value)
			->select('c.long_name AS skill')
			->pluckStrings('skill')
			->toList();
	}

	public function getDesign(string $sender, string $name='@'): ImplantConfig {
		$design = $this->db->table(ImplantDesign::getTable())
			->where('owner', $sender)
			->where('name', $name)
			->firstObj(ImplantDesign::class);
		return $design->design ?? new ImplantConfig();
	}

	public function saveDesign(string $sender, ImplantConfig $design, string $name='@'): void {
		$this->db->upsert(new ImplantDesign(
			name: $name,
			owner: $sender,
			design: $design,
		));
	}

	private function renderShoppingList(ShoppingList $list): string {
		/** @var list<string> */
		$parts = [];
		if (count($list->implants) > 0) {
			$part = '<header2>Empty Implants<end>';
			sort($list->implants);
			foreach ($list->implants as $implant) {
				$part .= "\n<tab>- {$implant}";
			}
			$parts []= $part;
		}
		if (count($list->shinyClusters) > 0) {
			$part = '<header2>Shiny Clusters<end>';
			sort($list->shinyClusters);
			foreach ($list->shinyClusters as $cluster) {
				$part .= "\n<tab>- {$cluster}";
			}
			$parts []= $part;
		}
		if (count($list->brightClusters) > 0) {
			$part = '<header2>Bright Clusters<end>';
			sort($list->brightClusters);
			foreach ($list->brightClusters as $cluster) {
				$part .= "\n<tab>- {$cluster}";
			}
			$parts []= $part;
		}
		if (count($list->fadedClusters) > 0) {
			$part = '<header2>Faded Clusters<end>';
			sort($list->fadedClusters);
			foreach ($list->fadedClusters as $cluster) {
				$part .= "\n<tab>- {$cluster}";
			}
			$parts []= $part;
		}
		return implode("\n\n", $parts);
	}

	private function getImplantDesignerBuild(string $sender): string {
		$design = $this->getDesign($sender);

		$blob = '[' . Text::makeChatcmd('Results', '/tell <myname> implantdesigner results');
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Clear All', '/tell <myname> implantdesigner clear');
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Shopping List', '/tell <myname> implantshoppinglist');
		$blob .= "]\n\n\n";

		foreach (ImplantSlot::cases() as $slot) {
			$blob .= Text::makeChatcmd($slot->longName(), "/tell <myname> implantdesigner {$slot->designSlotName()}");
			$slotConfig = $design->getSlot($slot);
			if (isset($slotConfig)) {
				$blob .= $this->getImplantSummary($slotConfig);
			} else {
				$blob .= " -Empty-\n";
			}
			$blob .= "\n";
		}

		return $blob;
	}

	private function getImplantSummary(SlotConfig $slotObj): string {
		if ($slotObj->symb !== null) {
			$msg = ' ' . $slotObj->symb->name.
				" - Treatment: {$slotObj->symb->treatment}".
				" Level: {$slotObj->symb->level}";
			foreach ($slotObj->symb->reqs as $req) {
				$msg .= " {$req->name}: {$req->amount}";
			}
			foreach ($slotObj->symb->mods as $mod) {
				$msg .= "\n<tab><highlight>{$mod->name}<end> ({$mod->amount})";
			}
			return $msg . "\n";
		}
		$ql = $slotObj->ql ?? 300;
		$implant = $this->getImplantInfo($ql, $slotObj);
		$msg = ' QL' . $ql;
		if ($implant !== null) {
			$msg .= " - Treatment: {$implant->treatment} {$implant->ability_name}: {$implant->ability}";
		}
		$msg .= "\n";
		if ($slotObj->isEmpty() || !isset($implant)) {
			return $msg;
		}

		foreach (ClusterGrade::cases() as $grade) {
			$skill = $slotObj->get($grade);
			if (!isset($skill)) {
				$msg .= "<tab><highlight>-Empty-<end>\n";
				continue;
			}
			$displaySkill = str_replace(' (%)', '', $skill);
			$unit = $this->db->table(Cluster::getTable(), 'c')
				->join(Skill::getTable(as: 's'), 'c.skill_id', 's.id')
				->where('c.long_name', $skill)
				->select('s.unit')
				->pluckStrings('unit')
				->first() ?? '';
			$effectId = $implant->getEffectTypeId($grade);
			$bonus = $this->getClusterModAmount($ql, $grade, $effectId);
			$msg .= sprintf(
				"<tab><highlight>%s<end> (%+d%s)\n",
				$displaySkill,
				$bonus,
				$unit
			);
		}
		return $msg;
	}

	private function getClusterModAmount(int $ql, ClusterGrade $grade, int $effectId): int {
		$etm = $this->db->table(EffectTypeMatrix::getTable())
			->where('id', $effectId)
			->asObj(EffectTypeMatrix::class)
			->firstOrFail();

		if ($ql < 201) {
			$minVal = $etm->min_val_low;
			$maxVal = $etm->max_val_low;
			$minQl = 1;
			$maxQl = 200;
		} else {
			$minVal = $etm->min_val_high;
			$maxVal = $etm->max_val_high;
			$minQl = 201;
			$maxQl = 300;
		}

		$modAmount = Util::interpolate($minQl, $maxQl, $minVal, $maxVal, $ql);
		if ($grade === ClusterGrade::Bright) {
			$modAmount = round($modAmount * 0.6, 0);
		} elseif ($grade === ClusterGrade::Faded) {
			$modAmount = round($modAmount * 0.4, 0);
		}

		return (int)$modAmount;
	}

	private function getSymbiantsLinks(ImplantSlot $slot): string {
		$links = $this->db->table(Pocketboss::getTable())
			->where('slot', $slot->longName())
			->select('type')
			->distinct()
			->orderBy('type')
			->pluckStrings('type')
			->map(static function (string $type) use ($slot): string {
				return Text::makeChatcmd($type, "/tell <myname> symb {$slot->designSlotName()} " . strtolower($type));
			});
		return "<header2>Symbiants<end>\n<tab>[" . $links->join('] [') . ']';
	}

	private function showClusterChoices(ImplantConfig $design, ImplantSlot $slot, ClusterGrade $grade, int $ql): string {
		$oldCluster = $design->getSlot($slot)?->get($grade);
		$msg = ' [' . Text::makeChatcmd('clear', "/tell <myname> implantdesigner {$slot->designSlotName()} {$grade->value} clear") . "]\n";
		$skills = $this->getClustersForSlot($slot, $grade);
		foreach ($skills as $skill) {
			$effect = $this->db->table(Cluster::getTable(), 'c')
				->join(Skill::getTable(as: 's'), 'c.skill_id', 's.id')
				->where('c.long_name', $skill)
				->select(['s.unit', 'c.effect_type_id'])
				->get()
				->first();
			$displaySkill = str_replace(' (%)', '', $skill);
			if (isset($oldCluster) && $oldCluster === $skill) {
				$msg .= "<tab><highlight>{$displaySkill}<end>";
			} else {
				$msg .= "<tab>{$displaySkill}";
			}
			if (isset($effect)) {
				$msg .= sprintf(
					' %+d%s',
					$this->getClusterModAmount($ql, $grade, $effect->effect_type_id),
					$effect->unit
				);
			}
			$msg .= ' [' . Text::makeChatcmd('set', "/tell <myname> implantdesigner {$slot->designSlotName()} {$grade->value} {$skill}");
			$msg .= "]\n";
		}
		$msg .= "\n";
		return $msg;
	}
}
