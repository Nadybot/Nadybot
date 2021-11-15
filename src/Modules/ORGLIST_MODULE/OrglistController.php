<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\{
	BuddylistManager,
	CmdContext,
	CommandReply,
	DB,
	Event,
	Nadybot,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;

/**
 * @author Tyrence (RK2)
 * @author Lucier (RK1)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'orglist',
 *		accessLevel = 'guild',
 *		description = 'Check an org roster',
 *		help        = 'orglist.txt'
 *	)
 */
class OrglistController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public GuildManager $guildManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public FindOrgController $findOrgController;

	protected ?Orglist $orglist = null;

	/** @var array<string,string[]> */
	protected array $orgrankmap = [
		"Anarchism"  => ["Anarchist"],
		"Monarchy"   => ["Monarch",   "Counsil",      "Follower"],
		"Feudalism"  => ["Lord",      "Knight",       "Vassal",          "Peasant"],
		"Republic"   => ["President", "Advisor",      "Veteran",         "Member",         "Applicant"],
		"Faction"    => ["Director",  "Board Member", "Executive",       "Member",         "Applicant"],
		"Department" => ["President", "General",      "Squad Commander", "Unit Commander", "Unit Leader", "Unit Member", "Applicant"],
	];

	/** Get a hierarchical array of all the ranks in the goven governing form */
	public function getOrgRanks(string $governingForm): array {
		return $this->orgrankmap[ucfirst(strtolower($governingForm))] ?? [];
	}

	/**
	 * @HandlesCommand("orglist")
	 * @Mask $action end
	 */
	public function orglistEndCommand(CmdContext $context, string $action): void {
		if (isset($this->orglist)) {
			$this->orglistEnd();
		} else {
			$context->reply("There is no orglist currently running.");
		}
	}

	/**
	 * @HandlesCommand("orglist")
	 */
	public function orglistCommand(CmdContext $context, string $search): void {
		if (preg_match("/^\d+$/", $search)) {
			$this->checkOrglist((int)$search, $context);
			return;
		}
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$this->getMatches(
			$search,
			function(array $orgs) use ($context, $search): void {
				$count = count($orgs);

				if ($count === 0) {
					$msg = "Could not find any orgs (or players in orgs) that match <highlight>$search<end>.";
					$context->reply($msg);
				} elseif ($count === 1) {
					$this->checkOrglist($orgs[0]->id, $context);
				} else {
					$blob = $this->findOrgController->formatResults($orgs);
					$msg = $this->text->makeBlob("Org Search Results for '{$search}' ($count)", $blob);
					$context->reply($msg);
				}
			}
		);
	}

	/**
	 * @psalm-param callable(list<Organization>, mixed...) $callback
	 */
	public function getMatches(string $search, callable $callback): void {
		$orgs = $this->findOrgController->lookupOrg($search);
		if (count($orgs) === 1 && strcasecmp($orgs[0]->name, $search) === 0) {
			$callback($orgs);
			return;
		}

		// check if search is a character and add character's org to org list if it's not already in the list
		$name = ucfirst(strtolower($search));
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($orgs, $callback): void {
				if ($whois === null || $whois->guild_id === 0 || $whois->guild_id === null) {
					$callback($orgs);
					return;
				}
				foreach ($orgs as $org) {
					if ($org->id === $whois->guild_id) {
						$callback($orgs);
						return;
					}
				}

				$obj = $this->findOrgController->getByID($whois->guild_id);
				if (isset($obj)) {
					$orgs []= $obj;
				}
				$callback($orgs);
			},
			$name
		);
	}

	public function checkOrglist(int $orgid, CommandReply $sendto): void {
		// Check if we are already doing a list.
		if (isset($this->orglist)) {
			$msg = "There is already an orglist running. You may force it to end by using <symbol>orglist end.";
			$sendto->reply($msg);
			return;
		}

		$this->orglist = new Orglist();
		$this->orglist->start = time();
		$this->orglist->org = "Org #{$orgid}";
		$this->orglist->sendto = $sendto;

		$sendto->reply("Downloading org roster for org id $orgid...");

		$this->guildManager->getByIdAsync($orgid, null, false, [$this, "checkOrg"], $sendto);
	}

	public function checkOrg(?Guild $org, CommandReply $sendto): void {
		if ($org === null || !isset($this->orglist)) {
			$msg = "Error in getting the Org info. Either org does not exist or AO's server was too slow to respond.";
			$sendto->reply($msg);
			unset($this->orglist);
			return;
		}

		$this->orglist->org = $org->orgname;
		if (isset($org->governing_form) && isset($this->orgrankmap[$org->governing_form])) {
			$this->orglist->orgtype = $this->orgrankmap[$org->governing_form];
		} else {
			$this->orglist->orgtype = $this->getOrgGoverningForm($org->members);
		}

		// Check each name if they are already on the buddylist (and get online status now)
		// Or make note of the name so we can add it to the buddylist later.
		foreach ($org->members as $member) {
			// Writing the whois info for all names
			// Name (Level 1/1, Sex Breed Profession)
			$thismember  = '<highlight>'.$member->name.'<end>';
			if (isset($member->level)) {
				$thismember .= " (Level <highlight>{$member->level}<end>";
			}
			if ($member->ai_level > 0) {
				$thismember .= "<green>/{$member->ai_level}<end>";
			}
			$thismember .= ", ".$member->gender;
			$thismember .= " ".$member->breed;
			if (isset($member->profession)) {
				$thismember .= " <highlight>{$member->profession}<end>)";
			}

			$this->orglist->result[$member->name] = new OrglistResult();
			$this->orglist->result[$member->name]->post = $thismember;

			$this->orglist->result[$member->name]->name = $member->name;
			$this->orglist->result[$member->name]->rank_id = $member->guild_rank_id;
		}

		$sendto->reply("Checking online status for " . count($org->members) ." members of <highlight>$org->orgname<end>…");

		$this->checkOnline($org->members, function(): void {
			$this->addOrgMembersToBuddylist();

			if (isset($this->orglist) && count($this->orglist->added) === 0) {
				$this->orglistEnd();
			}
		});
	}

	/**
	 * @param array<string,Player> $members
	 * @return string[]
	 */
	public function getOrgGoverningForm(array $members): array {
		$forms = $this->orgrankmap;
		foreach ($members as $member) {
			foreach ($forms as $name => $ranks) {
				if (!isset($member->guild_rank_id) || $ranks[$member->guild_rank_id] !== $member->guild_rank) {
					unset($forms[$name]);
				}
			}
			if (count($forms) === 1) {
				break;
			}
		}

		// it's possible we haven't narrowed it down to 1 at this point
		// If we haven't found the org yet, it can only be
		// Republic or Department with only a president.
		// choose the first one
		return array_shift($forms);
	}

	/**
	 * @param array<string,Player> $members
	 */
	public function checkOnline(array $members, callable $callback): void {
		if (!isset($this->orglist)) {
			$callback();
			return;
		}
		$jobsTotal = count($members);
		$async = false;
		foreach ($members as $member) {
			$buddyOnlineStatus = $this->buddylistManager->isOnline($member->name);
			if ($buddyOnlineStatus !== null) {
				$this->orglist->result[$member->name]->online = $buddyOnlineStatus;
				$jobsTotal--;
			} elseif ($this->chatBot->vars["name"] == $member->name) {
				$this->orglist->result[$member->name]->online = true;
				$jobsTotal--;
			} else {
				$async = true;
				// check if they exist
				$this->chatBot->getUid(
					$member->name,
					function (?int $uid, string $name, callable $callback) use (&$jobsTotal): void {
						$jobsTotal--;
						if (isset($uid) && isset($this->orglist)) {
							$this->orglist->check[$name] = true;
						}
						if ($jobsTotal === 0) {
							$callback();
						}
					},
					$member->name,
					$callback
				);
			}
		}
		if (!$async && $jobsTotal === 0) {
			$callback();
		}
	}

	public function addOrgMembersToBuddylist(): void {
		if (!isset($this->orglist)) {
			return;
		}
		foreach ($this->orglist->check as $name => $value) {
			if (!$this->checkBuddylistSize()) {
				return;
			}

			$this->orglist->added[$name] = true;
			unset($this->orglist->check[$name]);
			$this->buddylistManager->add($name, 'onlineorg');
		}
	}

	public function orglistEnd(): void {
		if (!isset($this->orglist)) {
			return;
		}
		$orgcolor = ["offline" => "<font color='#555555'>"];

		$msg = $this->orgmatesformat($this->orglist, $orgcolor, $this->orglist->start);
		$this->orglist->sendto->reply($msg);

		// in case it was ended early
		foreach ($this->orglist->added as $name => $value) {
			$this->buddylistManager->remove($name, 'onlineorg');
		}
		unset($this->orglist);
	}

	/**
	 * @return string[]
	 */
	public function orgmatesformat(Orglist $memberlist, array $orgcolor, int $timestart): array {
		$map = $memberlist->orgtype ?? [];

		$totalonline = 0;
		$totalcount = count($memberlist->result);
		$newlist = [];
		foreach ($memberlist->result as $amember) {
			if (!isset($amember->rank_id)) {
				continue;
			}
			$newlist[$amember->rank_id] ??= [];
			$newlist[$amember->rank_id] []= $amember->name;
		}

		$blob = '';

		for ($rankid = 0; $rankid < count($map); $rankid++) {
			$onlinelist = "";
			$offlinelist = "<tab>";
			$olcount = 0;
			$rank_online = 0;
			$rank_total = count($newlist[$rankid]);

			if ($rank_total > 0) {
				sort($newlist[$rankid]);
				for ($i = 0; $i < $rank_total; $i++) {
					if ($memberlist->result[$newlist[$rankid][$i]]->online ?? false) {
						$rank_online++;
						$onlinelist .= "<tab>" . $memberlist->result[$newlist[$rankid][$i]]->post . "\n";
					} else {
						if ($offlinelist !== "<tab>") {
							$offlinelist .= ", ";
							if (($olcount % 50) == 0) {
								$offlinelist .= "<end><pagebreak>" . $orgcolor["offline"];
							}
						}
						$offlinelist .= $newlist[$rankid][$i];
						$olcount++;
					}
				}
			}

			$totalonline += $rank_online;

			$blob .= "\n<header2>" . $map[$rankid] . "<end> ({$rank_online} / {$rank_total})\n";

			if ($onlinelist != "") {
				$blob .= $onlinelist;
			}
			if ($offlinelist != "") {
				$blob .= $orgcolor["offline"] . $offlinelist . "<end>\n";
			}
			$blob .= "\n";
		}

		$totaltime = time() - $timestart;
		$blob .= "\nLookup took $totaltime seconds.";

		return (array)$this->text->makeBlob("Orglist for '{$memberlist->org}' ($totalonline / $totalcount)", $blob);
	}

	/**
	 * @Event("logOn")
	 * @Event("logOff")
	 * @Description("Records online status of org members")
	 */
	public function orgMemberLogonEvent(UserStateEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$this->updateOrglist($eventObj->sender, $eventObj->type);
	}

	/**
	 * @Event("packet(41)")
	 * @Description("Records online status of org members")
	 */
	public function buddyRemovedEvent(Event $eventObj): void {
		if (isset($this->orglist)) {
			$this->addOrgMembersToBuddylist();
		}
	}

	public function updateOrglist(string $sender, string $type): void {
		if (!isset($this->orglist->added[$sender])) {
			return;
		}
		$this->orglist->result[$sender]->online = $type === "logon";

		$this->buddylistManager->remove($sender, 'onlineorg');
		unset($this->orglist->added[$sender]);

		if (count($this->orglist->check) === 0 && count($this->orglist->added) === 0) {
			$this->orglistEnd();
		}
	}

	/**
	 * Check if we are allowed to add more buddies or if we should
	 * slow down, because our buddylist gets too full
	 */
	public function checkBuddylistSize(): bool {
		return count($this->buddylistManager->buddyList) < ($this->chatBot->getBuddyListSize() - 5);
	}
}
