<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Exception;
use Nadybot\Core\{
	AccessManager,
	CommandManager,
	CommandReply,
	DB,
	HelpManager,
	SettingHandler,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\DBSchema\Setting;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'settings',
 *		accessLevel = 'mod',
 *		description = 'Change settings on the bot',
 *		help        = 'settings.txt',
 *		defaultStatus = '1'
 *	)
 */
class SettingsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public AccessManager $accessManager;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->settingManager->upload();
	}

	/**
	 * @HandlesCommand("settings")
	 * @Matches("/^settings$/i")
	 */
	public function settingsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = "Changing any of these settings will take effect immediately. Please note that some of these settings are read-only and cannot be changed.\n\n";
		/** @var Setting[] $data */
		$data = $this->db->table(SettingManager::DB_TABLE)
			->orderBy("module")
			->asObj(Setting::class)
			->toArray();
		$currentModule = '';
		foreach ($data as $row) {
			if ($row->module !== $currentModule) {
				$blob .= "\n<pagebreak><header2>".str_replace("_", " ", $row->module)."<end>\n";
				$currentModule = $row->module;
			}
			$blob .= "<tab>" . $row->description;

			if ($row->mode === "edit") {
				$editLink = $this->text->makeChatcmd('Modify', "/tell <myname> settings change {$row->name}");
				$blob .= " ($editLink)";
			}

			$settingHandler = $this->settingManager->getSettingHandler($row);
			if ($settingHandler instanceof SettingHandler) {
				$blob .= ": " . $settingHandler->displayValue($sender);
			}
			$blob .= "\n";
		}

		$msg = $this->text->makeBlob("Bot Settings", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("settings")
	 * @Matches("/^settings change ([a-z0-9_]+)$/i")
	 */
	public function changeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$settingName = strtolower($args[1]);
		/** @var Setting $row */
		$row = $this->db->table(SettingManager::DB_TABLE)
			->where("name", $settingName)
			->asObj(Setting::class)
			->first();
		if ($row === null) {
			$msg = "Could not find setting <highlight>{$settingName}<end>.";
			$sendto->reply($msg);
			return;
		}
		$settingHandler = $this->settingManager->getSettingHandler($row);
		$blob = "<header2>Basic Info<end>\n";
		$blob .= "<tab>Name: <highlight>{$row->name}<end>\n";
		$blob .= "<tab>Module: <highlight>{$row->module}<end>\n";
		$blob .= "<tab>Description: <highlight>{$row->description}<end>\n";
		$blob .= "<tab>Current Value: " . $settingHandler->displayValue($sender) . "\n\n";
		$blob .= $settingHandler->getDescription();
		$blob .= $settingHandler->getOptions();

		// show help topic if there is one
		$help = $this->helpManager->find($settingName, $sender);
		if ($help !== null) {
			$blob .= "\n\n<header2>Help ($settingName)<end>\n\n" . $help;
		}

		$msg = $this->text->makeBlob("Settings Info for {$settingName}", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("settings")
	 * @Matches("/^settings save ([a-z0-9_]+) (.+)$/i")
	 */
	public function saveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = strtolower($args[1]);
		$newValue = $args[2];
		/** @var ?Setting */
		$setting = $this->db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
		if ($setting === null) {
			$msg = "Could not find setting <highlight>{$name}<end>.";
			$sendto->reply($msg);
			return;
		}
		if (!$this->accessManager->checkAccess($sender, $setting->admin)) {
			$msg = "You don't have the necessary rights to change this setting.";
			$sendto->reply($msg);
			return;
		}
		$settingHandler = $this->settingManager->getSettingHandler($setting);
		try {
			$newValueToSave = $settingHandler->save($newValue);
			if ($this->settingManager->save($name, $newValueToSave)) {
				$settingHandler->getData()->value = $newValueToSave;
				$dispValue = $settingHandler->displayValue($sender);
				$savedValue = "<highlight>" . htmlspecialchars($newValue) . "<end>";
				if ($savedValue !== $dispValue) {
					$msg = "Setting <highlight>{$name}<end> has been saved with new value {$dispValue} (<highlight>".htmlspecialchars($newValue)."<end>).";
				} else {
					$msg = "Setting <highlight>{$name}<end> has been saved with new value {$dispValue}.";
				}
			} else {
				$msg = "Error! Setting <highlight>$name<end> could not be saved.";
			}
		} catch (Exception $e) {
			$msg = $e->getMessage();
		}
		$sendto->reply($msg);
	}
}
