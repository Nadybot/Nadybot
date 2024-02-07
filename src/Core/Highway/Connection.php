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
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SemanticVersion;

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

	private static int $packageNumber = 0;

	public function __construct(
		private WsConnection $wsConnection
	) {
	}

	public function getVersion(): string {
		return $this->wsConnection->getResponse()->getHeader("x-highway-version") ?? "0.1.1";
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
		$this->logger->notice("[{protocol}{url}] Closing connection", [
			"protocol" => $this->wsConnection->getTlsInfo() ? "wss://" : "ws://",
			"url" => $this->wsConnection->getRemoteAddress()->toString(),
		]);
		/** @var Promise<array{int,string}> */
		$closeHandler = $this->wsConnection->close($code, $reason);
		return $closeHandler;
	}

	/** @return Promise<Package> */
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
			$this->logger->notice("[{protocol}{url}] Received data: {data}", [
				"protocol" => $this->wsConnection->getTlsInfo() ? "wss://" : "ws://",
				"url" => $this->wsConnection->getRemoteAddress()->toString(),
				"data" => $data,
			]);
			$package = $this->parseHighwayPackage($data);
			return $package;
		});
	}

	/** @return Promise<void> */
	public function send(Package $package): Promise {
		return call(function () use ($package): Generator {
			$package->id = sprintf("%06d", ++static::$packageNumber);
			$mapper = new ObjectMapperUsingReflection();
			$json = $mapper->serializeObject($package);
			if (!isset($json['id']) || SemanticVersion::compareUsing($this->getVersion(), "0.2.0-alpha.1", "<")) {
				unset($json['id']);
			}
			$data = json_encode($json, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
			$this->logger->notice("[{protocol}{url}] Sending data: {data}", [
				"protocol" => $this->wsConnection->getTlsInfo() ? "wss://" : "ws://",
				"url" => $this->wsConnection->getRemoteAddress()->toString(),
				"data" => $data,
			]);
			yield $this->wsConnection->send($data);
		});
	}

	protected function parseHighwayPackage(string $data): Package {
		$json = json_decode($data, true);
		$mapper = new ObjectMapperUsingReflection();
		$baseInfo = $mapper->hydrateObject(Package::class, $json);
		$targetClass = self::PKG_CLASSES[$baseInfo->type]??null;
		if (!isset($targetClass) || !class_exists($targetClass)) {
			return $baseInfo;
		}

		try {
			/** @var Package */
			$package = $mapper->hydrateObject($targetClass, $json);
		} catch (UnableToHydrateObject $e) {
			throw $e;
		}
		return $package;
	}
}
