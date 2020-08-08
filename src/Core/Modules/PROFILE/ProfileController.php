<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Nadybot\Core\{
	CommandManager,
	CommandReply,
	DB,
	LoggerWrapper,
	SettingManager,
	Text,
	Util,
};
use Exception;
use Nadybot\Core\DBSchema\{
	CmdAlias,
	CmdCfg,
	EventCfg,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'profile',
 *		accessLevel = 'admin',
 *		description = 'View, add, remove, and load profiles',
 *		help        = 'profile.txt',
 *		alias       = 'profiles'
 *	)
 */
class ProfileController {
	const FILE_EXT = ".txt";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public LoggerWrapper $logger;
	
	private string $path;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->path = getcwd() . "/data/profiles/";
		
		// make sure that the profile folder exists
		if (!@is_dir($this->path)) {
			mkdir($this->path, 0777);
		}
	}
	
	/**
	 * @HandlesCommand("profile")
	 * @Matches("/^profile$/i")
	 */
	public function profileListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (($handle = opendir($this->path)) === false) {
			$msg = "Could not open profiles directory.";
			$sendto->reply($msg);
		}
		$profileList = [];

		while (false !== ($fileName = readdir($handle))) {
			// if file has the correct extension, it's a profile file
			if ($this->util->endsWith($fileName, static::FILE_EXT)) {
				$profileList[] = str_replace(static::FILE_EXT, '', $fileName);
			}
		}

		closedir($handle);

		sort($profileList);

		$linkContents = '';
		foreach ($profileList as $profile) {
			$name = ucfirst(strtolower($profile));
			$viewLink = $this->text->makeChatcmd("View", "/tell <myname> profile view $profile");
			$loadLink = $this->text->makeChatcmd("Load", "/tell <myname> profile load $profile");
			$linkContents .= "$name [$viewLink] [$loadLink]\n";
		}

		if ($linkContents) {
			$linkContents .= "\n\n<orange>Warning: Running a profile script will change your configuration.  Proceed only if you understand the consequences.<end>";
			$msg = $this->text->makeBlob('Profiles (' . count($profileList) . ')', $linkContents);
		} else {
			$msg = "No profiles available.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("profile")
	 * @Matches("/^profile view ([a-z0-9_-]+)$/i")
	 */
	public function profileViewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profileName = $args[1];
		$filename = $this->getFilename($profileName);
		if (!@file_exists($filename)) {
			$msg = "Profile <highlight>$profileName<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		$blob = htmlspecialchars(file_get_contents($filename));
		$blob = preg_replace("/^([^#])/m", "<tab>$1", $blob);
		$blob = preg_replace("/^# (.+)$/m", "<header2>$1<end>", $blob);
		$msg = $this->text->makeBlob("Profile $profileName", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("profile")
	 * @Matches("/^profile save ([a-z0-9_-]+)$/i")
	 */
	public function profileSaveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profileName = $args[1];
		$filename = $this->getFilename($profileName);
		if (@file_exists($filename)) {
			$msg = "Profile <highlight>$profileName<end> already exists.";
			$sendto->reply($msg);
			return;
		}
		$contents = "# Settings\n";
		foreach ($this->settingManager->settings as $name => $value) {
			if ($name !== "botid" && $name !== "version" && !$this->util->endsWith($name, "_db_version")) {
				$contents .= "!settings save $name {$value->value}\n";
			}
		}
		$contents .= "\n# Events\n";
		/** @var EventCfg[] */
		$data = $this->db->fetchAll(EventCfg::class, "SELECT * FROM eventcfg_<myname>");
		foreach ($data as $row) {
			$status = "disable";
			if ($row->status === 1) {
				$status = "enable";
			}
			$contents .= "!config event {$row->type} {$row->file} {$status} all\n";
		}
		$contents .= "\n# Commands\n";
		/** @var CmdCfg[] */
		$data = $this->db->fetchAll(CmdCfg::class, "SELECT * FROM cmdcfg_<myname>");
		foreach ($data as $row) {
			$status = "disable";
			if ($row->status === 1) {
				$status = "enable";
			}
			$contents .= "!config {$row->cmdevent} {$row->cmd} {$status} {$row->type}\n";
		}
		$contents .= "\n# Aliases\n";
		/** @var CmdAlias[] */
		$data = $this->db->fetchAll(CmdAlias::class, "SELECT * FROM cmd_alias_<myname> WHERE `status` = '1' ORDER BY alias ASC");
		foreach ($data as $row) {
			$contents .= "!alias rem {$row->alias}\n";
			$contents .= "!alias add {$row->alias} {$row->cmd}\n";
		}
		file_put_contents($filename, $contents);
		$msg = "Profile <highlight>$profileName<end> has been saved.";
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("profile")
	 * @Matches("/^profile (rem|remove|del|delete) ([a-z0-9_-]+)$/i")
	 */
	public function profileRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profileName = $args[2];
		$filename = $this->getFilename($profileName);
		if (!@file_exists($filename)) {
			$msg = "Profile <highlight>$profileName<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		if (@unlink($filename) === false) {
			$msg = "Unable to delete the profile <highlight>$profileName<end>.";
		} else {
			$msg = "Profile <highlight>$profileName<end> has been deleted.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("profile")
	 * @Matches("/^profile load ([a-z0-9_-]+)$/i")
	 */
	public function profileLoadCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profileName = $args[1];
		$filename = $this->getFilename($profileName);
		
		if (!@file_exists($filename)) {
			$msg = "Profile <highlight>$profileName<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		$sendto->reply("Loading profile <highlight>$profileName<end>...");
		$output = $this->loadProfile($filename, $sender);
		if ($output === null) {
			$msg = "There was an error loading the profile <highlight>$profileName<end>.";
		} else {
			$msg = $this->text->makeBlob("Profile Results: $profileName", $output);
		}
		$sendto->reply($msg);
	}
	
	public function getFilename(string $profileName): string {
		return $this->path . DIRECTORY_SEPARATOR . $profileName . static::FILE_EXT;
	}
	
	public function loadProfile(string $filename, string $sender): ?string {
		$profileData = file_get_contents($filename);
		$lines = explode("\n", $profileData);
		$this->db->beginTransaction();
		try {
			$profileSendTo = new ProfileCommandReply();
			$numSkipped = 0;
			for ($profileRow=0; $profileRow < count($lines); $profileRow++) {
				$line = $lines[$profileRow];
				if (substr($line, 0, 15) === "!settings save ") {
					$parts = explode(" ", $line, 4);
					if ($this->settingManager->get($parts[2]) === $parts[3]) {
						$numSkipped++;
						continue;
					}
				} elseif (preg_match("/^!config (cmd|subcmd) (.+) (enable|disable) ([^ ]+)$/", $line, $parts)) {
					/** @var CmdCfg $data */
					$data = $this->db->fetch(
						CmdCfg::class,
						"SELECT * FROM cmdcfg_<myname> WHERE `cmdevent` = ? AND `cmd` = ? AND `type` = ?",
						$parts[1],
						$parts[2],
						$parts[4]
					);
					if (isset($data)
						&& (
								($data->status === 1 && $parts[3] === 'enable')
							||	($data->status === 0 && $parts[3] === 'disable')
						)
					 ) {
						$numSkipped++;
						continue;
					}
				} elseif (preg_match("/^!config event (.+) ([^ ]+) (enable|disable) ([^ ]+)$/", $line, $parts)) {
					/** @var EventCfg $data */
					$data = $this->db->fetch(EventCfg::class, "SELECT * FROM eventcfg_<myname> WHERE `type` = ? AND `file` = ? ", $parts[1], $parts[2]);
					if (
							($data->status === 1 && $parts[3] === 'enable')
						||	($data->status === 0 && $parts[3] === 'disable')
					 ) {
						$numSkipped++;
						continue;
					}
				} elseif (substr($line, 0, 11) === "!alias rem ") {
					$alias = explode(" ", $line, 3)[2];
					if (preg_match("/^!alias add \Q$alias\E (.+)$/", $lines[$profileRow+1], $parts)) {
						/** @var CmdAlias $data */
						$data = $this->db->fetch(
							CmdAlias::class,
							"SELECT * FROM cmd_alias_<myname> WHERE `status` = '1' AND `alias` = ?",
							$alias,
						);
						if ($data !== null) {
							if ($data->cmd === $parts[1]) {
								$profileRow++;
								$numSkipped+=2;
								continue;
							}
						} else {
							$numSkipped++;
							continue;
						}
					}
				}
				if ($line[0] === "!") {
					$profileSendTo->reply("<pagebreak><orange>{$line}<end>");
					$line = substr($line, 1);
					$this->commandManager->process("msg", $line, $sender, $profileSendTo);
					$profileSendTo->reply("");
				} else {
					$numSkipped++;
				}
			}
			$this->db->commit();
			if ($numSkipped > 0) {
				$totalNum = count($lines);
				$profileSendTo->reply("Ignored {$numSkipped}/{$totalNum} unchanged settings.");
			}
			return $profileSendTo->result;
		} catch (Exception $e) {
			$this->logger->log("ERROR", "Could not load profile", $e);
			$this->db->rollback();
			return null;
		}
	}
}
