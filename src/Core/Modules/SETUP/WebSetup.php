<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use Amp\ByteStream\BufferException;
use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Http\HttpStatus;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Server\{DefaultErrorHandler, HttpServer, Request, Response, Router, SocketHttpServer};
use Amp\Socket\{ConnectContext, InternetAddress};
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\{Rfc6455Connector, WebsocketConnection, WebsocketHandshake};
use Amp\Websocket\WebsocketClosedException;
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\Config\{AutoUnfreeze, BotConfig};
use Nadybot\Core\{BotRunner, DB, Filesystem, Hydrator};
use Nadybot\Modules\WEBSERVER_MODULE\Drill;
use Nadylib\IMEX;
use Nadylib\IMEX\ImportException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Description: Configuration of the Basic bot settings via WebUI
 *
 * @author Nadyita (RK5)
 */
class WebSetup {
	private ?WebsocketConnection $drillConnection=null;

	/** @var array<string,DrillConnection> */
	private array $handlers = [];

	public function __construct(
		private Setup $setup,
		private BotConfig $configFile,
		private Filesystem $fs,
		private LoggerInterface $logger,
	) {
	}

	public function serve(): BotConfig {
		$errorHandler = new DefaultErrorHandler();

		$server = SocketHttpServer::createForDirectAccess($this->logger);
		$server->expose(new InternetAddress('0.0.0.0', 8_080));
		$server->expose(new InternetAddress('[::]', 8_080));
		$documentRoot = new DocumentRoot(
			httpServer: $server,
			errorHandler: $errorHandler,
			root: __DIR__ . '/html',
			filesystem: $this->fs->getFilesystem()
		);
		$router = new Router($server, $this->logger, $errorHandler);
		$router->setFallback($documentRoot);
		$router->addRoute('GET', '/specs', new ClosureRequestHandler($this->getSystemSpecs(...)));
		$router->addRoute('POST', '/config', new ClosureRequestHandler(fn (Request $request): Response => $this->saveConfig($server, $request)));
		$server->start($router, $errorHandler);
		EventLoop::queue($this->setupDrill(...));
		$this->setup->showStep(
			"You can now connect to\n\n".
			"    http://127.0.0.1:8080\n\n".
			"to configure your bot.\n"
		);
		EventLoop::run();
		return $this->configFile;
	}

	private function getSystemSpecs(Request $request): Response {
		return new Response(
			HttpStatus::OK,
			['content-type' => 'application/json'],
			Imex\JSON::export([
				'bot_version' => BotRunner::getVersion(false),
				'php_version' => \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION . '.' . \PHP_RELEASE_VERSION,
				'os' => \PHP_OS_FAMILY,
				'databases' => DB::getSupportedDBs(),
			])
		);
	}

	private function handleDrillData(WebsocketConnection $connection, Drill\Packet\Data $packet): void {
		$this->logger->debug('Received data for UUID {uuid}: {data}', [
			'uuid' => $packet->uuid,
			'data' => $packet->data,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			$this->logger->info('New client connected via Drill');
			$handler = new DrillConnection(
				uuid: $packet->uuid,
				host: '127.0.0.1',
				port: 8_080,
				logger: $this->logger,
				wsConnection: $connection,
			);
			$success = $handler->loop();
			if (!$success) {
				$this->logger->notice('Drill error connecting to local webserver, sending 502');
				$http = "HTTP/1.1 502\r\n".
					"Content-Length: 0\r\n".
					"\r\n";
				$errReply = new Drill\Packet\Data(uuid: $packet->uuid, data: $http);
				$connection->sendBinary($errReply->toString());
				$closeReply = new Drill\Packet\Closed(uuid: $packet->uuid);
				$connection->sendBinary($closeReply->toString());
				return;
			}
			$this->handlers[$packet->uuid] = $handler;
		}
		$this->handlers[$packet->uuid]->handle($packet);
	}

	private function setupDrill(): void {
		$url = 'wss://drill.nadysetup.org';
		$handshake = new WebsocketHandshake($url);
		$connectContext = (new ConnectContext())->withTcpNoDelay();
		$httpClient = (new HttpClientBuilder())
			->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
			->intercept(new RemoveRequestHeader('origin'))
			->build();
		$client = new Rfc6455Connector(httpClient: $httpClient);
		try {
			$this->logger->info('Connecting to Drill server {url}', ['url' => $url]);

			$this->drillConnection = $connection = $client->connect($handshake, null);
			while (null !== ($message = $connection->receive())) {
				$payload = $message->buffer();

				$this->processWebsocketMessage($connection, $payload);
			}
			if ($connection->getCloseInfo()->isByPeer()) {
				throw new WebsocketClosedException(
					'Drill unexpectedly closed the connection',
					$connection->getCloseInfo()->getCode(),
					$connection->getCloseInfo()->getReason(),
				);
			}
		} catch (Throwable $e) {
			$this->logger->error('Still endpoint errored: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		} finally {
			unset($client);
		}
		$this->logger->info('Connection to {url} successfully closed.', [
			'url' => $url,
		]);
	}

	private function processWebsocketMessage(WebsocketConnection $connection, string $msg): void {
		try {
			$packet = Drill\PacketFactory::parse($msg);
		} catch (Drill\UnsupportedPacketException $e) {
			$this->logger->warning('Received unsupported Drill package type {type}', [
				'type' => $e->getMessage(),
				'exception' => $e,
			]);
			return;
		}
		$this->logger->debug('Received Drill-package {package}', [
			'package' => $packet,
		]);
		match (true) {
			$packet instanceof Drill\Packet\Hello => $this->handleDrillHello($connection, $packet),
			$packet instanceof Drill\Packet\LetsGo => $this->handleDrillLetsGo($connection, $packet),
			$packet instanceof Drill\Packet\Data => $this->handleDrillData($connection, $packet),
			$packet instanceof Drill\Packet\Closed => $this->handleDrillClosed($connection, $packet),
			default => throw new Exception('Inappropriate drill-package received'),
		};
	}

	private function handleDrillLetsGo(WebsocketConnection $connection, Drill\Packet\LetsGo $packet): void {
		$this->setup->showStep(
			"You can now connect to\n\n".
			"    {$packet->publicUrl} or \n".
			"    http://127.0.0.1:8080\n\n".
			"to configure your bot.\n"
		);
	}

	private function handleDrillClosed(WebsocketConnection $connection, Drill\Packet\Closed $packet): void {
		$this->logger->info('Drill received disconnect for UUID {uuid}', [
			'uuid' => $packet->uuid,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			return;
		}
		$this->handlers[$packet->uuid]->handleDisconnect();
		unset($this->handlers[$packet->uuid]);
	}

	private function handleDrillHello(WebsocketConnection $connection, Drill\Packet\Hello $packet): void {
		if ($packet->authMode !== Drill\Auth::ANONYMOUS) {
			$this->logger->error("Drill server doesn't support Anonymous authentication");
			$connection->close();
			return;
		}
		if ($packet->protoVersion !== 1) {
			$this->logger->error('Drill server runs unsupported protocol version');
			$connection->close();
			return;
		}
		$answer = new Drill\Packet\PresentToken(token: str_repeat('x', 36));
		$connection->sendBinary($answer->toString());
	}

	private function saveConfig(HttpServer $server, Request $request): Response {
		$contentType = $request->getHeader('content-type');
		if ($contentType !== 'application/json') {
			return new Response(
				HttpStatus::UNSUPPORTED_MEDIA_TYPE,
				['content-type' => 'text/plain'],
				'Please only send json with proper content-type application/json'
			);
		}
		try {
			$body = $request->getBody()->buffer(new TimeoutCancellation(10), 1*1_024*1_024);
			$data = IMEX\JSON::import($body);
			$data['file_path'] = $this->configFile->getFilePath();
			$config = Hydrator::hydrate(BotConfig::class, $data);
			$config->autoUnfreeze ??= new AutoUnfreeze();
			// Save the entered info to $configFile
			$config->save($this->fs);
			$this->configFile = $config;
		} catch (BufferException) {
			return new Response(
				HttpStatus::PAYLOAD_TOO_LARGE,
				['content-type' => 'text/plain'],
				'This is way too large for a json config'
			);
		} catch (ImportException) {
			return new Response(
				HttpStatus::UNPROCESSABLE_ENTITY,
				['content-type' => 'text/plain'],
				'This is not a valid json string'
			);
		} catch (UnableToHydrateObject $e) {
			return new Response(
				HttpStatus::UNPROCESSABLE_ENTITY,
				['content-type' => 'text/plain'],
				'This is not a valid config: ' . $e->getMessage(),
			);
		}
		$drill = $this->drillConnection;
		if (isset($drill)) {
			EventLoop::queue($drill->close(...));
		}
		EventLoop::queue($server->stop(...));
		return new Response(
			HttpStatus::OK,
			['content-type' => 'text/plain'],
			'Hello, script!'
		);
	}
}
