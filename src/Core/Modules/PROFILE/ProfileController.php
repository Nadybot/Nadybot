<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use function Safe\file_get_contents;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandManager,
	ConfigFile,
	DB,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	ParamClass\PFilename,
	ParamClass\PRemove,
	Routing\Source,
	SettingManager,
	Text,
	Util,
};
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\DBSchema\{
	CmdAlias,
	CmdCfg,
	EventCfg,
	CmdPermission,
	RouteHopColor,
	RouteHopFormat,
};
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Safe\Exceptions\FilesystemException;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "profile",
		accessLevel: "admin",
		description: "View, add, remove, and load profiles",
		help: "profile.txt",
		alias: "profiles"
	)
]
class ProfileController extends ModuleInstance {
	public const FILE_EXT = ".txt";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Inject]
	public ConfigFile $config;

	private string $path;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$dataPath = $this->config->dataFolder;
		$this->path = "{$dataPath}/profiles/";

		// make sure that the profile folder exists
		if (!@is_dir($this->path)) {
			\Safe\mkdir($this->path, 0777);
		}
	}

	/**
	 * Get a list of all stored profiles
	 * @return string[]
	 */
	public function getProfileList(): array {
		$handle = \Safe\opendir($this->path);
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

	#[NCA\HandlesCommand("profile")]
	public function profileListCommand(CmdContext $context): void {
		try {
			$profileList = $this->getProfileList();
		} catch (Exception $e) {
			$context->reply($e->getMessage());
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
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("profile")]
	public function profileViewCommand(CmdContext $context, #[NCA\Str("view")] string $action, PFilename $profileName): void {
		$profileName = $profileName();
		$filename = $this->getFilename($profileName);
		if (!@file_exists($filename)) {
			$msg = "Profile <highlight>{$profileName}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$blob = htmlspecialchars(file_get_contents($filename));
		$blob = preg_replace("/^([^#])/m", "<tab>$1", $blob);
		$blob = preg_replace("/^# (.+)$/m", "<header2>$1<end>", $blob);
		$msg = $this->text->makeBlob("Profile $profileName", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("profile")]
	public function profileSaveCommand(CmdContext $context, #[NCA\Str("save")] string $action, PFilename $fileName): void {
		$fileName = $fileName();
		try {
			$this->saveProfile($fileName);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
		}
		$msg = "Profile <highlight>{$fileName}<end> has been saved.";
		$context->reply($msg);
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
		/** @var Collection<CmdCfg> */
		$data = $this->commandManager->getAll();
		foreach ($data as $row) {
			foreach ($row->permissions as $channel => $permissions) {
				$status = $permissions->enabled ? "enable" : "disable";
				$contents .= "!config {$row->cmdevent} {$row->cmd} {$status} {$channel}\n";
			}
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

		return \Safe\file_put_contents($filename, $contents) !== false;
	}

	#[NCA\HandlesCommand("profile")]
	public function profileRemCommand(CmdContext $context, PRemove $action, PFilename $fileName): void {
		$profileName = $fileName();
		$filename = $this->getFilename($profileName);
		if (!@file_exists($filename)) {
			$msg = "Profile <highlight>{$profileName}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		try {
			\Safe\unlink($filename);
			$msg = "Profile <highlight>{$profileName}<end> has been deleted.";
		} catch (FilesystemException $e) {
			$msg = "Unable to delete the profile <highlight>{$profileName}<end>: ".
				$e->getMessage();
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("profile")]
	public function profileLoadCommand(CmdContext $context, #[NCA\Str("load")] string $action, PFilename $fileName): void {
		$profileName = $fileName();
		$filename = $this->getFilename($profileName);

		if (!@file_exists($filename)) {
			$msg = "Profile <highlight>{$profileName}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$context->reply("Loading profile <highlight>{$profileName}<end>...");
		$output = $this->loadProfile($filename, $context->char->name);
		if ($output === null) {
			$msg = "There was an error loading the profile <highlight>$profileName<end>.";
		} else {
			$msg = $this->text->makeBlob("Profile Results: $profileName", $output);
		}
		$context->reply($msg);
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
			$context = new CmdContext($sender);
			$context->char->id = $this->chatBot->get_uid($sender) ?: null;
			$context->sendto = $profileSendTo;
			$context->permissionSet = $this->commandManager->getPermissionSets()->firstOrFail()->name;
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
					/** @var CmdPermission|null $data */
					$data = $this->db->table(CommandManager::DB_TABLE_PERMS)
						->where("cmd", $parts[2])
						->where("name", $parts[4])
						->asObj(CmdPermission::class)
						->first();
					if (isset($data) && ($data->enabled === ($parts[3] === 'enable'))) {
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
						/** @var ?CmdAlias $data */
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
					$context->message = $line;
					$this->commandManager->processCmd($context);
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
			$this->logger->error("Could not load profile: " . $e->getMessage(), ["exception" => $e]);
			$this->db->rollback();
			return null;
		}
	}
}
