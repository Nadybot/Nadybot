<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandReply,
	DB,
	DBSchema\Player,
	Event,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	Text,
	UserStateEvent,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Lucier (RK1)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "orglist",
		accessLevel: "member",
		description: "Check an org roster",
	)
]
class OrglistController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
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

	/**
	 * Get a hierarchical array of all the ranks in the goven governing form
	 * @return string[]
	 */
	public function getOrgRanks(string $governingForm): array {
		return $this->orgrankmap[ucfirst(strtolower($governingForm))] ?? [];
	}

	/** Stop a running orglist lookup */
	#[NCA\HandlesCommand("orglist")]
	public function orglistEndCommand(CmdContext $context, #[NCA\Str("end")] string $action): void {
		if (isset($this->orglist)) {
			$this->orglistEnd();
		} else {
			$context->reply("There is no orglist currently running.");
		}
	}

	/**
	 * Show who is online in an org / a player's org
	 *
	 * You can use '%' as a wildcard in the org name
	 */
	#[NCA\HandlesCommand("orglist")]
	#[NCA\Help\Example("<symbol>orglist Team Rainbow")]
	#[NCA\Help\Example("<symbol>orglist Nadyita")]
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

		$uidLookup = [];
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
			if (isset($member->charid)) {
				$uidLookup[$member->name] = $member->charid;
			}

			$this->orglist->result[$member->name] = new OrglistResult();
			$this->orglist->result[$member->name]->post = $thismember;

			$this->orglist->result[$member->name]->name = $member->name;
			$this->orglist->result[$member->name]->rank_id = $member->guild_rank_id;
		}

		$sendto->reply("Checking online status for " . count($org->members) ." members of <highlight>$org->orgname<end>…");

		$this->checkOnline($org->members);
		$this->addOrgMembersToBuddylist($uidLookup);

		if (isset($this->orglist) && count($this->orglist->added) === 0) {
			$this->orglistEnd();
			return;
		}
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
	public function checkOnline(array $members): void {
		if (!isset($this->orglist)) {
			return;
		}
		foreach ($members as $member) {
			$buddyOnlineStatus = $this->buddylistManager->isUidOnline($member->charid);
			if ($buddyOnlineStatus !== null) {
				$this->orglist->result[$member->name]->online = $buddyOnlineStatus;
			} elseif ($this->chatBot->char->name === $member->name) {
				$this->orglist->result[$member->name]->online = true;
			} else {
				$this->orglist->check[$member->name] = true;
			}
		}
	}

	/** @param array<string,int> $uidLookup */
	public function addOrgMembersToBuddylist(array $uidLookup=[]): void {
		if (!isset($this->orglist)) {
			return;
		}
		foreach ($this->orglist->check as $name => &$value) {
			if ($value === false) {
				continue;
			}
			if (!$this->checkBuddylistSize()) {
				return;
			}

			$value = false;
			if (isset($uidLookup[$name])) {
				$this->buddylistManager->addId($uidLookup[$name], 'onlineorg');
				$this->orglist->added[$name] = $uidLookup[$name];
				$this->orglist->numAdded++;
			} else {
				$this->chatBot->getUid($name, function(?int $uid) use ($name, &$value): void {
					if (!isset($this->orglist)) {
						return;
					}
					$this->orglist->added[$name] = 0;
					if (isset($uid)) {
						if (!$this->checkBuddylistSize()) {
							$value = true;
							return;
						}
						$this->buddylistManager->addId($uid, 'onlineorg');
						$this->orglist->added[$name] = $uid;
					}
					$this->orglist->numAdded++;
				});
			}
		}
		if ($this->orglist->numAdded >= count($this->orglist->check)) {
			unset($this->chatBot->id["_"]);
			$this->chatBot->getUid("_", function(?int $uid): void {
				$this->orglistEnd();
			});
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
		foreach ($this->orglist->added as $name => $uid) {
			$this->buddylistManager->removeId($uid, 'onlineorg');
		}
		unset($this->orglist);
	}

	/**
	 * @param array<string,string> $orgcolor
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
			$rank_total = count($newlist[$rankid]??[]);

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

			if ($onlinelist !== "") {
				$blob .= $onlinelist;
			}
			if ($offlinelist !== "<tab>") {
				$blob .= $orgcolor["offline"] . $offlinelist . "<end>\n";
			}
			$blob .= "\n";
		}

		$totaltime = time() - $timestart;
		$blob .= "\nLookup took $totaltime seconds.";

		return (array)$this->text->makeBlob("Orglist for '{$memberlist->org}' ($totalonline / $totalcount)", $blob);
	}

	#[
		NCA\Event(
			name: ["logOn", "logOff"],
			description: "Records online status of org members"
		)
	]
	public function orgMemberLogonEvent(UserStateEvent $eventObj): void {
		if (!is_string($eventObj->sender)) {
			return;
		}
		$this->updateOrglist($eventObj->sender, $eventObj->type);
	}

	#[NCA\Event(
		name: "packet(41)",
		description: "Records online status of org members"
	)]
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
