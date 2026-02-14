<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\ini_get;

use Amp\File\FilesystemException;
use Closure;
use Exception;
use Monolog\{DateTimeImmutable, Logger};
use Monolog\Processor\PsrLogMessageProcessor;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	Routing\RoutableMessage,
	Routing\Source,
};
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * A wrapper class to `\Monolog\Logger`
 */
#[NCA\Instance('logger')]
class LoggerWrapper implements LoggerInterface {
	public static Filesystem $fs;

	/** Route errors to the message hub */
	protected static bool $routeErrors = true;

	protected static PsrLogMessageProcessor $logProcessor;

	/**
	 * A closure that can modify the log level, the message and the context on the fly
	 *
	 * @var null|Closure(int,string|Stringable,array<array-key,mixed>):array{int,string|Stringable,array<array-key,mixed>}
	 */
	protected ?Closure $wrapper = null;

	/**
	 * @var array<array>
	 *
	 * @phpstan-var array<array{100|200|250|300|400|500|550|600,Stringable|string,array<array-key,mixed>}>
	 */
	protected static array $routingQueue = [];

	/**
	 * Set to `true` if we are logging an error during logging.
	 * Avoids endless loops.
	 */
	protected static bool $errorGiven = false;

	#[NCA\Inject]
	private BotConfig $config;

	/** The actual Monolog logger */
	private Logger $logger;

	/** The actual Monolog logger for tag CHAT */
	private ?Logger $chatLogger = null;

	/**
	 * @param string $tag The tag to use when logging. Nadybot usually uses
	 *                    the class name relative to `\Nadybot`, so
	 *                    `\Nadybot\Core\Nadybot` becomes `Core/Nadybot`
	 */
	public function __construct(string $tag) {
		$this->logger = LegacyLogger::fromConfig($tag);
		if (!isset(self::$logProcessor)) {
			self::$logProcessor = new PsrLogMessageProcessor(null, true);
		}
	}

	/**
	 * Log detailed debug information, including data like traces
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function debug(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::DEBUG, $message, $context);
	}

	/**
	 * Log information that describes what's generally been done right now
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function info(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::INFO, $message, $context);
	}

	/**
	 * Something important, like a milestone, has been reached,
	 * or generally something the bot admin should always see
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function notice(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::NOTICE, $message, $context);
	}

	/**
	 * Exceptional occurrences that are not errors.
	 * Examples:
	 * Use of deprecated APIs,
	 * poor use of an API,
	 * undesirable things that are not necessarily wrong.
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function warning(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::WARNING, $message, $context);
	}

	/**
	 * Runtime errors that the bot can ignore and continue
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function error(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::ERROR, $message, $context);
	}

	/**
	 * Urgent alerts that should not be ignored
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function critical(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::CRITICAL, $message, $context);
	}

	/**
	 * Action must be taken immediately.
	 * Examples:
	 * Bot down,
	 * database unavailable,
	 * things that should trigger an sms alert and wake you up.
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function alert(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::ALERT, $message, $context);
	}

	/**
	 * Urgent alert
	 *
	 * @param array<array-key,mixed> $context
	 */
	public function emergency(string|Stringable $message, array $context=[]): void {
		$this->passthru(Logger::EMERGENCY, $message, $context);
	}

	/**
	 * Log a message according to log settings
	 *
	 * @param mixed                  $level   The log level
	 * @param string                 $message The message to log
	 * @param array<array-key,mixed> $context
	 */
	public function log(mixed $level, string|Stringable $message, array $context=[]): void {
		if (!is_int($level) && !is_string($level)) {
			throw new \InvalidArgumentException('$level is expected to be a string or int');
		}

		$level = is_string($level) ? LegacyLogger::getLoggerLevel($level) : $level;

		/**
		 * @psalm-suppress ArgumentTypeCoercion
		 *
		 * @phpstan-ignore-next-line
		 */
		$this->logger->log($level, $message, $context);
	}

	/**
	 * Log a chat message, stripping potential HTML code from it, if configured
	 *
	 * @param string     $channel Either "Buddy" or an org or private-channel name
	 * @param string|int $sender  The name of the sender, or a number representing the channel
	 * @param string     $message The message to log
	 */
	public function logChat(string $channel, string|int $sender, string $message): void {
		if (!$this->config->general->showAomlMarkup) {
			$message = Safe::pregReplace('|<font.*?>|', '', $message);
			$message = Safe::pregReplace('|</font>|', '', $message);
			$message = Safe::pregReplace('|<a\\s+href=".+?">|s', '[link]', $message);
			$message = Safe::pregReplace("|<a\\s+href='.+?'>|s", '[link]', $message);
			$message = Safe::pregReplace('|<a\\s+href=.+?>|s', '[link]', $message);
			$message = Safe::pregReplace('|</a>|', '[/link]', $message);
		}

		$this->chatLogger ??= $this->logger->withName('CHAT');
		if ($channel === 'Buddy') {
			$this->chatLogger->notice('[{channel}] {sender} {message}', [
				'channel' => $channel,
				'sender' => $sender,
				'message' => $message,
			]);
		} elseif ($sender === '-1' || $sender === '4294967295' || $sender === -1 || $sender === 4_294_967_295) {
			$this->chatLogger->notice('[{channel}] {message}', [
				'channel' => $channel,
				'message' => $message,
			]);
		} else {
			$this->chatLogger->notice('[{channel}] {sender}: {message}', [
				'channel' => $channel,
				'sender' => $sender,
				'message' => $message,
			]);
		}
	}

	/** Get the relative path of the directory where logs of this bot are stored */
	public static function getLoggingDirectory(): string {
		$errorLog = ini_get('error_log');
		$logDir = dirname($errorLog);
		if (substr($logDir, 0, 1) !== '/') {
			try {
				$logDirNew = self::$fs->realPath(dirname(__DIR__, 2) . '/' . $logDir);
			} catch (FilesystemException) {
				$logDirNew = dirname(__DIR__, 2) . '/' . $logDir;
			}
			$logDir = $logDirNew;
		}
		return $logDir;
	}

	/**
	 * Check if logging is enabled for a given category
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 */
	public function isEnabledFor(string $category): bool {
		$level = LegacyLogger::getLoggerLevel($category);
		return $this->isHandling($level);
	}

	/**
	 * Check if logging is enabled for a given level
	 *
	 * @param int $level The log level (Logger::DEBUG, etc.)
	 *
	 * @phpstan-param 100|200|250|300|400|500|550|600 $level
	 */
	public function isHandling(int $level): bool {
		return $this->logger->isHandling($level);
	}

	/**
	 * Add a wrapper closure that can modify log level, log message, and context for every
	 * logging done via this instance.
	 *
	 * @param Closure(int,string|Stringable,array<array-key,mixed>):array{int,string|Stringable,array<array-key,mixed>} $caller
	 */
	public function wrap(Closure $caller): void {
		$this->wrapper = $caller;
	}

	/**
	 * Do the actual logging, and also route errors to the message hub, if configured
	 *
	 * @param int                    $logLevel The numeric log level
	 * @param string|Stringable      $message  The message to log
	 * @param array<array-key,mixed> $context  Additional context to log as an
	 *                                         associative array
	 *
	 * @phpstan-param 100|200|250|300|400|500|550|600 $logLevel
	 */
	private function passthru(int $logLevel, string|Stringable $message, array $context): void {
		$message = (string)$message;
		try {
			if (isset($this->wrapper)) {
				[$logLevel, $message, $context] = call_user_func($this->wrapper, $logLevel, $message, $context);

				/** @phpstan-var 100|200|250|300|400|500|550|600 $logLevel */
			}
			// @phpstan-ignore-next-line
			$this->logger->log($logLevel, $message, $context);
		} catch (Exception $e) {
			if (static::$errorGiven === true) {
				return;
			}
			static::$errorGiven = true;
			$this->passthru(Logger::ERROR, 'Error logging: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
		if ($logLevel < Logger::NOTICE) {
			return;
		}
		if (!static::$routeErrors) {
			return;
		}
		if (!Registry::hasInstance(MessageHub::class)) {
			self::$routingQueue []= [$logLevel, $message, $context];
			return;
		}
		$msgHub = Registry::getInstance(MessageHub::class);
		if (!$msgHub->routingLoaded()) {
			self::$routingQueue []= [$logLevel, $message, $context];
			return;
		}
		self::$routingQueue []= [$logLevel, $message, $context];
		while (count(self::$routingQueue) > 0) {
			[$logLevel, $message, $context] = array_shift(self::$routingQueue);
			static::$routeErrors = false;
			try {
				$loggingCategory = Logger::getLevelName($logLevel);
				$renderedMessage = (self::$logProcessor)([
					'message' => (string)$message,
					'context' => $context,
					'level' => $logLevel,
					'level_name' => $loggingCategory,
					'channel' => $loggingCategory,
					'datetime' => new DateTimeImmutable(false),
					'extra' => [],
				]);
				$rMessage = new RoutableMessage($renderedMessage['message']);
				$rMessage->appendPath(
					new Source(Source::LOG, $loggingCategory)
				);
				$msgHub->handle($rMessage);
			} catch (Throwable) {
			} finally {
				static::$routeErrors = true;
			}
		}
	}
}
