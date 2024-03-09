<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	DBSchema\Player,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\GuildManager,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "orgmembers",
		accessLevel: "member",
		description: "Show guild members sorted by name",
	)
]
class OrgMembersController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private GuildManager $guildManager;

	/** Show the members of an organization, sorted by name */
	#[NCA\HandlesCommand("orgmembers")]
	public function orgmembers2Command(CmdContext $context, int $orgId): void {
		$context->reply("Getting org info...");

		$org = $this->guildManager->byId($orgId);
		if ($org === null) {
			$msg = "Error in getting the org info. Either org does not exist or AO's server was too slow to respond.";
			$context->reply($msg);
			return;
		}

		/** @var Collection<Player> */
		$players = $this->db->table("players")
			->where("guild_id", $orgId)
			->where("dimension", $this->db->getDim())
			->orderBy("name")
			->asObj(Player::class);
		$numrows = $players->count();

		$blob = '';

		$currentLetter = '';
		foreach ($players as $player) {
			if ($currentLetter !== $player->name[0]) {
				$currentLetter = $player->name[0];
				$blob .= "\n\n<pagebreak><header2>{$currentLetter}<end>\n";
			}

			$blob .= "<tab><highlight>{$player->name}<end> ({$player->level}";
			if ($player->ai_level > 0) {
				$blob .= "/<green>{$player->ai_level}<end>";
			}
			$blob .= ", {$player->gender} {$player->breed} <highlight>{$player->profession}<end>, {$player->guild_rank})\n";
		}

		$msg = $this->text->makeBlob("Org members for '{$org->orgname}' ({$numrows})", $blob);
		$context->reply($msg);
	}
}
