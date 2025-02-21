<?php declare(strict_types=1);

namespace Nadybot\Core;

use Generator;
use Nadybot\Core\DBSchema\{CmdCfg, CmdPermission, HlpCfg, Setting};
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\HelpTopic,
	Modules\CONFIG\ConfigController,
};
use Psr\Log\LoggerInterface;

#[NCA\Instance]
class HelpManager {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private ConfigController $configController;

	#[NCA\Inject]
	private Util $util;

	/** @var array<string,bool> */
	private array $configuredHelp = [];

	public function init(): void {
		$this->db->table(HlpCfg::getTable())
			->update(['verify' => 0]);
		$this->db->table(HlpCfg::getTable())
			->asObj(HlpCfg::class)
			->each(function (HlpCfg $row): void {
				$this->configuredHelp[$row->name] = true;
			});
	}

	/** Register a help command */
	public function register(string $module, string $command, string $filename, string $admin, string $description): void {
		$logObj = new AnonObj(
			class: 'HelpFile',
			properties: [
				'module' => $module,
				'command' => $command,
				'helpfile' => $filename,
				'admin' => $admin,
				'description' => $description,
			]
		);
		$this->logger->info('Registering {help_file}', ['help_file' => $logObj]);

		$command = strtolower($command);

		// Check if the file exists
		$actualFilename = $this->util->verifyFilename($module . '/' . $filename);
		if ($actualFilename === '') {
			$this->logger->error('Error registering {help_file}: {error}', [
				'help_file' => $logObj,
				'error' => "The file doesn't exist",
			]);
			return;
		}

		if (isset($this->configuredHelp[$command])) {
			$this->db->table(HlpCfg::getTable())->where('name', $command)
				->update([
					'verify' => 1,
					'file' => $actualFilename,
					'module' => $module,
					'description' => $description,
				]);
		} else {
			$this->db->insert(new HlpCfg(
				name: $command,
				admin: $admin,
				verify: 1,
				file: $actualFilename,
				module: $module,
				description: $description,
			));
		}
	}

	/** Find a help topic by name if it exists and if the user has permissions to see it */
	public function find(string $helpcmd, string $char): ?string {
		$helpcmd = strtolower($helpcmd);
		$settingsHelp = $this->db->table(Setting::getTable())
			->where('name', $helpcmd)
			->where('help', '!=', '')
			->select('module', 'admin', 'name', 'help AS file', 'description');
		$hlpHelp = $this->db->table(HlpCfg::getTable())
			->where('name', $helpcmd)
			->where('file', '!=', '')
			->select('module', 'admin', 'name', 'file', 'description');
		$outerQuery = $this->db->fromSub(
			$settingsHelp->union($hlpHelp),
			'foo'
		)->select('foo.module', 'foo.file', 'foo.name', 'foo.admin AS admin_list', 'foo.description');

		$data = $outerQuery->asObj(HelpTopic::class);

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($char);

		$output = '';
		$shown = [];
		foreach ($data as $row) {
			if (!isset($row->file) || isset($shown[$row->file])) {
				continue;
			}
			if ($this->checkAccessLevels($accessLevel, $row->admin_list)) {
				$output .= $this->configController->getAliasInfo($row->name);
				$content = $this->fs->read($row->file);
				$output .= trim($content) . "\n\n";
				$shown[$row->file] = true;
			}
		}

		return ($output === '') ? null : $output;
	}

	public function update(string $helpTopic, string $admin): void {
		$helpTopic = strtolower($helpTopic);
		$admin = strtolower($admin);

		$this->db->table(HlpCfg::getTable())
			->where('name', $helpTopic)
			->update(['admin' => $admin]);
	}

	public function checkForHelpFile(string $module, string $file): string {
		$actualFilename = $this->util->verifyFilename($module . \DIRECTORY_SEPARATOR . $file);
		$baseDir = rtrim(BotRunner::getBasedir(), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
		if (str_starts_with($actualFilename, $baseDir)) {
			$actualFilename = './' . substr($actualFilename, strlen($baseDir));
		}
		if ($actualFilename === '') {
			$this->logger->warning('Error in registering the help file {module}/{file}: {error}', [
				'error' => "The file doesn't exist",
				'module' => $module,
				'file' => $file,
			]);
		}
		return $actualFilename;
	}

	/**
	 * Return all help topics a character has access to
	 *
	 * @return Generator<array-key,HelpTopic> Help topics
	 */
	public function getAllHelpTopics(?CmdContext $context): Generator {
		$cmdHelp = $this->db->table(CmdCfg::getTable(), 'c')
			->join(CmdPermission::getTable() . ' as p', 'c.cmd', 'p.cmd')
			->where('c.cmdevent', 'cmd')
			->where('p.enabled', true)
			->select('c.module', 'p.access_level as admin', 'c.cmd AS name');
		$cmdHelp->selectRaw('NULL' . $cmdHelp->as('file'));
		$cmdHelp->addSelect('description');
		$cmdHelp->selectRaw('2' . $cmdHelp->as('sort'));
		$settingsHelp = $this->db->table(Setting::getTable())
			->where('help', '!=', '')
			->select('module', 'admin', 'name', 'help AS file', 'description');
		$settingsHelp->selectRaw('3' . $settingsHelp->as('sort'));
		$hlpHelp = $this->db->table(HlpCfg::getTable())
			->select('module', 'admin', 'name', 'file', 'description');
		$hlpHelp->selectRaw('1' . $settingsHelp->as('sort'));
		$outerQuery = $this->db->fromSub(
			$cmdHelp->union($settingsHelp)->union($hlpHelp),
			'foo'
		)->select('foo.module', 'foo.file', 'foo.name', 'foo.description', 'foo.admin AS admin_list', 'foo.sort')
		->orderBy('module')
		->orderBy('name')
		->orderByDesc('sort')
		->orderBy('description');

		$data = $outerQuery->asObj(HelpTopic::class);

		$accessLevel = AccessLevel::All;
		if (isset($context)) {
			$accessLevel = $this->accessManager->getAccessLevelForCharacter($context->char->name);
		}

		$added = [];
		foreach ($data as $row) {
			$key = $row->module.$row->name.$row->description;
			if (isset($added[$key])) {
				continue;
			}
			if (!isset($context) || $this->checkAccessLevels($accessLevel, $row->admin_list)) {
				$obj = new HelpTopic(
					module: $row->module,
					name: $row->name,
					description: $row->description,
					admin_list: $row->admin_list,
					sort: $row->sort,
				);
				yield $obj;
				$added[$key] = true;
			}
		}
	}

	/** @param iterable<AccessLevel> $accessLevelsArray */
	public function checkAccessLevels(AccessLevel $accessLevel1, iterable $accessLevelsArray): bool {
		foreach ($accessLevelsArray as $accessLevel2) {
			if ($accessLevel1->atLeast($accessLevel2)) {
				return true;
			}
		}
		return false;
	}
}
