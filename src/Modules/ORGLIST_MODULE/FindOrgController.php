<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Event,
	CommandReply,
	DB,
	CacheManager,
	CacheResult,
	ConfigFile,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	SQLException,
	Text,
	Timer,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "findorg",
		accessLevel: "guest",
		description: "Find orgs by name",
	)
]
class FindOrgController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	protected bool $ready = false;

	/** @var string[] */
	private array $searches = [
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'others'
	];

	#[NCA\Setup]
	public function setup(): void {
		$this->ready = $this->db->table("organizations")
			->where("index", "others")
			->exists();
	}

	/**
	 * Check if the orglists are currently ready to be used
	 */
	public function isReady(): bool {
		return $this->ready;
	}

	public function sendNotReadyError(CommandReply $sendto): void {
		$sendto->reply(
			"The org roster is currently being updated, please wait."
		);
	}

	public function getByID(int $orgID): ?Organization {
		return $this->db->table("organizations")
			->where("id", $orgID)
			->asObj(Organization::class)
			->first();
	}

	/** Find an organization by its name */
	#[NCA\HandlesCommand("findorg")]
	public function findOrgCommand(CmdContext $context, string $search): void {
		if (!$this->isReady()) {
			$this->sendNotReadyError($context);
			return;
		}

		$orgs = $this->lookupOrg($search);
		$count = count($orgs);

		if ($count > 0) {
			$blob = $this->formatResults($orgs);
			$msg = $this->text->makeBlob("Org Search Results for '{$search}' ($count)", $blob);
		} else {
			$msg = "No matches found.";
		}
		$context->reply($msg);
	}

	/**
	 * @return Organization[]
	 * @throws SQLException
	 */
	public function lookupOrg(string $search, int $limit=50): array {
		$query = $this->db->table("organizations")
			->limit($limit);
		$tmp = explode(" ", $search);
		$this->db->addWhereFromParams($query, $tmp, "name");

		$orgs = $query->asObj(Organization::class);
		$exactMatches = $orgs->filter(function (Organization $org) use ($search): bool {
			return strcasecmp($org->name, $search) === 0;
		});
		if ($exactMatches->count() === 1) {
			return [$exactMatches->first()];
		}
		return $orgs->toArray();
	}

	/**
	 * @param Organization[] $orgs
	 */
	public function formatResults(array $orgs): string {
		$blob = "<header2>Matching orgs<end>\n";
		usort($orgs, function (Organization $a, Organization $b): int {
			return strcasecmp($a->name, $b->name);
		});
		foreach ($orgs as $org) {
			$whoisorg = $this->text->makeChatcmd('Whoisorg', "/tell <myname> whoisorg {$org->id}");
			$orglist = $this->text->makeChatcmd('Orglist', "/tell <myname> orglist {$org->id}");
			$orgmembers = $this->text->makeChatcmd('Orgmembers', "/tell <myname> orgmembers {$org->id}");
			$blob .= "<tab><{$org->faction}>{$org->name}<end> ({$org->id}) - ".
				"<highlight>{$org->num_members}<end> ".
				$this->text->pluralize("member", $org->num_members).
				", {$org->governing_form} [$orglist] [$whoisorg] [$orgmembers]\n";
		}
		return $blob;
	}

	public function handleOrglistResponse(CacheResult $result, string $url, int $searchIndex): void {
		if ($this->db->inTransaction()) {
			$this->timer->callLater(1, [$this, __FUNCTION__], ...func_get_args());
			return;
		}
		if (!isset($result->data) || !$result->success) {
			$retry = 5;
			$this->logger->warning(
				"Error downloading orglist for letter {letter}, retrying in {retry}s",
				[
					"letter" => $this->searches[$searchIndex],
					"retry" => $retry,
				]
			);
			$this->timer->callLater(
				$retry,
				function() use ($url, $searchIndex): void {
					$this->downloadOrglistLetter($url, $searchIndex);
				}
			);
			return;
		}
		if (!$result->usedCache || !$this->isReady()) {
			$search = $this->searches[$searchIndex];
			$pattern = '@<tr>\s*'.
				'<td align="left">\s*'.
					'<a href="(?:https?:)?//people.anarchy-online.com/org/stats/d/(\d+)/name/(\d+)">\s*'.
						'([^<]+)'.
					'</a>'.
				'</td>\s*'.
				'<td align="right">(\d+)</td>\s*'.
				'<td align="right">(\d+)</td>\s*'.
				'<td align="left">([^<]+)</td>\s*'.
				'<td align="left">([^<]+)</td>\s*'.
				'<td align="left" class="dim">RK\d+</td>\s*'.
				'</tr>@s';

			try {
				preg_match_all($pattern, $result->data, $arr, PREG_SET_ORDER);
				$this->logger->info("Updating orgs starting with $search");
				$inserts = [];
				foreach ($arr as $match) {
					$obj = new Organization();
					$obj->id = (int)$match[2];
					$obj->name = trim($match[3]);
					$obj->num_members = (int)$match[4];
					$obj->faction = $match[6];
					$obj->index = $search;
					$obj->governing_form = $match[7];
					$inserts []= get_object_vars($obj);
				}
				$this->db->beginTransaction();
				$this->db->table("organizations")
					->where("index", $search)
					->delete();
				$this->db->table("organizations")
					->chunkInsert($inserts);
				$this->db->commit();
			} catch (Exception $e) {
				$this->logger->error("Error downloading orgs: " . $e->getMessage(), ["exception" => $e]);
				$this->db->rollback();
				$this->ready = true;
			}
		}
		$searchIndex++;
		if ($searchIndex >= count($this->searches)) {
			$this->logger->notice("Finished downloading orglists");
			$this->ready = true;
			return;
		}
		$this->downloadOrglistLetter($url, $searchIndex);
	}

	protected function downloadOrglistLetter(string $url, int $searchIndex): void {
		$this->cacheManager->asyncLookup(
			$url . "?" . http_build_query([
				'l' => $this->searches[$searchIndex],
				'dim' => $this->config->dimension,
			]),
			"orglist",
			$this->searches[$searchIndex] . ".html",
			[$this, "isValidOrglist"],
			24 * 3600,
			false,
			[$this, "handleOrglistResponse"],
			$url,
			$searchIndex,
		);
	}

	#[NCA\Event(
		name: "timer(24hrs)",
		description: "Parses all orgs from People of Rubi Ka"
	)]
	public function parseAllOrgsEvent(Event $eventObj): void {
		$this->downloadOrglist();
	}

	public function downloadOrglist(): void {
		$url = "http://people.anarchy-online.com/people/lookup/orgs.html";

		$this->ready = $this->db->table("organizations")
			->where("index", "others")
			->exists();
		$this->logger->info("Downloading all orgs from '$url'");
		$this->downloadOrglistLetter($url, 0);
	}

	public function isValidOrglist(?string $html): bool {
		return isset($html) && str_contains($html, "ORGS BEGIN");
	}

	/** @return Collection<Organization> */
	public function getOrgsByName(string ...$names): Collection {
		if (empty($names)) {
			return new Collection();
		}
		return $this->db->table("organizations")
			->whereIn("name", $names)
			->asObj(Organization::class);
	}

	/** @return Collection<Organization> */
	public function getOrgsById(int ...$ids): Collection {
		if (empty($ids)) {
			return new Collection();
		}
		return $this->db->table("organizations")
			->whereIn("id", $ids)
			->asObj(Organization::class);
	}
}
