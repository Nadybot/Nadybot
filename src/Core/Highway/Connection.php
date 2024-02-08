<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Amp\call;
use function Safe\{json_decode, json_encode};
use Amp\Promise;
use Amp\Websocket\Client\Connection as WsConnection;
use Amp\Websocket\{ClosedException, Code, Message as WsMessage};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Generator;
use Nadybot\Core\Highway\In\{Error, Hello, InPackage, Join, Leave, Message, RoomInfo, Success};
use Nadybot\Core\Highway\Out\OutPackage;
use Nadybot\Core\{Attributes as NCA, LoggerWrapper, SemanticVersion};

class Connection {
	public const SUPPORTED_VERSIONS = ["~0.1.1", "~0.2.0-alpha.1"];

	private const PKG_CLASSES = [
		"hello" => Hello::class,
		"error" => Error::class,
		"success" => Success::class,
		"join" => Join::class,
		"room-info" => RoomInfo::class,
		"room_info" => RoomInfo::class,
		"message" => Message::class,
		"leave" => Leave::class,
	];

	#[NCA\Logger]
	private LoggerWrapper $logger;

	public function __construct(
		private WsConnection $wsConnection
	) {
	}

	public function getVersion(): string {
		return $this->wsConnection->getResponse()->getHeader("x-highway-version") ?? "0.1.1";
	}

	private function getUri(): string {
		$protocol = $this->wsConnection->getTlsInfo() ? "wss" : "ws";
		$connUri = $this->wsConnection->getResponse()->getRequest()->getUri();
		$host = $connUri->getHost();
		$port = $connUri->getPort();
		$result = "{$protocol}://{$host}";
		if (isset($port)) {
			$result .= "::{$port}";
		}
		return $result;
	}

	public function isSupportedVersion(): bool {
		$version = $this->getVersion();
		foreach (self::SUPPORTED_VERSIONS as $supported) {
			if (SemanticVersion::inMask($supported, $version)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return Promise<array{int,string}> Resolves with an array containing the close code at key 0 and the close reason at key 1.
	 *                        These may differ from those provided if the connection was closed prior.
	 */
	public function close(int $code=Code::NORMAL_CLOSE, string $reason=''): promise {
		$this->logger->info("[{uri}] Closing connection", [
			"uri" => $this->getUri(),
		]);

		/** @var Promise<array{int,string}> */
		$closeHandler = $this->wsConnection->close($code, $reason);
		return $closeHandler;
	}

	/** @return Promise<InPackage> */
	public function receive(): Promise {
		return call(function (): Generator {
			/** @var ?WsMessage */
			$message = yield $this->wsConnection->receive();
			if (!isset($message)) {
				if (!$this->wsConnection->isConnected()) {
					throw new ClosedException(
						'Highway-connection closed unexpectedly',
						Code::ABNORMAL_CLOSE,
						'Reading from the server failed'
					);
				}
				throw new Exception('Empty Highway-package received');
			}

			/** @var string */
			$data = yield $message->buffer();
			$this->logger->debug("[{uri}] Received data: {data}", [
				"uri" => $this->getUri(),
				"data" => $data,
			]);
			$package = $this->parseHighwayPackage($data);
			$this->logger->info("[{uri}] Received package {package}", [
				"uri" => $this->getUri(),
				"package" => $package->toString(),
			]);
			return $package;
		});
	}

	/** @return Promise<void> */
	public function send(OutPackage $package): Promise {
		return call(function () use ($package): Generator {
			$this->logger->info("[{uri}] Sending package {package}", [
				"uri" => $this->getUri(),
				"package" => $package->toString(),
			]);
			$mapper = new ObjectMapperUsingReflection();
			$json = $mapper->serializeObject($package);
			$serverSupportsIds = SemanticVersion::compareUsing($this->getVersion(), "0.2.0-alpha.1", ">=");
			if (!isset($json['id']) || !$serverSupportsIds) {
				unset($json['id']);
			}
			$data = json_encode($json, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
			$this->logger->debug("[{uri}] Sending data: {data}", [
				"uri" => $this->getUri(),
				"data" => $data,
			]);
			yield $this->wsConnection->send($data);
		});
	}

	protected function parseHighwayPackage(string $data): InPackage {
		$json = json_decode($data, true);
		$mapper = new ObjectMapperUsingReflection();
		$baseInfo = $mapper->hydrateObject(InPackage::class, $json);
		$targetClass = self::PKG_CLASSES[$baseInfo->type]??null;
		if (!isset($targetClass) || !class_exists($targetClass)) {
			return $baseInfo;
		}

		try {
			/** @var InPackage */
			$package = $mapper->hydrateObject($targetClass, $json);
		} catch (UnableToHydrateObject $e) {
			throw $e;
		}
		return $package;
	}
}
