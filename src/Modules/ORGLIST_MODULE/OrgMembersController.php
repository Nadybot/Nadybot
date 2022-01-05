<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandReply,
	DB,
	DBSchema\Player,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Text,
};

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "orgmembers",
		accessLevel: "guild",
		description: "Show guild members sorted by name",
		help: "orgmembers.txt"
	)
]
class OrgMembersController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\HandlesCommand("orgmembers")]
	public function orgmembers2Command(CmdContext $context, int $orgId): void {
		$context->reply("Getting org info...");

		$this->guildManager->getByIdAsync($orgId, null, false, [$this, "showOrglist"], $orgId, $context);
	}

	public function showOrglist(?Guild $org, int $guildId, CommandReply $sendto): void {
		if ($org === null) {
			$msg = "Error in getting the org info. Either org does not exist or AO's server was too slow to respond.";
			$sendto->reply($msg);
			return;
		}
		/** @var Collection<Player> */
		$players = $this->db->table("players")
			->where("guild_id", $guildId)
			->where("dimension", $this->db->getDim())
			->orderBy("name")
			->asObj(Player::class);
		$numrows = $players->count();

		$blob = '';

		$currentLetter = '';
		foreach ($players as $player) {
			if ($currentLetter !== $player->name[0]) {
				$currentLetter = $player->name[0];
				$blob .= "\n\n<pagebreak><header2>$currentLetter<end>\n";
			}

			$blob .= "<tab><highlight>{$player->name}<end> ({$player->level}";
			if ($player->ai_level > 0) {
				$blob .= "/<green>{$player->ai_level}<end>";
			}
			$blob .= ", {$player->gender} {$player->breed} <highlight>{$player->profession}<end>, {$player->guild_rank})\n";
		}

		$msg = $this->text->makeBlob("Org members for '$org->orgname' ($numrows)", $blob);
		$sendto->reply($msg);
	}
}
