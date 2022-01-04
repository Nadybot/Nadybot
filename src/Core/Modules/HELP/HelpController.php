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
	Instance,
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
		help: "help.txt",
		defaultStatus: 1
	)
]
class HelpController extends Instance {

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

	#[NCA\HandlesCommand("help")]
	public function helpShowCommand(CmdContext $context, string $cmd): void {
		$cmd = strtolower($cmd);

		if ($cmd === 'about') {
			$msg = $this->getAbout();
			$context->reply($msg);
			return;
		}

		// check for alias
		$row = $this->commandAlias->get($cmd);
		if ($row !== null && $row->status === 1) {
			$cmd = explode(' ', $row->cmd)[0];
		}

		$blob = $this->helpManager->find($cmd, $context->char->name);
		if ($blob === null) {
			$msg = "No help found on this topic.";
			$context->reply($msg);
			return;
		}
		$cmd = ucfirst($cmd);
		$msg = $this->text->makeBlob("Help ($cmd)", $blob);
		$context->reply($msg);
	}
}
