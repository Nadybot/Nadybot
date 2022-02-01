<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\HELP;

use function Safe\file_get_contents;

use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	CommandAlias,
	CommandManager,
	HelpManager,
	ModuleInstance,
	Text,
};

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "help",
		accessLevel: "all",
		description: "Show help topics",
		defaultStatus: 1
	)
]
class HelpController extends ModuleInstance {

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public HelpManager $helpManager;

	#[NCA\Inject]
	public Text $text;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->helpManager->register(
			$this->moduleName,
			"about",
			"about.txt",
			"all",
			"Info about the development of Nadybot"
		);

		$this->commandAlias->register($this->moduleName, "help about", "about");
	}

	/** @return string|string[] */
	public function getAbout(): string|array {
		$data = file_get_contents(__DIR__ . "/about.txt");
		$version = BotRunner::getVersion();
		$data = str_replace('<version>', $version, $data);
		return $this->text->makeBlob("About Nadybot $version", $data);
	}

	/** Get a list of all help topics */
	#[NCA\HandlesCommand("help")]
	public function helpListCommand(CmdContext $context): void {
		$data = $this->helpManager->getAllHelpTopics($context->char->name);

		if (count($data) === 0) {
			$msg = "No help files found.";
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
			$helpLink = $this->text->makeChatcmd($row->name, "/tell <myname> help $row->name");
			$blob .= "<tab>{$helpLink}: {$row->description}\n";
		}

		$msg = $this->text->makeBlob("Help (main)", $blob);

		$context->reply($msg);
	}

	/**
	 * See help for a given topic
	 *
	 * The topic can be a module name, a command or a topic like "budatime"
	 */
	#[NCA\HandlesCommand("help")]
	public function helpShowCommand(CmdContext $context, string $topic): void {
		$topic = strtolower($topic);

		if ($topic === 'about') {
			$msg = $this->getAbout();
			$context->reply($msg);
			return;
		}

		// check for alias
		$row = $this->commandAlias->get($topic);
		if ($row !== null && $row->status === 1) {
			$topic = explode(' ', $row->cmd)[0];
		}

		$blob = $this->helpManager->find($topic, $context->char->name);
		if ($blob === null) {
			$context->reply($this->commandManager->getCmdHelpFromCode($topic));
			return;
		}
		$topic = ucfirst($topic);
		$msg = $this->text->makeBlob("Help ($topic)", $blob);
		$context->reply($msg);
	}
}
