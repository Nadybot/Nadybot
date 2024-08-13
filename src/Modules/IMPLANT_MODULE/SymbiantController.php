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
	Types\Profession,
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
				/** @var array<string,int> $carry */
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
				$buffs = collect($item->buffs);

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

	/** @param iterable<string,SymbiantConfig> $configs */
	protected function configsToBlob(iterable $configs): string {
		/** @var list<ImplantType> */
		$types = $this->db->table(ImplantType::getTable())
			->asObjArr(ImplantType::class);

		/** @var array<string,string> */
		$typeMap = array_column($types, 'name', 'short_name');
		$blob = '';
		$slots = get_class_vars(SymbiantConfig::class);
		foreach ($slots as $slot => $defaultValue) {
			if (!isset($typeMap[$slot])) {
				continue;
			}
			$blob .= "\n<pagebreak><header2>" . $typeMap[$slot];
			$aoids = [];
			foreach ($configs as $unit => $config) {
				if (!count($config->{$slot})) {
					continue;
				}
				$aoids []= $config->{$slot}[0]->id;
			}
			$blob .= ' [' . Text::makeChatcmd(
				'compare',
				'/tell <myname> symbcompare ' . implode(' ', $aoids)
			) . "]<end>\n";
			foreach ($configs as $unit => $config) {
				if (!count($config->{$slot})) {
					continue;
				}

				/** @var list<Symbiant> */
				$symbs = array_slice($config->{$slot}, 0, 3);

				/** @var list<string> */
				$links = array_map(
					static function (Symbiant $symb): string {
						$name =  "QL{$symb->ql}";
						if ($symb->unit === 'Special') {
							$name = $symb->name;
						} elseif (count($matches = Safe::pregMatch("/\b(Alpha|Beta)$/", $symb->name)) === 2) {
							$name = $matches[1];
						}
						return $symb->getLink(text: $name);
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
		$symbs = collect($symbiants);

		/** @var Collection<string,Collection<int,Symbiant>> */
		$bySlot = $symbs->groupBy('slot_long_name');
		foreach ($bySlot as $slotName => $slotSymbs) {
			$lines = ["<tab><highlight>{$slotName}<end>"];

			/** @var Collection<string,Collection<int,Symbiant>> */
			$byUnit = $slotSymbs->groupBy('unit');
			foreach ($byUnit as $unitName => $unitSymbs) {
				if ($unitName === '') {
					$lines = array_merge(
						$lines,
						$unitSymbs->map(static function (Symbiant $symb): string {
							return '<tab>- ' . $symb->name;
						})->toArray()
					);
					continue;
				}
				$where = [];

				$inRegular = $unitSymbs->first(
					static function (Symbiant $symb): bool {
						return $symb->ql < 300 || (
							!str_contains($symb->name, 'Beta')
							&& !str_contains($symb->name, 'Alpha')
						);
					}
				) !== null;
				if ($inRegular) {
					$where []= 'regular';
				}
				$inBeta = $unitSymbs->first(
					static function (Symbiant $symb): bool {
						return $symb->ql === 300 && str_contains($symb->name, 'Beta');
					}
				) !== null;
				if ($inBeta) {
					$where []= 'beta';
				}
				$inAlpha = $unitSymbs->first(
					static function (Symbiant $symb): bool {
						return $symb->ql === 300 && str_contains($symb->name, 'Alpha');
					}
				) !== null;
				if ($inAlpha) {
					$where []= 'alpha';
				}
				$lines []= "<tab>- {$unitName} (" . Text::enumerate(...$where) . ')';
			}
			$result []= implode("\n", $lines);
		}
		return implode("\n\n", $result);
	}

	/**
	 * Find all symbiants buffing a given skill
	 *
	 * @return list<Symbiant>
	 */
	private function findSymbiantsBuffing(Skill $skill): array {
		return $this->db->table(Symbiant::getTable(), 'sym')
			->join(SymbiantClusterMatrix::getTable(as: 'scm'), 'scm.symbiant_id', '=', 'sym.id')
			->join(Cluster::getTable() . ' AS c', 'c.cluster_id', '=', 'scm.cluster_id')
			->join(ImplantType::getTable(as: 'it'), 'it.implant_type_id', 'sym.slot_id')
			->select(['sym.*', 'it.short_name AS slot_name', 'it.name AS slot_long_name'])
			->where('c.skill_id', $skill->id)
			->asObjArr(Symbiant::class);
	}

	/** @return list<string> */
	private function findBestSymbiants(CmdContext $context, ?PWord $prof, ?int $level): array {
		if (!isset($level) || !isset($prof)) {
			$whois = $this->playerManager->byName($context->char->name);
			if (!isset($whois) || !isset($whois->profession) || !isset($whois->level)) {
				return ['Could not retrieve whois info for you.'];
			}
			return $this->getAndRenderBestSymbiants($whois->profession, $whois->level);
		}
		try {
			$profession = Profession::byName($prof());
		} catch (\Exception) {
			return ["Could not find profession <highlight>{$prof}<end>."];
		}
		return $this->getAndRenderBestSymbiants($profession, $level);
	}

	/** @return list<string> */
	private function getAndRenderBestSymbiants(Profession $prof, int $level): array {
		$query = $this->db->table(Symbiant::getTable(), 's')
			->join(SymbiantProfessionMatrix::getTable('spm'), 'spm.symbiant_id', 's.id')
			->join(ImplantType::getTable(as: 'it'), 'it.implant_type_id', 's.slot_id')
			->where('spm.profession_id', $prof->toNumber())
			->where('s.level_req', '<=', $level)
			->where('s.name', 'NOT LIKE', 'Prototype%')
			->select(['s.*', 'it.short_name AS slot_name', 'it.name AS slot_long_name']);
		$query->orderByRaw($query->grammar->wrap('s.name') . ' like ? desc', ['%Alpha']);
		$query->orderByRaw($query->grammar->wrap('s.name') . ' like ? desc', ['%Beta']);
		$query->orderByDesc('s.ql');

		$symbiants = $query->asObj(Symbiant::class);

		/** @var array<string,SymbiantConfig> */
		$configs = [];
		foreach ($symbiants as $symbiant) {
			if (!strlen($symbiant->unit)) {
				$symbiant->unit = 'Special';
			}
			$configs[$symbiant->unit] ??= new SymbiantConfig();
			$configs[$symbiant->unit]->{$symbiant->slot_name} []= $symbiant;
		}
		$blob = $this->configsToBlob($configs);
		$msg = $this->text->makeBlob(
			"Best 3 symbiants in each slot for a level {$level} {$prof->value}",
			$blob
		);
		return (array)$msg;
	}
}
