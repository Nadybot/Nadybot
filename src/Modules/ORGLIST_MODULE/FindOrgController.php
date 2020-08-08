<?php

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\Event;
use Exception;
use Nadybot\Core\SQLException;
use stdClass;

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
	public $moduleName;
	
	/**
	 * @var \Nadybot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Nadybot\Core\Nadybot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Nadybot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Nadybot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @var \Nadybot\Core\Http $http
	 * @Inject
	 */
	public $http;
	
	/**
	 * @var \Nadybot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;
	
	private $searches = [
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
		'others'
	];

	/** @Setup */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "organizations");
	}
	
	/**
	 * @HandlesCommand("findorg")
	 * @Matches("/^findorg (.+)$/i")
	 */
	public function findOrgCommand($message, $channel, $sender, $sendto, $args) {
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
		
		$sql = "SELECT id, name, faction, num_members FROM organizations WHERE $query LIMIT ?";
		
		$orgs = $this->db->fetchAll(Organization::class, $sql, ...$params);
		
		return $orgs;
	}
	
	public function formatResults($orgs) {
		$blob = '';
		foreach ($orgs as $row) {
			$whoisorg = $this->text->makeChatcmd('Whoisorg', "/tell <myname> whoisorg {$row->id}");
			$orglist = $this->text->makeChatcmd('Orglist', "/tell <myname> orglist {$row->id}");
			$orgmembers = $this->text->makeChatcmd('Orgmembers', "/tell <myname> orgmembers {$row->id}");
			$blob .= "<{$row->faction}>{$row->name}<end> ({$row->id}) - {$row->num_members} members [$orglist] [$whoisorg] [$orgmembers]\n\n";
		}
		return $blob;
	}

	public function handleOrglistResponse(string $url, int $searchIndex, object $response) {
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
				$this->logger->log("DEBUG", "Finished downloading orgs");
				return;
			}
			$this->http
				->get($url)
				->withQueryParams(['l' => $this->searches[$searchIndex]])
				->withTimeout(60)
				->withCallback(function($response) use ($url, $searchIndex) {
					$this->handleOrglistResponse($url, $searchIndex, $response);
				});
		} catch (Exception $e) {
			$this->logger->log("ERROR", "Error downloading orgs");
			$this->db->rollback();
		}
	}
	
	/**
	 * @Event("timer(24hrs)")
	 * @Description("Parses all orgs from People of Rubi Ka")
	 */
	public function parseAllOrgsEvent(Event $eventObj) {
		$url = "http://people.anarchy-online.com/people/lookup/orgs.html";
		
		$this->logger->log("DEBUG", "Downloading all orgs from '$url'");
			$searchIndex = 0;
			$this->http
				->get($url)
				->withQueryParams(['l' => $this->searches[$searchIndex]])
				->withTimeout(60)
				->withCallback(function($response) use ($url, $searchIndex) {
					$this->handleOrglistResponse($url, $searchIndex, $response);
				});
	}
}
