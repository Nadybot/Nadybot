<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Timer;
use Nadybot\Core\TimerEvent;
use Nadybot\Modules\RELAY_MODULE\Layer\Chunker\Chunk;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;

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

	public function send(array $packets): array {
		$encoded = [];
		foreach ($packets as $packet) {
			$encoded = [...$encoded, ...$this->chunkPacket($packet)];
		}
		return $encoded;
	}

	public function createUUID(): string {
		$data = random_bytes(16);

		// Set version to 0100
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		// Set bits 6-7 to 10
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);

		// Output the 36 character UUID.
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	protected function chunkPacket(string $packet): array {
		if (strlen($packet) < $this->chunkSize) {
			return [$packet];
		}
		$chunks = str_split($packet, $this->chunkSize);
		$result = [];
		$uuid = $this->createUUID();
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
			$result []= json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
		}
		return $result;
	}

	public function receive(string $data): ?string {
		try {
			$json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
			$chunk = new Chunk($json);
		} catch (JsonException $e) {
			// Chunking is optional
			return $data;
		}
		if ($chunk->count === 1) {
			return $chunk->data;
		}
		if (!isset($this->timerEvent)) {
			$this->timerEvent = $this->timer->callLater(10, [$this, "cleanStaleChunks"]);
		}
		if (!isset($this->queue[$chunk->id])) {
			$chunk->sent = time();
			$this->queue[$chunk->id] = [
				$chunk->part => $chunk
			];
			return null;
		}
		$this->queue[$chunk->id][$chunk->part] = $chunk;
		if (count($this->queue[$chunk->id]) !== $chunk->count) {
			// Not yet complete;
			return null;
		}
		$data = "";
		for ($i = 1; $i <= $chunk->count; $i++) {
			$block  = $this->queue[$chunk->id][$i]->data ?? null;
			if (!isset($block)) {
				unset($this->queue[$chunk->id]);
				return null;
			}
			$data .= $block;
		}
		unset($this->queue[$chunk->id]);
		return $data;
	}

	public function cleanStaleChunks(): void {
		unset($this->timerEvent);
		$ids = array_keys($this->queue);
		foreach ($ids as $id) {
			$parts = array_keys($this->queue[$id]);
			if (!count($parts)
				|| time() - $this->queue[$id][$parts[0]]->sent > $this->timeout
			) {
				unset($this->queue[$id]);
			}
		}
		if (count($this->queue)) {
			$this->timerEvent = $this->timer->callLater(10, [$this, "cleanStaleChunks"]);
		}
	}
}
