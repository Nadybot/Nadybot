<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\LoggerWrapper;

class MockCommandReply implements CommandReply {
	public LoggerWrapper $logger;

	public ?string $logFile;
	public string $command;
	public array $output = [];

	public function __construct(string $command, ?string $logFile=null) {
		$this->logFile = $logFile;
		$this->command = $command;
	}

	public function reply($msg): void {
		foreach ((array)$msg as $result) {
			if (isset($this->logger)) {
				$this->logger->log('INFO', $result);
			}
			$result = preg_replace("/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}( [A-Z]{3,4})?/", "1970-01-01 00:00:00\\1", $result);
			$result = preg_replace("/\d\d-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}:\d{2}( [A-Z]{3,4})?/", "01-Jan-1970 00:00:00\\1", $result);
			$result = preg_replace("/\d\d-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}( [A-Z]{3,4})?/", "01-Jan-1970 00:00\\1", $result);
			$result = preg_replace("/\d\d-[A-Z][a-z]{2}-\d{4}/", "01-Jan-1970", $result);
			$result = preg_replace("/\d\d?(st|nd|rd) [A-Z][a-z]{2}, \d{2}:\d{2}/", "1st Jan, 00:00", $result);
			$result = preg_replace("/\b[12]\d{9}\b/", "1234567890", $result);
			$result = preg_replace("/\b[0-9a-fA-F]{40}\b/", "deadbeefdeadbeefdeadbeefdeadbeefdeadbeef", $result);
			$result = preg_replace("/\b[0-9a-fA-F]{32}\b/", "deadbeefdeadbeefdeadbeefdeadbeef", $result);
			$result = preg_replace("/(\s*\d+ (days|hrs|mins|secs))+/", "<duration>", $result);
			$this->output []= $result;
		}
	}

	public function __destruct() {
		if (!isset($this->logFile)) {
			return;
		}
		file_put_contents(
			$this->logFile,
			json_encode([
				"command" => $this->command,
				"output" => $this->output,
			], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE) . PHP_EOL,
			FILE_APPEND
		);
	}
}
