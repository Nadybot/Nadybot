<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\{WebsocketCloseCode, WebsocketClosedException};
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\Highway\In\InPackage;
use Nadybot\Core\Highway\Out\OutPackage;
use Nadybot\Core\Types\LogWrapInterface;
use Nadybot\Core\{Attributes as NCA, Hydrator, LoggerWrapper, SemanticVersion};
use Nadylib\IMEX\JSON;

/** A connection to a highway server */
class Connection implements LogWrapInterface {
	public const SUPPORTED_VERSIONS = ['~0.1.1', '~0.2.0-alpha.1'];

	#[NCA\Logger]
	private LoggerWrapper $logger;

	public function __construct(
		private WebsocketConnection $wsConnection
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
		$context['protocol'] = $this->wsConnection->getTlsInfo() ? 'wss' : 'ws';
		$connUri = $this->wsConnection->getHandshakeResponse()->getRequest()->getUri();
		$context['host'] = $connUri->getHost();
		$port = $connUri->getPort();
		$prefix = '{protocol}://{host}';
		if (isset($port)) {
			$prefix .= '::{port}';
			$context['port'] = $port;
		}
		$message = "[{$prefix}] " . $message;
		return [$logLevel, $message, $context];
	}

	/** Get the highway protocol version of the highway server */
	public function getVersion(): string {
		return $this->wsConnection->getHandshakeResponse()->getHeader('x-highway-version') ?? '0.1.1';
	}

	/** Check if the highway version of the server is a supported version by this bot */
	public function isSupportedVersion(): bool {
		$version = $this->getVersion();
		foreach (self::SUPPORTED_VERSIONS as $supported) {
			if (SemanticVersion::inMask($supported, $version)) {
				return true;
			}
		}
		return false;
	}

	/** Close the connection to the highway server */
	public function close(int $code=WebsocketCloseCode::NORMAL_CLOSE, string $reason=''): void {
		$this->logger->info('Closing connection');

		$this->wsConnection->close($code, $reason);
	}

	public function receive(): InPackage {
		$message = $this->wsConnection->receive();
		if (!isset($message)) {
			if ($this->wsConnection->isClosed()) {
				throw new WebsocketClosedException(
					'Highway-connection closed unexpectedly',
					WebsocketCloseCode::ABNORMAL_CLOSE,
					'Reading from the server failed'
				);
			}
			throw new Exception('Empty Highway-package received');
		}

		$data = $message->buffer();
		$this->logger->debug('Received data: {data}', ['data' => $data]);
		try {
			$package = Parser::parseHighwayPackage($data);
		} catch (UnableToHydrateObject $e) {
			$this->logger->error('Invalid highway-package received', [
				'exception' => $e,
			]);
			throw $e;
		}
		$this->logger->info('Received package {package}', ['package' => $package]);
		return $package;
	}

	public function send(OutPackage $package): void {
		$this->logger->info('Sending package {package}', ['package' => $package]);
		$json = Hydrator::serialize($package);
		$serverSupportsIds = SemanticVersion::compareUsing($this->getVersion(), '0.2.0-alpha.1', '>=');
		if (!isset($json['id']) || !$serverSupportsIds) {
			unset($json['id']);
		}
		$data = JSON::export($json, \JSON_INVALID_UTF8_SUBSTITUTE);
		$this->logger->debug('Sending data: {data}', ['data' => $data]);
		$this->wsConnection->sendText($data);
	}
}
