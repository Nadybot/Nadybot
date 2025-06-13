<?php declare(strict_types=1);

namespace Nadybot\Core;

use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\HelpTopic,
	Modules\CONFIG\ConfigController,
	Types\AccessLevel,
};
use Nadybot\Core\DBSchema\{CmdCfg, CmdPermission, HlpCfg, Setting};
use Psr\Log\LoggerInterface;

/** The class managing everything related to help */
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

	/** Initialize the database before the setup event */
	public function init(): void {
		$this->db->table(HlpCfg::getTable())
			->update(['verify' => 0]);
		$this->db->table(HlpCfg::getTable())
			->asObj(HlpCfg::class)
			->each(function (HlpCfg $row): void {
				$this->configuredHelp[$row->name] = true;
			});
	}

	/**
	 * Register help from a given file
	 *
	 * @param string      $module      Name of the module this belongs to
	 * @param string      $command     The command for which we're registering help
	 * @param string      $filename    A file location that contains the text to display
	 * @param AccessLevel $accessLevel The minimum access level required to see the help
	 * @param string      $description A short description of the help
	 */
	public function register(string $module, string $command, string $filename, AccessLevel $accessLevel, string $description): void {
		$logObj = new AnonObj(
			class: 'HelpFile',
			properties: [
				'module' => $module,
				'command' => $command,
				'helpfile' => $filename,
				'admin' => $accessLevel,
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
				access_level: $accessLevel,
				verify: 1,
				file: $actualFilename,
				module: $module,
				description: $description,
			));
		}
	}

	/**
	 * Find a help topic by name if it exists and if the user has permissions to see it
	 *
	 * @param string $helpcmd The command for which we're searching help
	 * @param string $char    The character name who is doing the search
	 *
	 * @return ?string `null` if not found or no access, otherwise the full help page
	 */
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
		)->select('foo.module', 'foo.file', 'foo.name', 'foo.admin AS access_level', 'foo.description');

		$data = $outerQuery->asObj(HelpTopic::class);

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($char);

		$output = '';
		$shown = [];
		foreach ($data as $row) {
			if (!isset($row->file) || isset($shown[$row->file])) {
				continue;
			}
			if ($accessLevel->atLeast($row->access_level)) {
				$output .= $this->configController->getAliasInfo($row->name);
				$content = $this->fs->read($row->file);
				$output .= trim($content) . "\n\n";
				$shown[$row->file] = true;
			}
		}

		return ($output === '') ? null : $output;
	}

	/** Change the required access level for a help topic */
	public function update(string $helpTopic, AccessLevel $accessLevel): void {
		$helpTopic = strtolower($helpTopic);

		$this->db->table(HlpCfg::getTable())
			->where('name', $helpTopic)
			->update(['admin' => $accessLevel]);
	}

	/** Try to register a help file for a given module */
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
		)->select('foo.module', 'foo.file', 'foo.name', 'foo.description', 'foo.admin AS access_level', 'foo.sort')
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
			if (!isset($context) || $accessLevel->atLeast($row->access_level)) {
				$obj = new HelpTopic(
					module: $row->module,
					name: $row->name,
					description: $row->description,
					access_level: $row->access_level,
					sort: $row->sort,
				);
				yield $obj;
				$added[$key] = true;
			}
		}
	}
}
