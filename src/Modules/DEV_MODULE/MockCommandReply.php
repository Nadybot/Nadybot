<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\LoggerWrapper;

class MockCommandReply implements CommandReply {
	public LoggerWrapper $logger;

	public string $logFile;
	public string $command;
	public array $output = [];

	public function __construct(string $command, string $logFile) {
		$this->logFile = $logFile;
		$this->command = $command;
	}

	public function reply($msg): void {
		var_dump($this->command . " data");
		foreach ((array)$msg as $result) {
			if (isset($this->logger)) {
				$this->logger->log('INFO', $result);
			}
			$this->output []= $result;
		}
	}

	public function __destruct() {
		var_dump($this->command . " destroyed");
		file_put_contents(
			$this->logFile,
			json_encode([
				"command" => $this->command,
				"output" => $this->output,
			]) . PHP_EOL,
			FILE_APPEND
		);
	}
}
