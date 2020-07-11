<?php

namespace Budabot\Modules\ORGLIST_MODULE;

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
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager $playerManager
	 * @Inject
	 */
	public $playerManager;
	
	/**
	 * @var \Budabot\Core\Modules\PLAYER_LOOKUP\GuildManager $guildManager
	 * @Inject
	 */
	public $guildManager;
	
	/**
	 * @HandlesCommand("orgmembers")
	 * @Matches("/^orgmembers (\d+)$/i")
	 */
	public function orgmembers2Command($message, $channel, $sender, $sendto, $args) {
		$guild_id = $args[1];

		$msg = "Getting org info...";
		$sendto->reply($msg);

		$org = $this->guildManager->getById($guild_id);
		if ($org === null) {
			$msg = "Error in getting the org info. Either org does not exist or AO's server was too slow to respond.";
			$sendto->reply($msg);
			return;
		}

		$sql = "SELECT * FROM players WHERE guild_id = ? AND dimension = '<dim>' ORDER BY name ASC";
		$data = $this->db->query($sql, $guild_id);
		$numrows = count($data);

		$blob = '';

		$currentLetter = '';
		foreach ($data as $row) {
			if ($currentLetter != $row->name[0]) {
				$currentLetter = $row->name[0];
				$blob .= "\n\n<header2>$currentLetter<end>\n";
			}

			$blob .= "<tab><highlight>{$row->name}, {$row->guild_rank} (Level {$row->level}";
			if ($row->ai_level > 0) {
				$blob .= "<green>/{$row->ai_level}<end>";
			}
			$blob .= ", {$row->gender} {$row->breed} {$row->profession})<end>\n";
		}

		$msg = $this->text->makeBlob("Org members for '$org->orgname' ($numrows)", $blob);
		$sendto->reply($msg);
	}
}
