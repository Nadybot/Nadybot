<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBRow;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * Bossloot Module Ver 1.1
 * Written By Jaqueme
 * For Budabot
 * Database Adapted From One Originally Compiled by Malosar For BeBot
 * Boss Drop Table Database Module
 * Written 5/11/07
 * Last Modified 5/14/07
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'boss',
 *		accessLevel = 'all',
 *		description = 'Shows bosses and their loot',
 *		help        = 'boss.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'bossloot',
 *		accessLevel = 'all',
 *		description = 'Finds which boss drops certain loot',
 *		help        = 'boss.txt'
 *	)
 */
class BosslootController {

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
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "boss_namedb");
		$this->db->loadSQLFile($this->moduleName, "boss_lootdb");
	}

	/**
	 * This command handler shows bosses and their loot.
	 *
	 * @HandlesCommand("boss")
	 * @Matches("/^boss (.+)$/i")
	 */
	public function bossCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = strtolower($args[1]);
		
		[$query, $params] = $this->util->generateQueryFromParams(explode(' ', $search), 'bossname');

		$bosses = $this->db->query(
			"SELECT bossid, bossname, w.answer ".
			"FROM boss_namedb b ".
			"LEFT JOIN whereis w ON b.bossname = w.name ".
			"WHERE $query",
			...$params
		);
		$count = count($bosses);

		if ($count === 0) {
			$output = "There were no matches for your search.";
			$sendto->reply($output);
			return;
		}
		if ($count > 1) {
			$blob = "Results of Search for '$search'\n\n";
			//If multiple matches found output list of bosses
			foreach ($bosses as $row) {
				$blob .= $this->getBossLootOutput($row);
			}
			$output = $this->text->makeBlob("Boss Search Results ($count)", $blob);
			$sendto->reply($output);
			return;
		}
		//If single match found, output full loot table
		$row = $bosses[0];

		$blob  = "Location: <highlight>{$row->answer}<end>\n\n";
		$blob .= "Loot:\n\n";

		/** @var AODBEntry[] */
		$data = $this->db->fetchAll(
			AODBEntry::class,
			"SELECT * FROM boss_lootdb b ".
			"LEFT JOIN aodb a ON (b.aoid=a.lowid OR (b.aoid IS NULL AND b.itemname = a.name)) ".
			"WHERE b.bossid = ?",
			$row->bossid
		);
		foreach ($data as $row2) {
			if (!isset($row2->icon)) {
				$this->logger->log('ERROR', "Missing item in AODB: {$row2->itemname}.");
				continue;
			}
			$blob .= $this->text->makeImage($row2->icon) . "\n";
			$blob .= $this->text->makeItem($row2->lowid, $row2->highid, $row2->highql, $row2->itemname) . "\n\n";
		}
		$output = $this->text->makeBlob($row->bossname, $blob);
		$sendto->reply($output);
	}

	/**
	 * This command handler finds which boss drops certain loot.
	 *
	 * @HandlesCommand("bossloot")
	 * @Matches("/^bossloot (.+)$/i")
	 */
	public function bosslootCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = strtolower($args[1]);

		$blob = "Bosses that drop items matching '$search':\n\n";

		[$query, $params] = $this->util->generateQueryFromParams(explode(' ', $search), 'b1.itemname');

		$loot = $this->db->query(
			"SELECT DISTINCT b2.bossid, b2.bossname, w.answer ".
			"FROM boss_lootdb b1 JOIN boss_namedb b2 ON b2.bossid = b1.bossid ".
			"LEFT JOIN whereis w ON w.name = b2.bossname ".
			"WHERE $query",
			...$params
		);
		$count = count($loot);

		$output = "There were no matches for your search.";
		if ($count !== 0) {
			foreach ($loot as $row) {
				$blob .= $this->getBossLootOutput($row);
			}
			$output = $this->text->makeBlob("Bossloot Search Results ($count)", $blob);
		}
		$sendto->reply($output);
	}

	public function getBossLootOutput(DBRow $row): string {
		$data = $this->db->query(
			"SELECT * FROM boss_lootdb b ".
			"LEFT JOIN aodb a ON (b.itemname = a.name) ".
			"WHERE b.bossid = ?",
			$row->bossid
		);
			
		$blob = '<pagebreak>' . $this->text->makeChatcmd($row->bossname, "/tell <myname> boss $row->bossname") . "\n";
		$blob .= "Location: <highlight>{$row->answer}<end>\n";
		$blob .= "Loot: ";
		foreach ($data as $row2) {
			$blob .= $this->text->makeItem($row2->lowid, $row2->highid, $row2->highql, $row2->itemname) . ', ';
		}
		$blob .= "\n\n";
		return $blob;
	}
}
