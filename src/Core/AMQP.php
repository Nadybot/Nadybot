<?php declare(strict_types=1);

namespace Nadybot\Core;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Nadybot\Core\Event;
use ErrorException;
use Exception;

/**
 * The AMQP class provides an interface to a central AMQP hub like RabbitMQ.
 *
 * It is meant to allow scalable communication between bots without running
 * into any form of rate-limiting due to a lot of messages.
 * It also allows external clients to communicate with the bot via AMQP
 *
 * @Instance
 */
class AMQP {
	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public EventManager $eventManager;

	protected AMQPChannel $channel;

	/**
	 * Did we receive a message on our last wait for new messages?
	 */
	private bool $lastWaitReceivedMessage = false;

	protected int $lastConnectTry = 0;

	private string $queueName;

	/** @var string[] */
	private array $exchanges = [];

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->queueName = $this->chatBot->vars['name'];
	}

	/**
	 * Connect our channel to a new exchange
	 * Don't try to connect if we're not (yet) connected
	 */
	public function connectExchange(string $exchange): bool {
		if (in_array($exchange, $this->exchanges)) {
			return true;
		}
		if ($this->channel === null) {
			$this->exchanges[] = $exchange;
			return true;
		}
		try {
			$this->channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, false, true);
			$this->channel->queue_bind($this->queueName, $exchange);
		} catch (Exception $e) {
			$this->exchanges[] = $exchange;
			return false;
		}
		$this->logger->log("INFO", "Now listening for AMQP messages on exchange {$exchange}.");
		return true;
	}

	/**
	 * Disconnect our channel from an exchange
	 * Don't try to connect if we're not (yet) connected
	 */
	public function disconnectExchange(string $exchange): bool {
		if (in_array($exchange, $this->exchanges)) {
			return true;
		}
		$this->exchanges[] = array_values(array_diff($this->exchanges, [$exchange]));
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
		if (time() - $this->lastConnectTry < 60) {
			return null;
		}
		$this->lastConnectTry = time();
		$vars = $this->chatBot->vars;
		if (!strlen($vars['amqp_server']) || !strlen($vars['amqp_user']) || !strlen($vars['amqp_password'])) {
			return null;
		}
		try {
			$connection = new AMQPStreamConnection(
				$vars['amqp_server'] ? $vars['amqp_server'] : '127.0.0.1',
				$vars['amqp_port'] ? $vars['amqp_port'] : 5672,
				$vars['amqp_user'],
				$vars['amqp_password'],
				$vars['amqp_vhost'] ? $vars['amqp_vhost'] : "/"
			);
		} catch (AMQPIOException $e) {
			$this->logger->log('INFO', 'Connection to AMQP server failed: ' . $e->getMessage());
			return null;
		}
		try {
			$channel = $connection->channel();
			$channel->queue_declare($this->queueName, false, false, false, true);
			foreach ($this->exchanges as $exchange) {
				$channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, false, true);
				$channel->queue_bind($this->queueName, $exchange);
				$this->logger->log("INFO", "Now listening for AMQP messages on exchange {$exchange}.");
			}
		} catch (AMQPTimeoutException $e) {
			$this->logger->log('INFO', 'Connection to AMQP server timed out.');
			return null;
		} catch (AMQPIOException $e) {
			$this->logger->log('INFO', 'Connection to AMQP server interruped.');
			return null;
		} catch (ErrorException $e) {
			$this->logger->log('INFO', 'Error Connecting to AMQP server: ' . $e->getMessage());
			return null;
		}
		$this->logger->log(
			'INFO',
			'Connected to AMQP server '.
			$vars['amqp_server'] . ':' . $vars['amqp_port']
		);
		$this->channel = $channel;
		$this->listenForMessages();

		return $channel;
	}

	/**
	 * Send a message to the configured AMQP exchange
	 */
	public function sendMessage(string $exchange, string $text): bool {
		$channel = $this->getChannel();
		if ($channel === null) {
			return false;
		}
		$contentType = strpos($text, '<') === false ? 'text/plain' : 'text/html';
		$message = new AMQPMessage(
			$text,
			['content_type' => $contentType]
		);
		$sender = $this->chatBot->vars['name'];
		try {
			$channel->basic_publish($message, $exchange, $sender);
		} catch (AMQPTimeoutException $e) {
			$this->logger->log('INFO', 'Sending message to AMQP server timed out.');
			$this->channel = null;
			return null;
		} catch (AMQPIOException $e) {
			$this->logger->log('INFO', 'Sending message to AMQP server interruped.');
			$this->channel = null;
			return null;
		} catch (ErrorException $e) {
			$this->logger->log('INFO', 'Error sending message to AMQP server: ' . $e->getMessage());
			$this->channel = null;
			return null;
		}
		$this->logger->logChat("Out. AMQP Msg.", $exchange, $text);
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
		return true;
	}

	/**
	 * Handle incoming AMQP messages by firing the @amqp event
	 */
	public function handleIncomingMessage(AMQPMessage $message): void {
		$this->lastWaitReceivedMessage = true;
		$sender = $message->delivery_info['routing_key'];
		$exchange = $message->delivery_info['exchange'];
		if ($sender === $this->chatBot->vars['name']) {
			$this->logger->log('DEBUG', 'Own AMQP Message received: ' . $message->body);
			return;
		}
		$this->logger->logChat('Inc. AMQP Msg.', $sender, $message->body);
		$eventObj = new Event();
		$eventObj->sender = $sender;
		$eventObj->channel = $exchange;
		$eventObj->type = 'amqp';
		$eventObj->message = $message->body;
		$this->eventManager->fireEvent($eventObj);
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
				$this->logger->log('INFO', 'AMQP server closed connection.');
				$this->channel = null;
				return;
			} catch (AMQPRuntimeException $e) {
				$this->logger->log('INFO', 'AMQP server runtime exception: ' . $e->getMessage());
				$this->channel = null;
				return;
			} catch (AMQPTimeoutException $e) {
				$this->logger->log('INFO', 'AMQP server timed out.');
				$this->channel = null;
				return;
			} catch (AMQPIOException $e) {
				$this->logger->log('INFO', 'AMQP IO exception.');
				$this->channel = null;
				return;
			} catch (ErrorException $e) {
				$this->logger->log('INFO', 'Error receving AMQP message: ' . $e->getMessage());
				$this->channel = null;
				return;
			}
		} while ($this->lastWaitReceivedMessage === true && $channel->is_consuming());
	}
}
