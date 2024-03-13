<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use function Safe\preg_grep;

use Amp\File\{FilesystemException};
use Nadybot\Core\DBSchema\{CmdCfg, CmdPermissionSet};
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	CommandManager,
	DB,
	Filesystem,
	ModuleInstance,
	SettingEvent,
	Text,
	UserException,
};

use Psr\Log\LoggerInterface;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
]
class CustomCmdController extends ModuleInstance {
	public const OFF = "off";

	#[NCA\Setting\Text(
		options: [
			self::OFF,
			"data",
		]
	)]
	/** Directory in which to search for custom textfile commands */
	public string $customCmdDir = self::OFF;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private CommandManager $cmdManager;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\SettingChangeHandler("custom_cmd_dir")]
	public function checkCustomCmdDir(string $setting, string $old, string $new): void {
		if ($new === self::OFF) {
			return;
		}
		if (str_contains($new, "..")) {
			throw new UserException("<highlight>..<end> is not allowed.");
		}
		$dir = BotRunner::getBasedir() . "/" . $new;
		if (!$this->fs->exists($dir)) {
			throw new UserException("The directory <highlight>" . htmlentities($dir) . "<end> doesn't exist.");
		}
		if (!$this->fs->isDirectory($dir)) {
			throw new UserException("<highlight>" . htmlentities($dir) . "<end> is not a directory");
		}
	}

	#[NCA\Event(
		name: "setting(custom_cmd_dir)",
		description: "Turn on/off commands",
	)]
	public function changeCustomCmdDir(SettingEvent $event): void {
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
			$this->registerCustomCommands($event->newValue->value, true);
		}
	}

	#[NCA\Setup]
	public function setup(): void {
		if ($this->customCmdDir === self::OFF) {
			return;
		}
		$this->registerCustomCommands($this->customCmdDir);
	}

	#[NCA\HandlesAllCommands]
	public function executeCustomCmd(CmdContext $context): void {
		$baseDir = BotRunner::getBasedir() . "/" . $this->customCmdDir;
		if ($this->fs->isDirectory($baseDir . "/" . $context->getCommand())) {
			$content = $this->mergeDirTextFiles($baseDir . "/" . $context->getCommand());
		} else {
			$content = $this->fs->read($baseDir . "/" . $context->getCommand() . ".txt");
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

	private function registerCustomCommands(string $path, bool $activate=false): void {
		$baseDir = BotRunner::getBasedir() . "/" . $path;

		try {
			$fileList = $this->fs->listFiles($baseDir);
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
			if ($this->fs->isDirectory($baseDir . "/" . $fileName)) {
				$files = $this->fs->listFiles($baseDir . "/" . $fileName);
				if (!count(preg_grep('/\.txt$/', $files))) {
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
	private function mergeDirTextFiles(string $dirName): string {
		$fileList = $this->fs->listFiles($dirName);
		natcasesort($fileList);
		$content = [];
		foreach ($fileList as $fileName) {
			if (!str_ends_with($fileName, ".txt")) {
				continue;
			}
			$content []= $this->fs->read($dirName . "/" . $fileName);
		}
		return join("\n\n", $content);
	}
}
