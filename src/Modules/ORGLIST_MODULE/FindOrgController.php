<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Amp\Pipeline\Pipeline;
use Exception;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Collection,
	Config\BotConfig,
	DB,
	Events\Event,
	Exceptions\SQLException,
	ModuleInstance,
	Text,
	Types\AccessLevel,
	Types\CommandReply,
	Types\Dimension,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\{
	DirectoryFeature,
	DirectoryRegistry,
	DirectoryUnavailableException,
	OrgListEntry,
	OrgListProvider,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'findorg',
		accessLevel: AccessLevel::Guest,
		description: 'Find orgs by name',
	)
]
class FindOrgController extends ModuleInstance {
	/** How many parallel downloads to use for downloading the orglist */
	#[NCA\Setting\Number]
	public int $numOrglistDlJobs = 5;

	/** Which service to use for orglist downloads on RK4-6 */
	#[NCA\Setting\Text(
		options: [
			'bork.aobots.org (Nadybot)' => DirectoryRegistry::BORK_URL,
			'people.anarchy-online.com (Funcom)' => DirectoryRegistry::PORK_URL,
		]
	)]
	public string $orglistPorkUrl = DirectoryRegistry::BORK_URL;

	protected bool $ready = false;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DirectoryRegistry $directoryRegistry;

	#[NCA\Inject]
	private GuildManager $guildManager;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Setup]
	public function setup(): void {
		$this->directoryRegistry->updateSettingOptions(
			'orglist_pork_url',
			DirectoryFeature::OrgList,
			Dimension::FUNCOM_DIMENSIONS,
		);
		$this->ready = $this->db->table(Organization::getTable())
			->where('index', 'others')
			->exists();
	}

	/** Check if the orglists are currently ready to be used */
	public function isReady(): bool {
		return $this->isAvailable() && $this->ready;
	}

	/**
	 * Check if orgs can be looked up by their id right now
	 *
	 * Without a list of all orgs, this is done via the org roster
	 */
	public function isReadyForIdLookups(): bool {
		return !$this->isAvailable() || $this->ready;
	}

	/** Check if there is a list of all orgs for this dimension at all */
	public function isAvailable(): bool {
		return $this->directoryRegistry->supports(DirectoryFeature::OrgList, $this->config->main->dimension);
	}

	public function sendNotReadyError(CommandReply $sendto): void {
		if (!$this->isAvailable()) {
			$sendto->reply('There is no list of all orgs for this server.');
			return;
		}
		$sendto->reply(
			'The org roster is currently being updated, please wait.'
		);
	}

	public function getByID(int $orgID): ?Organization {
		if (!$this->isAvailable()) {
			return $this->getFromRoster($orgID);
		}
		return $this->db->table(Organization::getTable())
			->where('id', $orgID)
			->firstObj(Organization::class);
	}

	/** Find an organization by its name */
	#[NCA\HandlesCommand('findorg')]
	public function findOrgCommand(CmdContext $context, string $search): void {
		if (!$this->isReady()) {
			$this->sendNotReadyError($context);
			return;
		}

		$orgs = $this->lookupOrg($search);
		$count = count($orgs);

		if ($count > 0) {
			$blob = $this->formatResults($orgs);
			$msg = Text::makeBlob("Org Search Results for '{$search}' ({$count})", $blob);
		} else {
			$msg = 'No matches found.';
		}
		$context->reply($msg);
	}

	/**
	 * @return list<Organization>
	 *
	 * @throws SQLException
	 */
	public function lookupOrg(string $search, int $limit=50): array {
		$query = $this->db->table(Organization::getTable())
			->limit($limit);
		$tmp = explode(' ', $search);
		$this->db->addWhereFromParams($query, $tmp, 'name');

		$orgs = $query->asObj(Organization::class);
		$exactMatches = $orgs->filter(static function (Organization $org) use ($search): bool {
			return strcasecmp($org->name, $search) === 0;
		});
		if ($exactMatches->count() === 1) {
			return [$exactMatches->firstOrFail()];
		}
		return $orgs->toList();
	}

	/** @param iterable<array-key,Organization> $orgs */
	public function formatResults(iterable $orgs): string {
		$blob = "<header2>Matching orgs<end>\n";
		$orgs = (new Collection($orgs))->uasort(static function (Organization $a, Organization $b): int {
			return strcasecmp($a->name, $b->name);
		});
		foreach ($orgs as $org) {
			$whoisorg = Text::makeChatcmd('Whoisorg', "/tell <myname> whoisorg {$org->id}");
			$orglist = Text::makeChatcmd('Orglist', "/tell <myname> orglist {$org->id}");
			$orgmembers = Text::makeChatcmd('Orgmembers', "/tell <myname> orgmembers {$org->id}");
			$blob .= "<tab>{$org->faction->inColor($org->name)} ({$org->id}) - ".
				"<highlight>{$org->num_members}<end> ".
				Text::pluralize('member', $org->num_members).
				", {$org->governing_form->name} [{$orglist}] [{$whoisorg}] [{$orgmembers}]\n";
		}
		return $blob;
	}

	/**
	 * Store one chunk of the org list in the database
	 *
	 * @param list<OrgListEntry> $orgs
	 */
	public function storeOrgs(array $orgs, string $chunk): void {
		$this->logger->info('Updating orgs starting with {letter}', ['letter' => $chunk]);
		$inserts = [];
		foreach ($orgs as $org) {
			$inserts []= [
				'id' => $org->id,
				'name' => $org->name,
				'num_members' => $org->numMembers,
				'faction' => $org->faction->name,
				'index' => $chunk,
				'governing_form' => $org->governingForm->name,
			];
		}
		$this->db->awaitBeginTransaction();
		try {
			$this->db->table(Organization::getTable())
				->chunkUpsert($inserts, ['id']);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->logger->error('Error downloading orgs: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			$this->db->rollback();
			$this->ready = true;
		}
	}

	/** Downloads the list of all orgs, if available for this dimension */
	#[NCA\Timer(interval: '24hrs')]
	public function downloadAllOrgsEvent(Event $eventObj): void {
		$providers = $this->directoryRegistry->orgListProviders(
			$this->config->main->dimension,
			$this->orglistPorkUrl,
		);
		if (count($providers) === 0) {
			return;
		}
		$provider = $providers[0];

		$this->ready = $this->db->table(Organization::getTable())
			->where('index', 'others')
			->exists();
		$this->logger->info('Downloading list of all orgs');
		try {
			/** @psalm-suppress MixedArgumentTypeCoercion */
			Pipeline::fromIterable($provider->getOrgListChunks())
				->concurrent($this->numOrglistDlJobs)
				->forEach(function (string $chunk) use ($providers): void {
					$this->downloadOrglistChunk($providers, $chunk);
				});
		} catch (Throwable $e) {
			$this->logger->error('Error downloading orglists: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
		$this->ready = true;
		$this->logger->info('Finished downloading orglists');
	}

	/** @return Collection<int,Organization> */
	public function getOrgsByName(string ...$names): Collection {
		if (!count($names)) {
			return new Collection();
		}
		return $this->db->table(Organization::getTable())
			->whereIn('name', $names)
			->asObj(Organization::class);
	}

	/** @return Collection<int,Organization> */
	public function getOrgsById(int ...$ids): Collection {
		if (!count($ids)) {
			return new Collection();
		}
		if (!$this->isAvailable()) {
			$orgs = [];
			foreach ($ids as $id) {
				$org = $this->getFromRoster($id);
				if (isset($org)) {
					$orgs []= $org;
				}
			}

			/** @var Collection<int,Organization> */
			$result = new Collection($orgs);
			return $result;
		}
		return $this->db->table(Organization::getTable())
			->whereIn('id', $ids)
			->asObj(Organization::class);
	}

	/** Get basic org information from the org roster, for dimensions without an org list */
	private function getFromRoster(int $orgID): ?Organization {
		try {
			$guild = $this->guildManager->byId($orgID);
		} catch (Throwable) {
			return null;
		}
		if (!isset($guild)) {
			return null;
		}
		return new Organization(
			id: $guild->guild_id,
			name: $guild->orgname,
			num_members: count($guild->members),
			faction: $guild->orgside,
			governing_form: $guild->governing_form,
		);
	}

	/**
	 * Download one chunk of the org list, trying the next provider if one is unavailable
	 *
	 * @param non-empty-list<OrgListProvider> $providers
	 */
	private function downloadOrglistChunk(array $providers, string $chunk): void {
		$lastError = null;
		foreach ($providers as $provider) {
			try {
				$result = $provider->lookupOrgList($chunk, $this->config->main->dimension);
			} catch (DirectoryUnavailableException $e) {
				$lastError = $e;
				$this->logger->warning('{provider} could not deliver the orglist for {letter}: {error}', [
					'provider' => $provider->getName(),
					'letter' => $chunk,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
				continue;
			}
			if ($result->fromCache && $this->isReady()) {
				return;
			}
			$this->storeOrgs($result->orgs, $chunk);
			return;
		}
		throw new Exception("Unable to download orglist for {$chunk}", 0, $lastError);
	}
}
