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

	/** @Logger */
	public LoggerWrapper $logger;

	protected bool $ready = false;

	private $searches = [
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
		'others'
	];

	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "organizations");
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
		$tmp = explode(" ", $search);
		[$query, $params] = $this->util->generateQueryFromParams($tmp, 'name');
		$params []= $limit;

		$sql = "SELECT * FROM organizations WHERE $query LIMIT ?";

		$orgs = $this->db->fetchAll(Organization::class, $sql, ...$params);

		return $orgs;
	}

	/**
	 * @param Organization[] $orgs
	 */
	public function formatResults(array $orgs): string {
		$blob = '';
		foreach ($orgs as $org) {
			$whoisorg = $this->text->makeChatcmd('Whoisorg', "/tell <myname> whoisorg {$org->id}");
			$orglist = $this->text->makeChatcmd('Orglist', "/tell <myname> orglist {$org->id}");
			$orgmembers = $this->text->makeChatcmd('Orgmembers', "/tell <myname> orgmembers {$org->id}");
			$blob .= "<{$org->faction}>{$org->name}<end> ({$org->id}) - {$org->num_members} members [$orglist] [$whoisorg] [$orgmembers]\n\n";
		}
		return $blob;
	}

	public function handleOrglistResponse(string $url, int $searchIndex, HttpResponse $response) {
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
			$this->db->beginTransaction();
			if ($search === 'others') {
				if ($this->db->getType() === $this->db::MYSQL) {
					$this->db->exec("DELETE FROM organizations WHERE name NOT REGEXP '^[a-zA-Z0-9]'");
				} else {
					$this->db->exec("DELETE FROM organizations WHERE name NOT GLOB '[a-zA-Z0-9]*'");
				}
			} else {
				$this->db->exec("DELETE FROM organizations WHERE name LIKE ?", "{$search}%");
			}
			foreach ($arr as $match) {
				$obj = new Organization();
				//$obj->server = $match[1]; unused
				$obj->id = (int)$match[2];
				$obj->name = trim($match[3]);
				$obj->num_members = (int)$match[4];
				$obj->faction = $match[6];
				//$obj->governingForm = $match[7]; unused

				$this->db->exec("INSERT INTO organizations (id, name, faction, num_members) VALUES (?, ?, ?, ?)", $obj->id, $obj->name, $obj->faction, $obj->num_members);
			}
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
			$this->logger->log("ERROR", "Error downloading orgs");
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

		$this->ready = false;
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
