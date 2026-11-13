<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\async;
use function Amp\Future\await;

use DateInterval;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Player,
	EventManager,
	ModuleInstance,
	Nadybot,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\{DirectoryRegistry, DirectoryUnavailableException};
use Psr\Log\LoggerInterface;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class GuildManager extends ModuleInstance {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private DirectoryRegistry $directoryRegistry;

	public function byId(int $guildID, ?int $dimension=null, bool $forceUpdate=false): ?Guild {
		// if no server number is specified use the one on which the bot is logged in
		$dimension ??= $this->config->main->dimension;

		$providers = $this->directoryRegistry->orgRosterProviders($dimension, $this->playerManager->porkUrl);
		if (count($providers) === 0) {
			return null;
		}
		$result = null;
		$lastError = null;
		foreach ($providers as $provider) {
			try {
				$result = $provider->lookupOrgRoster($guildID, $dimension, $forceUpdate);
				$lastError = null;
				break;
			} catch (DirectoryUnavailableException $e) {
				$lastError = $e;
				$this->logger->info('{provider} could not look up ORG {guild} D{dimension}: {error}', [
					'provider' => $provider->getName(),
					'guild' => $guildID,
					'dimension' => $dimension,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
		if (isset($lastError)) {
			throw new Exception('Empty data received when reading org data', 0, $lastError);
		}
		if (!isset($result)) {
			return null;
		}
		$guild = $result->guild;
		$fromCache = $result->fromCache;

		$luDateTime = $guild->last_update;
		if (isset($luDateTime) && $this->isMyGuild($guild->guild_id)) {
			// Try to time the next roster update to occur 1 day and 10m after the last export
			$key = $this->eventManager->getKeyForCronEvent(24*3_600, 'guildcontroller.downloadOrgRosterEvent');
			if (isset($key)) {
				$nextTime = $luDateTime->add(new DateInterval('P1DT10M'));
				if ($nextTime->getTimestamp() > time()) {
					$this->eventManager->setCronNextEvent($key, $nextTime->getTimestamp());
				}
			}
		}

		// pre-fetch the charids...this speeds things up immensely
		$futures = [];
		foreach ($guild->members as $name => $member) {
			if ($member->charid === 0 && ($this->chatBot->getUid($name, true) === null)) {
				$futures []= async($this->chatBot->getUid(...), $name);
			}
		}
		await($futures);

		foreach ($guild->members as $name => $member) {
			if ($member->charid === 0) {
				$member->charid = $this->chatBot->getUid($name, true) ?? 0;
			}
		}

		// If this result is from our cache, then this information is already present
		if ($fromCache) {
			return $guild;
		}
		$this->db->awaitBeginTransaction();

		$this->db->table(Player::getTable())
			->where('guild_id', $guild->guild_id)
			->where('dimension', $dimension)
			->update([
				'guild_id' => 0,
				'guild' => '',
			]);

		foreach ($guild->members as $member) {
			$this->playerManager->update($member);
		}

		$this->db->commit();
		return $guild;
	}

	/** Check if $guildId is the bot's guild id */
	public function isMyGuild(int $guildId): bool {
		return isset($this->config->orgId)
			&& $this->config->orgId === $guildId;
	}
}
