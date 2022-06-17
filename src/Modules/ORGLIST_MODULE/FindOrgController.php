<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use function Amp\File\filesystem;
use function Amp\Promise\all;
use function Amp\{call, delay};
use Amp\Cache\FileCache;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Amp\Promise;
use Amp\Sync\LocalKeyedMutex;
use Exception;
use Generator;
use Illuminate\Support\Collection;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandReply,
	ConfigFile,
	DB,
	Event,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	SQLException,
	Text,
	UserException,
	Util,
};
use Throwable;

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
	public HttpClientBuilder $builder;

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

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** How many parallel downloads to use for downloading the orglist */
	#[NCA\Setting\Number]
	public int $numOrglistDlJobs = 5;

	protected bool $ready = false;

	/** @var string[] */
	private array $todo = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->ready = $this->db->table("organizations")
			->where("index", "others")
			->exists();
	}

	/** Check if the orglists are currently ready to be used */
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
			$msg = $this->text->makeBlob("Org Search Results for '{$search}' ({$count})", $blob);
		} else {
			$msg = "No matches found.";
		}
		$context->reply($msg);
	}

	/**
	 * @return Organization[]
	 *
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

	/** @param Organization[] $orgs */
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
				", {$org->governing_form} [{$orglist}] [{$whoisorg}] [{$orgmembers}]\n";
		}
		return $blob;
	}

	/** @return Promise<void> */
	public function handleOrglistResponse(string $body, string $letter): Promise {
		return call(function () use ($body, $letter): Generator {
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

			preg_match_all($pattern, $body, $arr, PREG_SET_ORDER);
			$this->logger->info("Updating orgs starting with {$letter}");
			$inserts = [];
			foreach ($arr as $match) {
				$obj = new Organization();
				$obj->id = (int)$match[2];
				$obj->name = trim($match[3]);
				$obj->num_members = (int)$match[4];
				$obj->faction = $match[6];
				$obj->index = $letter;
				$obj->governing_form = $match[7];
				$inserts []= get_object_vars($obj);
			}
			while ($this->db->inTransaction()) {
				yield delay(100);
			}
			try {
				yield $this->db->awaitBeginTransaction();
				$this->db->table("organizations")
					->where("index", $letter)
					->delete();
				$this->db->table("organizations")
					->chunkInsert($inserts);
				$this->db->commit();
			} catch (Exception $e) {
				$this->logger->error("Error downloading orgs: " . $e->getMessage(), ["exception" => $e]);
				$this->db->rollback();
				$this->ready = true;
			}
		});
	}

	#[NCA\Event(
		name: "timer(24hrs)",
		description: "Parses all orgs from People of Rubi Ka"
	)]
	public function downloadAllOrgsEvent(Event $eventObj): Generator {
		$searches = [
			'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
			'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
			'others',
		];

		$cacheFolder = $this->config->cacheFolder . "/orglist";
		if (false === yield filesystem()->exists($cacheFolder)) {
			yield filesystem()->createDirectory($cacheFolder, 0700);
		}

		$this->ready = $this->db->table("organizations")
			->where("index", "others")
			->exists();
		$this->logger->info("Downloading list of all orgs");
		$this->todo = $searches;
		$jobs = [];
		for ($i = 0; $i < $this->numOrglistDlJobs; $i++) {
			$jobs []= $this->startDownloadOrglistJob();
		}
		try {
			yield all($jobs);
		} catch (Throwable $e) {
			$this->logger->error("Error downloading orglists: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
		$this->ready = true;
		$this->logger->info("Finished downloading orglists");
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

	/** @return Promise<void> */
	private function startDownloadOrglistJob(): Promise {
		return call(function (): Generator {
			while ($letter = array_shift($this->todo)) {
				yield $this->downloadOrglistLetter($letter);
			}
		});
	}

	/** @return Promise<void> */
	private function downloadOrglistLetter(string $letter): Promise {
		return call(function () use ($letter): Generator {
			$this->logger->info("Downloading orglist for letter {$letter}");
			$cache = new FileCache(
				$this->config->cacheFolder . '/orglist',
				new LocalKeyedMutex()
			);
			$body = yield $cache->get($letter);
			if ($body !== null) {
				if (!$this->isReady()) {
					yield $this->handleOrglistResponse($body, $letter);
				}
				return;
			}
			$url = "http://people.anarchy-online.com/people/lookup/orgs.html".
				"?l={$letter}&dim={$this->config->dimension}";
			$client = $this->builder->build();
			$retry = 5;
			do {
				/** @var Response */
				$response = yield $client->request(new Request($url));

				if ($response->getStatus() !== 200) {
					if (--$retry <= 0) {
						throw new UserException("Unable to download orglist for {$letter}");
					}
					$this->logger->warning(
						"Error downloading orglist for letter {letter}, retrying in {retry}s",
						[
							"letter" => $letter,
							"dim" => $this->config->dimension,
							"retry" => 5,
						]
					);
					yield delay(5000);
				}
			} while ($response->getStatus() !== 200 && $retry > 0);
			$body = yield $response->getBody()->buffer();
			if ($body === '' || !str_contains($body, "ORGS BEGIN")) {
				throw new Exception("Invalid data received from orglist for {$letter}");
			}
			yield $cache->set($letter, $body, 24 * 3600);
			yield $this->handleOrglistResponse($body, $letter);
		});
	}
}
