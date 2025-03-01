<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Amp\File\FilesystemException;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	Events\JoinMyPrivEvent,
	Filesystem,
	ModuleInstance,
	Nadybot,
	Text,
	Types\AccessLevel,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'rules',
		accessLevel: AccessLevel::All,
		description: 'Rules of this bot',
	),
	NCA\DefineCommand(
		command: 'raidrules',
		accessLevel: AccessLevel::All,
		description: 'Raid rules of this bot',
	)
]
class RulesController extends ModuleInstance {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private BotConfig $config;

	/** See the rules for this bot */
	#[NCA\HandlesCommand('rules')]
	#[NCA\Help\Epilogue(
		"To set up rules for this bot, put a file into\n".
		'<tab><highlight>data/rules.txt<end>'
	)]
	public function rulesCommand(CmdContext $context): void {
		$rulesPath = "{$this->config->paths->data}/rules.txt";
		try {
			if (!$this->fs->exists($rulesPath)) {
				$context->reply('This bot does not have any rules defined yet.');
				return;
			}
			$content = $this->fs->read($rulesPath);
		} catch (FilesystemException) {
			$context->reply('This bot has rules defined, but I was unable to read them.');
			return;
		}
		$msg = Text::makeBlob("<myname>'s rules", $content);
		$context->reply($msg);
	}

	/** See the raid rules for this bot */
	#[NCA\HandlesCommand('raidrules')]
	#[NCA\Help\Epilogue(
		"To set up raid rules for this bot, put a file into\n".
		'<tab><highlight>data/raidrules.txt<end>'
	)]
	public function raidrulesCommand(CmdContext $context): void {
		$rulesPath = "{$this->config->paths->data}/raidrules.txt";
		try {
			if (!$this->fs->exists($rulesPath)) {
				$context->reply('This bot does not have any raid rules defined yet.');
				return;
			}
			$content = $this->fs->read($rulesPath);
		} catch (FilesystemException) {
			$context->reply('This bot has raid rules defined, but I was unable to read them.');
			return;
		}
		$msg = Text::makeBlob("<myname>'s raid rules", $content);
		$context->reply($msg);
	}

	/** If you defined rules, send them to people joining the private channel */
	#[NCA\HandlesEvent]
	public function joinPrivateChannelShowRulesEvent(JoinMyPrivEvent $eventObj): void {
		$rulesPath = "{$this->config->paths->data}/rules.txt";
		try {
			if (!$this->fs->exists($rulesPath)) {
				return;
			}
			$content = $this->fs->read($rulesPath);
		} catch (FilesystemException) {
			return;
		}
		$msg = Text::makeBlob("<myname>'s rules", $content);
		$this->chatBot->sendMassTell($msg, $eventObj->sender);
	}
}
