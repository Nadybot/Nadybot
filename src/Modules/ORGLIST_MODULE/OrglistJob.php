<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Amp\DeferredFuture;
use Amp\Pipeline\{ConcurrentIterator, Pipeline};
use AO\Package\In\{BuddyRemoved, BuddyState, Ping};
use AO\Package\Out\{BuddyAdd, BuddyRemove, Pong};
use Exception;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Events\PackageEvent;
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadybot\Core\{Attributes as NCA, BuddylistManager, EventManager, Nadybot};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Revolt\EventLoop;

class OrglistJob {
	public readonly string $uuid;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private OrglistController $orglistController;

	#[NCA\Inject]
	private BotConfig $config;

	/** @var array<int,DeferredFuture<?bool>> */
	private array $addQueue = [];

	/** @var array<int,DeferredFuture<void>> */
	private array $removeQueue = [];

	/** @var array<string,list<int>> */
	private array $procQueue = [];

	/** @var list<string> */
	private array $workers = [];

	/** @var array<string,int> */
	private array $slotsFree = [];

	/** @var ConcurrentIterator<Player> */
	private ConcurrentIterator $iter;

	public function __construct(
		private Guild $org,
		private LoggerInterface $logger,
		?string $uuid=null,
	) {
		$this->uuid = $uuid ?? Uuid::uuid7()->toString();
		$pipeline = Pipeline::fromIterable($this->org->members)->unordered();
		$this->iter = $pipeline->getIterator();
	}

	/** @return array<string,bool> */
	public function run(): array {
		$numThreads = min($this->orglistController->getFreeBuddylistSlots() - 5, count($this->org->members));
		if (count($this->org->members) > 100 && $numThreads < 10) {
			throw new UserException(
				'You need more buddylist slots to be able to use this command.'
			);
		}
		$this->logger->notice('Using {numThreads} threads to get online status', [
			'numThreads' => $numThreads,
		]);

		$this->workers = [
			$this->config->main->character,
			...array_column($this->config->worker, 'character'),
		];
		foreach ($this->workers as $worker) {
			$workerObj = $this->chatBot->aoClient->getBestWorker($worker);
			$this->slotsFree[$worker] = 0;
			if (isset($workerObj)) {
				$this->slotsFree[$worker] = 1_000 - count($workerObj->getBuddylist());
			}
		}

		$this->eventManager->subscribe('packet(40)', $this->onBuddyAdded(...));
		$this->eventManager->subscribe('packet(41)', $this->onBuddyRemoved(...));
		$this->eventManager->subscribe('packet(100)', $this->onPingReceived(...));

		$result = Pipeline::fromIterable($this->iter)
			->unordered()
			->concurrent($numThreads)
			->map($this->processPlayer(...))
			->toArray();

		$this->eventManager->unsubscribe('packet(40)', $this->onBuddyAdded(...));
		$this->eventManager->unsubscribe('packet(41)', $this->onBuddyRemoved(...));
		$this->eventManager->unsubscribe('packet(100)', $this->onPingReceived(...));

		$onlineList = [];
		foreach ($result as $character) {
			$onlineList[$character->name] = $character->online;
		}
		return $onlineList;
	}

	private function processPlayer(Player $player): OrglistItem {
		$cachedOnline = $this->buddylistManager->isOnline($player->name);
		if (is_bool($cachedOnline)) {
			return new OrglistItem(name: $player->name, online: $cachedOnline);
		}
		do {
			$worker = array_shift($this->workers);
			assert(isset($worker));
			$this->workers[] = $worker;
		} while ($this->slotsFree[$worker] - count($this->procQueue[$worker] ?? []) <= 0);
		$uid = $player->charid;
		if (isset($this->addQueue[$uid])) {
			throw new Exception('Broken queue, restart the bot!');
		}
		$this->addQueue[$uid] = new DeferredFuture();
		// $this->logger->notice('Adding {uid} on {worker}', ['uid' => $uid, 'worker' => $worker]);
		$this->chatBot->sendPackage(new BuddyAdd(charId: $uid), $worker);
		$this->procQueue[$worker] []= $uid;
		if ($this->iter->isComplete()) {
			foreach ($this->workers as $sendVia) {
				$this->chatBot->sendPackage(new Pong($this->uuid), $sendVia);
			}
		}
		// $this->logger->notice('Awaiting adding of {uid} on {worker}', ['uid' => $uid, 'worker' => $worker]);
		$isOnline = $this->addQueue[$uid]->getFuture()->await();
		// $this->logger->notice('Awaiting adding of {uid} on {worker}: {online}', ['uid' => $uid, 'worker' => $worker, 'online' => json_encode($isOnline)]);
		unset($this->addQueue[$uid]);
		if (!isset($isOnline)) {
			// Character UID is inactive
			return new OrglistItem(name: $player->name, online: false);
		}
		$this->removeQueue[$uid] = new DeferredFuture();
		// $this->logger->notice('Removing {uid}', ['uid' => $uid]);
		$this->chatBot->sendPackage(new BuddyRemove(charId: $uid), $worker);
		// $this->logger->notice('Awaiting removed of {uid}', ['uid' => $uid]);
		$this->removeQueue[$uid]->getFuture()->await();
		// $this->logger->notice('{uid} removed', ['uid' => $uid]);
		unset($this->removeQueue[$uid]);
		return new OrglistItem(name: $player->name, online: $isOnline);
	}

	private function onBuddyAdded(PackageEvent $event): void {
		$package = $event->packet->package;
		if (!($package instanceof BuddyState) || !isset($this->addQueue[$package->charId])) {
			return;
		}
		while (($oldestUid = array_shift($this->procQueue[$event->packet->worker])) !== $package->charId) {
			$resolver = $this->addQueue[$oldestUid] ?? null;
			if (isset($resolver)) {
				$this->logger->debug('UID {uid} inactive', ['uid' => $oldestUid]);
				EventLoop::queue($resolver->complete(...), null);
			}
		}
		$this->addQueue[$package->charId]->complete($package->online);
	}

	private function onBuddyRemoved(PackageEvent $event): void {
		$package = $event->packet->package;
		if (!($package instanceof BuddyRemoved)) {
			return;
		}
		if (isset($this->removeQueue[$package->charId])) {
			$this->removeQueue[$package->charId]->complete();
		}
	}

	private function onPingReceived(PackageEvent $event): void {
		$package = $event->packet->package;
		if (!($package instanceof Ping) || $package->extra !== $this->uuid) {
			return;
		}
		while (($oldestUid = array_shift($this->procQueue[$event->packet->worker]))) {
			$resolver = $this->addQueue[$oldestUid] ?? null;
			if (isset($resolver)) {
				$this->logger->debug('UID {uid} inactive', ['uid' => $oldestUid]);
				EventLoop::queue($resolver->complete(...), null);
			}
		}
	}
}
