<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Amp\call;
use function Safe\{json_decode, json_encode};
use Amp\Promise;
use Amp\Websocket\Client\Connection as WsConnection;

use Amp\Websocket\Message as WsMessage;
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
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

	/** @return Promise<Package> */
	public function receive(): Promise {
		return call(function (): Generator {
			/** @var WsMessage */
			$message = yield $this->wsConnection->receive();

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
