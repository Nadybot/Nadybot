<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use function Amp\{delay};

use Amp\Pipeline\Pipeline;
use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	DBSchema\Player,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PNonGreedy,
	Text,
	UserException,
};
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @author Tyrence (RK2)
 * @author Lucier (RK1)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'orglist',
		accessLevel: 'member',
		description: 'Check an org roster',
	)
]
class OrglistController extends ModuleInstance {
	/** Show offline org members by default */
	#[NCA\Setting\Boolean]
	public bool $orglistShowOffline = true;

	protected ?Orglist $orglist = null;

	/** @var array<string,string[]> */
	protected array $orgrankmap = [
		'Anarchism'  => ['Anarchist'],
		'Monarchy'   => ['Monarch',   'Counsil',      'Follower'],
		'Feudalism'  => ['Lord',      'Knight',       'Vassal',          'Peasant'],
		'Republic'   => ['President', 'Advisor',      'Veteran',         'Member',         'Applicant'],
		'Faction'    => ['Director',  'Board Member', 'Executive',       'Member',         'Applicant'],
		'Department' => ['President', 'General',      'Squad Commander', 'Unit Commander', 'Unit Leader', 'Unit Member', 'Applicant'],
	];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private GuildManager $guildManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private FindOrgController $findOrgController;

	/**
	 * Get a hierarchical array of all the ranks in the goven governing form
	 *
	 * @return string[]
	 */
	public function getOrgRanks(string $governingForm): array {
		return $this->orgrankmap[ucfirst(strtolower($governingForm))] ?? [];
	}

	/**
	 * Show who is online in an org / a player's org. Add 'all' to see offline
	 * characters as well
	 *
	 * You can use '%' as a wildcard in the org name
	 */
	#[NCA\HandlesCommand('orglist')]
	#[NCA\Help\Example('<symbol>orglist Team Rainbow')]
	#[NCA\Help\Example('<symbol>orglist Nadyita')]
	public function orglistCommand(
		CmdContext $context,
		PNonGreedy $search,
		#[NCA\Str('all')] ?string $all,
	): void {
		if ($this->orglistShowOffline) {
			$all = 'all';
		}
		$search = $search();
		if (ctype_digit($search)) {
			$orgId = (int)$search;
		} else {
			if (!$this->findOrgController->isReady()) {
				$this->findOrgController->sendNotReadyError($context);
				return;
			}
			$orgs = $this->getOrgsMatchingSearch($search);
			$count = count($orgs);

			if ($count === 0) {
				$msg = "Could not find any orgs (or players in orgs) that match <highlight>{$search}<end>.";
				$context->reply($msg);
				return;
			} elseif ($count !== 1) {
				$blob = $this->findOrgController->formatResults($orgs);
				$msg = $this->text->makeBlob("Org Search Results for '{$search}' ({$count})", $blob);
				$context->reply($msg);
				return;
			}
			$orgId = $orgs[0]->id;
		}

		$org = $this->guildManager->byId($orgId);
		if (!isset($org)) {
			$msg = "Error in getting the Org info. Either org does not exist or AO's server was too slow to respond.";
			$context->reply($msg);
			return;
		}
		$context->reply(
			'Checking online-status of <highlight>' . count($org->members) . '<end> '.
			'members of <' . strtolower($org->orgside) . ">{$org->orgname}<end>."
		);
		$startTime = microtime(true);
		$onlineStates = $this->getOnlineStates($org);
		$msg = $this->renderOrglist($org, $onlineStates, $startTime, isset($all));
		$context->reply($msg);
	}

	/**
	 * Get a list of orgs that match the given search string.
	 * If $search is a character name, this character's org will be
	 * included as well.
	 *
	 * @return Organization[]
	 */
	public function getOrgsMatchingSearch(string $search): array {
		$orgs = $this->findOrgController->lookupOrg($search);
		if (count($orgs) === 1 && strcasecmp($orgs[0]->name, $search) === 0) {
			return $orgs;
		}
		// check if search is a character and add character's org to org list if it's not already in the list
		$name = ucfirst(strtolower($search));
		$whois = $this->playerManager->byName($name);
		if ($whois === null || $whois->guild_id === 0 || $whois->guild_id === null) {
			return $orgs;
		}
		foreach ($orgs as $org) {
			if ($org->id === $whois->guild_id) {
				return $orgs;
			}
		}

		$obj = $this->findOrgController->getByID($whois->guild_id);
		if (isset($obj)) {
			$orgs []= $obj;
		}
		return $orgs;
	}

	/**
	 * Check the online status of all org members and return them in an array,
	 * keyed by the character name
	 *
	 * @return array<string,bool>
	 */
	public function getOnlineStates(Guild $org): array {
		$todo = [];
		foreach ($org->members as $member) {
			$todo []= $member->name;
		}

		$onlineStates = [];
		$numThreads = min($this->getFreeBuddylistSlots() - 5, count($org->members));
		if (count($org->members) > 100 && $numThreads < 10) {
			throw new UserException(
				'You need more buddylist slots to be able to use this command.'
			);
		}
		$this->logger->notice('Using {numThreads} threads to get online status', [
			'numThreads' => $numThreads,
		]);
		$onlineList = Pipeline::fromIterable($todo)
			->concurrent($numThreads)
			->map(function (string $name): bool {
				$uid = $this->chatBot->getUid($name);
				if (!isset($uid)) {
					return false;
				}
				while ($this->getFreeBuddylistSlots() < 5) {
					delay(0.01);
				}
				return $this->buddylistManager->checkIsOnline($uid);
			})->toArray();
		return array_combine($todo, $onlineList);
	}

	/**
	 * @param array<string,Player> $members
	 *
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

	/** Get the number of currently unused buddylist slots */
	public function getFreeBuddylistSlots(): int {
		return $this->chatBot->getBuddyListSize() - count($this->buddylistManager->buddyList);
	}

	/**
	 * Render the given org and list of online chars into a nice blob
	 *
	 * @param array<string,bool> $onlineStates
	 *
	 * @return string[]
	 */
	private function renderOrglist(Guild $org, array $onlineStates, float $startTime, bool $renderOffline): array {
		if (isset($org->governing_form, $this->orgrankmap[$org->governing_form])) {
			$orgRankNames = $this->orgrankmap[$org->governing_form];
		} else {
			$orgRankNames = $this->getOrgGoverningForm($org->members);
		}

		$totalOnline = count(array_filter($onlineStates, static fn (bool $online) => $online));
		$totalCount = count($org->members);

		$rankGroups = [];
		foreach ($org->members as $member) {
			if (!isset($member->guild_rank_id)) {
				continue;
			}
			$rankGroups[$member->guild_rank_id] ??= (object)['total' => 0, 'online' => [], 'offline' => []];
			$rankGroups[$member->guild_rank_id]->total++;
			if ($onlineStates[$member->name] ?? false) {
				$rankGroups[$member->guild_rank_id]->online []= $member;
			} else {
				if ($renderOffline) {
					$rankGroups[$member->guild_rank_id]->offline []= $member;
				}
			}
		}

		/** @var array<int,stdClass> $rankGroups */

		$renderedGroups = [];
		for ($rankid = 0; $rankid < count($orgRankNames); $rankid++) {
			if (isset($rankGroups[$rankid])) {
				$renderedGroups []= $this->renderOrglistRankGroup($orgRankNames[$rankid], $rankGroups[$rankid]);
			}
		}

		$blob = implode("\n\n", $renderedGroups);
		$totalTime = round((microtime(true) - $startTime), 1);
		$blob .= "\n\n<i>Lookup took {$totalTime} seconds.</i>";

		return (array)$this->text->makeBlob("Orglist for '{$org->orgname}' ({$totalOnline} / {$totalCount})", $blob);
	}

	/** Render the online/offline list for a single rank */
	private function renderOrglistRankGroup(string $rankName, stdClass $rankGroup): string {
		$blob = "<pagebreak><header2>{$rankName}<end> (" . count($rankGroup->online) . "/{$rankGroup->total})";
		$sortFunc = static fn (Player $p1, Player $p2): int => strcmp($p1->name, $p2->name);
		usort($rankGroup->online, $sortFunc);
		usort($rankGroup->offline, $sortFunc);
		foreach ($rankGroup->online as $member) {
			$blob .= "\n<tab>" . $this->renderOnlineMember($member);
		}
		if (count($rankGroup->offline) > 0) {
			$names = array_column($rankGroup->offline, 'name');
			$chunks = array_chunk($names, 50, false);
			$blob .= "\n<tab>";
			for ($i = 0; $i < count($chunks); $i++) {
				$chunk = $chunks[$i];
				$suffix = ',';
				if ($i === count($chunks)-1) {
					$suffix = '';
				}
				$blob .= '<pagebreak><font color=#555555>'.
					implode(',', $chunk) . "{$suffix}</font>";
			}
		}
		if (!count($rankGroup->online) && !count($rankGroup->offline)) {
			$blob .= "\n<tab>&lt;none&gt;";
		}
		return $blob;
	}

	/** Render a single, online player */
	private function renderOnlineMember(Player $member): string {
		$line  = "<highlight>{$member->name}<end>";
		if (isset($member->level)) {
			$line .= " (Level <highlight>{$member->level}<end>";
		}
		if ($member->ai_level > 0) {
			$line .= "<green>/{$member->ai_level}<end>";
		}
		$line .= ', '.$member->gender;
		$line .= ' '.$member->breed;
		if (isset($member->profession)) {
			$line .= ' ' . $member->profession->inColor() . ')';
		}
		return $line;
	}
}
