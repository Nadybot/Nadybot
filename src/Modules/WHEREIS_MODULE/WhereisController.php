<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Jaqueme
 *  Database adapted from one originally compiled by Malosar for BeBot
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whereis',
 *		accessLevel = 'all',
 *		description = 'Shows where places and NPCs are',
 *		help        = 'whereis.txt'
 *	)
 */
class WhereisController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public DB $db;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'whereis');
	}

	/**
	 * @HandlesCommand("whereis")
	 * @Matches("/^whereis (.+)$/i")
	 */
	public function whereisCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = strtolower($args[1]);
		$words = explode(' ', $search);
		[$query1, $params1] = $this->util->generateQueryFromParams($words, 'name');
		[$query2, $params2] = $this->util->generateQueryFromParams($words, 'keywords');
		
		$sql = "SELECT * FROM whereis w ".
			"LEFT JOIN playfields p ON w.playfield_id = p.id ".
			"WHERE ($query1) OR ($query2)";
			/** @var WhereisResult[] */
		$npcs = $this->db->fetchAll(
			WhereisResult::class,
			$sql,
			...[...$params1, ...$params2]
		);
		$count = count($npcs);

		if ($count === 0) {
			$msg = "There were no matches for your search.";
			$sendto->reply($msg);
			return;
		}
		$blob = "";
		foreach ($npcs as $npc) {
			$blob .= "<pagebreak><header2>{$npc->name}<end>\n".
				"<tab>{$npc->answer}";
			if ($npc->playfield_id !== 0 && $npc->xcoord !== 0 && $npc->ycoord !== 0) {
				$blob .= " " . $this->text->makeChatcmd("waypoint: {$npc->xcoord}x{$npc->ycoord} {$npc->short_name}", "/waypoint {$npc->xcoord} {$npc->ycoord} {$npc->playfield_id}");
			}
			$blob .= "\n\n";
		}

		$msg = $this->text->makeBlob("Found $count matches for \"$search\".", $blob);
		$sendto->reply($msg);
	}
}
