<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use ErrorException;
use Exception;
use Nadybot\Core\EventLoop;
use Nadybot\Core\EventManager;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
use Nadybot\Modules\RELAY_MODULE\RelayStatus;
use Nadybot\Modules\RELAY_MODULE\StatusProvider;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * @RelayTransport("amqp")
 * @Description("AMQP is a transport layer provided by software like RabbitMQ.
 * 	It allows near-realtime communication, but because the server is not part
 * 	of Anarchy Online, you might have a hard time debugging errors.
 * 	AMQP has a built-in transport protocol: Every client can subscribe
 * 	to one or more exchanges and sending a message to an exchange will
 * 	automatically send it to everyone else that's subscribed to it.
 * 	This transport was introduced in Nadybot 5.0.")
 * @Param(
 * 	name='exchange',
 * 	description='The name of the exchange to subscribe to. Use a,b,c to subscribe to more than one.',
 * 	type='string',
 * 	required=true
 * )
 * @Param(
 * 	name='user',
 * 	description='The username to connect with.',
 * 	type='string',
 * 	required=true
 * )
 * @Param(
 * 	name='password',
 * 	description='The password to connect with.',
 * 	type='string',
 * 	required=true
 * )
 * @Param(
 * 	name='server',
 * 	description='Hostname or IP address of the AMQP server.',
 * 	type='string',
 * 	required=true
 * )
 * @Param(
 * 	name='port',
 * 	description='The port of the AMQP server.',
 * 	type='int',
 * 	required=false
 * )
 * @Param(
 * 	name='vhost',
 * 	description='If your AMQP server is setup to use virtual hosts, set yours here.',
 * 	type='string',
 * 	required=false
 * )
 * @Param(
 * 	name='queue',
 * 	description='The name of the queue we will use internally. Set this if you have multiple relays to the same AMQP server.',
 * 	type='string',
 * 	required=false
 * )
 * @Param(
 * 	name='reconnect-interval',
 * 	description='Seconds to wait between reconnects.',
 * 	type='int',
 * 	required=false
 * )
 */
class AMQP implements TransportInterface, StatusProvider {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public EventManager $eventManager;

	protected ?AMQPChannel $channel = null;

	protected ?AMQPStreamConnection $connection;

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** Slot we use in the event loop */
	protected int $loopTicket;

	protected $initCallback;

	/**
	 * Did we receive a message on our last wait for new messages?
	 */
	private bool $lastWaitReceivedMessage = false;

	protected int $lastConnectTry = 0;
	protected int $reconnectInterval = 60;

	private ?string $queueName = null;

	/** @var array<string,AMQPExchange> */
	private array $exchanges = [];

	protected array $exchangeNames = [];
	protected string $user;
	protected string $password;
	protected string $server;
	protected int $port;
	protected string $vhost;

	protected int $eventTicket;

	public function __construct(
		 string $exchange,
		 string $user,
		 string $password,
		 string $server="127.0.0.1",
		 int $port=5672,
		 string $vhost="/",
		 string $queueName=null,
		 int $reconnectInterval=60
	) {
		$this->exchangeNames = explode(",", $exchange);
		$this->server = $server;
		$this->user = $user;
		$this->password = $password;
		$this->port = $port;
		if ($port < 1 || $port > 65535) {
			throw new Exception("port must be between 1 and 65535");
		}
		$this->vhost = $vhost;
		$this->queueName = $queueName;
		$this->reconnectInterval = $reconnectInterval;
	}

	public function getStatus(): RelayStatus {
		return $this->status ?? new RelayStatus();
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function send(array $data): array {
		foreach ($data as $chunk) {
			foreach ($this->exchangeNames as $exchange) {
				$this->sendMessage($exchange, $chunk);
			}
		}
		return [];
	}

	public function init(callable $callback): array {
		$this->queueName ??= $this->chatBot->char->name;
		$this->initCallback = $callback;
		foreach ($this->exchangeNames as $exchange) {
			$exchObject = new AMQPExchange();
			$exchObject->name = $exchange;
			$exchObject->type = AMQPExchangeType::FANOUT;
			$this->connectExchange($exchObject);
		}
		if (!isset($this->eventTicket)) {
			$this->eventTicket = EventLoop::add([$this, "processMessages"]);
		}
		return [];
	}

	public function deinit(callable $callback): array {
		foreach ($this->exchangeNames as $exchange) {
			$this->disconnectExchange($exchange);
		}
		if (isset($this->connection) && $this->connection->isConnected()) {
			$this->connection->close();
		}
		$callback();
		if (isset($this->eventTicket)) {
			EventLoop::rem($this->eventTicket);
			unset($this->eventTicket);
		}
		return [];
	}

	/**
	 * Connect our channel to a new exchange
	 * Don't try to connect if we're not (yet) connected
	 */
	public function connectExchange(AMQPExchange $exchange): bool {
		if (isset($this->exchanges[$exchange->name])) {
			return true;
		}
		if ($this->channel === null) {
			$this->exchanges[$exchange->name] = $exchange;
			return true;
		}
		try {
			if ($exchange->type === AMQPExchangeType::FANOUT) {
				$this->channel->exchange_declare($exchange->name, AMQPExchangeType::FANOUT, false, false, true);
			}
			if (count($exchange->routingKeys)) {
				foreach ($exchange->routingKeys as $key) {
					$this->channel->queue_bind($this->queueName, $exchange->name, $key);
				}
			} else {
				$this->channel->queue_bind($this->queueName, $exchange->name);
			}
		} catch (Exception $e) {
			$this->exchanges[$exchange->name] = $exchange;
			return false;
		}
		$this->logger->log("INFO", "Now connected to {$exchange->type} AMQP exchange \"{$exchange->name}\".");
		return true;
	}

	/**
	 * Disconnect our channel from an exchange
	 * Don't try to connect if we're not (yet) connected
	 */
	public function disconnectExchange(string $exchange): bool {
		if (!isset($this->exchanges[$exchange])) {
			return true;
		}
		unset($this->exchanges[$exchange]);
		if ($this->channel === null) {
			return true;
		}
		try {
			$this->channel->queue_unbind($this->queueName, $exchange);
		} catch (Exception $e) {
			return false;
		}
		$this->logger->log("INFO", "No longer listening for AMQP messages on exchange {$exchange}.");
		return true;
	}

	/**
	 * Get the channel object by trying to connect
	 */
	public function getChannel(): ?AMQPChannel {
		if (isset($this->channel)) {
			return $this->channel;
		}
		// Only try to (re)connect once every minute
		if (time() - $this->lastConnectTry < $this->reconnectInterval) {
			return null;
		}
		$this->lastConnectTry = time();
		$this->status = new RelayStatus(RelayStatus::INIT, "Connecting");
		try {
			$connection = new AMQPStreamConnection(
				$this->server,
				$this->port,
				$this->user,
				$this->password,
				$this->vhost
			);
		} catch (AMQPIOException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Connection to AMQP server failed: ' . $e->getMessage()
			);
			$this->logger->log('INFO', $this->status->text);
			return null;
		} catch (Throwable $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Connection to AMQP server failed: ' . $e->getMessage()
			);
			$this->logger->log(
				'INFO',
				'Connection to AMQP server failed (' . get_class($e) . '): '.
				$e->getMessage()
			);
			return null;
		}
		$this->connection = $connection;
		try {
			$channel = $connection->channel();
			$channel->queue_declare($this->queueName, false, false, false, true);
			foreach ($this->exchanges as $exchangeName => $exchange) {
				if ($exchange->type === AMQPExchangeType::FANOUT) {
					$channel->exchange_declare($exchangeName, AMQPExchangeType::FANOUT, false, false, true);
				}
				if (count($exchange->routingKeys)) {
					foreach ($exchange->routingKeys as $key) {
						$channel->queue_bind($this->queueName, $exchangeName, $key);
					}
				} else {
					$channel->queue_bind($this->queueName, $exchangeName);
				}
				$this->logger->log("INFO", "Now connected to {$exchange->type} AMQP exchange \"{$exchange->name}\".");
			}
		} catch (AMQPTimeoutException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Connection to AMQP server timed out'
			);
			$this->logger->log('INFO', $this->status->text);
			return null;
		} catch (AMQPIOException $e) {
			$this->status = new RelayStatus(RelayStatus::ERROR, 'Connection to AMQP server interrupted');
			$this->logger->log('INFO', $this->status->text);
			return null;
		} catch (AMQPProtocolChannelException $e) {
			$this->status = new RelayStatus(RelayStatus::ERROR, $e->getMessage());
			$this->logger->log('INFO', 'AMQP error: ' . $e->getMessage());
			return null;
		} catch (ErrorException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Error Connecting to AMQP server: ' . $e->getMessage()
			);
			$this->logger->log('INFO', $this->status->text);
			return null;
		}
		$this->status = new RelayStatus(
			RelayStatus::READY,
			"Connected to AMQP server {$this->server}:{$this->port}"
		);
		$this->logger->log('INFO', $this->status->text);
		$this->channel = $channel;
		$this->listenForMessages();

		return $channel;
	}

	/**
	 * Send a message to the configured AMQP exchange
	 */
	public function sendMessage(string $exchange, string $text, ?string $routingKey=null): bool {
		$channel = $this->getChannel();
		if ($channel === null) {
			return false;
		}
		$contentType = strpos($text, '<') === false ? 'text/plain' : 'text/html';
		$message = new AMQPMessage(
			$text,
			['content_type' => $contentType]
		);
		$sender = $this->chatBot->char->name;
		$routingKey ??= $sender;
		try {
			$channel->basic_publish($message, $exchange, $routingKey);
		} catch (AMQPTimeoutException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Sending message to AMQP server timed out'
			);
		} catch (AMQPIOException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Sending message to AMQP server interrupted'
			);
		} catch (ErrorException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Error sending message to AMQP server: ' . $e->getMessage()
			);
		}
		if (isset($e)) {
			$this->logger->log('INFO', $this->status->text);
			$this->channel = null;
			$this->connection = null;
			if (isset($this->eventTicket)) {
				EventLoop::rem($this->eventTicket);
				unset($this->eventTicket);
			}
			$args = func_get_args();
			$this->relay->init(function() use ($args): void {
				$this->sendMessage(...$args);
			});
			return null;
		}
		$this->logger->log("DEBUG", "AMQP[{$exchange}]: {$text}");
		return true;
	}

	/**
	 * Register us as listeners for new messages
	 */
	public function listenForMessages(): bool {
		$channel = $this->getChannel();
		if ($channel === null) {
			return false;
		}
		// Don't listen more than once
		if ($channel->is_consuming()) {
			return true;
		}
		$channel->basic_consume(
			$this->queueName,
			'', // consumer tag
			false, // no local
			true, // no ack
			true, // exclusive
			false, // no wait
			[$this, 'handleIncomingMessage']
		);
		$this->logger->log('INFO', 'Listening for AMQP messages on queue ' . $this->queueName);
		if (isset($this->initCallback)) {
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
		}
		return true;
	}

	/**
	 * Handle incoming AMQP messages
	 */
	public function handleIncomingMessage(AMQPMessage $message): void {
		$this->lastWaitReceivedMessage = true;
		$sender = $message->delivery_info['routing_key'];
		$exchange = $message->delivery_info['exchange'];
		if ($sender === $this->chatBot->char->name) {
			$this->logger->log('DEBUG', 'Own AMQP Message received: ' . $message->body);
			return;
		}
		$this->logger->logChat('Inc. AMQP Msg.', $sender, $message->body);
		$msg = new RelayMessage();
		$msg->packages = [$message->body];
		$msg->sender = $sender;
		$this->relay->receiveFromTransport($msg);
	}

	/**
	 * Process all messages currently in the AMQP queue for us
	 * Will also handle initial connect and reconnects
	 */
	public function processMessages(): void {
		$channel = $this->getChannel();
		if ($channel === null || !$channel->is_consuming()) {
			return;
		}
		do {
			$this->lastWaitReceivedMessage = false;
			try {
				$channel->wait(null, true);
			} catch (AMQPConnectionClosedException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'AMQP server closed connection'
				);
			} catch (AMQPRuntimeException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'AMQP server runtime exception: ' . $e->getMessage()
				);
			} catch (AMQPTimeoutException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'AMQP server timed out'
				);
			} catch (AMQPIOException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'AMQP IO exception'
				);
			} catch (ErrorException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'Error receiving AMQP message: ' . $e->getMessage()
				);
			} catch (Throwable $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'Unknown AMQP exception (' . get_class($e) . "): ".
						$e->getMessage()
				);
			}
			if (isset($e)) {
				$this->logger->log('INFO', $this->status->text);
				$this->channel = null;
				$this->connection = null;
				if (isset($this->eventTicket)) {
					EventLoop::rem($this->eventTicket);
					unset($this->eventTicket);
				}
				$this->relay->init();
				return;
			}
		} while ($this->lastWaitReceivedMessage === true && $channel->is_consuming());
	}
}
