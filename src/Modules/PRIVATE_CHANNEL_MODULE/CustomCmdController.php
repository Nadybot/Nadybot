<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use function Amp\File\filesystem;

use Amp\File\FilesystemException;
use Generator;

use Nadybot\Core\DBSchema\{CmdCfg, CmdPermissionSet};
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	CommandManager,
	ConfigFile,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	SettingEvent,
	Text,
	UserException,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
]
class CustomCmdController extends ModuleInstance {
	public const OFF = "off";

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setting\Text(
		options: [
			self::OFF,
			"data",
		]
	)]
	/** Directory in which to search for custom textfile commands */
	public string $customCmdDir = self::OFF;

	#[NCA\SettingChangeHandler("custom_cmd_dir")]
	public function checkCustomCmdDir(string $setting, string $old, string $new): void {
		if ($new === self::OFF) {
			return;
		}
		if (str_contains($new, "..")) {
			throw new UserException("<highlight>..<end> is not allowed.");
		}
		$dir = BotRunner::getBasedir() . "/" . $new;
		if (!@file_exists($dir)) {
			throw new UserException("The directory <highlight>" . htmlentities($dir) . "<end> doesn't exist.");
		}
		if (!is_dir($dir)) {
			throw new UserException("<highlight>" . htmlentities($dir) . "<end> is not a directory");
		}
	}

	#[NCA\Event(
		name: "setting(custom_cmd_dir)",
		description: "Turn on/off commands",
	)]
	public function changeCustomCmdDir(SettingEvent $event): Generator {
		if ($event->oldValue->value !== self::OFF) {
			$this->db->table($this->cmdManager::DB_TABLE)
				->where("file", 'CustomCmdController.executeCustomCmd:123')
				->asObj(CmdCfg::class)
				->each(function (CmdCfg $cfg): void {
					$this->cmdManager->getPermissionSets()
						->each(function (CmdPermissionSet $permSet) use ($cfg): void {
							$this->cmdManager->deactivate(
								$permSet->name,
								$cfg->file,
								$cfg->cmd,
							);
						});
				});
			$this->db->table($this->cmdManager::DB_TABLE)
				->where("file", 'CustomCmdController.executeCustomCmd:123')
				->delete();
		}
		if ($event->newValue->value !== self::OFF && $event->newValue->value !== null) {
			yield from $this->registerCustomCommands($event->newValue->value, true);
		}
	}

	#[NCA\Setup]
	public function setup(): Generator {
		if ($this->customCmdDir === self::OFF) {
			return;
		}
		yield from $this->registerCustomCommands($this->customCmdDir);
	}

	#[NCA\HandlesAllCommands]
	public function executeCustomCmd(CmdContext $context): Generator {
		$fs = filesystem();
		$baseDir = BotRunner::getBasedir() . "/" . $this->customCmdDir;
		if (yield $fs->isDirectory($baseDir . "/" . $context->getCommand())) {
			$content = yield from $this->mergeDirTextFiles($baseDir . "/" . $context->getCommand());
		} else {
			$content = yield $fs->read($baseDir . "/" . $context->getCommand() . ".txt");
		}
		$lines = explode("\n", $content);
		$headerHadTags = false;
		$firstLine = preg_replace_callback(
			"/(<.*?>)/",
			function (array $match) use (&$headerHadTags): string {
				if ($match[1] === '<myname>') {
					return '<myname>';
				}
				$headerHadTags = true;
				return '';
			},
			array_shift($lines)
		);
		$context->reply(
			$this->text->makeBlob(
				$firstLine,
				$headerHadTags ? $content : implode("\n", $lines),
			)
		);
	}

	private function registerCustomCommands(string $path, bool $activate=false): Generator {
		$fs = filesystem();
		$baseDir = BotRunner::getBasedir() . "/" . $path;

		try {
			/** @var string[] */
			$fileList = yield $fs->listFiles($baseDir);
		} catch (FilesystemException $e) {
			$this->logger->warning("Unable to open {dir} to search for custom commands: {error}", [
				"dir" => $baseDir,
				"error" => $e->getMessage(),
			]);
			return;
		}
		foreach ($fileList as $fileName) {
			if (substr_count($fileName, ".") > 1) {
				continue;
			}
			if (yield $fs->isDirectory($baseDir . "/" . $fileName)) {
				/** @var string[] */
				$files = yield $fs->listFiles($baseDir . "/" . $fileName);
				if (empty(preg_grep('/\.txt$/', $files))) {
					continue;
				}
				$this->addDynamicCmd($fileName, $activate);
			} elseif (str_ends_with($fileName, ".txt")) {
				$this->addDynamicCmd(basename($fileName, ".txt"), $activate);
			}
		}
	}

	private function addDynamicCmd(string $cmdName, bool $activate=false): void {
		if ($this->cmdExists($cmdName)) {
			return;
		}

		$this->cmdManager->register(
			$this->getModuleName(),
			'CustomCmdController.executeCustomCmd:123',
			$cmdName,
			"guest",
			"A dynamic command based on {$cmdName}",
			1
		);
		if (!$activate) {
			return;
		}
		$this->cmdManager->getPermissionSets()
			->each(function (CmdPermissionSet $set) use ($cmdName): void {
				$this->cmdManager->activate(
					$set->name,
					'CustomCmdController.executeCustomCmd:123',
					$cmdName,
					"guest"
				);
			});
	}

	/** Check if a given command is already defined somewhere else */
	private function cmdExists(string $cmd): bool {
		$exists = $this->db->table($this->cmdManager::DB_TABLE)
			->where("cmd", $cmd)
			->where("file", "!=", 'CustomCmdController.executeCustomCmd:123')
			->count() > 0;
		return $exists;
	}

	/** Concatenate all .txt-files of a directory */
	private function mergeDirTextFiles(string $dirName): Generator {
		$fs = filesystem();
		$fileList = yield $fs->listFiles($dirName);
		natcasesort($fileList);
		$content = [];
		foreach ($fileList as $fileName) {
			if (!str_ends_with($fileName, ".txt")) {
				continue;
			}
			$content []= yield $fs->read($dirName . "/" . $fileName);
		}
		return join("\n\n", $content);
	}
}
