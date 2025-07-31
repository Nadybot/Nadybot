<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use function Amp\delay;
use Amp\DeferredFuture;
use Amp\Pipeline\Pipeline;
use AO\Package\In\{BuddyRemoved, BuddyState, Ping};
use AO\Package\Out\{BuddyAdd, BuddyRemove, Pong};
use Exception;
use Nadybot\Core\{Attributes as NCA, BuddylistManager, EventManager, Nadybot};
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Events\PackageEvent;
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
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

	/** Which character are we currently processing */
	private int $queuePosition = 0;

	/** How many online-requests have been answered */
	private int $numAnswersReceived = 0;

	public function __construct(
		private Guild $org,
		private LoggerInterface $logger,
		?string $uuid=null,
	) {
		$this->uuid = $uuid ?? Uuid::uuid7()->toString();
	}

	/**
	 * Get the online-state of all the org-members of `$this->org`
	 *
	 * @return array<string,bool>
	 */
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

		$stateId = EventLoop::repeat(1, $this->showStateInfo(...));

		$this->eventManager->subscribe('packet(40)', $this->onBuddyAdded(...));
		$this->eventManager->subscribe('packet(41)', $this->onBuddyRemoved(...));
		$this->eventManager->subscribe('packet(100)', $this->onPingReceived(...));

		$result = Pipeline::fromIterable($this->org->members)
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
		$this->showStateInfo();
		EventLoop::cancel($stateId);
		return $onlineList;
	}

	private function showStateInfo(): void {
		$state = [];
		foreach ($this->workers as $worker) {
			$workerObj = $this->chatBot->aoClient->getBestWorker($worker);
			if (!isset($workerObj)) {
				continue;
			}
			$state[$worker] = [
				'waiting' => count($this->procQueue[$worker]),
				'queue' => $workerObj->getQueueSize(),
				'buddylist' => count($workerObj->getBuddylist()),
			];
		}
		$this->logger->info(
			"Orglist stats:\n".
			"    Position: {current}/{max}\n".
			"    Answers:  {answers}/{max}\n".
			'    Worker queue: {state}',
			[
				'current' => $this->queuePosition,
				'answers' => $this->numAnswersReceived,
				'max' => count($this->org->members),
				'state' => $state,
			]
		);
	}

	private function sendFinalPing(): void {
		if ($this->queuePosition === count($this->org->members)) {
			foreach ($this->workers as $sendVia) {
				$this->chatBot->sendPackage(new Pong($this->uuid), $sendVia);
			}
		}
	}

	/**
	 * Get the name and online status for a single player, and sent
	 * a final pong if done
	 */
	private function processPlayer(Player $player): OrglistItem {
		$this->queuePosition++;
		$cachedOnline = $this->buddylistManager->isOnline($player->name);
		if (is_bool($cachedOnline)) {
			$this->sendFinalPing();
			return new OrglistItem(name: $player->name, online: $cachedOnline);
		}
		$first = null;
		do {
			$worker = array_shift($this->workers);
			assert(isset($worker));
			$this->workers[] = $worker;
			if (isset($first) && $first === $worker) {
				delay(0.01);
			}
			$first ??= $worker;
		} while ($this->slotsFree[$worker] - count($this->procQueue[$worker] ?? []) <= 0);
		$uid = $player->charid;
		if ($uid === 0) {
			$this->sendFinalPing();
			return new OrglistItem(name: $player->name, online: false);
		}
		if (isset($this->addQueue[$uid])) {
			throw new Exception('Broken queue, restart the bot!');
		}

		/** @var DeferredFuture<?bool> */
		$addFuture = new DeferredFuture();
		$this->addQueue[$uid] = $addFuture; // @phpstan-ignore-line
		// $this->logger->notice('Adding {uid} on {worker}', ['uid' => $uid, 'worker' => $worker]);
		$this->chatBot->sendPackage(new BuddyAdd(charId: $uid), $worker);
		$this->procQueue[$worker] []= $uid;
		$this->sendFinalPing();
		// $this->logger->notice('Awaiting adding of {uid} on {worker}', ['uid' => $uid, 'worker' => $worker]);
		$isOnline = $this->addQueue[$uid]->getFuture()->await();
		$this->numAnswersReceived++;

		// $this->logger->notice('Awaiting adding of {uid} on {worker}: {online}', ['uid' => $uid, 'worker' => $worker, 'online' => json_encode($isOnline)]);
		/** @phpstan-ignore-next-line */
		unset($this->addQueue[$uid]);
		if (!isset($isOnline)) {
			// Character UID is inactive
			return new OrglistItem(name: $player->name, online: false);
		}

		/** @var DeferredFuture<void> */
		$remFuture = new DeferredFuture();
		$this->removeQueue[$uid] = $remFuture;
		// $this->logger->notice('Removing {uid}', ['uid' => $uid]);
		$this->chatBot->sendPackage(new BuddyRemove(charId: $uid), $worker);
		// $this->logger->notice('Awaiting removed of {uid}', ['uid' => $uid]);
		$this->removeQueue[$uid]->getFuture()->await();
		// $this->logger->notice('{uid} removed', ['uid' => $uid]);
		unset($this->removeQueue[$uid]);
		return new OrglistItem(name: $player->name, online: $isOnline);
	}

	/**
	 * Callback handling buddy states
	 *
	 * If we get the online-result for a buddy, all other non-answered requests
	 * on the same worker can be considered invalid.
	 */
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

	/** Callback handling buddy removal. Wake up the fiber waiting for it */
	private function onBuddyRemoved(PackageEvent $event): void {
		$package = $event->packet->package;
		if (!($package instanceof BuddyRemoved)) {
			return;
		}
		if (isset($this->removeQueue[$package->charId])) {
			$this->removeQueue[$package->charId]->complete();
		}
	}

	/**
	 * Callback handling pings
	 *
	 * If the pong is answered, all non-answered buddy-add requests
	 * on the same worker can be considered invalid.
	 */
	private function onPingReceived(PackageEvent $event): void {
		$package = $event->packet->package;
		if (!($package instanceof Ping) || $package->extra !== $this->uuid) {
			return;
		}
		$this->procQueue[$event->packet->worker] ??= [];
		while (($oldestUid = array_shift($this->procQueue[$event->packet->worker]))) {
			$resolver = $this->addQueue[$oldestUid] ?? null;
			if (isset($resolver)) {
				$this->logger->debug('UID {uid} inactive', ['uid' => $oldestUid]);
				EventLoop::queue($resolver->complete(...), null);
			}
		}
	}
}
