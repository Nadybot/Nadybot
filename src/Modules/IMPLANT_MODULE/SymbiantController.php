<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

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
		$sql = "SELECT s.*, it.`ShortName` as `SlotName`, it.`Name` as `SlotLongName` FROM `Symbiant` s ".
			"JOIN `SymbiantProfessionMatrix` spm ON (spm.`SymbiantID` = s.`id`) ".
			"JOIN `Profession` p ON (p.`ID` = spm.`ProfessionID`) ".
			"JOIN `ImplantType` it ON (it.`ImplantTypeID` = s.`SlotID`) ".
			"WHERE p.`Name` = ? ".
			"AND s.`LevelReq` <= ? ".
			"AND s.`Name` NOT LIKE 'Prototype%' ".
			"ORDER BY s.`Name` LIKE '%Alpha' DESC, s.`Name` LIKE '%Beta' DESC, s.`QL` DESC";
		/** @var Symbiant[] */
		$symbiants = $this->db->fetchAll(Symbiant::class, $sql, $prof, $level);
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
		$sql = "SELECT * FROM `ImplantType`";
		/** @var ImplantType[] */
		$types = $this->db->fetchAll(ImplantType::class, $sql);
		$typeMap = array_column($types, "Name", "ShortName");
		$blob = '';
		$slots = get_class_vars(SymbiantConfig::class);
		foreach ($slots as $slot => $defaultValue) {
			if (!isset($typeMap[$slot])) {
				continue;
			}
			$blob .= "\n<header2>" . $typeMap[$slot] . "<end>\n";
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
}
