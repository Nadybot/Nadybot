<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Timer;
use Nadybot\Core\TimerEvent;
use Nadybot\Core\Util;
use Nadybot\Modules\RELAY_MODULE\Layer\Chunker\Chunk;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
use Throwable;

/**
 * @RelayStackMember("chunker")
 * @Description("This adds the ability to chunk and re-assemble
 * 	long messages on the fly, so we can send large payloads
 * 	over a medium that only has a limited package size.
 * 	Of course this only works if all Bots use this chunker.")
 * @Param(name='length', description='The maximum supported chunk size', type='int', required=true)
 * @Param(name='timeout', description='How many seconds to wait for all packets to arrive', type='int', required=false)
 */
class Chunker implements RelayLayerInterface {
	protected int $chunkSize = 50000;
	protected int $timeout = 60;

	protected Relay $relay;

	/** @var array<string,array<int,Chunk>> */
	protected $queue = [];

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public Util $util;

	protected TimerEvent $timerEvent;

	/** @Logger */
	public LoggerWrapper $logger;

	public function __construct(int $chunkSize, int $timeout=60) {
		$this->chunkSize = $chunkSize;
		$this->timeout = $timeout;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function init(callable $callback): array {
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function send(array $data): array {
		$encoded = [];
		foreach ($data as $packet) {
			$encoded = [...$encoded, ...$this->chunkPacket($packet)];
		}
		return $encoded;
	}

	/**
	 * @return string[]
	 * @psalm-return list<string>
	 */
	protected function chunkPacket(string $packet): array {
		if (strlen($packet) < $this->chunkSize) {
			return [$packet];
		}
		$chunks = str_split($packet, $this->chunkSize);
		if ($chunks === false) {
			return [$packet];
		}
		$result = [];
		$uuid = $this->util->createUUID();
		$part = 1;
		$created = time();
		foreach ($chunks as $chunk) {
			$msg = new Chunk([
				"id" => $uuid,
				"part" => $part++,
				"count" => count($chunks),
				"sent" => $created,
				"data" => $chunk,
			]);
			$json = json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			if ($json !== false) {
				$result []= json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			}
		}
		return $result;
	}

	public function receive(RelayMessage $msg): ?RelayMessage {
		foreach ($msg->packages as &$data) {
			try {
				$json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
				$chunk = new Chunk($json);
			} catch (Throwable $e) {
				// Chunking is optional
				continue;
			}
			if ($chunk->count === 1) {
				$this->logger->log("INFO", "Single-chunk chunk received.");
				continue;
			}
			if (!isset($this->timerEvent)) {
				$this->logger->log("INFO", "Setup new cleanup call");
				$this->timerEvent = $this->timer->callLater(10, [$this, "cleanStaleChunks"]);
			}
			if (!isset($this->queue[$chunk->id])) {
				$this->logger->log("INFO", "New chunk {$chunk->id} {$chunk->part}/{$chunk->count} received.");
				$chunk->sent = time();
				$this->queue[$chunk->id] = [
					$chunk->part => $chunk
				];
				$data = null;
				continue;
			}
			$this->queue[$chunk->id][$chunk->part] = $chunk;
			if (count($this->queue[$chunk->id]) !== $chunk->count) {
				$this->logger->log("INFO", "New chunk part for {$chunk->id} {$chunk->part}/{$chunk->count} received, still not complete.");
				// Not yet complete;
				$data = null;
				continue;
			}
			$this->logger->log("INFO", "New chunk part for {$chunk->id} {$chunk->part}/{$chunk->count} received, now complete.");
			$data = "";
			for ($i = 1; $i <= $chunk->count; $i++) {
				$block = $this->queue[$chunk->id][$i]->data ?? null;
				if (!isset($block)) {
					unset($this->queue[$chunk->id]);
					$this->logger->log("ERROR", "Invalid data received.");
					$data = null;
					continue 2;
				}
				$data .= $block;
			}
			$this->logger->log("INFO", "Removed chunks from memory.");
			unset($this->queue[$chunk->id]);
		}
		$msg->packages = array_values(array_filter($msg->packages));
		return $msg;
	}

	public function cleanStaleChunks(): void {
		unset($this->timerEvent);
		$ids = array_keys($this->queue);
		foreach ($ids as $id) {
			$parts = array_keys($this->queue[$id]);
			if (!count($parts)
				|| time() - $this->queue[$id][$parts[0]]->sent > $this->timeout
			) {
				$this->logger->log("INFO", "Removing stale chunk {$id}");
				unset($this->queue[$id]);
			}
		}
		if (count($this->queue)) {
			$this->logger->log("INFO", "Calling cleanup in 10");
			$this->timerEvent = $this->timer->callLater(10, [$this, "cleanStaleChunks"]);
		} else {
			$this->logger->log("INFO", "No more unfinished chunks.");
		}
	}
}
