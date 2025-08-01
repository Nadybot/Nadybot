<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use function Amp\Socket\connect;
use function Safe\ini_get;

use Amp\ByteStream\BufferException;
use Amp\{CancelledException, TimeoutCancellation, TimeoutException};
use Amp\Http\HttpStatus;
use Amp\Http\Server\{DefaultErrorHandler, HttpServer, Request, Response, Router, SocketHttpServer};
use Amp\Http\Server\Driver\{ConnectionLimitingClientFactory, ConnectionLimitingServerSocketFactory, SocketClientFactory};
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Socket\InternetAddress;
use Amp\Sync\LocalSemaphore;
use AO\Client\{SingleClient, WorkerConfig};
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use InvalidArgumentException;
use Nadybot\Core\{BotRunner, DB, Filesystem, Hydrator, Options, Safe};
use Nadybot\Core\Config\{AutoUnfreeze, BotConfig};
use Nadybot\Core\Drill;
use Nadybot\Core\Drill\{AbstractDrillPacket, DrillAuthMode, DrillConnection, DrillConnector, DrillHttpConnection};
use Nadylib\IMEX;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Description: Configuration of the Basic bot settings via WebUI
 *
 * @author Nadyita (RK5)
 */
class WebSetup {
	private ?DrillConnection $drillConnection=null;

	/** @var array<string,DrillHttpConnection> */
	private array $handlers = [];

	public function __construct(
		private Setup $setup,
		private BotConfig $configFile,
		private Options $options,
		private Filesystem $fs,
		private LoggerInterface $logger,
	) {
	}

	public function serve(): BotConfig {
		$errorHandler = new DefaultErrorHandler();

		$server = new SocketHttpServer(
			logger: $this->logger,
			serverSocketFactory: new ConnectionLimitingServerSocketFactory(new LocalSemaphore(100)),
			clientFactory: new ConnectionLimitingClientFactory(
				new SocketClientFactory($this->logger),
				$this->logger,
				10,
			),
			middleware: [new PreGzipMiddleware()],
		);
		$server->expose(new InternetAddress('127.0.0.1', 8_080));
		$server->expose(new InternetAddress('[::1]', 8_080));
		$router = new Router($server, $this->logger, $errorHandler);
		$footNote = '';
		if ($this->options->vueDevMode) {
			$fallbackHandler = new DebugToVue(port: 5_173);
			$footNote = "\n\nDon't forget to start the vite development server with\n".
				'"npm run dev -- --host"';
		} else {
			$fallbackHandler = new DocumentRoot(
				httpServer: $server,
				errorHandler: $errorHandler,
				root: __DIR__ . '/html',
				filesystem: $this->fs->getFilesystem()
			);
		}

		$router->setFallback($fallbackHandler);
		$router->addRoute('GET', '/characters', new ClosureRequestHandler($this->getAccountCharacters(...)));
		$router->addRoute('GET', '/specs', new ClosureRequestHandler($this->getSystemSpecs(...)));
		$router->addRoute('GET', '/timezones', new ClosureRequestHandler($this->getTimezones(...)));
		$router->addRoute('POST', '/config', new ClosureRequestHandler(fn (Request $request): Response => $this->saveConfig($server, $request)));
		$server->start($router, $errorHandler);
		if (!$this->options->vueDevMode) {
			EventLoop::queue($this->setupDrill(...));
		}
		$this->setup->showStep(
			"You can now connect to\n\n".
			"    http://127.0.0.1:8080\n\n".
			"to configure your bot.\n".
			$footNote
		);
		EventLoop::run();
		return $this->configFile;
	}

	/** @param array<string,string> $replacements */
	private function errPage(int $code, array $replacements=[]): Response {
		$text = str_replace(
			array_map(static fn (string $a): string => '{$' . $a . '}', array_keys($replacements)),
			array_values($replacements),
			$this->fs->read(__DIR__ . \DIRECTORY_SEPARATOR . 'html' . \DIRECTORY_SEPARATOR . $code . '.html')
		);
		return new Response($code, ['content-type' => 'text/html'], $text);
	}

	private function getAccountCharacters(Request $request): Response {
		$dimension = $request->getQueryParameter('dimension');
		$login = $request->getQueryParameter('login');
		$password = $request->getQueryParameter('password');
		if (!isset($dimension, $login, $password) || !ctype_digit($dimension)) {
			return $this->errPage(
				HttpStatus::BAD_REQUEST,
				['message' => 'Required parameters: dimension, login, password']
			);
		}

		/** @var ?list<\AO\Character> */
		$chars = null;
		$timeout = 10;
		try {
			$workerConf = new WorkerConfig(
				dimension: (int)$dimension,
				username: $login,
				password: $password,
				character: 'Xxxx'
			);
			$connection = connect(uri: $workerConf->getServer(), cancellation: new TimeoutCancellation($timeout));
			$client = new SingleClient(
				connection: new \AO\Connection(reader: $connection, writer: $connection),
				parser: \AO\Parser::createDefault(),
			);
			$chars = $client->getChars($workerConf->username, $workerConf->password);
		} catch (InvalidArgumentException) {
			return $this->errPage(
				HttpStatus::UNPROCESSABLE_ENTITY,
				['message' => "Unknown dimension &quot;{$dimension}&quot;"]
			);
		} catch (CancelledException $e) {
			if ($e->getPrevious() instanceof TimeoutException) {
				return $this->errPage(HttpStatus::REQUEST_TIMEOUT, ['timeout' => (string)$timeout]);
			}
			return $this->errPage(
				HttpStatus::INTERNAL_SERVER_ERROR,
				['message' => htmlentities($e->getMessage())],
			);
		} catch (\Throwable) {
			return $this->errPage(HttpStatus::UNAUTHORIZED);
		}
		return new Response(
			HttpStatus::OK,
			['content-type' => 'application/json'],
			IMEX\JSON::export(
				iterator_to_array(Hydrator::serializeObjects($chars), false)
			)
		);
	}

	private function getTimezones(Request $request): Response {
		$timezones = \DateTimeZone::listIdentifiers();
		$hierarchy = [];
		foreach ($timezones as $timezone) {
			$parts = explode('/', $timezone, 2);
			if (count($parts) === 2) {
				$hierarchy[$parts[0]] ??= [];
				$hierarchy[$parts[0]] []= $timezone;
			} else {
				$hierarchy['Other'] ??= [];
				$hierarchy['Other'] []= $timezone;
			}
		}
		return new Response(
			HttpStatus::OK,
			['content-type' => 'application/json'],
			Imex\JSON::export($hierarchy)
		);
	}

	private function getSystemSpecs(Request $request): Response {
		$memoryLimit = ini_get('memory_limit');
		if (count($matches = Safe::pregMatch('/^(\d+)([kmg])$/i', $memoryLimit)) === 3) {
			if (strtolower($matches[2]) === 'm') {
				$memoryLimit = (int)$matches[1] * 1_024 * 1_024;
			} elseif (strtolower($matches[2]) === 'k') {
				$memoryLimit = (int)$matches[1] * 1_024;
			} elseif (strtolower($matches[2]) === 'g') {
				$memoryLimit = (int)$matches[1] * 1_024 * 1_024 * 1_024;
			} else {
				$memoryLimit = (int)$matches[1];
			}
		}

		return new Response(
			HttpStatus::OK,
			['content-type' => 'application/json'],
			Imex\JSON::export([
				'bot_version' => BotRunner::getVersion(false),
				'php_version' => \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION . '.' . \PHP_RELEASE_VERSION,
				'os' => \PHP_OS_FAMILY,
				'databases' => DB::getSupportedDBs(),
				'memory' => [
					'current_usage' => memory_get_usage(),
					'current_usage_real' => memory_get_usage(true),
					'peak_usage' => memory_get_peak_usage(),
					'peak_usage_real' => memory_get_peak_usage(true),
					'available' => (int)$memoryLimit,
				],
			])
		);
	}

	private function handleDrillData(DrillConnection $connection, Drill\Packet\Data $packet): void {
		$this->logger->debug('Received data for UUID {uuid}: {data}', [
			'uuid' => $packet->uuid,
			'data' => $packet->data,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			$this->logger->info('New client connected via Drill');
			$handler = new DrillHttpConnection(
				uuid: $packet->uuid,
				host: '127.0.0.1',
				port: 8_080,
				logger: $this->logger,
				drillConnection: $connection,
			);
			$success = $handler->loop();
			if (!$success) {
				$this->logger->notice('Drill error connecting to local webserver, sending 502');
				$http = "HTTP/1.1 502\r\n".
					"Content-Length: 0\r\n".
					"\r\n";
				$errReply = new Drill\Packet\Data(uuid: $packet->uuid, data: $http);
				$connection->send($errReply);
				$closeReply = new Drill\Packet\Closed(uuid: $packet->uuid);
				$connection->send($closeReply);
				return;
			}
			$this->handlers[$packet->uuid] = $handler;
		}
		$this->handlers[$packet->uuid]->handle($packet);
	}

	private function setupDrill(): void {
		try {
			$client = new DrillConnector(uri: 'wss://drill.nadysetup.org', logger: $this->logger);
			$this->drillConnection = $client->connect();
			EventLoop::queue($this->sendAndReceiveDrill(...), $this->drillConnection);
		} catch (Throwable) {
			$this->logger->warning('No Drill connection for this web  setup');
		}
	}

	private function sendAndReceiveDrill(DrillConnection $connection): void {
		try {
			while (null !== ($message = $connection->receive())) {
				$this->processDrillMessage($connection, $message);
			}
		} catch (Throwable) {
			$connection->close();
		}
		$this->logger->info('Drill connection successfully closed.');
	}

	private function processDrillMessage(DrillConnection $connection, AbstractDrillPacket $packet): void {
		match (true) {
			$packet instanceof Drill\Packet\Hello => $this->handleDrillHello($connection, $packet),
			$packet instanceof Drill\Packet\LetsGo => $this->handleDrillLetsGo($connection, $packet),
			$packet instanceof Drill\Packet\Data => $this->handleDrillData($connection, $packet),
			$packet instanceof Drill\Packet\Closed => $this->handleDrillClosed($connection, $packet),
			default => throw new Exception('Inappropriate drill-package received'),
		};
	}

	private function handleDrillLetsGo(DrillConnection $connection, Drill\Packet\LetsGo $packet): void {
		$this->setup->showStep(
			"You can now connect to\n\n".
			"    {$packet->publicUrl} or \n".
			"    http://127.0.0.1:8080\n\n".
			"to configure your bot.\n"
		);
	}

	private function handleDrillClosed(DrillConnection $connection, Drill\Packet\Closed $packet): void {
		$this->logger->info('Drill received disconnect for UUID {uuid}', [
			'uuid' => $packet->uuid,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			return;
		}
		$this->handlers[$packet->uuid]->handleDisconnect();
		unset($this->handlers[$packet->uuid]);
	}

	private function handleDrillHello(DrillConnection $connection, Drill\Packet\Hello $packet): void {
		if ($packet->authMode !== DrillAuthMode::ANONYMOUS) {
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
		$connection->send($answer);
	}

	private function saveConfig(HttpServer $server, Request $request): Response {
		$contentType = $request->getHeader('content-type') ?? 'unset';
		if ($contentType !== 'application/json') {
			return $this->errPage(
				HttpStatus::UNSUPPORTED_MEDIA_TYPE,
				['content-type' => htmlentities($contentType)],
			);
		}
		try {
			$body = $request->getBody()->buffer(new TimeoutCancellation(10), 1*1_024*1_024);

			/** @var array<string,mixed> */
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
		} catch (IMEX\ImportException) {
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
			HttpStatus::NO_CONTENT,
		);
	}
}
