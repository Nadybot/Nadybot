<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	SQLException,
	Text,
	Util,
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
		$ranks = $this->db->fetchAll(
			OrgRankMapping::class,
			"SELECT * FROM `org_rank_mapping_<myname>` ORDER BY `min_rank` DESC"
		);
		return $ranks;
	}

	/**
	 * @HandlesCommand("maprank")
	 * @Matches("/^maprank$/i")
	 */
	public function maprankCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$maps = $this->getMappings();
		if (!count($maps)) {
			$sendto->reply("There are currently no org rank to bot rank mappings defined.");
			return;
		}
		$blob = "";
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
