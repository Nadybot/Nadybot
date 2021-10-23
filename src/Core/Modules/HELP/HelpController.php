<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\HELP;

use Nadybot\Core\{
	BotRunner,
	CmdContext,
	CommandAlias,
	CommandManager,
	HelpManager,
	Text,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command       = 'help',
 *		accessLevel   = 'all',
 *		description   = 'Show help topics',
 *		help          = 'help.txt',
 *		defaultStatus = '1'
 *	)
 */
class HelpController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public Text $text;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->helpManager->register(
			$this->moduleName,
			"about",
			"about.txt",
			"all",
			"Info about the development of Nadybot"
		);

		$this->commandAlias->register($this->moduleName, "help about", "about");
	}

	public function getAbout() {
		$data = file_get_contents(__DIR__ . "/about.txt");
		$version = BotRunner::getVersion();
		$data = str_replace('<version>', $version, $data);
		return $this->text->makeBlob("About Nadybot $version", $data);
	}

	/**
	 * @HandlesCommand("help")
	 */
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
	 * @HandlesCommand("help")
	 */
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
