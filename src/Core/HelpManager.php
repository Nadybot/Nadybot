<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Modules\CONFIG\ConfigController;
use Nadybot\Core\DBSchema\HelpTopic;

#[NCA\Instance]
class HelpManager {
	public const DB_TABLE = "hlpcfg_<myname>";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public AccessManager $accessManager;
	#
	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public ConfigController $configController;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * @name: register
	 * @description: Registers a help command
	 */
	public function register(string $module, string $command, string $filename, string $admin, string $description): void {
		$this->logger->info("Registering $module:help($command) Helpfile:($filename)");

		$command = strtolower($command);

		// Check if the file exists
		$actual_filename = $this->util->verifyFilename($module . '/' . $filename);
		if ($actual_filename == '') {
			$this->logger->error("Error in registering the File $filename for Help command $module:help($command). The file doesn't exist!");
			return;
		}

		if (isset($this->chatBot->existing_helps[$command])) {
			$this->db->table(self::DB_TABLE)->where("name", $command)
				->update([
					"verify" => 1,
					"file" => $actual_filename,
					"module" => $module,
					"description" => $description,
				]);
		} else {
			$this->db->table(self::DB_TABLE)
				->insert([
					"name" => $command,
					"admin" => $admin,
					"verify" => 1,
					"file" => $actual_filename,
					"module" => $module,
					"description" => $description,
				]);
		}
	}

	/**
	 * Find a help topic by name if it exists and if the user has permissions to see it
	 */
	public function find(string $helpcmd, string $char): ?string {
		$helpcmd = strtolower($helpcmd);
		$settingsHelp = $this->db->table(SettingManager::DB_TABLE)
			->where("name", $helpcmd)
			->where("help", "!=", '')
			->select("module", "admin", "name", "help AS file");
		$hlpHelp = $this->db->table(self::DB_TABLE)
			->where("name", $helpcmd)
			->where("file", "!=", '')
			->select("module", "admin", "name", "file");
		$outerQuery = $this->db->fromSub(
			$settingsHelp->union($hlpHelp),
			"foo"
		)->select("foo.module", "foo.file", "foo.name", "foo.admin AS admin_list");
		/** @var HelpTopic[] $data */
		$data = $outerQuery->asObj(HelpTopic::class)->toArray();

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($char);

		$output = '';
		$shown = [];
		foreach ($data as $row) {
			if (!isset($row->file) || isset($shown[$row->file])) {
				continue;
			}
			if ($this->checkAccessLevels($accessLevel, explode(",", $row->admin_list))) {
				$output .= $this->configController->getAliasInfo($row->name);
				$content = \Safe\file_get_contents($row->file);
				if (is_string($content)) {
					$output .= trim($content) . "\n\n";
				}
				$shown[$row->file] = true;
			}
		}

		return empty($output) ? null : $output;
	}

	public function update(string $helpTopic, string $admin): void {
		$helpTopic = strtolower($helpTopic);
		$admin = strtolower($admin);

		$this->db->table(self::DB_TABLE)
			->where("name", $helpTopic)
			->update(["admin" => $admin]);
	}

	public function checkForHelpFile(string $module, string $file): string {
		$actualFilename = $this->util->verifyFilename($module . DIRECTORY_SEPARATOR . $file);
		if ($actualFilename == '') {
			$this->logger->warning("Error in registering the help file {$module}/{$file}. The file doesn't exist!");
		}
		return $actualFilename;
	}

	/**
	 * Return all help topics a character has access to
	 *
	 * @return HelpTopic[] Help topics
	 */
	public function getAllHelpTopics(?CmdContext $context): array {
		$cmdHelp = $this->db->table(CommandManager::DB_TABLE, "c")
			->join(CommandManager::DB_TABLE_PERMS . " as p", "c.cmd", "p.cmd")
			->where("c.cmdevent", "cmd")
			->where("p.enabled", true)
			->select("c.module", "p.access_level as admin", "c.cmd AS name");
		$cmdHelp->selectRaw("NULL" . $cmdHelp->as("file"));
		$cmdHelp->addSelect("description");
		$cmdHelp->selectRaw("2" . $cmdHelp->as("sort"));
		$settingsHelp = $this->db->table(SettingManager::DB_TABLE)
			->where("help", "!=", '')
			->select("module", "admin", "name", "help AS file", "description");
		$settingsHelp->selectRaw("3" . $settingsHelp->as("sort"));
		$hlpHelp = $this->db->table(self::DB_TABLE)
			->select("module", "admin", "name", "file", "description");
		$hlpHelp->selectRaw("1" . $settingsHelp->as("sort"));
		$outerQuery = $this->db->fromSub(
			$cmdHelp->union($settingsHelp)->union($hlpHelp),
			"foo"
		)->select("foo.module", "foo.file", "foo.name", "foo.description", "foo.admin AS admin_list", "foo.sort")
		->orderBy("module")
		->orderBy("name")
		->orderByDesc("sort")
		->orderBy("description");
		/** @var HelpTopic[] $data */
		$data = $outerQuery->asObj(HelpTopic::class)->toArray();

		if (isset($context)) {
			$accessLevel = $this->accessManager->getAccessLevelForCharacter($context->char->name);
		}

		$topics = [];
		$added = [];
		foreach ($data as $row) {
			$key = $row->module.$row->name.$row->description;
			if (isset($added[$key])) {
				continue;
			}
			if (!isset($context) || $this->checkAccessLevels($accessLevel??"all", explode(",", $row->admin_list))) {
				$obj = new HelpTopic();
				$obj->module = $row->module;
				$obj->name = $row->name;
				$obj->description = $row->description;
				$topics []= $obj;
				$added[$key] = true;
			}
		}

		return $topics;
	}

	/**
	 * @param string[] $accessLevelsArray
	 */
	public function checkAccessLevels(string $accessLevel1, array $accessLevelsArray): bool {
		foreach ($accessLevelsArray as $accessLevel2) {
			if ($this->accessManager->compareAccessLevels($accessLevel1, $accessLevel2) >= 0) {
				return true;
			}
		}
		return false;
	}
}
