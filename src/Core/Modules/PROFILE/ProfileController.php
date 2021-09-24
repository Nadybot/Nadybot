<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Nadybot\Core\{
	CommandAlias,
	CommandManager,
	CommandReply,
	DB,
	EventManager,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	SettingManager,
	Text,
	Util,
};
use Exception;
use Nadybot\Core\DBSchema\{
	CmdAlias,
	CmdCfg,
	EventCfg,
	RouteHopColor,
	RouteHopFormat,
};
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\RELAY_MODULE\RelayController;

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
	public const FILE_EXT = ".txt";

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
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public LoggerWrapper $logger;

	/** @Inject */
	public RelayController $relayController;

	private string $path;

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$dataPath = $this->chatBot->vars["datafolder"] ?? "./data";
		$this->path = "{$dataPath}/profiles/";

		// make sure that the profile folder exists
		if (!@is_dir($this->path)) {
			mkdir($this->path, 0777);
		}
	}

	/**
	 * Get a list of all stored profiles
	 *
	 * @return string[]
	 */
	public function getProfileList(): array {
		if (($handle = opendir($this->path)) === false) {
			throw new Exception("Could not open profiles directory.");
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
		return $profileList;
	}

	/**
	 * @HandlesCommand("profile")
	 * @Matches("/^profile$/i")
	 */
	public function profileListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		try {
			$profileList = $this->getProfileList();
		} catch (Exception $e) {
			$sendto->reply($e->getMessage());
			return;
		}

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
		try {
			$this->saveProfile($args[1]);
		} catch (Exception $e) {
			$sendto->reply($e->getMessage());
		}
		$msg = "Profile <highlight>{$args[1]}<end> has been saved.";
		$sendto->reply($msg);
	}

	public function saveProfile(string $profileName): bool {
		$filename = $this->getFilename($profileName);
		if (@file_exists($filename)) {
			throw new Exception("Profile <highlight>$profileName<end> already exists.");
		}
		$contents = "# Settings\n";
		foreach ($this->settingManager->settings as $name => $value) {
			if ($name !== "botid" && $name !== "version" && !$this->util->endsWith($name, "_db_version")) {
				$contents .= "!settings save $name {$value->value}\n";
			}
		}
		$contents .= "\n# Events\n";
		/** @var EventCfg[] */
		$data = $this->db->table(EventManager::DB_TABLE)->asObj(EventCfg::class)->toArray();
		foreach ($data as $row) {
			$status = "disable";
			if ($row->status === 1) {
				$status = "enable";
			}
			$contents .= "!config event {$row->type} {$row->file} {$status} all\n";
		}
		$contents .= "\n# Commands\n";
		/** @var CmdCfg[] */
		$data = $this->db->table(CommandManager::DB_TABLE)
			->asObj(CmdCfg::class)
			->toArray();
		foreach ($data as $row) {
			$status = "disable";
			if ($row->status === 1) {
				$status = "enable";
			}
			$contents .= "!config {$row->cmdevent} {$row->cmd} {$status} {$row->type}\n";
		}
		$contents .= "\n# Aliases\n";
		/** @var CmdAlias[] */
		$data = $this->db->table(CommandAlias::DB_TABLE)
			->where("status", 1)
			->orderBy("alias")
			->asObj(CmdAlias::class)->toArray();
		foreach ($data as $row) {
			$contents .= "!alias rem {$row->alias}\n";
			$contents .= "!alias add {$row->alias} {$row->cmd}\n";
		}

		$contents .= "\n# Relays\n".
			"!relay remall\n".
			join("\n", $this->relayController->getRelayDump()) . "\n";

		$contents .= "\n# Routes\n".
			"!route remall\n".
			join("\n", $this->messageHub->getRouteDump(true)) . "\n";

		$contents .= "\n# Route colors\n".
			"!route color remall\n";
		/** @var RouteHopColor[] */
		$data = $this->db->table(MessageHub::DB_TABLE_COLORS)
			->asObj(RouteHopColor::class)->toArray();
		foreach ($data as $row) {
			foreach (["text", "tag"] as $color) {
				if (isset($row->{"{$color}_color"})) {
					$contents .= "!route color {$color} set {$row->hop} ";
					if (isset($row->where)) {
						$contents .= "-> {$row->where} ";
					}
					$contents .= $row->{"{$color}_color"} . "\n";
				}
			}
		}

		$contents .= "\n# Route format\n".
			"!route format remall\n";
		/** @var RouteHopFormat[] */
		$data = $this->db->table(Source::DB_TABLE)
			->asObj(RouteHopFormat::class)->toArray();
		foreach ($data as $row) {
			if ($row->render === false) {
				$contents .= "!route format render {$row->hop} false\n";
			}
			$contents .= "!route format display {$row->hop} {$row->format}\n";
		}

		return file_put_contents($filename, $contents) !== false;
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
					/** @var CmdCfg|null $data */
					$data = $this->db->table(CommandManager::DB_TABLE)
						->where("cmdevent", $parts[1])
						->where("cmd", $parts[2])
						->where("type", $parts[4])
						->asObj(CmdCfg::class)
						->first();
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
					$data = $this->db->table(EventManager::DB_TABLE)
						->where("type", $parts[1])
						->where("file", $parts[2])
						->asObj(EventCfg::class)
						->first();
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
						$data = $this->db->table(CommandAlias::DB_TABLE)
							->where("status", 1)
							->where("alias", $alias)
							->asObj(CmdAlias::class)
							->first();
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
