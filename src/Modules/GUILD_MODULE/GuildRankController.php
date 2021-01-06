<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\{
	AccessManager,
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Modules\ORGLIST_MODULE\OrglistController;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = "ranks",
 *		accessLevel = "all",
 *		description = "Show a list of all available org ranks",
 *		help        = "ranks.txt"
 *	)
 *	@DefineCommand(
 *		command     = "maprank",
 *		accessLevel = "admin",
 *		description = "Define how org ranks map to bot ranks",
 *		help        = "maprank.txt"
 *	)
 */
class GuildRankController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public GuildManager $guildManager;

	/** @Inject */
	public GuildController $guildController;

	/** @Inject */
	public OrglistController $orglistController;

	/** @Inject */
	public Text $text;

	/**
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "org_rank_mapping");

		$this->settingManager->add(
			$this->moduleName,
			"map_org_ranks_to_bot_ranks",
			"Map org ranks to bot ranks",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
	}

	/**
	 * Get a list of all defined rank mappings
	 * @return OrgRankMapping[]
	 */
	public function getMappings(): array {
		/** @var OrgRankMapping[] */
		$ranks = $this->db->fetchAll(
			OrgRankMapping::class,
			"SELECT * FROM `org_rank_mapping_<myname>` ORDER BY `min_rank` ASC"
		);
		return $ranks;
	}

	public function getEffectiveAccessLevel(int $rank): string {
		/** @var OrgRankMapping */
		$rank = $this->db->fetch(
			OrgRankMapping::class,
			"SELECT * FROM `org_rank_mapping_<myname>` WHERE `min_rank` >= ? ORDER BY `min_rank` ASC",
			$rank
		);
		return $rank ? $rank->access_level : "guild";
	}

	/**
	 * @HandlesCommand("maprank")
	 * @Matches("/^maprank$/i")
	 */
	public function maprankListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "displayRankMappings"],
			$sendto
		);
	}

	public function displayRankMappings(?Guild $guild, CommandReply $sendto): void {
		$maps = $this->getMappings();
		$mapKeys = array_reduce(
			$maps,
			function(array $carry, OrgRankMapping $m): array {
				$carry[$m->min_rank] = true;
				return $carry;
			},
			[]
		);
		$ranks = $this->orglistController->getOrgRanks($guild->governing_form);
		if (!count($maps)) {
			$sendto->reply("There are currently no org rank to bot rank mappings defined.");
			return;
		}
		$blob = "<header2>Mapped ranks<end>\n";
		foreach ($ranks as $rank => $rankName) {
			$accessLevel = $this->getEffectiveAccessLevel($rank);
			$blob .= "<tab>".
				"{$rank} - {$rankName}: ".
				"<highlight>".
				$this->accessManager->getDisplayName($accessLevel).
				"<end>";
			if (isset($mapKeys[$rank])) {
				$blob .= " [" . $this->text->makeChatcmd("remove", "/tell <myname> maprank del {$rank}") . "]";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob("Defined mappings (" . count($maps) . ")", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("maprank")
	 * @Matches("/^maprank (\d+)(?: to)? (.+)$/i")
	 */
	public function maprankCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "setRankMapping"],
			(int)$args[1],
			$args[2],
			$sendto
		);
	}

	public function setRankMapping(?Guild $guild, int $rank, string $accessLevel, CommandReply $sendto): void {
		if (!isset($guild)) {
			$sendto->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $this->orglistController->getOrgRanks($guild->governing_form);
		$accessLevels = $this->accessManager->getAccessLevels();
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		if (!isset($accessLevels[$accessLevel])) {
			$sendto->reply("<highlight>{$accessLevel}<end> is not a valid access level. Please use the short form.");
			return;
		}
		if (!isset($ranks[$rank])) {
			$sendto->reply("{$guild->governing_form} doesn't have a rank #{$rank}.");
			return;
		}
		$alName = $this->accessManager->getDisplayName($accessLevel);
		$rankName = $ranks[$rank];

		$rankMapping = new OrgRankMapping();
		$rankMapping->access_level = $accessLevel;
		$rankMapping->min_rank = $rank;
		/** @var ?OrgRankMapping */
		$alEntry = $this->db->fetch(
			OrgRankMapping::class,
			"SELECT * FROM `org_rank_mapping_<myname>` WHERE `access_level` = ?",
			$rankMapping->access_level
		);
		/** @var ?OrgRankMapping */
		$rankEntry = $this->db->fetch(
			OrgRankMapping::class,
			"SELECT * FROM `org_rank_mapping_<myname>` WHERE `min_rank` = ?",
			$rankMapping->min_rank
		);
		if (isset($alEntry) && isset($rankEntry)) {
			$sendto->reply("You have already assigned rank mapping for both {$alName} and {$rankName}.");
			return;
		}
		if (isset($alEntry)) {
			$this->db->update("org_rank_mapping_<myname>", "access_level", $rankMapping);
		} elseif (isset($rankEntry)) {
			$this->db->update("org_rank_mapping_<myname>", "min_rank", $rankMapping);
		} else {
			$this->db->insert("org_rank_mapping_<myname>", $rankMapping);
		}
		$sendto->reply("Every <highlight>{$rankName}<end> or higher will now be mapped to <highlight>{$alName}<end>.");
	}

	/**
	 * @HandlesCommand("maprank")
	 * @Matches("/^maprank\s+(?:del|delete|rem|remove)\s+(\d+)$/i")
	 */
	public function maprankDelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "delRankMapping"],
			(int)$args[1],
			$sendto
		);
	}

	public function delRankMapping(?Guild $guild, int $rank, CommandReply $sendto): void {
		if (!isset($guild)) {
			$sendto->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $this->orglistController->getOrgRanks($guild->governing_form);
		if (!isset($ranks[$rank])) {
			$sendto->reply("{$guild->governing_form} doesn't have a rank #{$rank}.");
			return;
		}
		$deleted = $this->db->exec("DELETE FROM `org_rank_mapping_<myname>` WHERE `min_rank` = ?", $rank);
		if ($deleted === 0) {
			$sendto->reply("You haven't defined any access level for <highlight>{$ranks[$rank]}<end>.");
			return;
		}
		$sendto->reply("The accesslevel mapping for <highlight>{$ranks[$rank]}<end> was deleted successfully.");
	}

	/**
	 * @HandlesCommand("ranks")
	 * @Matches("/^ranks$/i")
	 */
	public function ranksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		if (!$this->guildController->isGuildBot()) {
			$sendto->reply("The bot must be in an org.");
			return;
		}
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "displayGuildRanks"],
			$sendto
		);
	}

	public function displayGuildRanks(?Guild $guild, CommandReply $sendto): void {
		if (!isset($guild)) {
			$sendto->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $this->orglistController->getOrgRanks($guild->governing_form);
		$blob = "<header2>Org ranks of {$guild->governing_form}<end>\n";
		foreach ($ranks as $id => $name) {
			$blob .= "<tab>{$id}: <highlight>{$name}<end>\n";
		}
		$msg = $this->text->makeBlob($guild->governing_form, $blob);
		$sendto->reply($msg);
	}
}
