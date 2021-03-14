<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class PlayerLookupController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, 'players');
		if ($this->db->getType() === $this->db::MYSQL) {
			$this->db->exec("ALTER TABLE `players` CHANGE `prof_title` `prof_title` VARCHAR(40)");
		}
		$this->upgradeToUniqueNameDim();
	}

	/**
	 * Ensure that name and dimension are a unique tuple
	 * @return void
	 */
	protected function upgradeToUniqueNameDim() {
		if ($this->db->columnUnique("players", "name")) {
			return;
		}
		if ($this->db->getType() === $this->db::MYSQL) {
			$this->logger->log('INFO', "Upgrading schema for table players");
			$doublePlayers = $this->db->query(
				"SELECT `name`, `dimension`, COUNT(*) AS `amount` ".
				"FROM `players` ".
				"GROUP BY `name`, `dimension` ".
				"HAVING COUNT(*) > 1"
			);
			$this->logger->log('INFO', "* Deleting " . count($doublePlayers) . " duplicates");
			foreach ($doublePlayers as $duplicate) {
				$this->db->exec(
					"DELETE FROM `players` WHERE `name`=? AND `dimension`=? LIMIT ?",
					$duplicate->name,
					$duplicate->dimension,
					$duplicate->amount - 1
				);
			}
			$this->logger->log('INFO', "* Adding UNIQUE index");
			$this->db->exec(
				"ALTER TABLE `players` ".
				"ADD CONSTRAINT `UC_players_name_dim` UNIQUE (`name`, `dimension`)"
			);
			$this->logger->log('INFO', "* DONE");
		} elseif ($this->db->getType() === $this->db::SQLITE) {
			$this->logger->log('INFO', "Upgrading schema for table players");
			$this->logger->log('INFO', "* Deleting duplicates");
			$this->db->exec(
				"DELETE FROM `players` ".
				"WHERE rowid NOT IN ( ".
					"SELECT MIN(rowid) FROM `players` GROUP BY `name`, `dimension`".
				")"
			);
			$this->logger->log('INFO', "* Adding UNIQUE index");
			$this->db->exec(
				"CREATE UNIQUE INDEX `players_name_dim_idx` ".
				"ON `players`(`name`, `dimension`)"
			);
			$this->logger->log('INFO', "* DONE");
		}
	}
}
