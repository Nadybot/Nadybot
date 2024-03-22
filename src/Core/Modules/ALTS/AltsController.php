<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use function Amp\async;

use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Event\ConnectEvent;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandReply,
	DB,
	DBSchema\Alt,
	DBSchema\Audit,
	EventManager,
	LogonEvent,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	QueryBuilder,
	Registry,
	SQLException,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'alts',
		accessLevel: 'member',
		description: 'Alt character handling',
	),
	NCA\DefineCommand(
		command: 'altsadmin',
		accessLevel: 'mod',
		description: "Manage someone else's alts",
	),
	NCA\DefineCommand(
		command: 'altvalidate',
		accessLevel: 'all',
		description: 'Validate alts for admin privileges',
	),
	NCA\DefineCommand(
		command: 'altdecline',
		accessLevel: 'all',
		description: 'Declines being the alt of someone else',
	),

	NCA\ProvidesEvent(AltAddEvent::class),
	NCA\ProvidesEvent(AltDelEvent::class),
	NCA\ProvidesEvent(AltValidateEvent::class),
	NCA\ProvidesEvent(AltDeclineEvent::class),
	NCA\ProvidesEvent(AltNewMainEvent::class),
	NCA\HasMigrations
]
class AltsController extends ModuleInstance {
	public const ALT_VALIDATE = 'altvalidate';
	public const MAIN_VALIDATE = 'mainvalidate';

	/** Adding alt requires confirmation from alt */
	#[NCA\Setting\Boolean]
	public bool $altsRequireConfirmation = true;

	/** Show the org in the altlist */
	#[NCA\Setting\Boolean]
	public bool $altsShowOrg = true;

	/** How to show profession in alts list */
	#[NCA\Setting\Options(options: [
		'off' => 0,
		'icon' => 1,
		'short' => 2,
		'full' => 3,
		'icon+short' => 4,
		'icon+full' => 5,
	])]
	public int $altsProfessionDisplay = 1;

	/** By what to sort the alts list */
	#[NCA\Setting\Options(options: ['level', 'name'])]
	public string $altsSort = 'level';

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	/** @var array<string,string> */
	private array $alts = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->cacheAlts();
	}

	public function getMainOf(string $char): string {
		return $this->alts[$char] ?? $char;
	}

	/** @return string[] */
	public function getAltsOf(string $char): array {
		$alts = [$char];
		foreach ($this->alts as $alt => $main) {
			if ($main === $char && $main !== $alt) {
				$alts []= $alt;
			}
		}
		return $alts;
	}

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Add unvalidated alts/mains to friendlist'
	)]
	public function addNonValidatedAsBuddies(ConnectEvent $event): void {
		$myName = $this->config->main->character;
		$this->db->table('alts')->where('validated_by_alt', false)->where('added_via', $myName)
			->asObj(Alt::class)->each(function (Alt $alt) {
				$this->buddylistManager->addName($alt->alt, static::ALT_VALIDATE);
			});
		$this->db->table('alts')->where('validated_by_main', false)->where('added_via', $myName)
			->select('main')->distinct()
			->pluckStrings('main')
			->each(function (string $main) {
				$this->buddylistManager->addName($main, static::MAIN_VALIDATE);
			});
	}

	/** Add one or more alts to someone else's main */
	#[NCA\HandlesCommand('altsadmin')]
	#[NCA\Help\Group('altsadmin')]
	#[NCA\Help\Prologue(
		'This command allows anyone with the required accesslevel to add '.
		'and remove alts of org and bot members. This will only work with access '.
		"levels equal or lower than the one executing the command.\n".
		"Alts added to other players this way don't need to be confirmed."
	)]
	public function addAltadminCommand(
		CmdContext $context,
		PCharacter $main,
		#[NCA\Str('add')] string $action,
		PCharacter ...$names
	): void {
		$result = $this->addAltsToMain($context->char->name, $main(), ...$names);
		$context->reply(implode("\n", $result));
	}

	/** Add one or more alts to your main */
	#[NCA\HandlesCommand('alts')]
	#[NCA\Help\Group('alts')]
	#[NCA\Help\Epilogue(
		"<header2>Validation after '<symbol>alts add'<end>\n\n".
		"If <a href='chatcmd:///tell <myname> settings change alts_require_confirmation'>alts require confirmation</a> is on (default is on), then the\n".
		"main character who ran '<symbol>alts add' is unvalidated.\n\n".
		"In order to confirm an unvalidated main and share access level with them,\n".
		"you need to run\n".
		"<tab><highlight><symbol>altvalidate &lt;name of the main&gt;<end> on your alt.\n\n".
		"But don't worry, once you logon with the alt, you should automatically receive\n".
		'a request from the bot to confirm or decline your main.'
	)]
	public function addAltCommand(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		PCharacter ...$names
	): void {
		$result = $this->addAltsToMain($context->char->name, $context->char->name, ...$names);
		$context->reply(implode("\n", $result));
	}

	/** Add yourself as an alt of another main character */
	#[NCA\HandlesCommand('alts')]
	#[NCA\Help\Group('alts')]
	#[NCA\Help\Epilogue(
		"<header2>Validation after 'alts main'<end>\n\n".
		"Alts added to someone's altlist via '<symbol>alts main' are unvalidated.\n\n".
		"Unvalidated alts do not <a href='chatcmd:///tell <myname> settings change alts_inherit_admin'>inherit</a> the main character's access level.\n\n".
		"In order to confirm an unvalidated alt and share access level with them,\n".
		"you need to run\n".
		"<tab><highlight><symbol>altvalidate &lt;name of the alt&gt;<end> on your main.\n\n".
		'You should automatically receive a request for this after login.'
	)]
	public function addMainCommand(
		CmdContext $context,
		#[NCA\Str('main')] string $action,
		PCharacter $main
	): void {
		$newMain = $main();

		if ($newMain === $context->char->name) {
			$msg = 'You cannot add yourself as your own alt.';
			$context->reply($msg);
			return;
		}

		$senderAltInfo = $this->getAltInfo($context->char->name, true);

		if ($senderAltInfo->main === $newMain) {
			if ($senderAltInfo->isValidated($context->char->name)) {
				$msg = "<highlight>{$newMain}<end> is already your main.";
			} else {
				$msg = "You already requested <highlight>{$newMain}<end> to become your main.";
			}
			$context->reply($msg);
			return;
		}

		if (count($senderAltInfo->alts) > 0) {
			$msg = 'You can only request to be added to a main, if you are not '.
				"someone's alt already and don't have alts yourself - pending or not.";
			$context->reply($msg);
			return;
		}

		$uid = $this->chatBot->getUid($newMain);
		if ($uid === null) {
			$msg = "Character <highlight>{$newMain}<end> does not exist.";
			$context->reply($msg);
			return;
		}

		$newMainAltInfo = $this->getAltInfo($newMain, true);

		// insert into database
		$this->addAlt($newMainAltInfo->main, $context->char->name, false, true);

		// Try to inform a validated player from that account about new unvalidated alts
		$sentTo = null;
		$receivers = [$newMainAltInfo->main, ...$newMainAltInfo->getAllValidatedAlts()];
		foreach ($receivers as $receiver) {
			if ($this->buddylistManager->isOnline($receiver)) {
				$unvalidatedByMain = $newMainAltInfo->getAllMainUnvalidatedAlts(true);
				$this->sendMainValidationRequest($receiver, $context->char->name, ...$unvalidatedByMain);
				$sentTo = $receiver;
				break;
			}
		}
		// If no one was online, we will send the request on next login
		if (!isset($sentTo)) {
			$this->buddylistManager->addName($newMainAltInfo->main, static::MAIN_VALIDATE);
		}

		// update character information for both, main and alt
		async($this->playerManager->byName(...), $newMain)->ignore();
		async($this->playerManager->byName(...), $context->char->name)->ignore();
		// @todo Send a warning if the new main's accesslevel is lower than ours

		$msg = "Successfully requested to be added as <highlight>{$newMain}'s<end> alt. ".
			'Make sure to confirm the request on <highlight>';
		if (isset($sentTo)) {
			$msg .= "{$sentTo}<end>.";
		} elseif (count($newMainAltInfo->getAllValidatedAlts())) {
			$msg .= "{$newMain}<end> or one of their validated alts.";
		} else {
			$msg .= "{$newMain}<end>.";
		}
		$context->reply($msg);
	}

	/** Remove someone's alt */
	#[NCA\HandlesCommand('altsadmin')]
	#[NCA\Help\Group('altsadmin')]
	public function removeSomeonesAltCommand(
		CmdContext $context,
		PCharacter $main,
		PRemove $action,
		PCharacter $alt
	): void {
		$main = $main();
		$alt = $alt();
		$user = $context->char->name;

		$uid = $this->chatBot->getUid($main);
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$main}<end> does not exist.");
			return;
		}
		$altInfo = $this->getAltInfo($main, true);

		$rights = $this->accessManager->compareCharacterAccessLevels($user, $altInfo->main);
		if ($rights < 0) {
			$context->reply("You cannot manage someone's alts if they have a higher access level than you.");
			return;
		}

		if ($altInfo->main === $alt) {
			$msg = "You cannot remove <highlight>{$alt}<end>, because it's the main character.";
		} elseif (!isset($altInfo->alts[$alt])) {
			$msg = "<highlight>{$alt}<end> is not registered as {$main}'s alt.";
		} elseif (!$altInfo->isValidated($main) && $main !== $altInfo->main) {
			$msg = "{$main} is neither the main character, nor a validated alt.";
		} else {
			$this->remAlt($altInfo->main, $alt);
			$msg = "{$alt} is no longer <highlight>{$altInfo->main}'s<end> alt.";
			$this->buddylistManager->remove($alt, static::ALT_VALIDATE);
			$this->removeMainFromBuddyListIfPossible($altInfo->main);
		}
		$context->reply($msg);
	}

	/** Remove one of your alts */
	#[NCA\HandlesCommand('alts')]
	#[NCA\Help\Group('alts')]
	public function removeAltCommand(CmdContext $context, PRemove $rem, PCharacter $name): void {
		$name = $name();

		$altInfo = $this->getAltInfo($context->char->name, true);

		// You can only remove alts if you're the main or a validated alt
		// or if you remove yourself
		if ($altInfo->main === $name) {
			$msg = "You cannot remove <highlight>{$name}<end> as your main.";
		} elseif (!isset($altInfo->alts[$name])) {
			$msg = "<highlight>{$name}<end> is not registered as your alt.";
		} elseif (!$altInfo->isValidated($context->char->name) && $name !== $context->char->name) {
			$msg = 'You must be on a validated alt to remove an alt that is not yourself.';
		} else {
			$this->remAlt($altInfo->main, $name);
			if ($name !== $context->char->name) {
				$msg = "<highlight>{$name}<end> has been removed as your alt.";
			} else {
				$msg = "You are no longer <highlight>{$altInfo->main}'s<end> alt.";
			}
			$this->buddylistManager->remove($name, static::ALT_VALIDATE);
			$this->removeMainFromBuddyListIfPossible($altInfo->main);
		}
		$context->reply($msg);
	}

	/** Set someone's alt as their new main */
	#[NCA\HandlesCommand('altsadmin')]
	#[NCA\Help\Group('altsadmin')]
	public function setSomeonesMainCommand(
		CmdContext $context,
		PCharacter $newMain,
		#[NCA\Str('setmain')] string $action
	): void {
		$msg = $this->makeAltNewMain($context->char->name, $newMain());
		$context->reply($msg);
	}

	/** Set your current character as your main */
	#[NCA\HandlesCommand('alts')]
	#[NCA\Help\Group('alts')]
	public function setMainCommand(
		CmdContext $context,
		#[NCA\Str('setmain')] string $action
	): void {
		$msg = $this->makeAltNewMain($context->char->name, $context->char->name);
		$context->reply($msg);
	}

	/** List your or &lt;name&gt;'s alts */
	#[NCA\HandlesCommand('alts')]
	#[NCA\Help\Group('alts')]
	public function altsCommand(CmdContext $context, ?PCharacter $name): void {
		$name = isset($name) ? $name() : $context->char->name;

		$altInfo = $this->getAltInfo($name, true);
		if (count($altInfo->alts) === 0) {
			$msg = "No alts are registered for <highlight>{$name}<end>.";
			$context->reply($msg);
			return;
		}
		$context->reply($altInfo->getAltsBlob());
	}

	/** Validate an alt or main */
	#[NCA\HandlesCommand('altvalidate')]
	#[NCA\Help\Group('alts')]
	#[NCA\Help\Prologue(
		"Alts added to someone's altlist via '<symbol>alts main' are unvalidated, and cannot ".
		"<a href='chatcmd:///tell <myname> settings change alts_inherit_admin'>inherit</a> ".
		"admin privileges.\n\n".
		'This command allows the main of an altlist (or another validated character '.
		"at your bot owner's ".
		"<a href='chatcmd:///tell <myname> settings change validate_from_validated_alt'>discretion</a>) ".
		"to validate characters on your altlist, enabling them to inherit the main's admin ".
		'privileges.'
	)]
	public function altvalidateCommand(CmdContext $context, PCharacter $name): void {
		$altInfo = $this->getAltInfo($context->char->name, true);
		$toValidate = $name();

		if ($altInfo->isValidated($context->char->name)) {
			$this->validateAsMain($toValidate, $altInfo, $context);
		} else {
			$this->validateAsAlt($toValidate, $context->char->name, $altInfo, $context);
		}
	}

	/** Declines an alt or main requests */
	#[NCA\HandlesCommand('altdecline')]
	#[NCA\Help\Group('alts')]
	public function altDeclineCommand(CmdContext $context, PCharacter $name): void {
		$altInfo = $this->getAltInfo($context->char->name, true);
		$toDecline = $name();

		if ($altInfo->isValidated($context->char->name)) {
			$this->declineAsMain($toDecline, $altInfo, $context);
		} else {
			$this->declineAsAlt($toDecline, $context->char->name, $altInfo, $context);
		}
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Reminds unvalidates alts/mains to accept or deny'
	)]
	public function checkUnvalidatedAltsEvent(LogonEvent $eventObj): void {
		if (!$this->chatBot->isReady()
			|| !is_string($eventObj->sender)
			|| $eventObj->wasOnline !== false
		) {
			return;
		}
		$sender = $eventObj->sender;
		$altInfo = $this->getAltInfo($sender, true);
		if (!$altInfo->hasUnvalidatedAlts()) {
			return;
		}
		if (!$altInfo->isValidated($sender)) {
			if ($altInfo->alts[$sender]->added_via !== $this->chatBot->char?->name) {
				return;
			}
			if (!$altInfo->alts[$sender]->validated_by_alt) {
				$this->sendAltValidationRequest($sender, $altInfo);
			}
			return;
		}
		$unvalidatedByMain = $altInfo->getAllMainUnvalidatedAlts(true);
		if (count($unvalidatedByMain)) {
			$this->sendMainValidationRequest($sender, ...$unvalidatedByMain);
		}
	}

	/** Send $sender a request to confirm that they are $altInfo->main's alt */
	public function sendAltValidationRequest(string $sender, AltInfo $altInfo): void {
		$blob = "<header2>Are you an alt of {$altInfo->main}?<end>\n";
		$blob .= "<tab>We received a request from <highlight>{$altInfo->main}<end> ".
			"to add you as their alt.\n\n";
		$blob .= '<tab>Do you agree to this: ';
		$blob .= '['.
			$this->text->makeChatcmd('yes', "/tell <myname> altvalidate {$altInfo->main}").
			'] ['.
			$this->text->makeChatcmd('no', "/tell <myname> altdecline {$altInfo->main}").
			']';
		$msg = "{$altInfo->main} requested to add you as their alt :: ".
			((array)$this->text->makeBlob('decide', $blob, "Decide if you are {$altInfo->main}'s alt"))[0];
		$this->chatBot->sendTell($msg, $sender);
	}

	/** Send $main a request to confirm that a given list of users are their alts */
	public function sendMainValidationRequest(string $main, string ...$alts): void {
		$plural = (count($alts) > 1) ? 's' : '';
		$blob = "<header2>Alt confirmation<end>\n".
			'<tab>We received a request from ' . count($alts) . " player{$plural} ".
			"to be added to your alt list.\n\n".
			"<header2>Choose what to do<end>\n";
		foreach ($alts as $alt) {
			$blob .= '<tab> ['.
				$this->text->makeChatcmd('confirm', "/tell <myname> altvalidate {$alt}").
				'] ['.
				$this->text->makeChatcmd('decline', "/tell <myname> altdecline {$alt}").
				"] {$alt}\n";
		}
		$msg = 'You have <highlight>' . count($alts) . '<end> unanswered '.
			"alt request{$plural} :: ".
			((array)$this->text->makeBlob('decide', $blob, 'Decide who is your alt'))[0];
		$this->chatBot->sendTell($msg, $main);
	}

	/**
	 * Get information about the mains and alts of a player
	 *
	 * @param string $player The name of either the main or one of their alts
	 *
	 * @return AltInfo Information about the main and the alts
	 */
	public function getAltInfo(string $player, bool $includePending=false): AltInfo {
		$player = ucfirst(strtolower($player));

		$ai = new AltInfo();
		Registry::injectDependencies($ai);
		$ai->main = $player;
		$query = $this->db->table('alts')
			->where(static function (QueryBuilder $query) use ($includePending, $player) {
				$query->where('main', $player)
					->orWhere('main', static function (QueryBuilder $subQuery) use ($player, $includePending) {
						$subQuery->from('alts')->where('alt', $player)->select('main');
						if (!$includePending) {
							$subQuery->where('validated_by_main', true)
								->where('validated_by_alt', true);
						}
					});
			});
		if (!$includePending) {
			$query->where('validated_by_main', true)->where('validated_by_alt', true);
		}
		$query->asObj(Alt::class)
			->filter(static fn (Alt $alt): bool => $alt->alt !== $alt->main)
			->each(function (Alt $row) use ($ai) {
				$ai->main = $row->main;
				$ai->alts[$row->alt] = new AltValidationStatus();
				$ai->alts[$row->alt]->validated_by_alt = $row->validated_by_alt??false;
				$ai->alts[$row->alt]->validated_by_main = $row->validated_by_main??false;
				$ai->alts[$row->alt]->added_via = $row->added_via ?? $this->db->getMyname();
			});

		return $ai;
	}

	/** This method adds given $alt as $main's alt character. */
	public function addAlt(string $main, string $alt, bool $validatedByMain, bool $validatedByAlt, bool $sendEvent=true): int {
		$main = ucfirst(strtolower($main));
		$alt = ucfirst(strtolower($alt));

		$added = $this->db->table('alts')
			->insert([
				'alt' => $alt,
				'main' => $main,
				'validated_by_main' => $validatedByMain,
				'validated_by_alt' => $validatedByAlt,
				'added_via' => $this->db->getMyname(),
			]);
		if ($added && $sendEvent) {
			$event = new AltAddEvent(
				main: $main,
				alt: $alt,
				validated: $validatedByAlt && $validatedByMain,
			);
			$this->eventManager->fireEvent($event);
		}
		if ($validatedByAlt && $validatedByMain) {
			$this->alts[$alt] = $main;
			$audit = new Audit(
				actor: $main,
				actee: $alt,
				action: AccessManager::ADD_ALT,
			);
			$this->accessManager->addAudit($audit);
		}
		return $added ? 1 : 0;
	}

	/** This method removes given a $alt from being $main's alt character. */
	public function remAlt(string $main, string $alt): int {
		/** @var ?Alt */
		$old = $this->db->table('alts')
			->where('alt', $alt)
			->where('main', $main)
			->asObj(Alt::class)
			->first();
		$deleted = $this->db->table('alts')
			->where('alt', $alt)
			->where('main', $main)
			->delete();
		if ($deleted > 0) {
			$event = new AltDelEvent(
				main: $main,
				alt: $alt,
				validated: isset($old) ? ($old->validated_by_alt && $old->validated_by_main) : false,
			);
			$this->eventManager->fireEvent($event);

			if (isset($old) && $old->validated_by_alt && $old->validated_by_main) {
				unset($this->alts[$alt]);
				$audit = new Audit(
					actor: $main,
					actee: $alt,
					action: AccessManager::DEL_ALT,
				);
				$this->accessManager->addAudit($audit);
			}
		}
		return $deleted;
	}

	#[
		NCA\NewsTile(
			name: 'alts-info',
			description: 'Displays basic information about your alts',
			example: "<header2>Account<end>\n".
				"<tab>Your main is <highlight>Nady<end>\n".
				'<tab>You have <u>15 alts</u>.'
		)
	]
	public function altsTile(string $sender): ?string {
		$altInfo = $this->getAltInfo($sender, true);
		$altsCmdText = 'no alts';
		if (count($altInfo->getAllAlts()) === 2) {
			$altsCmdText = '1 alt';
		} elseif (count($altInfo->getAllAlts()) > 2) {
			$altsCmdText = (count($altInfo->getAllAlts())-1) . ' alts';
		}
		if ($altInfo->hasUnvalidatedAlts()) {
			$numUnvalidated = 0;
			foreach ($altInfo->getAllAlts() as $alt) {
				if (!$altInfo->isValidated($alt)) {
					$numUnvalidated++;
				}
			}
			if ($numUnvalidated > 1) {
				$altsCmdText .= ", {$numUnvalidated} need validation";
			} else {
				$altsCmdText .= ", {$numUnvalidated} needs validation";
			}
		}
		$altsCommand = $altsCmdText;
		if (count($altInfo->getAllAlts()) > 1) {
			$altsCommand = $this->text->makeChatcmd($altsCmdText, '/tell <myname> alts');
		}
		$blob = "<header2>Account<end>\n".
			"<tab>Your main is <highlight>{$altInfo->main}<end>\n".
			"<tab>You have {$altsCommand}.";
		return $blob;
	}

	#[
		NCA\NewsTile(
			name: 'alts-unvalidated',
			description: 'Show a notice if char has any unvalidated alts',
			example: "<header2>Unvalidated Alts [<u>see more</u>]<end>\n".
			"<tab>- Char1\n".
			'<tab>- Char2'
		)
	]
	public function unvalidatedAltsTile(string $sender): ?string {
		$altInfo = $this->getAltInfo($sender, true);
		if (!$altInfo->hasUnvalidatedAlts()) {
			return null;
		}
		$altsLink = $this->text->makeChatcmd('see more', '/tell <myname> alts');
		$blob = "<header2>Unvalidated Alts [{$altsLink}]<end>";
		foreach ($altInfo->getAllAlts() as $alt) {
			if (!$altInfo->isValidated($alt)) {
				$blob .= "\n<tab>- {$alt}";
			}
		}
		return $blob;
	}

	protected function validateAsMain(string $toValidate, AltInfo $altInfo, CommandReply $sendto): void {
		if (!isset($altInfo->alts[$toValidate])
			|| !$altInfo->alts[$toValidate]->validated_by_alt) {
			$sendto->reply("You don't have a pending alt validation request from <highlight>{$toValidate}<end>.");
			return;
		}
		if ($altInfo->isValidated($toValidate)) {
			$sendto->reply("<highlight>{$toValidate}<end> is already a validated alt of you.");
			return;
		}

		$this->db->table('alts')
			->where('alt', $toValidate)
			->where('main', $altInfo->main)
			->update(['validated_by_main' => true]);

		$this->alts[$toValidate] = $altInfo->main;
		$this->fireAltValidatedEvent($altInfo->main, $toValidate);

		$sendto->reply("<highlight>{$toValidate}<end> has been validated as your alt.");
		$this->removeMainFromBuddyListIfPossible($altInfo->main);
	}

	protected function validateAsAlt(string $toValidate, string $sender, AltInfo $altInfo, CommandReply $sendto): void {
		if (!$altInfo->isValidated($toValidate)) {
			$sendto->reply("<highlight>{$toValidate}<end> didn't request to add you as their alt.");
			return;
		}
		if (!isset($altInfo->alts[$sender]) || !$altInfo->alts[$sender]->validated_by_main) {
			$sendto->reply("<highlight>{$toValidate}<end> didn't request to add you as their alt.");
			return;
		}

		$this->db->table('alts')
			->where('alt', $sender)
			->where('main', $altInfo->main)
			->update(['validated_by_alt' => true]);

		$this->alts[$sender] = $altInfo->main;
		$this->fireAltValidatedEvent($altInfo->main, $sender);

		$audit = new Audit(
			actor: $altInfo->main,
			actee: $sender,
			action: AccessManager::ADD_ALT,
		);
		$this->accessManager->addAudit($audit);

		$sendto->reply("<highlight>{$toValidate}<end> has been validated as your main.");
		$this->buddylistManager->remove($sender, static::ALT_VALIDATE);
	}

	protected function fireAltValidatedEvent(string $main, string $alt): void {
		$event = new AltValidateEvent(
			main: $main,
			alt: $alt,
			validated: true,
		);
		$this->eventManager->fireEvent($event);
	}

	protected function declineAsMain(string $toDecline, AltInfo $altInfo, CommandReply $sendto): void {
		if (!isset($altInfo->alts[$toDecline])
			|| !$altInfo->alts[$toDecline]->validated_by_alt) {
			$sendto->reply("You don't have a pending alt validation request from <highlight>{$toDecline}<end>.");
			return;
		}
		if ($altInfo->isValidated($toDecline)) {
			$sendto->reply("<highlight>{$toDecline}<end> is already a validated alt of yours.");
		}

		$this->db->table('alts')
			->where('alt', $toDecline)
			->where('main', $altInfo->main)
			->delete();

		$this->fireAltDeclinedEvent($altInfo->main, $toDecline);

		$sendto->reply("You declined <highlight>{$toDecline}'s<end> request to become your alt.");
		$this->removeMainFromBuddyListIfPossible($altInfo->main);
	}

	protected function declineAsAlt(string $toDecline, string $sender, AltInfo $altInfo, CommandReply $sendto): void {
		if (!$altInfo->isValidated($toDecline)) {
			$sendto->reply("<highlight>{$toDecline}<end> didn't request to add you as their alt.");
			return;
		}
		if (!isset($altInfo->alts[$sender]) || !$altInfo->alts[$sender]->validated_by_main) {
			$sendto->reply("<highlight>{$toDecline}<end> didn't request to add you as their alt.");
			return;
		}

		$this->db->table('alts')
			->where('alt', $sender)
			->where('main', $altInfo->main)
			->delete();

		$this->fireAltDeclinedEvent($altInfo->main, $sender);

		$sendto->reply("You declined <highlight>{$toDecline}'s<end> request to be added as their alt.");
		$this->buddylistManager->remove($sender, static::ALT_VALIDATE);
	}

	protected function fireAltDeclinedEvent(string $main, string $alt): void {
		$event = new AltDeclineEvent(
			main: $main,
			alt: $alt,
			validated: true,
		);
		$this->eventManager->fireEvent($event);
	}

	protected function removeMainFromBuddyListIfPossible(string $main): void {
		$hasUnvalidatedAlts = $this->db->table('alts')
			->where('main', $main)->where('validated_by_main', false)->exists();
		if ($hasUnvalidatedAlts) {
			return;
		}
		$this->buddylistManager->remove($main, static::MAIN_VALIDATE);
	}

	/**
	 * Add $alts to the alt list of $main
	 * Security-wise, $user is the user triggering the command
	 *
	 * @return string[] the result message to print
	 */
	private function addAltsToMain(string $user, string $main, PCharacter ...$alts): array {
		/** @var string[] */
		$result = [];

		$uid = $this->chatBot->getUid($main);
		if (!isset($uid)) {
			$result []= "Character <highlight>{$main}<end> does not exist.";
			return $result;
		}
		$modifierAltInfo = $this->getAltInfo($user, true);
		$mainAltInfo = $this->getAltInfo($main, true);
		$selfModify = $modifierAltInfo->main === $mainAltInfo->main;
		if (!$mainAltInfo->isValidated($main)) {
			if ($selfModify) {
				$result []= 'You can only add alts on a main or validated alt.';
				return $result;
			}
			$result []= 'You can only add alts to a main or validated alt.';
			return $result;
		}
		$rights = $this->accessManager->compareCharacterAccessLevels($user, $main);
		if (!$selfModify && $rights < 0) {
			$result []= "You cannot manage someone's alts if they have a higher access level than you.";
			return $result;
		}
		$validated = !$selfModify || $this->altsRequireConfirmation === false;

		$success = 0;

		// Pop a name from the array until none are left
		foreach ($alts as $name) {
			$name = $name();
			if ($name === $main) {
				if ($selfModify) {
					$result []= 'You cannot add yourself as your own alt.';
				} else {
					$result []= "You cannot add {$name} as their own alt.";
				}
				continue;
			}

			$uid = $this->chatBot->getUid($name);
			if ($uid === null) {
				$result []= "Character <highlight>{$name}<end> does not exist.";
				continue;
			}
			$rights = $this->accessManager->compareCharacterAccessLevels($user, $name);
			if (!$selfModify && $rights < 0) {
				$result []= "{$name} has a higher access level than you.";
				continue;
			}

			$altInfo = $this->getAltInfo($name, true);
			if ($altInfo->main === $mainAltInfo->main) {
				if ($altInfo->isValidated($name)) {
					if ($selfModify) {
						$result []= "<highlight>{$name}<end> is already registered to you.";
					} else {
						$result []= "<highlight>{$name}<end> is already registered to {$main}.";
					}
				} elseif ($altInfo->alts[$name]->validated_by_main) {
					if ($selfModify) {
						$result []= "You already requested adding <highlight>{$name}<end> as your alt.";
					} else {
						$result []= "{$main} already requested adding <highlight>{$name}<end> as their alt.";
					}
				} else {
					if ($selfModify) {
						$result []= "<highlight>{$name}<end> already requested to be added as your alt.";
					} else {
						$result []= "<highlight>{$name}<end> already requested to be added as {$main}'s alt.";
					}
				}
				continue;
			}

			if (count($altInfo->alts) > 0) {
				// already registered to someone else
				if ($altInfo->main === $name) {
					$result []= "Cannot add alt, because <highlight>{$name}<end> is already registered as a main with alts.";
				} else {
					if ($altInfo->isValidated($name)) {
						$result []= "Cannot add alt, because <highlight>{$name}<end> is already registered as an alt of <highlight>{$altInfo->main}<end>.";
					} elseif ($altInfo->alts[$name]->validated_by_main) {
						$result []= "Cannot add alt, because <highlight>{$name}<end> has a pending alt add request from <highlight>{$altInfo->main}<end>.";
					} else {
						$result []= "Cannot add alt, because <highlight>{$name}<end> already requested to be an alt of <highlight>{$altInfo->main}<end>.";
					}
				}
				continue;
			}

			// insert into database
			$this->addAlt($mainAltInfo->main, $name, true, $validated);
			$success++;
			if (!$validated) {
				if ($this->buddylistManager->isOnline($name)) {
					$this->sendAltValidationRequest($name, $mainAltInfo);
				} else {
					$this->buddylistManager->addName($name, static::ALT_VALIDATE);
				}
			}

			// update character information
			async($this->playerManager->byName(...), $name)->ignore();
		}

		if ($success === 0) {
			return $result;
		}
		$s = ($success === 1 ? 's' : '');
		$numAlts = ($success === 1 ? 'Alt' : "{$success} alts");
		if ($validated) {
			$result []= "{$numAlts} added successfully.";
		} else {
			$result []= "{$numAlts} added successfully, but <highlight>require{$s} confirmation<end>. " .
			'Make sure to confirm you as their main.';
		}
		// @todo Send a warning if the alt's accesslevel is higher than ours
		return $result;
	}

	/**
	 * Set $newMain to be the new main out of $newMain's alts
	 * Security-wise, $user is the user triggering the command
	 *
	 * @return string the result message to print
	 */
	private function makeAltNewMain(string $user, string $newMain): string {
		$userAltInfo = $this->getAltInfo($user);
		$altInfo = $this->getAltInfo($newMain);
		$selfModify = $userAltInfo->main === $altInfo->main;

		if ($altInfo->main === $newMain) {
			if ($selfModify) {
				return "<highlight>{$newMain}<end> is already registered as your main.";
			}
			return "<highlight>{$newMain}<end> is already registered as their main character.";
		}

		if (!$selfModify) {
			$rightsMain = $this->accessManager->compareCharacterAccessLevels($user, $altInfo->main);
			$rightsNewMain = $this->accessManager->compareCharacterAccessLevels($user, $newMain);
			if ($rightsMain < 0 || $rightsNewMain < 0) {
				return "You cannot change someone's main if they have a higher access level than you.";
			}
		}

		if (!$altInfo->isValidated($newMain)) {
			if ($selfModify) {
				return 'You must run this command from a validated character.';
			}
			return 'You cannot make an unvalidated alt the new main.';
		}

		$this->db->awaitBeginTransaction();
		try {
			// remove all the old alt information
			$this->db->table('alts')->where('main', $altInfo->main)->delete();

			// add current main to new main as an alt
			$this->addAlt($newMain, $altInfo->main, true, true, false);

			// add current alts to new main
			foreach ($altInfo->alts as $alt => $validated) {
				if ($alt !== $newMain) {
					$this->addAlt($newMain, $alt, $validated->validated_by_main, $validated->validated_by_alt, false);
				}
			}
			$this->db->commit();
		} catch (SQLException $e) {
			$this->db->rollback();
			return 'There was a database error changing the main character. No changes were made.';
		}

		$audit = new Audit(
			actor: $newMain,
			action: AccessManager::SET_MAIN,
		);
		$this->accessManager->addAudit($audit);

		// @todo Send a warning if the new main's accesslevel is not the highest
		$event = new AltNewMainEvent(
			main: $newMain,
			alt: $altInfo->main,
			validated: true,
		);
		$this->eventManager->fireEvent($event);

		if ($selfModify) {
			return "Your main is now <highlight>{$newMain}<end>.";
		}
		return "<highlight>{$newMain}<end> is now the new main character.";
	}

	private function cacheAlts(): void {
		$this->alts = [];
		$this->db->table('alts')
			->where('validated_by_main', true)
			->where('validated_by_alt', true)
			->asObj(Alt::class)
			->filter(static fn (Alt $alt): bool => $alt->alt !== $alt->main)
			->each(function (Alt $alt): void {
				$this->alts[$alt->alt] = $alt->main;
			});
	}
}
