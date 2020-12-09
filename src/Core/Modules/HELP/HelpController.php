<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\HELP;

use Nadybot\Core\{
	BotRunner,
	CommandAlias,
	CommandManager,
	CommandReply,
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
	 * @Matches("/^help$/i")
	 */
	public function helpListCommand(string $message, string $channel, string $sender, CommandReply $sendto): void {
		$data = $this->helpManager->getAllHelpTopics($sender);

		if (count($data) === 0) {
			$msg = "No help files found.";
			$sendto->reply($msg);
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

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("help")
	 * @Matches("/^help (.+)$/i")
	 */
	public function helpShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$helpcmd = strtolower($args[1]);
		
		if ($helpcmd === 'about') {
			$msg = $this->getAbout();
			$sendto->reply($msg);
			return;
		}
	
		// check for alias
		$row = $this->commandAlias->get($helpcmd);
		if ($row !== null && $row->status === 1) {
			$helpcmd = explode(' ', $row->cmd)[0];
		}

		$blob = $this->helpManager->find($helpcmd, $sender);
		if ($blob === null) {
			$msg = "No help found on this topic.";
			$sendto->reply($msg);
			return;
		}
		$helpcmd = ucfirst($helpcmd);
		$msg = $this->text->makeBlob("Help ($helpcmd)", $blob);
		$sendto->reply($msg);
	}
}
