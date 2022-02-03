<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	AOChatEvent,
	CmdContext,
	ConfigFile,
	ModuleInstance,
	Nadybot,
	Text,
};
use Safe\Exceptions\FilesystemException;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "rules",
		accessLevel: "all",
		description: "Rules of this bot",
	)
]
class RulesController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	/** See the rules for this bot */
	#[NCA\HandlesCommand("rules")]
	#[NCA\Help\Epilogue(
		"To set up rules for this bot, put a file into\n".
		"<tab><highlight>data/rules.txt<end>"
	)]
	public function rulesCommand(CmdContext $context): void {
		$dataPath = $this->config->dataFolder;
		if (!@file_exists("{$dataPath}/rules.txt")) {
			$context->reply("This bot does not have any rules defined yet.");
			return;
		}
		try {
			$content = \Safe\file_get_contents("{$dataPath}/rules.txt");
		} catch (FilesystemException) {
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
		if (!is_string($eventObj->sender) || !@file_exists("{$dataPath}/rules.txt")) {
			return;
		}
		try {
			$content = \Safe\file_get_contents("{$dataPath}/rules.txt");
		} catch (FilesystemException) {
			return;
		}
		$msg = $this->text->makeBlob("<myname>'s rules", $content);
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}
}
