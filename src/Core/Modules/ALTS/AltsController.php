<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use function Amp\Promise\rethrow;

use Generator;
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
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	QueryBuilder,
	Registry,
	SQLException,
	Text,
	UserStateEvent,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "alts",
		accessLevel: "member",
		description: "Alt character handling",
	),
	NCA\DefineCommand(
		command: "altvalidate",
		accessLevel: "all",
		description: "Validate alts for admin privileges",
	),
	NCA\DefineCommand(
		command: "altdecline",
		accessLevel: "all",
		description: "Declines being the alt of someone else",
	),

	NCA\ProvidesEvent("alt(add)"),
	NCA\ProvidesEvent("alt(del)"),
	NCA\ProvidesEvent("alt(validate)"),
	NCA\ProvidesEvent("alt(decline)"),
	NCA\ProvidesEvent("alt(newmain)"),
	NCA\HasMigrations
]
class AltsController extends ModuleInstance {
	public const ALT_VALIDATE = "altvalidate";
	public const MAIN_VALIDATE = "mainvalidate";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

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
	#[NCA\Setting\Options(options: ["level", "name"])]
	public string $altsSort = 'level';

	/** @var array<string,string> */
	private array $alts = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->cacheAlts();
	}

	private function cacheAlts(): void {
		$this->alts = [];
		$this->db->table("alts")
			->where("added_via", $this->db->getMyname())
			->where("validated_by_main", true)
			->where("validated_by_alt", true)
			->asObj(Alt::class)
			->each(function (Alt $alt): void {
				$this->alts[$alt->alt] = $alt->main;
			});
	}

	public function getMainOf(string $char): string {
		return $this->alts[$char] ?? $char;
	}

	/** @return string[] */
	public function getAltsOf(string $char): array {
		$alts = [$char];
		foreach ($this->alts as $alt => $main) {
			if ($main === $char) {
				$alts []= $alt;
			}
		}
		return $alts;
	}

	#[NCA\Event(
		name: "connect",
		description: "Add unvalidated alts/mains to friendlist"
	)]
	public function addNonValidatedAsBuddies(): Generator {
		$myName = ucfirst(strtolower($this->chatBot->char->name));
		yield $this->db->table("alts")->where("validated_by_alt", false)->where("added_via", $myName)
			->asObj(Alt::class)->map(function(Alt $alt) {
				return $this->buddylistManager->addAsync($alt->alt, static::ALT_VALIDATE);
			})->toArray();
		yield $this->db->table("alts")->where("validated_by_main", false)->where("added_via", $myName)
			->select("main")->distinct()
			->pluckAs("main", "string")
			->map(function(string $main) {
				return $this->buddylistManager->addAsync($main, static::MAIN_VALIDATE);
			})->toArray();
	}

	/**
	 * Add one or more alts to your main
	 */
	#[NCA\HandlesCommand("alts")]
	#[NCA\Help\Group("alts")]
	#[NCA\Help\Epilogue(
		"<header2>Validation after '<symbol>alts add'<end>\n\n".
		"If <a href='chatcmd:///tell <myname> settings change alts_require_confirmation'>alts require confirmation</a> is on (default is on), then the\n".
		"main character who ran '<symbol>alts add' is unvalidated.\n\n".
		"In order to confirm an unvalidated main and share access level with them,\n".
		"you need to run\n".
		"<tab><highlight><symbol>altvalidate &lt;name of the main&gt;<end> on your alt.\n\n".
		"But don't worry, once you logon with the alt, you should automatically receive\n".
		"a request from the bot to confirm or decline your main."
	)]
	public function addAltCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter ...$names
	): Generator {
		$senderAltInfo = $this->getAltInfo($context->char->name, true);
		if (!$senderAltInfo->isValidated($context->char->name)) {
			$context->reply("You can only add alts from a main or validated alt.");
			return;
		}
		$validated = $this->altsRequireConfirmation === false;

		$success = 0;

		// Pop a name from the array until none are left
		foreach ($names as $name) {
			$name = $name();
			if ($name === $context->char->name) {
				$msg = "You cannot add yourself as your own alt.";
				$context->reply($msg);
				continue;
			}

			$uid = yield $this->chatBot->getUid2($name);
			if ($uid === null) {
				$msg = "Character <highlight>{$name}<end> does not exist.";
				$context->reply($msg);
				continue;
			}

			$altInfo = $this->getAltInfo($name, true);
			if ($altInfo->main === $senderAltInfo->main) {
				if ($altInfo->isValidated($name)) {
					$msg = "<highlight>{$name}<end> is already registered to you.";
				} elseif ($altInfo->alts[$name]->validated_by_main) {
					$msg = "You already requested adding <highlight>{$name}<end> as an alt.";
				} else {
					$msg = "<highlight>{$name}<end> already requested to be added as your alt.";
				}
				$context->reply($msg);
				continue;
			}

			if (count($altInfo->alts) > 0) {
				// already registered to someone else
				if ($altInfo->main === $name) {
					$msg = "Cannot add alt, because <highlight>{$name}<end> is already registered as a main with alts.";
				} else {
					if ($altInfo->isValidated($name)) {
						$msg = "Cannot add alt, because <highlight>{$name}<end> is already registered as an alt of <highlight>{$altInfo->main}<end>.";
					} elseif ($altInfo->alts[$name]->validated_by_main) {
						$msg = "Cannot add alt, because <highlight>{$name}<end> has a pending alt add request from <highlight>{$altInfo->main}<end>.";
					} else {
						$msg = "Cannot add alt, because <highlight>{$name}<end> already requested to be an alt of <highlight>{$altInfo->main}<end>.";
					}
				}
				$context->reply($msg);
				continue;
			}

			// insert into database
			$this->addAlt($senderAltInfo->main, $name, true, $validated);
			$success++;
			if (!$validated) {
				if ($this->buddylistManager->isOnline($name)) {
					$this->sendAltValidationRequest($name, $senderAltInfo);
				} else {
					yield $this->buddylistManager->addAsync($name, static::ALT_VALIDATE);
				}
			}

			// update character information
			rethrow($this->playerManager->byName($name));
		}

		if ($success === 0) {
			return;
		}
		$s = ($success === 1 ? "s" : "");
		$numAlts = ($success === 1 ? "Alt" : "$success alts");
		if ($validated) {
			$msg = "{$numAlts} added successfully.";
		} else {
			$msg = "{$numAlts} added successfully, but <highlight>require{$s} confirmation<end>. ".
				"Make sure to confirm you as their main.";
		}
		// @todo Send a warning if the alt's accesslevel is higher than ours
		$context->reply($msg);
	}

	/**
	 * Add yourself as an alt of another main character
	 */
	#[NCA\HandlesCommand("alts")]
	#[NCA\Help\Group("alts")]
	#[NCA\Help\Epilogue(
		"<header2>Validation after 'alts main'<end>\n\n".
		"Alts added to someone's altlist via '<symbol>alts main' are unvalidated.\n\n".
		"Unvalidated alts do not <a href='chatcmd:///tell <myname> settings change alts_inherit_admin'>inherit</a> the main character's access level.\n\n".
		"In order to confirm an unvalidated alt and share access level with them,\n".
		"you need to run\n".
		"<tab><highlight><symbol>altvalidate &lt;name of the alt&gt;<end> on your main.\n\n".
		"You should automatically receive a request for this after login."
	)]
	public function addMainCommand(
		CmdContext $context,
		#[NCA\Str("main")] string $action,
		PCharacter $main
	): Generator {
		$newMain = $main();

		if ($newMain === $context->char->name) {
			$msg = "You cannot add yourself as your own alt.";
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
			$msg = "You can only request to be added to a main, if you are not ".
				"someone's alt already and don't have alts yourself - pending or not.";
			$context->reply($msg);
			return;
		}

		$uid = yield $this->chatBot->getUid2($newMain);
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
			yield $this->buddylistManager->addAsync($newMainAltInfo->main, static::MAIN_VALIDATE);
		}

		// update character information for both, main and alt
		rethrow($this->playerManager->byName($newMain));
		rethrow($this->playerManager->byName($context->char->name));
		// @todo Send a warning if the new main's accesslevel is lower than ours

		$msg = "Successfully requested to be added as <highlight>{$newMain}'s<end> alt. ".
			"Make sure to confirm the request on <highlight>";
		if (isset($sentTo)) {
			$msg .= "{$sentTo}<end>.";
		} elseif (count($newMainAltInfo->getAllValidatedAlts())) {
			$msg .= "{$newMain}<end> or one of their validated alts.";
		} else {
			$msg .= "{$newMain}<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * Remove one of your alts
	 */
	#[NCA\HandlesCommand("alts")]
	#[NCA\Help\Group("alts")]
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
			$msg = "You must be on a validated alt to remove an alt that is not yourself.";
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

	/**
	 * Set your current character as your main
	 */
	#[NCA\HandlesCommand("alts")]
	#[NCA\Help\Group("alts")]
	public function setMainCommand(
		CmdContext $context,
		#[NCA\Str("setmain")] string $action
	): Generator {
		$newMain = $context->char->name;
		$altInfo = $this->getAltInfo($newMain);

		if ($altInfo->main === $newMain) {
			$msg = "<highlight>{$newMain}<end> is already registered as your main.";
			$context->reply($msg);
			return;
		}

		if (!$altInfo->isValidated($newMain)) {
			$msg = "You must run this command from a validated character.";
			$context->reply($msg);
			return;
		}

		yield $this->db->awaitBeginTransaction();
		try {
			// remove all the old alt information
			$this->db->table("alts")->where("main", $altInfo->main)->delete();

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
			$context->reply("There was a database error changing your main. No changes were made.");
			return;
		}

		$audit = new Audit();
		$audit->actor = $newMain;
		$audit->action = AccessManager::SET_MAIN;
		$this->accessManager->addAudit($audit);

		// @todo Send a warning if the new main's accesslevel is not the highest
		$event = new AltEvent();
		$event->main = $newMain;
		$event->alt = $altInfo->main;
		$event->type = 'alt(newmain)';
		$this->eventManager->fireEvent($event);

		$msg = "Your main is now <highlight>{$newMain}<end>.";
		$context->reply($msg);
	}

	/**
	 * List your or &lt;name&gt;'s alts
	 */
	#[NCA\HandlesCommand("alts")]
	#[NCA\Help\Group("alts")]
	public function altsCommand(CmdContext $context, ?PCharacter $name): Generator {
		$name = isset($name) ? $name() : $context->char->name;

		$altInfo = $this->getAltInfo($name, true);
		if (count($altInfo->alts) === 0) {
			$msg = "No alts are registered for <highlight>{$name}<end>.";
			$context->reply($msg);
			return;
		}
		$context->reply(yield $altInfo->getAltsBlob());
	}

	/**
	 * Validate an alt or main
	 */
	#[NCA\HandlesCommand("altvalidate")]
	#[NCA\Help\Group("alts")]
	#[NCA\Help\Prologue(
		"Alts added to someone's altlist via '<symbol>alts main' are unvalidated, and cannot ".
		"<a href='chatcmd:///tell <myname> settings change alts_inherit_admin'>inherit</a> ".
		"admin privileges.\n\n".
		"This command allows the main of an altlist (or another validated character ".
		"at your bot owner's ".
		"<a href='chatcmd:///tell <myname> settings change validate_from_validated_alt'>discretion</a>) ".
		"to validate characters on your altlist, enabling them to inherit the main's admin ".
		"privileges."
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

		$this->db->table("alts")
			->where("alt", $toValidate)
			->where("main", $altInfo->main)
			->update(["validated_by_main" => true]);

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

		$this->db->table("alts")
			->where("alt", $sender)
			->where("main", $altInfo->main)
			->update(["validated_by_alt" => true]);

		$this->alts[$sender] = $altInfo->main;
		$this->fireAltValidatedEvent($altInfo->main, $sender);

		$audit = new Audit();
		$audit->actor = $altInfo->main;
		$audit->actee = $sender;
		$audit->action = AccessManager::ADD_ALT;
		$this->accessManager->addAudit($audit);

		$sendto->reply("<highlight>$toValidate<end> has been validated as your main.");
		$this->buddylistManager->remove($sender, static::ALT_VALIDATE);
	}

	protected function fireAltValidatedEvent(string $main, string $alt): void {
		$event = new AltEvent();
		$event->main = $main;
		$event->alt = $alt;
		$event->validated = true;
		$event->type = 'alt(validate)';
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Declines an alt or main requests
	 */
	#[NCA\HandlesCommand("altdecline")]
	#[NCA\Help\Group("alts")]
	public function altDeclineCommand(CmdContext $context, PCharacter $name): void {
		$altInfo = $this->getAltInfo($context->char->name, true);
		$toDecline = $name();

		if ($altInfo->isValidated($context->char->name)) {
			$this->declineAsMain($toDecline, $altInfo, $context);
		} else {
			$this->declineAsAlt($toDecline, $context->char->name, $altInfo, $context);
		}
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

		$this->db->table("alts")
			->where("alt", $toDecline)
			->where("main", $altInfo->main)
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

		$this->db->table("alts")
			->where("alt", $sender)
			->where("main", $altInfo->main)
			->delete();

		$this->fireAltDeclinedEvent($altInfo->main, $sender);

		$sendto->reply("You declined <highlight>{$toDecline}'s<end> request to be added as their alt.");
		$this->buddylistManager->remove($sender, static::ALT_VALIDATE);
	}

	protected function fireAltDeclinedEvent(string $main, string $alt): void {
		$event = new AltEvent();
		$event->main = $main;
		$event->alt = $alt;
		$event->validated = true;
		$event->type = 'alt(decline)';
		$this->eventManager->fireEvent($event);
	}

	protected function removeMainFromBuddyListIfPossible(string $main): void {
		$hasUnvalidatedAlts = $this->db->table("alts")
			->where("main", $main)->where("validated_by_main", false)->exists();
		if ($hasUnvalidatedAlts) {
			return;
		}
		$this->buddylistManager->remove($main, static::MAIN_VALIDATE);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Reminds unvalidates alts/mains to accept or deny"
	)]
	public function checkUnvalidatedAltsEvent(UserStateEvent $eventObj): void {
		if (!$this->chatBot->isReady() || !is_string($eventObj->sender)) {
			return;
		}
		$sender = $eventObj->sender;
		$altInfo = $this->getAltInfo($sender, true);
		if (!$altInfo->hasUnvalidatedAlts()) {
			return;
		}
		if (!$altInfo->isValidated($sender)) {
			if ($altInfo->alts[$sender]->added_via !== $this->chatBot->char->name) {
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

	/**
	 * Send $sender a request to confirm that they are $altInfo->main's alt
	 */
	public function sendAltValidationRequest(string $sender, AltInfo $altInfo): void {
		$blob = "<header2>Are you an alt of {$altInfo->main}?<end>\n";
		$blob .= "<tab>We received a request from <highlight>{$altInfo->main}<end> ".
			"to add you as their alt.\n\n";
		$blob .= "<tab>Do you agree to this: ";
		$blob .= "[".
			$this->text->makeChatcmd("yes", "/tell <myname> altvalidate {$altInfo->main}").
			"] [".
			$this->text->makeChatcmd("no", "/tell <myname> altdecline {$altInfo->main}").
			"]";
		$msg = "{$altInfo->main} requested to add you as their alt :: ".
			((array)$this->text->makeBlob("decide", $blob, "Decide if you are {$altInfo->main}'s alt"))[0];
		$this->chatBot->sendTell($msg, $sender);
	}

	/**
	 * Send $main a request to confirm that a given list of users are their alts
	 */
	public function sendMainValidationRequest(string $main, string ...$alts): void {
		$plural = (count($alts) > 1) ? "s" : "";
		$blob = "<header2>Alt confirmation<end>\n".
			"<tab>We received a request from " . count($alts) . " player{$plural} ".
			"to be added to your alt list.\n\n".
			"<header2>Choose what to do<end>\n";
		foreach ($alts as $alt) {
			$blob .= "<tab> [".
				$this->text->makeChatcmd("confirm", "/tell <myname> altvalidate {$alt}").
				"] [".
				$this->text->makeChatcmd("decline", "/tell <myname> altdecline {$alt}").
				"] {$alt}\n";
		}
		$msg = "You have <highlight>" . count($alts) . "<end> unanswered ".
			"alt request{$plural} :: ".
			((array)$this->text->makeBlob("decide", $blob, "Decide who is your alt"))[0];
		$this->chatBot->sendTell($msg, $main);
	}

	/**
	 * Get information about the mains and alts of a player
	 * @param string $player The name of either the main or one of their alts
	 * @return AltInfo Information about the main and the alts
	 */
	public function getAltInfo(string $player, bool $includePending=false): AltInfo {
		$player = ucfirst(strtolower($player));

		$ai = new AltInfo();
		Registry::injectDependencies($ai);
		$ai->main = $player;
		$query = $this->db->table("alts")
			->where(function(QueryBuilder $query) use ($includePending, $player) {
				$query->where("main", $player)
					->orWhere("main", function(QueryBuilder $subQuery) use ($player, $includePending) {
						$subQuery->from("alts")->where("alt", $player)->select("main");
						if (!$includePending) {
							$subQuery->where("validated_by_main", true)
								->where("validated_by_alt", true);
						}
					});
			});
		if (!$includePending) {
			$query->where("validated_by_main", true)->where("validated_by_alt", true);
		}
		$query->asObj(Alt::class)->each(function(Alt $row) use ($ai) {
			$ai->main = $row->main;
			$ai->alts[$row->alt] = new AltValidationStatus();
			$ai->alts[$row->alt]->validated_by_alt = $row->validated_by_alt??false;
			$ai->alts[$row->alt]->validated_by_main = $row->validated_by_main??false;
			$ai->alts[$row->alt]->added_via = $row->added_via ?? $this->db->getMyname();
		});

		return $ai;
	}

	/**
	 * This method adds given $alt as $main's alt character.
	 */
	public function addAlt(string $main, string $alt, bool $validatedByMain, bool $validatedByAlt, bool $sendEvent=true): int {
		$main = ucfirst(strtolower($main));
		$alt = ucfirst(strtolower($alt));

		$added = $this->db->table("alts")
			->insert([
				"alt" => $alt,
				"main" => $main,
				"validated_by_main" => $validatedByMain,
				"validated_by_alt" => $validatedByAlt,
				"added_via" => $this->db->getMyname(),
			]);
		if ($added && $sendEvent) {
			$event = new AltEvent();
			$event->main = $main;
			$event->alt = $alt;
			$event->validated = $validatedByAlt && $validatedByMain;
			$event->type = 'alt(add)';
			$this->eventManager->fireEvent($event);
		}
		if ($validatedByAlt && $validatedByMain) {
			$this->alts[$alt] = $main;
			$audit = new Audit();
			$audit->actor = $main;
			$audit->actee = $alt;
			$audit->action = AccessManager::ADD_ALT;
			$this->accessManager->addAudit($audit);
		}
		return $added ? 1 : 0;
	}

	/**
	 * This method removes given a $alt from being $main's alt character.
	 */
	public function remAlt(string $main, string $alt): int {
		/** @var ?Alt */
		$old = $this->db->table("alts")
			->where("alt", $alt)
			->where("main", $main)
			->asObj(Alt::class)
			->first();
		$deleted = $this->db->table("alts")
			->where("alt", $alt)
			->where("main", $main)
			->delete();
		if ($deleted > 0) {
			$event = new AltEvent();
			$event->main = $main;
			$event->alt = $alt;
			$event->type = 'alt(del)';
			$this->eventManager->fireEvent($event);

			if (isset($old) && $old->validated_by_alt && $old->validated_by_main) {
				unset($this->alts[$alt]);
				$audit = new Audit();
				$audit->actor = $main;
				$audit->actee = $alt;
				$audit->action = AccessManager::DEL_ALT;
				$this->accessManager->addAudit($audit);
			}
		}
		return $deleted;
	}

	#[
		NCA\NewsTile(
			name: "alts-info",
			description: "Displays basic information about your alts",
			example:
				"<header2>Account<end>\n".
				"<tab>Your main is <highlight>Nady<end>\n".
				"<tab>You have <u>15 alts</u>."
		)
	]
	public function altsTile(string $sender, callable $callback): void {
		$altInfo = $this->getAltInfo($sender, true);
		$altsCmdText = "no alts";
		if (count($altInfo->getAllAlts()) === 2) {
			$altsCmdText = "1 alt";
		} elseif (count($altInfo->getAllAlts()) > 2) {
			$altsCmdText = (count($altInfo->getAllAlts())-1) . " alts";
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
			$altsCommand = $this->text->makeChatcmd($altsCmdText, "/tell <myname> alts");
		}
		$blob = "<header2>Account<end>\n".
			"<tab>Your main is <highlight>{$altInfo->main}<end>\n".
			"<tab>You have {$altsCommand}.";
		$callback($blob);
	}

	#[
		NCA\NewsTile(
			name: "alts-unvalidated",
			description: "Show a notice if char has any unvalidated alts",
			example:
				"<header2>Unvalidated Alts [<u>see more</u>]<end>\n".
			"<tab>- Char1\n".
			"<tab>- Char2"
		)
	]
	public function unvalidatedAltsTile(string $sender, callable $callback): void {
		$altInfo = $this->getAltInfo($sender, true);
		if (!$altInfo->hasUnvalidatedAlts()) {
			$callback(null);
			return;
		}
		$altsLink = $this->text->makeChatcmd("see more", "/tell <myname> alts");
		$blob = "<header2>Unvalidated Alts [{$altsLink}]<end>";
		foreach ($altInfo->getAllAlts() as $alt) {
			if (!$altInfo->isValidated($alt)) {
				$blob .= "\n<tab>- {$alt}";
			}
		}
		$callback($blob);
	}
}
