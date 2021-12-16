<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\AOChatEvent;
use Nadybot\Core\CmdContext;
use Nadybot\Core\ConfigFile;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;

/**
 * @author Nadyita (RK5)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "rules",
		accessLevel: "all",
		description: "Rules of this bot",
		help: "rules.txt"
	)
]
class RulesController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\HandlesCommand("rules")]
	public function rulesCommand(CmdContext $context): void {
		$dataPath = $this->config->dataFolder;
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

	#[NCA\Event(
		name: "joinPriv",
		description: "If you defined rules, send them to people joining the private channel"
	)]
	public function joinPrivateChannelShowRulesEvent(AOChatEvent $eventObj): void {
		$dataPath = $this->config->dataFolder;
		if (
			!is_string($eventObj->sender)
			|| !@file_exists("{$dataPath}/rules.txt")
			|| ($content = @file_get_contents("{$dataPath}/rules.txt")) === false
		) {
			return;
		}
		$msg = $this->text->makeBlob("<myname>'s rules", $content);
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}
}
