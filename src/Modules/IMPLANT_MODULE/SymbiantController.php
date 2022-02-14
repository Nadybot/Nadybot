<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	DBSchema\Player,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	ParamClass\PWord,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\{
	ExtBuff,
	ItemsController,
	ItemWithBuffs,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "bestsymbiants",
		accessLevel: "guest",
		description: "Shows the best symbiants for the slots",
	),
	NCA\DefineCommand(
		command: "symbcompare",
		accessLevel: "guest",
		description: "Compare symbiants with each other",
	)
]
class SymbiantController extends ModuleInstance {
	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public Util $util;

	/** Show the 3 best symbiants for a profession at a given level */
	#[NCA\HandlesCommand("bestsymbiants")]
	#[NCA\Help\Example("<symbol>bestsymbiants 120 enf")]
	public function findBestSymbiantsLvlProf(CmdContext $context, int $level, PWord $prof): void {
		$this->findBestSymbiants($context, $prof, $level);
	}

	/** Show the 3 best symbiants for a profession at a given level */
	#[NCA\HandlesCommand("bestsymbiants")]
	#[NCA\Help\Example("<symbol>bestsymbiants 15 trader")]
	public function findBestSymbiantsProfLvl(CmdContext $context, PWord $prof, int $level): void {
		$this->findBestSymbiants($context, $prof, $level);
	}

	/** Show the best symbiants your character can currently equip */
	#[NCA\HandlesCommand("bestsymbiants")]
	public function findBestSymbiantsAuto(CmdContext $context): void {
		$this->findBestSymbiants($context, null, null);
	}

	public function findBestSymbiants(CmdContext $context, ?PWord $prof, ?int $level): void {
		if (!isset($level) || !isset($prof)) {
			$this->playerManager->getByNameAsync(
				function(?Player $whois) use ($context): void {
					if (!isset($whois) || !isset($whois->profession) || !isset($whois->level)) {
						$msg = "Could not retrieve whois info for you.";
						$context->reply($msg);
						return;
					}
					$this->showBestSymbiants($whois->profession, $whois->level, $context);
				},
				$context->char->name
			);
			return;
		}
		$profession = $this->util->getProfessionName($prof());
		if ($profession === '') {
			$msg = "Could not find profession <highlight>{$prof}<end>.";
			$context->reply($msg);
			return;
		}
		$this->showBestSymbiants($profession, $level, $context);
	}

	public function showBestSymbiants(string $prof, int $level, CmdContext $context): void {
		$query = $this->db->table("Symbiant AS s")
			->join("SymbiantProfessionMatrix AS spm", "spm.SymbiantID", "s.ID")
			->join("Profession AS p", "p.ID", "spm.ProfessionID")
			->join("ImplantType AS it", "it.ImplantTypeID", "s.SlotID")
			->where("p.Name", $prof)
			->where("s.LevelReq", "<=", $level)
			->where("s.Name", "NOT LIKE", "Prototype%")
			->select("s.*", "it.ShortName AS SlotName", "it.Name AS SlotLongName");
		$query->orderByRaw($query->grammar->wrap("s.Name") . " like ? desc", ['%Alpha']);
		$query->orderByRaw($query->grammar->wrap("s.Name") . " like ? desc", ['%Beta']);
		$query->orderByDesc("s.QL");
		/** @var Symbiant[] */
		$symbiants = $query->asObj(Symbiant::class)->toArray();
		/** @var array<string,SymbiantConfig> */
		$configs = [];
		foreach ($symbiants as $symbiant) {
			if (!strlen($symbiant->Unit)) {
				$symbiant->Unit = "Special";
			}
			$configs[$symbiant->Unit] ??= new SymbiantConfig();
			$configs[$symbiant->Unit]->{$symbiant->SlotName} []= $symbiant;
		}
		$blob = $this->configsToBlob($configs);
		$msg = $this->text->makeBlob(
			"Best 3 symbiants in each slot for a level {$level} {$prof}",
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * @param array<string,SymbiantConfig> $configs
	 */
	protected function configsToBlob(array $configs): string {
		/** @var ImplantType[] */
		$types = $this->db->table("ImplantType")
			->asObj(ImplantType::class)
			->toArray();
		$typeMap = array_column($types, "Name", "ShortName");
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
			$blob .= " [" . $this->text->makeChatcmd(
				"compare",
				"/tell <myname> symbcompare " . join(" ", $aoids)
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
						} elseif (preg_match("/\b(Alpha|Beta)$/", $symb->Name, $matches)) {
							$name = $matches[1];
						}
						return $this->text->makeItem($symb->ID, $symb->ID, $symb->QL, $name);
					},
					$symbs
				);
				$blob .= "<tab>{$unit}: " . join(" &gt; ", $links) . "\n";
			}
		}
		return $blob;
	}

	/** Compare symbiants by their id to see how they differ in the bonus they give */
	#[NCA\HandlesCommand("symbcompare")]
	public function compareSymbiants(CmdContext $context, int ...$ids): void {
		$items = $this->itemsController->getByIDs(...$ids);
		$symbs = $this->itemsController->addBuffs(...$items->toArray());

		if ($symbs->count() < 2) {
			$context->reply("You have to give at least 2 symbiants for a comparison.");
			return;
		}

		// Count which skill is buffed by how many
		$buffCounter = $symbs->reduce(function(array $carry, ItemWithBuffs $item): array {
			foreach ($item->buffs as $buff) {
				$carry[$buff->skill->name] ??= 0;
				$carry[$buff->skill->name]++;
			}
			return $carry;
		}, []);
		ksort($buffCounter);
		asort($buffCounter);

		// Map each symbiant to a blob
		$blobs = $symbs->map(function(ItemWithBuffs $item) use ($buffCounter, $symbs): string {
			$blob = "<header2>{$item->name}<end>\n";
			foreach ($buffCounter as $skillName => $count) {
				$colorStart = "";
				$colorEnd = "";
				$buffs = new Collection($item->buffs);
				/** @var ?ExtBuff */
				$buff = $buffs->filter(function (ExtBuff $buff) use ($skillName): bool {
					return $buff->skill->name === $skillName;
				})->first();
				if (!isset($buff)) {
					continue;
				} elseif ($count < $symbs->count()) {
					$colorStart = "<font color=#90FF90>";
					$colorEnd = "</font>";
				}
				$blob .= "<tab>{$colorStart}" . $buff->skill->name;
				$blob .= ": " . sprintf("%+d", $buff->amount) . $buff->skill->unit;
				$blob .= "{$colorEnd}\n";
			}
			return $blob;
		});
		$msg = $this->text->makeBlob("Item comparison", $blobs->join("\n"));
		$context->reply($msg);
	}
}
