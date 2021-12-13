<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CmdContext,
	CommandReply,
	DB,
	DBRow,
	DBSchema\Alt,
	Event,
	EventManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	QueryBuilder,
	Registry,
	SettingManager,
	SQLException,
	Text,
	UserStateEvent,
};
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PCharacterList;
use Nadybot\Core\ParamClass\PRemove;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'alts',
 *		accessLevel   = 'member',
 *		description   = 'Alt character handling',
 *		help          = 'alts.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'altvalidate',
 *		accessLevel   = 'all',
 *		description   = 'Validate alts for admin privileges',
 *		help          = 'alts.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'altdecline',
 *		accessLevel   = 'all',
 *		description   = 'Declines being the alt of someone else',
 *		help          = 'alts.txt'
 *	)
 * @ProvidesEvent("alt(add)")
 * @ProvidesEvent("alt(del)")
 * @ProvidesEvent("alt(validate)")
 * @ProvidesEvent("alt(decline)")
 * @ProvidesEvent("alt(newmain)")
 */
class AltsController {

	public const ALT_VALIDATE = "altvalidate";
	public const MAIN_VALIDATE = "mainvalidate";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

		$this->settingManager->add(
			$this->moduleName,
			'alts_require_confirmation',
			'Adding alt requires confirmation from alt',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'mod'
		);
		$this->settingManager->add(
			$this->moduleName,
			'alts_show_org',
			'Show the org in the altlist',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'mod'
		);
		$this->settingManager->add(
			$this->moduleName,
			'alts_profession_display',
			'How to show profession in alts list',
			'edit',
			'options',
			'1',
			'off;icon;short;full;icon+short;icon+full',
			'0;1;2;4;3;5',
			'mod'
		);
		$this->settingManager->add(
			$this->moduleName,
			'alts_sort',
			'By what to sort the alts list',
			'edit',
			'options',
			'level',
			'level;name',
			'',
			'mod'
		);
	}

	/**
	 * @Event(name="connect",
	 * 	description="Add unvalidated alts/mains to friendlist")
	 */
	public function addNonValidatedAsBuddies(): void {
		$myName = ucfirst(strtolower($this->chatBot->vars["name"]));
		$this->db->table("alts")->where("validated_by_alt", false)->where("added_via", $myName)
			->asObj(Alt::class)->each(function(Alt $alt) {
				$this->buddylistManager->add($alt->alt, static::ALT_VALIDATE);
			});
		$this->db->table("alts")->where("validated_by_main", false)->where("added_via", $myName)
			->select("main")->distinct()
			->asObj()->each(function(DBRow $main) {
				$this->buddylistManager->add($main->main, static::MAIN_VALIDATE);
			});
	}

	/**
	 * This command handler adds alt characters.
	 *
	 * @HandlesCommand("alts")
	 * @Mask $action add
	 */
	public function addAltCommand(CmdContext $context, string $action, PCharacterList $names): void {
		$senderAltInfo = $this->getAltInfo($context->char->name, true);
		if (!$senderAltInfo->isValidated($context->char->name)) {
			$context->reply("You can only add alts from a main or validated alt.");
			return;
		}
		$validated = $this->settingManager->getBool('alts_require_confirmation') === false;

		$success = 0;

		// Pop a name from the array until none are left
		foreach ($names->chars as $name) {
			$name = ucfirst(strtolower($name));
			if ($name === $context->char->name) {
				$msg = "You cannot add yourself as your own alt.";
				$context->reply($msg);
				continue;
			}

			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
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
					$this->buddylistManager->add($name, static::ALT_VALIDATE);
				}
			}

			// update character information
			$this->playerManager->getByNameAsync(function() {
			}, $name);
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
	 * This command handler adds alts to another main character.
	 *
	 * @HandlesCommand("alts")
	 * @Mask $action main
	 */
	public function addMainCommand(CmdContext $context, string $action, PCharacter $main): void {
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

		$uid = $this->chatBot->get_uid($newMain);
		if (!$uid) {
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
			$this->buddylistManager->add($newMainAltInfo->main, static::MAIN_VALIDATE);
		}

		// update character information for both, main and alt
		$this->playerManager->getByNameAsync(function() {
		}, $newMain);
		$this->playerManager->getByNameAsync(function() {
		}, $context->char->name);
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
	 * This command handler removes an alt character.
	 *
	 * @HandlesCommand("alts")
	 */
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
	 * This command handler sets main character.
	 *
	 * @HandlesCommand("alts")
	 * @Mask $action setmain
	 */
	public function setMainCommand(CmdContext $context, string $action): void {
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

		$this->db->beginTransaction();
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
	 * This command handler lists alt characters.
	 *
	 * @HandlesCommand("alts")
	 */
	public function altsCommand(CmdContext $context, ?PCharacter $name): void {
		$name = isset($name) ? $name() : $context->char->name;

		$altInfo = $this->getAltInfo($name, true);
		if (count($altInfo->alts) === 0) {
			$msg = "No alts are registered for <highlight>{$name}<end>.";
			$context->reply($msg);
			return;
		}
		$altInfo->getAltsBlobAsync([$context, "reply"]);
	}

	/**
	 * This command handler validates alts or mains for admin privileges.
	 *
	 * @HandlesCommand("altvalidate")
	 */
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
	 * This command handler declines alt or main requests
	 *
	 * @HandlesCommand("altdecline")
	 */
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

	/**
	 * @Event(name="logOn",
	 * 	description="Reminds unvalidates alts/mains to accept or deny")
	 */
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
			if ($altInfo->alts[$sender]->added_via !== $this->chatBot->vars['name']) {
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
				$audit = new Audit();
				$audit->actor = $main;
				$audit->actee = $alt;
				$audit->action = AccessManager::DEL_ALT;
				$this->accessManager->addAudit($audit);
			}
		}
		return $deleted;
	}

	/**
	 * @NewsTile("alts-info")
	 * @Description("Displays basic information about your alts")
	 * @Example("<header2>Account<end>
	 * <tab>Your main is <highlight>Nady<end>
	 * <tab>You have <u>15 alts</u>.")
	 */
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

	/**
	 * @NewsTile("alts-unvalidated")
	 * @Description("Show a notice if char has any unvalidated alts")
	 * @Example("<header2>Unvalidated Alts [<u>see more</u>]<end>
	 * <tab>- Char1
	 * <tab>- Char2")
	 */
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
