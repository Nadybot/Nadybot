<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'orgmembers',
 *		accessLevel = 'guild',
 *		description = 'Show guild members sorted by name',
 *		help        = 'orgmembers.txt'
 *	)
 */
class OrgMembersController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public GuildManager $guildManager;
	
	/**
	 * @HandlesCommand("orgmembers")
	 * @Matches("/^orgmembers ([1-9]\d*)$/i")
	 */
	public function orgmembers2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$guildId = (int)$args[1];

		$msg = "Getting org info...";
		$sendto->reply($msg);

		$org = $this->guildManager->getById($guildId);
		if ($org === null) {
			$msg = "Error in getting the org info. Either org does not exist or AO's server was too slow to respond.";
			$sendto->reply($msg);
			return;
		}

		$sql = "SELECT * FROM players WHERE guild_id = ? AND dimension = '<dim>' ORDER BY name ASC";
		/** @var Player[] */
		$players = $this->db->fetchAll(Player::class, $sql, $guildId);
		$numrows = count($players);

		$blob = '';

		$currentLetter = '';
		foreach ($players as $player) {
			if ($currentLetter !== $player->name[0]) {
				$currentLetter = $player->name[0];
				$blob .= "\n\n<header2>$currentLetter<end>\n";
			}

			$blob .= "<tab><highlight>{$player->name}<end>, {$player->guild_rank} (Level {$player->level}";
			if ($player->ai_level > 0) {
				$blob .= "<green>/{$player->ai_level}<end>";
			}
			$blob .= ", {$player->gender} {$player->breed} {$player->profession})\n";
		}

		$msg = $this->text->makeBlob("Org members for '$org->orgname' ($numrows)", $blob);
		$sendto->reply($msg);
	}
}
