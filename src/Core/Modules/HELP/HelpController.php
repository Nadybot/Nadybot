<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\HELP;

use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	ClassLoader,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	Filesystem,
	HelpManager,
	ModuleInstance,
	Modules\CONFIG\ConfigController,
	Modules\PREFERENCES\Preferences,
	Safe,
	Text,
	Types\Status,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'help',
		accessLevel: 'all',
		description: 'Show help topics',
		defaultStatus: Status::Enabled
	),
	NCA\DefineCommand(
		command: 'adminhelp',
		accessLevel: 'mod',
		description: 'Show admin help topics',
		defaultStatus: Status::Enabled
	),
]
class HelpController extends ModuleInstance {
	public const LEGEND_PREF = 'help_legend';

	/** Show mods the required access level for each command */
	#[NCA\Setting\Boolean] public bool $helpShowAL = true;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private HelpManager $helpManager;

	#[NCA\Inject]
	private Preferences $preferences;

	#[NCA\Inject]
	private ClassLoader $classLoader;

	#[NCA\Inject]
	private ConfigController $configController;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Setup]
	public function setup(): void {
		$this->helpManager->register(
			$this->moduleName,
			'about',
			'about.txt',
			'all',
			'Info about the development of Nadybot'
		);

		$this->commandAlias->register($this->moduleName, 'help about', 'about');
		$this->commandAlias->register($this->moduleName, 'help modules', 'modules');
	}

	public function getAbout(): string {
		$data = $this->fs->read(__DIR__ . '/about.txt');
		$version = BotRunner::getVersion();
		$data = str_replace('<version>', $version, $data);
		return Text::makeBlob("About Nadybot {$version}", $data);
	}

	/** Get a list of all help topics */
	#[NCA\HandlesCommand('help')]
	public function helpListCommand(
		CmdContext $context,
		#[NCA\Str('topics', 'list')] string $action
	): void {
		$data = collect($this->helpManager->getAllHelpTopics($context));

		if (count($data) === 0) {
			$msg = 'No help files found.';
			$context->reply($msg);
			return;
		}
		$blob = '';
		$currentModule = '';
		foreach ($data as $row) {
			if ($currentModule !== $row->module) {
				$blob .= "\n<pagebreak><header2>{$row->module}<end>\n";
				$currentModule = $row->module;
			}
			$helpLink = Text::makeChatcmd($row->name, "/tell <myname> help {$row->name}");
			$blob .= "<tab>{$helpLink}: {$row->description}\n";
		}

		$msg = Text::makeBlob('Help (main)', $blob);

		$context->reply($msg);
	}

	/** Get the initial help overview */
	#[NCA\HandlesCommand('help')]
	public function helpCommand(CmdContext $context): void {
		$data = $this->fs->read(__DIR__ . '/overview.txt');
		$version = BotRunner::getVersion();
		$database = $this->db->getVersion();
		$data = str_replace(
			['<version>', '<database>', '<php_version>'],
			[$version, $database, \PHP_VERSION],
			$data
		);
		$msg = Text::makeBlob('Help', $data);
		$context->reply($msg);
	}

	/** Get an explanation of the syntax used throughout the help */
	#[NCA\HandlesCommand('help')]
	public function helpSyntaxCommand(
		CmdContext $context,
		#[NCA\Str('syntax')] string $action
	): void {
		$data = $this->fs->read(__DIR__ . '/syntax.txt');
		$msg = Text::makeBlob('Help', trim($data));
		$context->reply($msg);
	}

	/** Get a list of all modules with their short description */
	#[NCA\HandlesCommand('help')]
	public function helpModulesCommand(
		CmdContext $context,
		#[NCA\Str('modules')] string $action
	): void {
		$modules = $this->classLoader->registeredModules;

		/** @var array<string,string> */
		$data = [];
		foreach ($modules as $module => $path) {
			$data[$module] = $this->configController->getModuleDescription($module) ?? '&lt;no description&gt;';
			$data[$module] = Safe::pregReplaceCallback(
				"/(https?:\/\/[^\s\n<]+)/s",
				static function (array $matches): string {
					return Text::makeChatcmd($matches[1], "/start {$matches[1]}");
				},
				$data[$module]
			);
		}
		ksort($data);

		/** @var list<string> */
		$blobs = [];
		foreach ($data as $module => $description) {
			$blobs []= "<pagebreak><header2>{$module}<end>\n<tab>".
				implode("\n<tab>", explode("\n", $description));
		}
		$blob = '';
		if ($this->commandManager->couldRunCommand($context, 'config HELP')) {
			$blob = 'Use <highlight><symbol>config &lt;module name&gt;<end> to configure '.
				"a module's settings, events and commands.\n\n";
		}
		$blob .= implode("\n\n", $blobs);
		$msg = Text::makeBlob('Help', $blob);
		$context->reply($msg);
	}

	/** Get the initial adminhelp overview */
	#[NCA\HandlesCommand('adminhelp')]
	public function adminhelpCommand(CmdContext $context): void {
		$data = $this->fs->read(__DIR__ . '/adminhelp.txt');
		$msg = Text::makeBlob('Help', $data);
		$context->reply($msg);
	}

	/** Enable or disable showing the syntax explanation on every help page */
	#[NCA\HandlesCommand('help')]
	public function helpLegendSettingCommand(
		CmdContext $context,
		bool $enable,
		#[NCA\Str('explanation', 'legend')] string $topic
	): void {
		$this->preferences->save(
			$context->char->name,
			self::LEGEND_PREF,
			$enable ? '1' : '0'
		);
		if ($enable) {
			$context->reply('Showing the syntax explanation is now <on>on<end>.');
		} else {
			$context->reply('Showing the syntax explanation is now <off>off<end>.');
		}
	}

	/**
	 * See help for a given topic
	 *
	 * The topic can be a module name, a command or a topic like 'budatime'
	 */
	#[NCA\HandlesCommand('help')]
	public function helpShowCommand(CmdContext $context, string $topic): void {
		$topic = strtolower($topic);

		if ($topic === 'about') {
			$msg = $this->getAbout();
			$context->reply($msg);
			return;
		}

		// check for alias
		$row = $this->commandAlias->get($topic);
		if ($row !== null && $row->status === Status::Enabled) {
			$topic = explode(' ', $row->cmd)[0];
		}

		$blob = $this->helpManager->find($topic, $context->char->name);
		if ($blob === null) {
			$context->reply($this->commandManager->getCmdHelpFromCode($topic, $context));
			return;
		}
		$topic = ucfirst($topic);
		$msg = Text::makeBlob("Help ({$topic})", $blob);
		$context->reply($msg);
	}
}
