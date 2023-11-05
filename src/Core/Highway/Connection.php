<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Amp\call;
use function Safe\{json_decode, json_encode};
use Amp\Promise;
use Amp\Websocket\Client\Connection as WsConnection;
use Amp\Websocket\{ClosedException, Code, Message as WsMessage};
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Exception;
use Generator;

class Connection {
	private const PKG_CLASSES = [
		"hello" => Hello::class,
		"error" => Error::class,
		"success" => Success::class,
		"join" => Join::class,
		"room-info" => RoomInfo::class,
		"message" => Message::class,
		"leave" => Leave::class,
	];

	public function __construct(
		private WsConnection $wsConnection
	) {
	}

	/**
	 * @return Promise<array{int,string}> Resolves with an array containing the close code at key 0 and the close reason at key 1.
	 *                        These may differ from those provided if the connection was closed prior.
	 */
	public function close(int $code=Code::NORMAL_CLOSE, string $reason=''): promise {
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
			$package = $this->parseHighwayPackage($data);
			return $package;
		});
	}

	/** @return Promise<void> */
	public function send(Package $package): Promise {
		return call(function () use ($package): Generator {
			$mapper = new ObjectMapperUsingReflection();
			$json = $mapper->serializeObject($package);
			yield $this->wsConnection->send(json_encode($json, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
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

		/** @var Package */
		$package = $mapper->hydrateObject($targetClass, $json);
		return $package;
	}
}
