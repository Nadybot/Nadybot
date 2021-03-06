<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBRow;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\ITEMS_MODULE\Skill;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'bestsymbiants',
 *		accessLevel = 'all',
 *		description = 'Shows the best symbiants for the slots',
 *		help        = 'bestsymbiants.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'symbcompare',
 *		accessLevel = 'all',
 *		description = 'Compare symbiants with each other',
 *		help        = 'bestsymbiants.txt'
 *	)
 */
class SymbiantController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public ItemsController $itemsController;

	/** @Inject */
	public Util $util;

	/**
	 * @HandlesCommand("bestsymbiants")
	 * @Matches("/^bestsymbiants$/i")
	 * @Matches("/^bestsymbiants (?<prof>[^ ]+) (?<level>\d+)$/i")
	 * @Matches("/^bestsymbiants (?<level>\d+) (?<prof>[^ ]+)$/i")
	 */
	public function findBestSymbiants(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($args['level'])) {
			$this->playerManager->getByNameAsync(
				function(?Player $whois) use ($args, $sendto): void {
					if (empty($whois)) {
						$msg = "Could not retrieve whois info for you.";
						$sendto->reply($msg);
						return;
					}
					$this->showBestSymbiants($whois->profession, $whois->level, $sendto);
				},
				$sender
			);
			return;
		}
		$prof = $this->util->getProfessionName($args['prof']);
		if ($prof === '') {
			$msg = "Could not find profession <highlight>{$args['prof']}<end>.";
			$sendto->reply($msg);
			return;
		}
		$this->showBestSymbiants($prof, (int)$args['level'], $sendto);
	}

	public function showBestSymbiants(string $prof, int $level, CommandReply $sendto): void {
		$query = $this->db->table("Symbiant AS s")
			->join("SymbiantProfessionMatrix AS spm", "spm.SymbiantID", "s.ID")
			->join("Profession AS p", "p.ID", "spm.ProfessionID")
			->join("ImplantType AS it", "it.ImplantTypeID", "s.SlotID")
			->where("p.Name", $prof)
			->where("s.LevelReq", "<=", $level)
			->where("s.Name", "NOT LIKE", "Prototype%")
			->select("s.*", "it.ShortName AS SlotName", "it.Name AS SlotLongName");
		$query->orderByRaw($query->grammar->wrap("s.Name") . " like ? desc", '%Alpha');
		$query->orderByRaw($query->grammar->wrap("s.Name") . " like ? desc", '%Beta');
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
		$sendto->reply($msg);
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
			$blob .= "\n<header2>" . $typeMap[$slot];
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

	/**
	 * @HandlesCommand("symbcompare")
	 * @Matches("/^symbcompare ((?:\s*\d+)+)$/i")
	 */
	public function compareSymbiants(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$ids = new Collection(preg_split("/\s+/", $args[1]));

		// Get all symbs that exist
		$symbs = $ids->map(function(string $id) {
			$item = $this->itemsController->findById((int)$id);
			$item->buffs = $this->db->table("item_buffs")
				->where("item_id", $id)
				->asObj()
				->reduce(function(array $carry, DBRow $obj): array {
					$carry[$obj->attribute_id] = (int)$obj->amount;
					return $carry;
				}, []);
			return $item;
		})->filter();

		// Count which skill is buffed by how many
		$buffCounter = $symbs->reduce(function(array $carry, AODBEntry $item) {
			foreach ($item->buffs as $skillId => $amount) {
				$carry[$skillId] ??= 0;
				$carry[$skillId]++;
			}
			return $carry;
		}, []);

		// Cache all involved skills
		/** @var Collection<Skill> */
		$skills = $this->db->table("skills")
			->whereIn("id", array_keys($buffCounter))
			->orderBy("name")
			->asObj(Skill::class);

		$skills = $skills->sort(function(Skill $s1, Skill $s2) use ($buffCounter): int {
			return $buffCounter[$s1->id] <=> $buffCounter[$s2->id]
				?: strcmp($s1->name, $s2->name);
		});
		// Map each symbiant to a blob
		$blobs = $symbs->map(function(AODBEntry $item) use ($buffCounter, $skills, $symbs): string {
			$blob = "<header2>{$item->name}<end>\n";
			foreach ($skills as $skill) {
				$colorStart = "";
				$colorEnd = "";
				if (!isset($item->buffs[$skill->id])) {
					continue;
					$colorStart = "<red>";
					$colorEnd = "<end>";
				} elseif ($buffCounter[$skill->id] < $symbs->count()) {
					$colorStart = "<font color=#90FF90>";
					$colorEnd = "</font>";
				}
				$blob .= "<tab>{$colorStart}" . $skill->name;
				if (isset($item->buffs[$skill->id])) {
					$blob .= ": " . sprintf("%+d", $item->buffs[$skill->id]) . $skill->unit;
				}
				$blob .= "{$colorEnd}\n";
			}
			return $blob;
		});
		$msg = $this->text->makeBlob("Item comparison", $blobs->join("\n"));
		$sendto->reply($msg);
	}
}
