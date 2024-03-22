<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	ParamClass\PWord,
	Safe,
	Text,
	Util,
};

use Nadybot\Modules\ITEMS_MODULE\{
	ExtBuff,
	ItemWithBuffs,
	ItemsController,
	Skill,
	WhatBuffsController,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'bestsymbiants',
		accessLevel: 'guest',
		description: 'Shows the best symbiants for the slots',
	),
	NCA\DefineCommand(
		command: 'symbcompare',
		accessLevel: 'guest',
		description: 'Compare symbiants with each other',
	),
	NCA\DefineCommand(
		command: 'symbbuffs',
		accessLevel: 'guest',
		description: 'Find symbiants buffing a given skill',
	)
]
class SymbiantController extends ModuleInstance {
	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Inject]
	private WhatBuffsController $wbCtrl;

	#[NCA\Inject]
	private Util $util;

	/** Show the 3 best symbiants for a profession at a given level */
	#[NCA\HandlesCommand('bestsymbiants')]
	#[NCA\Help\Example('<symbol>bestsymbiants 120 enf')]
	public function findBestSymbiantsLvlProf(CmdContext $context, int $level, PWord $prof): void {
		$context->reply(
			$this->findBestSymbiants($context, $prof, $level)
		);
	}

	/** Show the 3 best symbiants for a profession at a given level */
	#[NCA\HandlesCommand('bestsymbiants')]
	#[NCA\Help\Example('<symbol>bestsymbiants 15 trader')]
	public function findBestSymbiantsProfLvl(CmdContext $context, PWord $prof, int $level): void {
		$context->reply(
			$this->findBestSymbiants($context, $prof, $level)
		);
	}

	/** Show the best symbiants your character can currently equip */
	#[NCA\HandlesCommand('bestsymbiants')]
	public function findBestSymbiantsAuto(CmdContext $context): void {
		$context->reply(
			$this->findBestSymbiants($context, null, null)
		);
	}

	/** Compare symbiants by their id to see how they differ in the bonus they give */
	#[NCA\HandlesCommand('symbcompare')]
	public function compareSymbiants(CmdContext $context, int ...$ids): void {
		$items = $this->itemsController->getByIDs(...$ids);
		$symbs = $this->itemsController->addBuffs(...$items->toArray());

		if ($symbs->count() < 2) {
			$context->reply('You have to give at least 2 symbiants for a comparison.');
			return;
		}

		// Count which skill is buffed by how many
		$buffCounter = $symbs->reduce(static function (array $carry, ItemWithBuffs $item): array {
			foreach ($item->buffs as $buff) {
				$carry[$buff->skill->name] ??= 0;
				$carry[$buff->skill->name]++;
			}
			return $carry;
		}, []);
		ksort($buffCounter);
		asort($buffCounter);

		// Map each symbiant to a blob
		$blobs = $symbs->map(static function (ItemWithBuffs $item) use ($buffCounter, $symbs): string {
			$blob = "<header2>{$item->name}<end>\n";
			foreach ($buffCounter as $skillName => $count) {
				$colorStart = '';
				$colorEnd = '';
				$buffs = new Collection($item->buffs);

				/** @var ?ExtBuff */
				$buff = $buffs->filter(static function (ExtBuff $buff) use ($skillName): bool {
					return $buff->skill->name === $skillName;
				})->first();
				if (!isset($buff)) {
					continue;
				} elseif ($count < $symbs->count()) {
					$colorStart = '<font color=#90FF90>';
					$colorEnd = '</font>';
				}
				$blob .= "<tab>{$colorStart}" . $buff->skill->name;
				$blob .= ': ' . sprintf('%+d', $buff->amount) . $buff->skill->unit;
				$blob .= "{$colorEnd}\n";
			}
			return $blob;
		});
		$msg = $this->text->makeBlob('Item comparison', $blobs->join("\n"));
		$context->reply($msg);
	}

	/** Find symbiants  buffing a given skill */
	#[NCA\HandlesCommand('symbbuffs')]
	public function findSymbiants(CmdContext $context, string $skillName): void {
		$skills = $this->wbCtrl->searchForSkill($skillName);
		if (count($skills) === 0) {
			$context->reply("No skill matching '<highlight>{$skillName}<end>' found.");
			return;
		}
		$skillBlocks = [];
		foreach ($skills as $skill) {
			$symbs = $this->findSymbiantsBuffing($skill);
			if (count($symbs) === 0) {
				continue;
			}
			$skillBlocks []= "<header2>{$skill->name}<end>\n" . $this->renderSymbiantBuffs(...$symbs);
		}
		if (count($skillBlocks) === 0) {
			$context->reply("No symbiants buffing '<highlight>{$skillName}<end>' found.");
			return;
		}
		$blob = implode("\n\n", $skillBlocks);
		$msg = $this->text->makeBlob("Symbiants buffing '{$skillName}'", $blob);
		$context->reply($msg);
	}

	/** @param array<string,SymbiantConfig> $configs */
	protected function configsToBlob(array $configs): string {
		/** @var ImplantType[] */
		$types = $this->db->table('ImplantType')
			->asObj(ImplantType::class)
			->toArray();
		$typeMap = array_column($types, 'Name', 'ShortName');
		$blob = '';
		$slots = get_class_vars(SymbiantConfig::class);
		foreach ($slots as $slot => $defaultValue) {
			if (!isset($typeMap[$slot])) {
				continue;
			}
			$blob .= "\n<pagebreak><header2>" . $typeMap[$slot];
			$aoids = [];
			foreach ($configs as $unit => $config) {
				if (empty($config->{$slot})) {
					continue;
				}
				$aoids []= $config->{$slot}[0]->ID;
			}
			$blob .= ' [' . $this->text->makeChatcmd(
				'compare',
				'/tell <myname> symbcompare ' . implode(' ', $aoids)
			) . "]<end>\n";
			foreach ($configs as $unit => $config) {
				if (empty($config->{$slot})) {
					continue;
				}

				/** @var Symbiant[] */
				$symbs = array_slice($config->{$slot}, 0, 3);
				$links = array_map(
					function (Symbiant $symb): string {
						$name =  "QL{$symb->QL}";
						if ($symb->Unit === 'Special') {
							$name = $symb->Name;
						} elseif (count($matches = Safe::pregMatch("/\b(Alpha|Beta)$/", $symb->Name)) === 2) {
							$name = $matches[1];
						}
						return $this->text->makeItem($symb->ID, $symb->ID, $symb->QL, $name);
					},
					$symbs
				);
				$blob .= "<tab>{$unit}: " . implode(' &gt; ', $links) . "\n";
			}
		}
		return $blob;
	}

	/** Render a slot-grouped list of symbiants */
	private function renderSymbiantBuffs(Symbiant ...$symbiants): string {
		$result = [];
		$symbs = new Collection($symbiants);
		$bySlot = $symbs->groupBy('SlotLongName');
		foreach ($bySlot as $slotName => $slotSymbs) {
			$lines = ["<tab><highlight>{$slotName}<end>"];

			/** @var Collection<Symbiant> $slotSymbs */
			$byUnit = $slotSymbs->groupBy('Unit');
			foreach ($byUnit as $unitName => $unitSymbs) {
				/** @var Collection<Symbiant> $unitSymbs */
				if (empty($unitName)) {
					$lines = array_merge(
						$lines,
						$unitSymbs->map(static function (Symbiant $symb): string {
							return '<tab>- ' . $symb->Name;
						})->toArray()
					);
					continue;
				}
				$where = [];

				$inRegular = $unitSymbs->first(
					static function (Symbiant $symb): bool {
						return $symb->QL < 300 || (
							!str_contains($symb->Name, 'Beta')
							&& !str_contains($symb->Name, 'Alpha')
						);
					}
				) !== null;
				if ($inRegular) {
					$where []= 'regular';
				}
				$inBeta = $unitSymbs->first(
					static function (Symbiant $symb): bool {
						return $symb->QL === 300 && str_contains($symb->Name, 'Beta');
					}
				) !== null;
				if ($inBeta) {
					$where []= 'beta';
				}
				$inAlpha = $unitSymbs->first(
					static function (Symbiant $symb): bool {
						return $symb->QL === 300 && str_contains($symb->Name, 'Alpha');
					}
				) !== null;
				if ($inAlpha) {
					$where []= 'alpha';
				}
				$lines []= "<tab>- {$unitName} (" . $this->text->enumerate(...$where) . ')';
			}
			$result []= implode("\n", $lines);
		}
		return implode("\n\n", $result);
	}

	/**
	 * Find all symbiants buffing a given skill
	 *
	 * @return Symbiant[]
	 */
	private function findSymbiantsBuffing(Skill $skill): array {
		$symbiants = $this->db->table('Symbiant', 'sym')
			->join('SymbiantClusterMatrix AS scm', 'scm.SymbiantID', '=', 'sym.ID')
			->join('Cluster AS c', 'c.ClusterID', '=', 'scm.ClusterID')
			->join('ImplantType AS it', 'it.ImplantTypeID', 'sym.SlotID')
			->select(['sym.*', 'it.ShortName AS SlotName', 'it.Name AS SlotLongName'])
			->where('c.SkillID', $skill->id)
			->asObj(Symbiant::class);
		return $symbiants->toArray();
	}

	/** @return string[] */
	private function findBestSymbiants(CmdContext $context, ?PWord $prof, ?int $level): array {
		if (!isset($level) || !isset($prof)) {
			$whois = $this->playerManager->byName($context->char->name);
			if (!isset($whois) || !isset($whois->profession) || !isset($whois->level)) {
				return ['Could not retrieve whois info for you.'];
			}
			return $this->getAndRenderBestSymbiants($whois->profession, $whois->level);
		}
		$profession = $this->util->getProfessionName($prof());
		if ($profession === '') {
			return ["Could not find profession <highlight>{$prof}<end>."];
		}
		return $this->getAndRenderBestSymbiants($profession, $level);
	}

	/** @return string[] */
	private function getAndRenderBestSymbiants(string $prof, int $level): array {
		$query = $this->db->table('Symbiant AS s')
			->join('SymbiantProfessionMatrix AS spm', 'spm.SymbiantID', 's.ID')
			->join('Profession AS p', 'p.ID', 'spm.ProfessionID')
			->join('ImplantType AS it', 'it.ImplantTypeID', 's.SlotID')
			->where('p.Name', $prof)
			->where('s.LevelReq', '<=', $level)
			->where('s.Name', 'NOT LIKE', 'Prototype%')
			->select(['s.*', 'it.ShortName AS SlotName', 'it.Name AS SlotLongName']);
		$query->orderByRaw($query->grammar->wrap('s.Name') . ' like ? desc', ['%Alpha']);
		$query->orderByRaw($query->grammar->wrap('s.Name') . ' like ? desc', ['%Beta']);
		$query->orderByDesc('s.QL');

		/** @var Symbiant[] */
		$symbiants = $query->asObj(Symbiant::class)->toArray();

		/** @var array<string,SymbiantConfig> */
		$configs = [];
		foreach ($symbiants as $symbiant) {
			if (!strlen($symbiant->Unit)) {
				$symbiant->Unit = 'Special';
			}
			$configs[$symbiant->Unit] ??= new SymbiantConfig();
			$configs[$symbiant->Unit]->{$symbiant->SlotName} []= $symbiant;
		}
		$blob = $this->configsToBlob($configs);
		$msg = $this->text->makeBlob(
			"Best 3 symbiants in each slot for a level {$level} {$prof}",
			$blob
		);
		return (array)$msg;
	}
}
