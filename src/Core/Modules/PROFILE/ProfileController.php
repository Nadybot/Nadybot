<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use function Safe\{json_decode, json_encode};

use Amp\File\{FilesystemException};
use Exception;
use Nadybot\Core\DBSchema\{
	CmdAlias,
	CmdPermSetMapping,
	CmdPermission,
	CmdPermissionSet,
	EventCfg,
	ExtCmdPermissionSet,
	RouteHopColor,
	RouteHopFormat,
};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	DB,
	Filesystem,
	MessageHub,
	ModuleInstance,
	Nadybot,
	ParamClass\PFilename,
	ParamClass\PRemove,
	Safe,
	SettingManager,
	SubcommandManager,
	Text,
	Types\CommandReply,
};
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'profile',
		accessLevel: 'admin',
		description: 'View, add, remove, and load profiles',
		alias: 'profiles'
	)
]
class ProfileController extends ModuleInstance {
	public const FILE_EXT = '.txt';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private SubcommandManager $subcommandManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private RelayController $relayController;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	private string $path;

	#[NCA\Setup]
	public function setup(): void {
		$dataPath = $this->config->paths->data;
		$this->path = "{$dataPath}/profiles/";

		// make sure that the profile folder exists
		if (!$this->fs->isDirectory($this->path)) {
			try {
				$this->fs->createDirectory($this->path, 0777);
			} catch (Exception $e) {
				$this->logger->warning('Unable to create profile directory {dir}: {error}', [
					'dir' => $this->path,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Get a list of all stored profiles
	 *
	 * @return list<string>
	 */
	public function getProfileList(): array {
		$fileList = $this->fs->listFiles($this->path);
		$profileList = [];

		foreach ($fileList as $fileName) {
			// if file has the correct extension, it's a profile file
			if (str_ends_with($fileName, static::FILE_EXT)) {
				$profileList []= str_replace(static::FILE_EXT, '', $fileName);
			}
		}

		sort($profileList);
		return $profileList;
	}

	/** See the list of available profiles */
	#[NCA\HandlesCommand('profile')]
	#[NCA\Help\Epilogue(
		'Note: Profiles are stored in ./data/profiles. '.
		"Only lines that start with '!' will be executed and all other lines will ".
		'be ignored. Feel free to add or edit profiles as you wish.'
	)]
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
			$viewLink = Text::makeChatcmd('view', "/tell <myname> profile view {$profile}");
			$loadLink = Text::makeChatcmd('load', "/tell <myname> profile load {$profile}");
			$linkContents .= "{$name} [{$viewLink}] [{$loadLink}]\n";
		}

		if ($linkContents) {
			$linkContents .= "\n\n<orange>Warning: Running a profile script will change your configuration.  Proceed only if you understand the consequences.<end>";
			$msg = $this->text->makeBlob('Profiles (' . count($profileList) . ')', $linkContents);
		} else {
			$msg = 'No profiles available.';
		}
		$context->reply($msg);
	}

	/** View a profile */
	#[NCA\HandlesCommand('profile')]
	public function profileViewCommand(
		CmdContext $context,
		#[NCA\Str('view')] string $action,
		PFilename $profileName
	): void {
		$profileName = $profileName();
		$filename = $this->getFilename($profileName);
		if (!$this->fs->exists($filename)) {
			$msg = "Profile <highlight>{$profileName}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$blob = htmlspecialchars($this->fs->read($filename));
		$blob = Safe::pregReplace('/^([^#])/m', '<tab>$1', $blob);
		$blob = Safe::pregReplace('/^# (.+)$/m', '<header2>$1<end>', $blob);

		/** @var string $blob */
		$msg = $this->text->makeBlob("Profile {$profileName}", $blob);
		$context->reply($msg);
	}

	/** Save the current configuration as a profile */
	#[NCA\HandlesCommand('profile')]
	public function profileSaveCommand(CmdContext $context, #[NCA\Str('save')] string $action, PFilename $profileName): void {
		$profileName = $profileName();
		try {
			$this->saveProfile($profileName);
		} catch (Exception $e) {
			$context->reply(
				'Error saving the profile: <highlight>' . $e->getMessage() . '<end>'
			);
			return;
		}
		$msg = "Profile <highlight>{$profileName}<end> has been saved.";
		$context->reply($msg);
	}

	public function saveProfile(string $profileName): bool {
		$filename = $this->getFilename($profileName);
		if ($this->fs->exists($filename)) {
			throw new Exception("Profile <highlight>{$profileName}<end> already exists.");
		}
		$contents = "# Permission maps\n";
		$sets = $this->commandManager->getExtPermissionSets();
		$setData = json_encode($sets, \JSON_UNESCAPED_SLASHES|\JSON_UNESCAPED_UNICODE);
		$setData = Safe::pregReplace("/\"id\":\d+,/", '', $setData);

		/** @var string $setData */
		$contents .= "!permissions {$setData}\n";

		$contents .= "\n# Settings\n";
		foreach ($this->settingManager->settings as $name => $value) {
			if ($name !== 'botid' && $name !== 'version' && !str_ends_with($name, '_db_version')) {
				$contents .= "!settings save {$name} {$value->value}\n";
			}
		}
		$contents .= "\n# Events\n";

		$data = $this->db->table(EventCfg::getTable())->asObj(EventCfg::class);
		foreach ($data as $row) {
			$status = 'disable';
			if ($row->status === 1) {
				$status = 'enable';
			}
			$contents .= "!config event {$row->type} {$row->file} {$status} all\n";
		}
		$contents .= "\n# Commands\n";

		$data = $this->commandManager->getAll(true);
		foreach ($data as $row) {
			foreach ($row->permissions as $channel => $permissions) {
				$status = $permissions->enabled ? 'enable' : 'disable';
				$contents .= "!config {$row->cmdevent} {$row->cmd} {$status} {$channel}\n";
				$contents .= "!config {$row->cmdevent} {$row->cmd} admin {$channel} {$permissions->access_level}\n";
			}
		}
		$contents .= "\n# Aliases\n";

		$data = $this->db->table(CmdAlias::getTable())
			->where('status', 1)
			->orderBy('alias')
			->asObj(CmdAlias::class);
		foreach ($data as $row) {
			$contents .= "!alias rem {$row->alias}\n";
			$contents .= "!alias add {$row->alias} {$row->cmd}\n";
		}

		$contents .= "\n# Relays\n".
			"!relay remall\n".
			implode("\n", $this->relayController->getRelayDump()) . "\n";

		$contents .= "\n# Routes\n".
			"!route remall\n".
			implode("\n", $this->messageHub->getRouteDump(true)) . "\n";

		$contents .= "\n# Route colors\n".
			"!route color remall\n";

		$data = $this->db->table(RouteHopColor::getTable())
			->asObj(RouteHopColor::class);
		foreach ($data as $row) {
			foreach (['text', 'tag'] as $color) {
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

		$data = $this->db->table(RouteHopFormat::getTable())
			->asObj(RouteHopFormat::class);
		foreach ($data as $row) {
			if ($row->render === false) {
				$contents .= "!route format render {$row->hop} false\n";
			}
			$contents .= "!route format display {$row->hop} {$row->format}\n";
		}

		try {
			$this->fs->write($filename, $contents);
			return true;
		} catch (FilesystemException) {
			return false;
		}
	}

	/** Remove a profile */
	#[NCA\HandlesCommand('profile')]
	public function profileRemCommand(
		CmdContext $context,
		PRemove $action,
		PFilename $profileName
	): void {
		$profileName = $profileName();
		$filename = $this->getFilename($profileName);
		if (!$this->fs->exists($filename)) {
			$msg = "Profile <highlight>{$profileName}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		try {
			$this->fs->deleteFile($filename);
			$msg = "Profile <highlight>{$profileName}<end> has been deleted.";
		} catch (FilesystemException $e) {
			$msg = "Unable to delete the profile <highlight>{$profileName}<end>: ".
				$e->getMessage();
		}
		$context->reply($msg);
	}

	/** Load a profile, replacing all your settings with the profile's */
	#[NCA\HandlesCommand('profile')]
	public function profileLoadCommand(
		CmdContext $context,
		#[NCA\Str('load')] string $action,
		PFilename $profileName
	): void {
		$profileName = $profileName();
		$filename = $this->getFilename($profileName);

		if (false === $this->fs->exists($filename)) {
			$msg = "Profile <highlight>{$profileName}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$context->reply("Loading profile <highlight>{$profileName}<end>...");
		$output = $this->loadProfile($filename, $context->char->name);
		if ($output === null) {
			$msg = "There was an error loading the profile <highlight>{$profileName}<end>.";
		} else {
			$msg = $this->text->makeBlob("Profile Results: {$profileName}", $output);
		}
		$context->reply($msg);
	}

	public function getFilename(string $profileName): string {
		return $this->path . \DIRECTORY_SEPARATOR . $profileName . static::FILE_EXT;
	}

	public function loadProfile(string $filename, string $sender): ?string {
		$profileData = $this->fs->read($filename);
		$lines = explode("\n", $profileData);
		$this->db->awaitBeginTransaction();
		try {
			$profileSendTo = new ProfileCommandReply();
			$context = new CmdContext(
				charName: $sender,
				charId: $this->chatBot->getUid($sender),
				sendto: $profileSendTo,
				permissionSet: $this->commandManager->getPermissionSets()->firstOrFail()->name,
			);
			$numSkipped = 0;
			for ($profileRow=0; $profileRow < count($lines); $profileRow++) {
				$line = $lines[$profileRow];
				if (substr($line, 0, 15) === '!settings save ') {
					$parts = explode(' ', $line, 4);
					if ($this->settingManager->get($parts[2]) === $parts[3]) {
						$numSkipped++;
						continue;
					}
				} elseif (count($parts = Safe::pregMatch('/^!config (cmd|subcmd) (.+) (enable|disable) ([^ ]+)$/', $line))) {
					$exists = $this->db->table(CmdPermission::getTable())
						->where('cmd', $parts[2])
						->where('permission_set', $parts[4])
						->where('enabled', $parts[3] === 'enable')
						->exists();
					if ($exists) {
						$numSkipped++;
						continue;
					}
				} elseif (count($parts = Safe::pregMatch('/^!config (cmd|subcmd) (.+) admin ([^ ]+) ([^ ]+)$/', $line))) {
					$exists = $this->db->table(CmdPermission::getTable())
						->where('cmd', $parts[2])
						->where('permission_set', $parts[3])
						->where('access_level', $parts[4])
						->exists();
					if ($exists) {
						$numSkipped++;
						continue;
					}
				} elseif (count($parts = Safe::pregMatch('/^!config event (.+) ([^ ]+) (enable|disable) ([^ ]+)$/', $line))) {
					$exists = $this->db->table(EventCfg::getTable())
						->where('type', $parts[1])
						->where('file', $parts[2])
						->where('status', ($parts[3] === 'enable') ? 1 : 0)
						->exists();
					if ($exists) {
						$numSkipped++;
						continue;
					}
				} elseif (substr($line, 0, 11) === '!alias rem ') {
					/** @psalm-suppress PossiblyUndefinedArrayOffset */
					$alias = explode(' ', $line, 3)[2];
					if (count($parts = Safe::pregMatch("/^!alias add \Q{$alias}\E (.+)$/", $lines[$profileRow+1]))) {
						/** @var ?CmdAlias $data */
						$data = $this->db->table(CmdAlias::getTable())
							->where('status', 1)
							->where('alias', $alias)
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
				if (count($matches = Safe::pregMatch('/^!permissions (.+)$/', $line))) {
					$profileSendTo->reply("<pagebreak><orange>{$line}<end>");
					$this->loadPermissions($matches[1], $profileSendTo);
					$profileSendTo->reply('');
				} elseif ($line[0] === '!') {
					$profileSendTo->reply("<pagebreak><orange>{$line}<end>");
					$line = substr($line, 1);
					$context->message = $line;
					$this->commandManager->processCmd($context);
					$profileSendTo->reply('');
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
			$this->logger->error('Could not load profile: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			$this->db->rollback();
			return null;
		}
	}

	private function loadPermissions(string $export, CommandReply $reply): void {
		/** @var list<ExtCmdPermissionSet> */
		$sets = json_decode($export);
		$this->db->table(CmdPermission::getTable())->delete();
		$this->db->table(CmdPermissionSet::getTable())->delete();
		$this->db->table(CmdPermSetMapping::getTable())->delete();
		$reply->reply('All permissions reset');

		$this->commandManager->commands = [];
		$this->subcommandManager->subcommands = [];
		foreach ($sets as $set) {
			$this->commandManager->createPermissionSet($set->name, $set->letter);
			$reply->reply(
				"Created permission set <highlight>{$set->name}<end> ".
				"with letter <highlight>{$set->letter}<end>."
			);
			foreach ($set->mappings as $mapping) {
				/** @var string */
				$id = $mapping->id;
				$mapping->id = Uuid::fromString($id);
				$map = new CmdPermSetMapping(...get_object_vars($mapping));
				$map->permission_set = $set->name;
				$this->db->insert($map);
				$reply->reply(
					"Mapped <highlight>{$map->source}<end> ".
					"to <highlight>{$map->permission_set}<end> ".
					'with ' . ($map->symbol_optional ? 'optional ' : '').
					"prefix <highlight>{$map->symbol}<end>, ".
					($map->feedback ? '' : 'not ') . 'giving unknown command errors.'
				);
			}
		}
		$this->commandManager->loadPermsetMappings();
		$this->commandManager->loadCommands();
		$this->subcommandManager->loadSubcommands();
	}
}
