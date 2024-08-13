<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PAttribute,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\{WhatBuffsController};
use stdClass;

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
	private Text $text;

	#[NCA\Inject]
	private WhatBuffsController $whatBuffsController;

	#[NCA\Inject]
	private ImplantController $implantController;

	/** @var list<string> */
	private array $slots = ['head', 'eye', 'ear', 'rarm', 'chest', 'larm', 'rwrist', 'waist', 'lwrist', 'rhand', 'legs', 'lhand', 'feet'];

	/** @var list<string> */
	private array $grades = ['shiny', 'bright', 'faded'];

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
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/Profession.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/Symbiant.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/SymbiantAbilityMatrix.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/SymbiantClusterMatrix.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/SymbiantProfessionMatrix.csv');
	}

	/** Show a shopping list for your current implant design */
	#[NCA\HandlesCommand('implantshoppinglist')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantShoplistCommand(CmdContext $context): void {
		$design = $this->getDesign($context->char->name, '@');
		$list = new ShoppingList();

		/** @var array<string,string> */
		$lookup = $this->db->table(Cluster::getTable())
			->asObj(Cluster::class)
			->reduce(
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
			foreach (['shiny', 'bright', 'faded'] as $grade) {
				/** @psalm-var 'shiny'|'bright'|'faded' $grade */
				if (!isset($slotObj->{$grade})) {
					continue;
				}
				$name = $lookup[$slotObj->{$grade}];
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
				$listGrade = "{$grade}Clusters";
				$list->{$listGrade} []= $name;
				$addImp = true;
			}
			if ($addImp) {
				/** @var string */
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
		$msg = $this->text->makeBlob('Implant Shopping List', $blob);
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
		$msg = $this->text->makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Remove all clusters from your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerClearCommand(CmdContext $context, #[NCA\Str('clear')] string $action): void {
		$this->saveDesign($context->char->name, '@', new ImplantConfig());
		$msg = 'Implant Designer has been cleared.';
		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** See a specific slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotCommand(CmdContext $context, PImplantSlot $slot): void {
		$slot = $slot()->designSlotName();

		$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Clear this slot', "/tell <myname> implantdesigner {$slot} clear");
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Require Ability', "/tell <myname> implantdesigner {$slot} require");
		$blob .= "]\n\n\n";
		$blob .= '<header2>Implants<end>  ';
		foreach ([25, 50, 75, 100, 125, 150, 175, 200, 225, 250, 275, 300] as $ql) {
			$blob .= Text::makeChatcmd((string)$ql, "/tell <myname> implantdesigner {$slot} {$ql}") . ' ';
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
			$ql = (int)($design->{$slot}?->ql ?? 300);
			$blob .= "<header2>QL<end> {$ql}";
			$implant = $this->getImplantInfo($ql, $design->{$slot}->shiny, $design->{$slot}->bright, $design->{$slot}->faded);
			if ($implant !== null) {
				$blob .= " - Treatment: {$implant->treatment} {$implant->ability_name}: {$implant->ability}";
			}
			$blob .= "\n\n";

			$blob .= '<header2>Shiny<end>';
			$blob .= $this->showClusterChoices($design, $slot, 'shiny');

			$blob .= '<header2>Bright<end>';
			$blob .= $this->showClusterChoices($design, $slot, 'bright');

			$blob .= '<header2>Faded<end>';
			$blob .= $this->showClusterChoices($design, $slot, 'faded');
		}

		$msg = $this->text->makeBlob("Implant Designer ({$slot})", $blob);

		$context->reply($msg);
	}

	/** Add a cluster to a slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotAddClusterCommand(
		CmdContext $context,
		PImplantSlot $slot,
		PClusterSlot $grade,
		string $cluster
	): void {
		$slot = $slot();
		$slotName = $slot->designSlotName();
		$grade = $grade();
		$design = $this->getDesign($context->char->name, '@');
		$design->{$slotName} ??= new SlotConfig();

		/**
		 * @var SlotConfig
		 */
		$slotObj = $design->{$slotName};

		if ($grade === 'symb') {
			/** @var ?Symbiant */
			$symbRow = $this->db->table(Symbiant::getTable(), 's')
				->join(ImplantType::getTable(as: 'i'), 's.SlotID', 'i.implant_type_id')
				->where('i.short_name', $slot->designSlotName())
				->where('s.Name', $cluster)
				->select('s.*')
				->addSelect('i.short_name AS SlotName')
				->addSelect('i.name AS SlotLongName')
				->asObj(Symbiant::class)->first();

			if ($symbRow === null) {
				$msg = "Could not find symbiant <highlight>{$cluster}<end>.";
			} else {
				// convert slot to symb
				$slotObj->shiny = null;
				$slotObj->bright = null;
				$slotObj->faded = null;
				$slotObj->ql = null;

				$symb = new SymbiantSlot(
					name: $symbRow->Name,
					Treatment: $symbRow->TreatmentReq,
					Level: $symbRow->LevelReq,
					reqs: $this->db->table(SymbiantAbilityMatrix::getTable(), 's')
						->join(Ability::getTable(as: 'a'), 's.AbilityID', 'a.ability_id')
						->where('s.SymbiantID', $symbRow->ID)
						->select(['a.name', 's.Amount as amount'])
						->asObjArr(AbilityAmount::class),
					mods: $this->db->table(SymbiantClusterMatrix::getTable(), 's')
						->join(Cluster::getTable(as: 'c'), 's.ClusterID', 'c.cluster_id')
						->where('SymbiantID', $symbRow->ID)
						->select(['c.long_name AS Name', 's.Amount'])
						->asObjArr(AbilityAmount::class)
				);

				$slotObj->symb = $symb;
				$msg = "<highlight>{$slot->longName()}(symb)<end> has been set to <highlight>{$symb->name}<end>.";
			}
		} else {
			if (strtolower($cluster) === 'clear') {
				if ($slotObj->{$grade} === null) {
					$msg = "There is no cluster in <highlight>{$slot->longName()}({$grade})<end>.";
				} else {
					$slotObj->{$grade} = null;
					$msg = "<highlight>{$slot->longName()}({$grade})<end> has been cleared.";
				}
			} else {
				$clusterObj = $this->db->table(Cluster::getTable())
					->whereIlike('long_name', strtolower($cluster))
					->limit(1)
					->asObj(Cluster::class)
					->first();
				if (!isset($clusterObj)) {
					$matches = $this->whatBuffsController->searchForSkill($cluster);
					if (count($matches) !== 1) {
						$context->reply("Unknown skill <highlight>{$cluster}<end>.");
						return;
					}
					$match = $matches[0];
					$clusterObj = $this->db->table(Cluster::getTable())
						->where('skill_id', $match->id)
						->asObj(Cluster::class)
						->first();
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
					->where('ct.name', $grade)
					->where('it.short_name', $slot->designSlotName())
					->exists();
				if (!$valid) {
					$context->reply("There is no {$grade} {$clusterObj->long_name} cluster for the {$slot->longName()}.");
					return;
				}
				$slotObj->{$grade} = $clusterObj->long_name;
				$msg = "<highlight>{$slot->longName()}({$grade})<end> has been set to <highlight>{$clusterObj->long_name}<end>.";
			}
		}

		$this->saveDesign($context->char->name, '@', $design);

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Set the QL for a slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotQLCommand(
		CmdContext $context,
		PImplantSlot $slot,
		int $ql
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		$slotName = $slot->designSlotName();
		$design->{$slotName} ??= new SlotConfig();
		if ($ql < 1 || $ql > 300) {
			$context->reply('Invalid ql given. Allowed ranges are 1 to 300');
			return;
		}

		/** @var SlotConfig */
		$slotObj = $design->{$slotName};
		$slotObj->symb = null;
		$slotObj->ql = $ql;
		$this->saveDesign($context->char->name, '@', $design);

		$msg = "<highlight>{$slot->longName()}<end> has been set to QL <highlight>{$ql}<end>.";

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Clear all clusters from a slot in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotClearCommand(
		CmdContext $context,
		PImplantSlot $slot,
		#[NCA\Str('clear')] string $action
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		$design->{$slot->designSlotName()} = null;
		$this->saveDesign($context->char->name, '@', $design);

		$msg = "<highlight>{$slot->longName()}<end> has been cleared.";

		$context->reply($msg);

		// send results
		$blob = $this->getImplantDesignerBuild($context->char->name);
		$msg = $this->text->makeBlob('Implant Designer', $blob);
		$context->reply($msg);
	}

	/** Show how to make a slot require a certain attribute in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotRequireCommand(
		CmdContext $context,
		PImplantSlot $slot,
		#[NCA\Str('require')] string $action
	): void {
		$slot = $slot();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->{$slot->designSlotName()};
		if (!isset($slotObj)) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->symb)) {
			$msg = 'You cannot require an ability for a symbiant.';
		} elseif (!isset($slotObj->shiny) && !isset($slotObj->bright) && !isset($slotObj->faded)) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->shiny) && isset($slotObj->bright) && isset($slotObj->faded) > 0) {
			$msg = 'You must have at least one empty cluster to require an ability.';
		} else {
			$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
			$blob .= ']<tab>[';
			$blob .= Text::makeChatcmd('Clear this slot', "/tell <myname> implantdesigner {$slot->designSlotName()} clear");
			$blob .= "]\n\n\n";
			$blob .= Text::makeChatcmd($slot->longName(), "/tell <myname> implantdesigner {$slot->designSlotName()}");
			if ($slotObj instanceof SlotConfig) {
				$blob .= $this->getImplantSummary($slotObj) . "\n";
			}
			$blob .= "Which ability do you want to require for {$slot->longName()}?\n\n";
			$abilities = $this->db->table(Ability::getTable())->select('name')
				->pluckStrings('name')->toArray();
			foreach ($abilities as $ability) {
				$blob .= Text::makeChatcmd($ability, "/tell <myname> implantdesigner {$slot->designSlotName()} require {$ability}") . "\n";
			}
			$msg = $this->text->makeBlob("Implant Designer Require Ability ({$slot->longName()})", $blob);
		}

		$context->reply($msg);
	}

	/** Show how to make a slot require a certain attribute in your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerSlotRequireAbilityCommand(
		CmdContext $context,
		PImplantSlot $slot,
		#[NCA\Str('require')] string $action,
		PAttribute $ability
	): void {
		$slot = $slot();
		$ability = $ability();

		$design = $this->getDesign($context->char->name, '@');
		$slotObj = $design->{$slot->designSlotName()};
		if (!isset($slotObj)) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->symb)) {
			$msg = 'You cannot require an ability for a symbiant.';
		} elseif (!isset($slotObj->shiny) && !isset($slotObj->bright) && !isset($slotObj->faded)) {
			$msg = 'You must have at least one cluster filled to require an ability.';
		} elseif (isset($slotObj->shiny) && isset($slotObj->bright) && isset($slotObj->faded)) {
			$msg = 'You must have at least one empty cluster to require an ability.';
		} else {
			$blob  = '[' . Text::makeChatcmd('See Build', '/tell <myname> implantdesigner');
			$blob .= ']<tab>[';
			$blob .= Text::makeChatcmd('Clear this slot', "/tell <myname> implantdesigner {$slot->designSlotName()} clear");
			$blob .= "]\n\n\n";
			$blob .= Text::makeChatcmd($slot->longName(), "/tell <myname> implantdesigner {$slot->designSlotName()}");
			if ($slotObj instanceof SlotConfig) {
				$blob .= $this->getImplantSummary($slotObj) . "\n";
			}
			$blob .= "Combinations for <highlight>{$slot->longName()}<end> that will require {$ability}:\n";
			$query = $this->db
				->table(ImplantMatrix::getTable(), 'i')
				->join(Cluster::getTable(as: 'c1'), 'i.shining_id', 'c1.cluster_id')
				->join(Cluster::getTable(as: 'c2'), 'i.bright_id', 'c2.cluster_id')
				->join(Cluster::getTable(as: 'c3'), 'i.faded_id', 'c3.cluster_id')
				->join(Ability::getTable(as: 'a'), 'i.ability_id', 'a.ability_id')
				->where('a.name', ucfirst($ability))
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
					$results []= ['shiny', $row->shiny_effect];
				}
				if (!isset($slotObj->bright)) {
					$results []= ['bright', $row->bright_effect];
				}
				if (!isset($slotObj->faded)) {
					$results []= ['faded', $row->faded_effect];
				}

				/** @var list<string> $results */
				$results = array_map(
					/**
					 * @param list<string> $item
					 *
					 * @psalm-param list{string,string} $item
					 */
					static function (array $item) use ($slot): string {
						return ($item[1] === '') ? '-Empty-' : Text::makeChatcmd($item[1], "/tell <myname> implantdesigner {$slot->designSlotName()} {$item[0]} {$item[1]}");
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
			$msg = $this->text->makeBlob("Implant Designer Require {$ability} ({$slot->longName()}) ({$count})", $blob);
		}

		$context->reply($msg);
	}

	/** Show the result of your current implant design */
	#[NCA\HandlesCommand('implantdesigner')]
	#[NCA\Help\Group('implantdesigner')]
	public function implantdesignerResultCommand(CmdContext $context, #[NCA\Str('result', 'results')] string $action): void {
		$blob = $this->getImplantDesignerResults($context->char->name);

		$msg = $this->text->makeBlob('Implant Designer Results', $blob);

		$context->reply($msg);
	}

	public function getImplantDesignerResults(string $name): string {
		$design = $this->getDesign($name, '@');

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

		foreach ($this->slots as $slot) {
			/** @var ?SlotConfig */
			$slotObj = $design->{$slot};

			// skip empty slots
			if ($slotObj === null) {
				continue;
			}

			if (isset($slotObj->symb)) {
				$symb = $slotObj->symb;

				// add reqs
				if ($symb->Treatment > $reqs['Treatment']) {
					$reqs['Treatment'] = $symb->Treatment;
				}
				if ($symb->Level > $reqs['Level']) {
					$reqs['Level'] = $symb->Level;
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
				$implant = $this->getImplantInfo($ql, $slotObj->shiny, $slotObj->bright, $slotObj->faded);
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
				foreach ($this->grades as $grade) {
					if (isset($slotObj->{$grade})) {
						$effectTypeIdName = strtolower($grade) . '_effect_type_id';
						$effectId = $implant->{$effectTypeIdName};
						$mods[$slotObj->{$grade}] += $this->getClusterModAmount($ql, $grade, $effectId);

						// add cluster
						$clusters []= new ShoppingCluster(
							ql: $this->implantController->getClusterMinQl($ql, $grade),
							slot: $slot,
							grade: $grade,
							name: $slotObj->{$grade},
						);
					}
				}
			}
		}

		// sort mods by name alphabetically
		ksort($mods);

		// sort clusters by name alphabetically, and then by grade, shiny first
		$grades = $this->grades;
		usort($clusters, static function (object $cluster1, object $cluster2) use ($grades): int {
			$val = strcmp($cluster1->name, $cluster2->name);
			if ($val === 0) {
				$val1 = array_search($cluster1->grade, $grades, true);
				$val2 = array_search($cluster2->grade, $grades, true);
				return $val1 <=> $val2;
			}
			return $val <=> 0;
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
		$row = $this->db->table(ImplantMatrix::getTable(), 'i')
			->join(Cluster::getTable(as: 'cs'), 'i.shining_id', 'cs.cluster_id')
			->join(Cluster::getTable(as: 'cb'), 'i.bright_id', 'cb.cluster_id')
			->join(Cluster::getTable(as: 'cf'), 'i.faded_id', 'cf.cluster_id')
			->join(Ability::getTable(as: 'a'), 'i.ability_id', 'a.ability_id')
			->whereIlike('cs.long_name', strtolower($shiny ?? ''))
			->whereIlike('cb.long_name', strtolower($bright ?? ''))
			->whereIlike('cf.long_name', strtolower($faded ?? ''))
			->select(['i.ability_ql1', 'i.ability_ql200'])
			->addSelect(['i.ability_ql201', 'i.ability_ql300', 'i.treat_ql1'])
			->addSelect(['i.treat_ql200', 'i.treat_ql201', 'i.treat_ql300'])
			->addSelect('cs.effect_type_id as shiny_effect_type_id')
			->addSelect('cb.effect_type_id as bright_effect_type_id')
			->addSelect('cf.effect_type_id as faded_effect_type_id')
			->addSelect('a.name AS ability_name')
			->limit(1)
			->asObj(ImplantInfo::class)
			->first();

		if ($row === null) {
			return null;
		}
		return $this->addImplantInfo($row, $ql);
	}

	/** @return list<string> */
	public function getClustersForSlot(string $implantType, string $clusterType): array {
		return $this->db
			->table(Cluster::getTable(), 'c')
			->join(ClusterImplantMap::getTable(as: 'cim'), 'c.cluster_id', 'cim.cluster_id')
			->join(ClusterType::getTable(as: 'ct'), 'cim.cluster_type_id', 'ct.cluster_type_id')
			->join(ImplantType::getTable(as: 'i'), 'cim.implant_type_id', 'i.implant_type_id')
			->where('i.short_name', strtolower($implantType))
			->where('ct.name', strtolower($clusterType))
			->select('c.long_name AS skill')
			->pluckStrings('skill')
			->toList();
	}

	public function getDesign(string $sender, string $name): ImplantConfig {
		$design = $this->db->table(ImplantDesign::getTable())
			->where('owner', $sender)
			->where('name', $name)
			->asObj(ImplantDesign::class)
			->first();
		return $design->design ?? new ImplantConfig();
	}

	public function saveDesign(string $sender, string $name, ImplantConfig $design): void {
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
		$design = $this->getDesign($sender, '@');

		$blob = '[' . Text::makeChatcmd('Results', '/tell <myname> implantdesigner results');
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Clear All', '/tell <myname> implantdesigner clear');
		$blob .= ']<tab>[';
		$blob .= Text::makeChatcmd('Shopping List', '/tell <myname> implantshoppinglist');
		$blob .= "]\n\n\n";

		foreach ($this->slots as $slot) {
			$blob .= Text::makeChatcmd($slot, "/tell <myname> implantdesigner {$slot}");
			if (isset($design->{$slot})) {
				$blob .= $this->getImplantSummary($design->{$slot});
			} else {
				$blob .= "\n";
			}
			$blob .= "\n";
		}

		return $blob;
	}

	private function getImplantSummary(stdClass|SlotConfig $slotObj): string {
		if ($slotObj->symb !== null) {
			$msg = ' ' . $slotObj->symb->name . "\n";
			return $msg;
		}
		$ql = (int)($slotObj->ql ?? 300);
		$implant = $this->getImplantInfo($ql, $slotObj->shiny, $slotObj->bright, $slotObj->faded);
		$msg = ' QL' . $ql;
		if ($implant !== null) {
			$msg .= " - Treatment: {$implant->treatment} {$implant->ability_name}: {$implant->ability}";
		}
		$msg .= "\n";

		foreach ($this->grades as $grade) {
			if (!isset($slotObj->{$grade})) {
				$msg .= "<tab><highlight>-Empty-<end>\n";
			} else {
				$effectTypeIdName = strtolower($grade) . '_effect_type_id';
				$effectId = $implant->{$effectTypeIdName};
				$msg .= "<tab><highlight>{$slotObj->{$grade}}<end> (" . $this->getClusterModAmount($ql, $grade, $effectId) . ")\n";
			}
		}
		return $msg;
	}

	private function getClusterModAmount(int $ql, string $grade, int $effectId): int {
		/** @var EffectTypeMatrix */
		$etm = $this->db->table(EffectTypeMatrix::getTable())
			->where('id', $effectId)
			->asObj(EffectTypeMatrix::class)->firstOrFail();

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
		if ($grade === 'bright') {
			$modAmount = round($modAmount * 0.6, 0);
		} elseif ($grade === 'faded') {
			$modAmount = round($modAmount * 0.4, 0);
		}

		return (int)$modAmount;
	}

	private function getSymbiantsLinks(string $slot): string {
		$artilleryLink = Text::makeChatcmd('Artillery', "/tell <myname> symb {$slot} artillery");
		$controlLink = Text::makeChatcmd('Control', "/tell <myname> symb {$slot} control");
		$exterminationLink = Text::makeChatcmd('Extermination', "/tell <myname> symb {$slot} extermination");
		$infantryLink = Text::makeChatcmd('Infantry', "/tell <myname> symb {$slot} infantry");
		$supportLink = Text::makeChatcmd('Support', "/tell <myname> symb {$slot} support");
		return "<header2>Symbiants<end>  {$artilleryLink}  {$controlLink}  {$exterminationLink}  {$infantryLink}  {$supportLink}";
	}

	private function showClusterChoices(object $design, string $slot, string $grade): string {
		$msg = '';
		if (isset($design->{$slot}->{$grade})) {
			$msg .= " - {$design->{$slot}->{$grade}}";
		}
		$msg .= "\n";
		$msg .= Text::makeChatcmd('-Empty-', "/tell <myname> implantdesigner {$slot} {$grade} clear") . "\n";
		$skills = $this->getClustersForSlot($slot, $grade);
		foreach ($skills as $skill) {
			$msg .= Text::makeChatcmd($skill, "/tell <myname> implantdesigner {$slot} {$grade} {$skill}") . "\n";
		}
		$msg .= "\n\n";
		return $msg;
	}

	private function addImplantInfo(ImplantInfo $implantInfo, int $ql): ImplantInfo {
		if ($ql < 201) {
			$minAbility = $implantInfo->ability_ql1;
			$maxAbility = $implantInfo->ability_ql200;
			$minTreatment = $implantInfo->treat_ql1;
			$maxTreatment = $implantInfo->treat_ql200;
			$minQl = 1;
			$maxQl = 200;
		} else {
			$minAbility = $implantInfo->ability_ql201;
			$maxAbility = $implantInfo->ability_ql300;
			$minTreatment = $implantInfo->treat_ql201;
			$maxTreatment = $implantInfo->treat_ql300;
			$minQl = 201;
			$maxQl = 300;
		}

		$implantInfo->ability = Util::interpolate($minQl, $maxQl, $minAbility, $maxAbility, $ql);
		$implantInfo->treatment = Util::interpolate($minQl, $maxQl, $minTreatment, $maxTreatment, $ql);

		return $implantInfo;
	}
}
