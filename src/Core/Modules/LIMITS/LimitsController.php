<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS;

use Nadybot\Core\{
	AccessManager,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Util,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\{
	PlayerHistoryManager,
	PlayerManager,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class LimitsController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public PlayerManager $playerManager;
	
	/** @Inject */
	public PlayerHistoryManager $playerHistoryManager;
	
	/** @Inject */
	public Util $util;

	/** @Inject */
	public RateIgnoreController $rateIgnoreController;
	
	/** @Logger */
	public LoggerWrapper $logger;
	
	/**
	 * @Setup
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			"tell_req_lvl",
			"Minimum level required to send tell to bot",
			"edit",
			"number",
			"0",
			"0;10;50;100;150;190;205;215"
		);
		$this->settingManager->add(
			$this->moduleName,
			"tell_req_faction",
			"Faction required to send tell to bot",
			"edit",
			"options",
			"all",
			"all;Omni;Neutral;Clan;not Omni;not Neutral;not Clan"
		);
		$this->settingManager->add(
			$this->moduleName,
			"tell_min_player_age",
			"Minimum age of player to send tell to bot",
			"edit",
			"time",
			"1s",
			"1s;7days;14days;1month;2months;6months;1year;2years",
			'',
			'mod',
			'limits.txt'
		);
		$this->settingManager->add(
			$this->moduleName,
			"tell_error_msg_type",
			"How to show error messages when limit requirements are not met",
			"edit",
			"options",
			"2",
			"Specific;Generic;None",
			"2;1;0"
		);
	}
	
	/**
	 * Check if $sender is allowed to send $message
	 */
	public function check(string $sender, string $message): bool {
		if (
			preg_match("/^about$/i", $message)
			|| $this->rateIgnoreController->check($sender)
			|| $sender === ucfirst(strtolower($this->settingManager->get("relaybot")))
			// if access level is at least member, skip checks
			|| $this->accessManager->checkAccess($sender, 'member')
			|| ($msg = $this->getAccessErrorMessage($sender)) === null
		) {
			return true;
		}
	
		$this->logger->log('Info', "$sender denied access to bot due to: $msg");

		$this->handleLimitCheckFail($msg, $sender);

		$cmd = explode(' ', $message, 2)[0];
		$cmd = strtolower($cmd);

		if ($this->settingManager->getBool('access_denied_notify_guild')) {
			$this->chatBot->sendGuild("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end> due to limit checks.", true);
		}
		if ($this->settingManager->getBool('access_denied_notify_priv')) {
			$this->chatBot->sendPrivate("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end> due to limit checks.", true);
		}

		return false;
	}
	
	/**
	 * React to a $sender being denied to send $msg to us
	 */
	public function handleLimitCheckFail(string $msg, string $sender): void {
		if ($this->settingManager->getInt('tell_error_msg_type') === 2) {
			$this->chatBot->sendTell($msg, $sender);
		} elseif ($this->settingManager->getInt('tell_error_msg_type') === 1) {
			$msg = "Error! You do not have access to this bot.";
			$this->chatBot->sendTell($msg, $sender);
		}
	}

	/**
	 * Check if $sender is allowed to run commands on the bot
	 */
	public function getAccessErrorMessage(string $sender): ?string {
		$tellReqFaction = $this->settingManager->get('tell_req_faction');
		$tellReqLevel = $this->settingManager->getInt('tell_req_lvl');
		if ($tellReqLevel > 0 || $tellReqFaction !== "all") {
			// get player info which is needed for following checks
			$whois = $this->playerManager->getByName($sender);
			if ($whois === null) {
				return "Error! Unable to get your character info for limit checks. Please try again later.";
			}

			// check minlvl
			if ($tellReqLevel > 0 && $tellReqLevel > $whois->level) {
				return "Error! You must be at least level <highlight>$tellReqLevel<end>.";
			}

			// check faction limit
			if (
				in_array($tellReqFaction, ["Omni", "Clan", "Neutral"])
				&& $tellReqFaction !== $whois->faction
			) {
				return "Error! You must be <".strtolower($tellReqFaction).">$tellReqFaction<end>.";
			}
			if (in_array($tellReqFaction, ["not Omni", "not Clan", "not Neutral"])) {
				$tmp = explode(" ", $tellReqFaction);
				if ($tmp[1] === $whois->faction) {
					return "Error! You must not be <".strtolower($tmp[1]).">{$tmp[1]}<end>.";
				}
			}
		}
		
		// check player age
		if ($this->settingManager->getInt("tell_min_player_age") > 1) {
			$history = $this->playerHistoryManager->lookup($sender, (int)$this->chatBot->vars['dimension']);
			if ($history === null) {
				return "Error! Unable to get your character history for limit checks. Please try again later.";
			}
			$minAge = time() - $this->settingManager->getInt("tell_min_player_age");
			$entry = array_pop($history->data);
			// TODO check for rename

			if ($entry->last_changed > $minAge) {
				$timeString = $this->util->unixtimeToReadable($this->settingManager->getInt("tell_min_player_age"));
				return "Error! You must be at least <highlight>$timeString<end> old.";
			}
		}
		
		return null;
	}
}
