<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\CmdContext;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'rules',
 *		accessLevel = 'all',
 *		description = "Rules of this bot",
 *		help        = 'rules.txt'
 *	)
 */
class RulesController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/**
	 * @var \Nadybot\Core\Nadybot $chatBot
	 * @Inject
	 */
	public Nadybot $chatBot;

	/**
	 * @HandlesCommand("rules")
	 */
	public function rulesCommand(CmdContext $context): void {
		$dataPath = $this->chatBot->vars["datafolder"] ?? "./data";
		if (!@file_exists("{$dataPath}/rules.txt")) {
			$context->reply("This bot does not have any rules defined yet.");
			return;
		}
		$content = @file_get_contents("{$dataPath}/rules.txt");
		if ($content === false) {
			$context->reply("This bot has rules defined, but I was unable to read them.");
			return;
		}
		$msg = $this->text->makeBlob("<myname>'s rules", $content);
		$context->reply($msg);
	}
}
