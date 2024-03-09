<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use function Safe\{json_decode, json_encode};
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	Util,
};
use Nadybot\Modules\RELAY_MODULE\{
	Layer\Chunker\Chunk,
	Relay,
	RelayLayerInterface,
	RelayMessage,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

#[
	NCA\RelayStackMember(
		name: "chunker",
		description: "This adds the ability to chunk and re-assemble\n".
			"long messages on the fly, so we can send large payloads\n".
			"over a medium that only has a limited package size.\n".
			"Of course this only works if all Bots use this chunker."
	),
	NCA\Param(
		name: "length",
		type: "int",
		description: "The maximum supported chunk size",
		required: true
	),
	NCA\Param(
		name: "timeout",
		type: "int",
		description: "How many seconds to wait for all packets to arrive",
		required: false
	)
]
class Chunker implements RelayLayerInterface {
	/** @psalm-var positive-int */
	protected int $chunkSize = 50000;
	protected int $timeout = 60;

	protected Relay $relay;

	/** @var array<string,array<int,Chunk>> */
	protected $queue = [];

	protected ?string $timerHandler = null;
	#[NCA\Logger]

	private LoggerInterface $logger;

	#[NCA\Inject]
	private Util $util;

	public function __construct(int $chunkSize, int $timeout=60) {
		if ($chunkSize < 1) {
			throw new InvalidArgumentException("length cannot be less than 1");
		}
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

	public function receive(RelayMessage $msg): ?RelayMessage {
		foreach ($msg->packages as &$data) {
			try {
				$json = json_decode($data, true);
				$mapper = new ObjectMapperUsingReflection();

				/** @var Chunk */
				$chunk = $mapper->hydrateObject(Chunk::class, $json);
			} catch (Throwable $e) {
				// Chunking is optional
				continue;
			}
			if ($chunk->count === 1) {
				$this->logger->debug("Single-chunk chunk received.");
				continue;
			}
			if (!isset($this->timerHandler)) {
				$this->logger->debug("Setup new cleanup call");
				$this->timerHandler = EventLoop::delay(10, function (string $token): void {
					$this->cleanStaleChunks();
				});
			}
			if (!isset($this->queue[$chunk->id])) {
				$this->logger->debug("New chunk {id} {part}/{count} received.", [
					"id" => $chunk->id,
					"part" => $chunk->part,
					"count" => $chunk->count,
				]);
				$chunk->sent = time();
				$this->queue[$chunk->id] = [
					$chunk->part => $chunk,
				];
				$data = null;
				continue;
			}
			$this->queue[$chunk->id][$chunk->part] = $chunk;
			if (count($this->queue[$chunk->id]) !== $chunk->count) {
				$this->logger->debug("New chunk part for {id} {part}/{count} received, still not complete.", [
					"id" => $chunk->id,
					"part" => $chunk->part,
					"count" => $chunk->count,
				]);
				// Not yet complete;
				$data = null;
				continue;
			}
			$this->logger->debug("New chunk part for {id} {part}/{count} received, now complete.", [
				"id" => $chunk->id,
				"part" => $chunk->part,
				"count" => $chunk->count,
			]);
			$data = "";
			for ($i = 1; $i <= $chunk->count; $i++) {
				$block = $this->queue[$chunk->id][$i]->data ?? null;
				if (!isset($block)) {
					unset($this->queue[$chunk->id]);
					$this->logger->error("Invalid data received.");
					$data = null;
					continue 2;
				}
				$data .= $block;
			}
			$this->logger->debug("Removed chunks from memory.");
			unset($this->queue[$chunk->id]);
		}
		$msg->packages = array_values(array_filter($msg->packages));
		return $msg;
	}

	public function cleanStaleChunks(): void {
		$this->timerHandler = null;
		$ids = array_keys($this->queue);
		foreach ($ids as $id) {
			$parts = array_keys($this->queue[$id]);
			if (!count($parts)
				|| time() - $this->queue[$id][$parts[0]]->sent > $this->timeout
			) {
				$this->logger->debug("Removing stale chunk {id}", ["id" => $id]);
				unset($this->queue[$id]);
			}
		}
		if (count($this->queue)) {
			$this->logger->debug("Calling cleanup in 10");
			$this->timerHandler = EventLoop::delay(10, function (string $token): void {
				$this->cleanStaleChunks();
			});
		} else {
			$this->logger->debug("No more unfinished chunks.");
		}
	}

	/**
	 * @return string[]
	 *
	 * @psalm-return list<string>
	 */
	protected function chunkPacket(string $packet): array {
		if (strlen($packet) < $this->chunkSize) {
			return [$packet];
		}

		/** @var string[] */
		$chunks = str_split($packet, $this->chunkSize);
		$result = [];
		$uuid = $this->util->createUUID();
		$part = 1;
		$created = time();
		foreach ($chunks as $chunk) {
			$msg = new Chunk(
				id: $uuid,
				part: $part++,
				count: count($chunks),
				sent: $created,
				data: $chunk,
			);
			$result []= json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
		}
		return $result;
	}
}
