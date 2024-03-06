<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Amp\File\{Filesystem, FilesystemException};
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	ModuleInstance,
	Nadybot,
	Text,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "rules",
		accessLevel: "all",
		description: "Rules of this bot",
	),
	NCA\DefineCommand(
		command: "raidrules",
		accessLevel: "all",
		description: "Raid rules of this bot",
	)
]
class RulesController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Filesystem $fs;

	#[NCA\Inject]
	public BotConfig $config;

	/** See the rules for this bot */
	#[NCA\HandlesCommand("rules")]
	#[NCA\Help\Epilogue(
		"To set up rules for this bot, put a file into\n".
		"<tab><highlight>data/rules.txt<end>"
	)]
	public function rulesCommand(CmdContext $context): void {
		$rulesPath = "{$this->config->paths->data}/rules.txt";
		try {
			if (!$this->fs->exists($rulesPath)) {
				$context->reply("This bot does not have any rules defined yet.");
				return;
			}
			$content = $this->fs->read($rulesPath);
		} catch (FilesystemException) {
			$context->reply("This bot has rules defined, but I was unable to read them.");
			return;
		}
		$msg = $this->text->makeBlob("<myname>'s rules", $content);
		$context->reply($msg);
	}

	/** See the raid rules for this bot */
	#[NCA\HandlesCommand("raidrules")]
	#[NCA\Help\Epilogue(
		"To set up raid rules for this bot, put a file into\n".
		"<tab><highlight>data/raidrules.txt<end>"
	)]
	public function raidrulesCommand(CmdContext $context): void {
		$rulesPath = "{$this->config->paths->data}/raidrules.txt";
		try {
			if (!$this->fs->exists($rulesPath)) {
				$context->reply("This bot does not have any raid rules defined yet.");
				return;
			}
			$content = $this->fs->read($rulesPath);
		} catch (FilesystemException) {
			$context->reply("This bot has raid rules defined, but I was unable to read them.");
			return;
		}
		$msg = $this->text->makeBlob("<myname>'s raid rules", $content);
		$context->reply($msg);
	}

	#[NCA\Event(
		name: "joinPriv",
		description: "If you defined rules, send them to people joining the private channel"
	)]
	public function joinPrivateChannelShowRulesEvent(AOChatEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$rulesPath = "{$this->config->paths->data}/rules.txt";
		try {
			if (!$this->fs->exists($rulesPath)) {
				return;
			}
			$content = $this->fs->read($rulesPath);
		} catch (FilesystemException) {
			return;
		}
		$msg = $this->text->makeBlob("<myname>'s rules", $content);
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}
}
