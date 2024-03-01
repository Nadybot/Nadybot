<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Amp\call;
use function Safe\json_encode;
use Amp\Promise;
use Amp\Websocket\Client\Connection as WsConnection;
use Amp\Websocket\{ClosedException, Code, Message as WsMessage};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Generator;
use Nadybot\Core\Highway\In\InPackage;
use Nadybot\Core\Highway\Out\OutPackage;
use Nadybot\Core\{Attributes as NCA, LogWrapInterface, LoggerWrapper, SemanticVersion};

class Connection implements LogWrapInterface {
	public const SUPPORTED_VERSIONS = ["~0.1.1", "~0.2.0-alpha.1"];

	#[NCA\Logger]
	private LoggerWrapper $logger;

	public function __construct(
		private WsConnection $wsConnection
	) {
	}

	/**
	 * Wrap the logger by modifying all logging parameters
	 *
	 * @param 100|200|250|300|400|500|550|600 $logLevel
	 * @param array<string,mixed>             $context
	 *
	 * @return array{100|200|250|300|400|500|550|600, string, array<string, mixed>}
	 */
	public function wrapLogs(int $logLevel, string $message, array $context): array {
		$context['protocol'] = $this->wsConnection->getTlsInfo() ? "wss" : "ws";
		$connUri = $this->wsConnection->getResponse()->getRequest()->getUri();
		$context['host'] = $connUri->getHost();
		$port = $connUri->getPort();
		$prefix = "{protocol}://{host}";
		if (isset($port)) {
			$prefix .= "::{port}";
			$context['port'] = $port;
		}
		$message = "[{$prefix}] " . $message;
		return [$logLevel, $message, $context];
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
	 *                                    These may differ from those provided if the connection was closed prior.
	 */
	public function close(int $code=Code::NORMAL_CLOSE, string $reason=''): promise {
		$this->logger->info("Closing connection");

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
			$this->logger->debug("Received data: {data}", ["data" => $data]);
			try {
				$package = Parser::parseHighwayPackage($data);
			} catch (UnableToHydrateObject $e) {
				$this->logger->error("Invalid highway-package received");
				throw $e;
			}
			$this->logger->info("Received package {package}", ["package" => $package]);
			return $package;
		});
	}

	/** @return Promise<void> */
	public function send(OutPackage $package): Promise {
		return call(function () use ($package): Generator {
			$this->logger->info("Sending package {package}", ["package" => $package]);
			$mapper = new ObjectMapperUsingReflection();
			$json = $mapper->serializeObject($package);
			$serverSupportsIds = SemanticVersion::compareUsing($this->getVersion(), "0.2.0-alpha.1", ">=");
			if (!isset($json['id']) || !$serverSupportsIds) {
				unset($json['id']);
			}
			$data = json_encode($json, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
			$this->logger->debug("Sending data: {data}", ["data" => $data]);
			yield $this->wsConnection->send($data);
		});
	}
}
