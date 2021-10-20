<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Exception;
use Nadybot\Core\{
	Event,
	CommandReply,
	DB,
	Http,
	HttpResponse,
	LoggerWrapper,
	Nadybot,
	SQLException,
	Text,
	Timer,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'findorg',
 *		accessLevel = 'all',
 *		description = 'Find orgs by name',
 *		help        = 'findorg.txt'
 *	)
 */
class FindOrgController {

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
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	protected bool $ready = false;

	private $searches = [
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'others'
	];

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
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

	/**
	 * @HandlesCommand("findorg")
	 * @Matches("/^findorg (.+)$/i")
	 */
	public function findOrgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->isReady()) {
			$this->sendNotReadyError($sendto);
			return;
		}
		$search = $args[1];

		$orgs = $this->lookupOrg($search);
		$count = count($orgs);

		if ($count > 0) {
			$blob = $this->formatResults($orgs);
			$msg = $this->text->makeBlob("Org Search Results for '{$search}' ($count)", $blob);
		} else {
			$msg = "No matches found.";
		}
		$sendto->reply($msg);
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

	public function handleOrglistResponse(string $url, int $searchIndex, HttpResponse $response): void {
		if ($this->db->inTransaction()) {
			$this->timer->callLater(1, [$this, __FUNCTION__], ...func_get_args());
			return;
		}
		if ($response === null || $response->headers["status-code"] !== "200") {
			$this->ready = true;
			return;
		}
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
			preg_match_all($pattern, $response->body, $arr, PREG_SET_ORDER);
			$this->logger->log("DEBUG", "Updating orgs starting with $search");
			$inserts = [];
			foreach ($arr as $match) {
				$obj = new Organization();
				//$obj->server = $match[1]; unused
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
			$searchIndex++;
			if ($searchIndex >= count($this->searches)) {
				$this->logger->log("INFO", "Finished downloading orglists");
				$this->ready = true;
				return;
			}
			$this->http
				->get($url)
				->withQueryParams(['l' => $this->searches[$searchIndex]])
				->withTimeout(60)
				->withCallback(function(HttpResponse $response) use ($url, $searchIndex) {
					$this->handleOrglistResponse($url, $searchIndex, $response);
				});
		} catch (Exception $e) {
			$this->logger->log("ERROR", "Error downloading orgs: " . $e->getMessage(), $e);
			$this->db->rollback();
			$this->ready = true;
		}
	}

	/**
	 * @Event("timer(24hrs)")
	 * @Description("Parses all orgs from People of Rubi Ka")
	 */
	public function parseAllOrgsEvent(Event $eventObj): void {
		$this->downloadOrglist();
	}

	public function downloadOrglist(): void {
		$url = "http://people.anarchy-online.com/people/lookup/orgs.html";

		$this->ready = $this->db->table("organizations")
			->where("index", "others")
			->exists();
		$this->logger->log("DEBUG", "Downloading all orgs from '$url'");
			$searchIndex = 0;
			$this->http
				->get($url)
				->withQueryParams(['l' => $this->searches[$searchIndex]])
				->withTimeout(60)
				->withCallback(function(HttpResponse $response) use ($url, $searchIndex) {
					$this->handleOrglistResponse($url, $searchIndex, $response);
				});
	}
}
