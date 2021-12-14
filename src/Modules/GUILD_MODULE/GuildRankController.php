<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Modules\ORGLIST_MODULE\OrglistController;

/**
 * @author Nadyita (RK5)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "ranks",
		accessLevel: "all",
		description: "Show a list of all available org ranks",
		help: "ranks.txt"
	),
	NCA\DefineCommand(
		command: "maprank",
		accessLevel: "admin",
		description: "Define how org ranks map to bot ranks",
		help: "maprank.txt"
	)
]
class GuildRankController {

	public const DB_TABLE = "org_rank_mapping_<myname>";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public GuildController $guildController;

	#[NCA\Inject]
	public OrglistController $orglistController;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/RankMapping");

		$this->settingManager->add(
			module: $this->moduleName,
			name: "map_org_ranks_to_bot_ranks",
			description: "Map org ranks to bot ranks",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
	}

	/**
	 * Get a list of all defined rank mappings
	 * @return OrgRankMapping[]
	 */
	public function getMappings(): array {
		return $this->db->table(self::DB_TABLE)
			->orderBy("min_rank")
			->asObj(OrgRankMapping::class)
			->toArray();
	}

	public function getEffectiveAccessLevel(int $rank): string {
		/** @var ?OrgRankMapping */
		$rank = $this->db->table(self::DB_TABLE)
			->where("min_rank", ">=", $rank)
			->orderBy("min_rank")
			->limit(1)
			->asObj(OrgRankMapping::class)
			->first();
		return $rank ? $rank->access_level : "guild";
	}

	#[NCA\HandlesCommand("maprank")]
	public function maprankListCommand(CmdContext $context): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply("The bot must be in an org.");
			return;
		}
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "displayRankMappings"],
			$context
		);
	}

	public function displayRankMappings(?Guild $guild, CmdContext $context): void {
		if (!isset($guild)) {
			$context->reply("Unable to find information about organization. Maybe PORK is down.");
			return;
		}
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
			$context->reply("There are currently no org rank to bot rank mappings defined.");
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
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("maprank")]
	public function maprankCommand(CmdContext $context, int $rank, #[NCA\Str("to")] ?string $to, PWord $accessLevel): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply("The bot must be in an org.");
			return;
		}
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "setRankMapping"],
			$rank,
			$accessLevel(),
			$context->char->name,
			$context
		);
	}

	public function setRankMapping(?Guild $guild, int $rank, string $accessLevel, string $sender, CommandReply $sendto): void {
		if (!isset($guild)) {
			$sendto->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $this->orglistController->getOrgRanks($guild->governing_form);
		$accessLevels = $this->accessManager->getAccessLevels();
		try {
			$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		} catch (Exception $e) {
			// Catch system error about invalid access level
		}
		if (!isset($accessLevels[$accessLevel])) {
			$sendto->reply(
				"<highlight>{$accessLevel}<end> is not a valid access level. ".
				"Please use the short form like 'admin', 'mod' or 'rl'."
			);
			return;
		}
		$senderAL = $this->accessManager->getAccessLevelForCharacter($sender);
		$senderHasHigherAL = $this->accessManager->compareAccessLevels($senderAL, $accessLevel) > 0;
		if ($senderAL !== "superadmin" && !$senderHasHigherAL) {
			$sendto->reply("You can only manage access levels below your own.");
			return;
		}
		if (!isset($ranks[$rank])) {
			$sendto->reply("{$guild->governing_form} doesn't have a rank #{$rank}.");
			return;
		}
		$currentEAL = $this->getEffectiveAccessLevel($rank);
		if ($this->accessManager->compareAccessLevels($accessLevel, $currentEAL) < 0) {
			$sendto->reply("You cannot assign declining access levels.");
			return;
		}
		$alName = $this->accessManager->getDisplayName($accessLevel);
		$rankName = $ranks[$rank];

		$rankMapping = new OrgRankMapping();
		$rankMapping->access_level = $accessLevel;
		$rankMapping->min_rank = $rank;
		/** @var ?OrgRankMapping */
		$alEntry = $this->db->table(self::DB_TABLE)
			->where("access_level", $rankMapping->access_level)
			->asObj(OrgRankMapping::class)
			->first();
		/** @var ?OrgRankMapping */
		$rankEntry = $this->db->table(self::DB_TABLE)
			->where("min_rank", $rankMapping->min_rank)
			->asObj(OrgRankMapping::class)
			->first();
		if (isset($alEntry) && isset($rankEntry)) {
			$sendto->reply("You have already assigned rank mapping for both {$alName} and {$rankName}.");
			return;
		}
		if (isset($alEntry)) {
			$this->db->update(self::DB_TABLE, "access_level", $rankMapping);
		} elseif (isset($rankEntry)) {
			$this->db->update(self::DB_TABLE, "min_rank", $rankMapping);
		} else {
			$this->db->insert(self::DB_TABLE, $rankMapping, null);
		}
		$sendto->reply("Every <highlight>{$rankName}<end> or higher will now be mapped to <highlight>{$alName}<end>.");
	}

	#[NCA\HandlesCommand("maprank")]
	public function maprankDelCommand(CmdContext $context, PRemove $action, int $rankId): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply("The bot must be in an org.");
			return;
		}
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "delRankMapping"],
			$rankId,
			$context->char->name,
			$context
		);
	}

	public function delRankMapping(?Guild $guild, int $rank, string $sender, CmdContext $context): void {
		if (!isset($guild)) {
			$context->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $this->orglistController->getOrgRanks($guild->governing_form);
		if (!isset($ranks[$rank])) {
			$context->reply("{$guild->governing_form} doesn't have a rank #{$rank}.");
			return;
		}
		/** @var ?OrgRankMapping */
		$oldEntry = $this->db->table(self::DB_TABLE)
			->where("min_rank", $rank)
			->asObj(OrgRankMapping::class)
			->first();
		if (!isset($oldEntry)) {
			$context->reply("You haven't defined any access level for <highlight>{$ranks[$rank]}<end>.");
			return;
		}
		$senderAL = $this->accessManager->getAccessLevelForCharacter($sender);
		$senderHasHigherAL = $this->accessManager->compareAccessLevels($senderAL, $oldEntry->access_level) > 0;
		if ($senderAL !== "superadmin" && !$senderHasHigherAL) {
			$context->reply("You can only manage access levels below your own.");
			return;
		}
		$this->db->table(self::DB_TABLE)
			->where("min_rank", $rank)
			->delete();
		$context->reply(
			"The access level mapping <highlight>{$ranks[$rank]}<end> to ".
			"<highlight>" . $this->accessManager->getDisplayName($oldEntry->access_level) . "<end> ".
			"was deleted successfully."
		);
	}

	#[NCA\HandlesCommand("ranks")]
	public function ranksCommand(CmdContext $context): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply("The bot must be in an org.");
			return;
		}
		$this->guildManager->getByIdAsync(
			$this->chatBot->vars["my_guild_id"],
			null,
			false,
			[$this, "displayGuildRanks"],
			$context
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
		$msg = $this->text->makeBlob(
			"Ranks of {$guild->governing_form} (" . count($ranks) . ")",
			$blob,
			$guild->governing_form
		);
		$sendto->reply($msg);
	}
}
