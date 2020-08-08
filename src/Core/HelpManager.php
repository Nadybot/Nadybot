<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;

use Nadybot\Core\Modules\CONFIG\ConfigController;
use Nadybot\Core\DBSchema\HelpTopic;

/**
 * @Instance
 */
class HelpManager {

	/** @Inject */
	public DB $db;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public ConfigController $configController;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @name: register
	 * @description: Registers a help command
	 */
	public function register(string $module, string $command, string $filename, string $admin, string $description): void {
		$this->logger->log('DEBUG', "Registering $module:help($command) Helpfile:($filename)");

		$command = strtolower($command);

		// Check if the file exists
		$actual_filename = $this->util->verifyFilename($module . '/' . $filename);
		if ($actual_filename == '') {
			$this->logger->log('ERROR', "Error in registering the File $filename for Help command $module:help($command). The file doesn't exist!");
			return;
		}

		if (isset($this->chatBot->existing_helps[$command])) {
			$sql = "UPDATE hlpcfg_<myname> SET `verify` = 1, `file` = ?, `module` = ?, `description` = ? WHERE `name` = ?";
			$this->db->exec($sql, $actual_filename, $module, $description, $command);
		} else {
			$sql = "INSERT INTO hlpcfg_<myname> (`name`, `module`, `file`, `description`, `admin`, `verify`) VALUES (?, ?, ?, ?, ?, ?)";
			$this->db->exec($sql, $command, $module, $actual_filename, $description, $admin, '1');
		}
	}

	/**
	 * Find a help topic by name if it exists and if the user has permissions to see it
	 */
	public function find(string $helpcmd, string $char): ?string {
		$helpcmd = strtolower($helpcmd);

		$sql = "
			SELECT module, file, name, GROUP_CONCAT(admin) AS admin_list FROM
				(SELECT module, admin, cmd AS name, help AS file FROM cmdcfg_<myname> WHERE cmdevent = 'cmd' AND cmd = ?  AND status = 1 AND help != ''
				UNION
				SELECT module, admin, name, help AS file FROM settings_<myname> WHERE name = ? AND help != ''
				UNION
				SELECT module, admin, name, file FROM hlpcfg_<myname> WHERE name = ? AND file != '') t
			GROUP BY module, file";
		/** @var HelpTopic[] $data */
		$data = $this->db->fetchAll(HelpTopic::class, $sql, $helpcmd, $helpcmd, $helpcmd);

		if (count($data) === 0) {
			$helpcmd = strtoupper($helpcmd);
			$sql = "
				SELECT module, file, name, GROUP_CONCAT(admin) AS admin_list FROM
					(SELECT module, admin, cmd AS name, help AS file FROM cmdcfg_<myname> WHERE cmdevent = 'cmd' AND module = ? AND status = 1 AND help != ''
					UNION
					SELECT module, admin, name, help AS file FROM settings_<myname> WHERE module = ? AND help != ''
					UNION
					SELECT module, admin, name, file FROM hlpcfg_<myname> WHERE module = ? AND file != '') t
			GROUP BY module, file";
			/** @var HelpTopic[] $data */
			$data = $this->db->fetchAll(HelpTopic::class, $sql, $helpcmd, $helpcmd, $helpcmd);
		}

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($char);

		$output = '';
		foreach ($data as $row) {
			if ($this->checkAccessLevels($accessLevel, explode(",", $row->admin_list))) {
				$output .= $this->configController->getAliasInfo($row->name);
				$output .= trim(file_get_contents($row->file)) . "\n\n";
			}
		}

		return empty($output) ? null : $output;
	}

	public function update(string $helpTopic, string $admin): void {
		$helpTopic = strtolower($helpTopic);
		$admin = strtolower($admin);

		$this->db->exec("UPDATE hlpcfg_<myname> SET `admin` = ? WHERE `name` = ?", $admin, $helpTopic);
	}

	public function checkForHelpFile(string $module, string $file): string {
		$actualFilename = $this->util->verifyFilename($module . DIRECTORY_SEPARATOR . $file);
		if ($actualFilename == '') {
			$this->logger->log('WARN', "Error in registering the help file {$module}/{$file}. The file doesn't exist!");
		}
		return $actualFilename;
	}

	/**
	 * Return all help topics a character has access to
	 *
	 * @param string $char Name of the char
	 * @return HelpTopic[] Help topics
	 * @throws \Exception
	 */
	public function getAllHelpTopics($char): array {
		$sql = "
			SELECT module, file, name, description, sort, GROUP_CONCAT(admin) AS admin_list FROM (
				SELECT module, admin, help AS file, name, description, 3 AS sort FROM settings_<myname> WHERE help != ''
				UNION
				SELECT module, admin, help AS file, cmd AS name, description, 2 AS sort FROM cmdcfg_<myname> WHERE `cmdevent` = 'cmd' AND status = 1 AND help != ''
				UNION
				SELECT module, admin, file, name, description, 1 AS sort FROM hlpcfg_<myname>) t
			GROUP BY module, file, name, description, sort
			ORDER BY module, name, sort DESC, description";
		/** @var HelpTopic[] $data */
		$data = $this->db->fetchAll(HelpTopic::class, $sql);

		if ($char !== null) {
			$accessLevel = $this->accessManager->getAccessLevelForCharacter($char);
		}

		$topics = [];
		foreach ($data as $row) {
			if ($char === null || $this->checkAccessLevels($accessLevel, explode(",", $row->admin_list))) {
				$obj = new HelpTopic();
				$obj->module = $row->module;
				$obj->name = $row->name;
				$obj->description = $row->description;
				$topics []= $obj;
			}
		}

		return $topics;
	}

	public function checkAccessLevels(string $accessLevel1, array $accessLevelsArray): bool {
		foreach ($accessLevelsArray as $accessLevel2) {
			if ($this->accessManager->compareAccessLevels($accessLevel1, $accessLevel2) >= 0) {
				return true;
			}
		}
		return false;
	}
}
