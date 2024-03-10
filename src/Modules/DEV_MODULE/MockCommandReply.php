<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use function Safe\json_encode;

use Amp\File\Filesystem;
use Nadybot\Core\{Attributes as NCA, CommandReply, Safe};
use Psr\Log\LoggerInterface;

class MockCommandReply implements CommandReply {
	public ?string $logFile;
	public string $command;

	/** @var string[] */
	public array $output = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Filesystem $fs;

	public function __construct(string $command, ?string $logFile=null) {
		$this->logFile = $logFile;
		$this->command = $command;
	}

	public function __destruct() {
		if (!isset($this->logFile)) {
			return;
		}
		try {
			$file = $this->fs->openFile($this->logFile, "a");
			$file->write(
				json_encode([
					"command" => $this->command,
					"output" => $this->output,
				], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE) . PHP_EOL,
			);
		} finally {
			if (isset($file)) {
				$file->close();
			}
		}
	}

	/** @param string|string[] $msg */
	public function reply(string|array $msg): void {
		foreach ((array)$msg as $result) {
			if (isset($this->logger)) {
				$this->logger->notice($result);
			}
			$result = Safe::pregReplace("/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}( [A-Z]{3,4})?/", "1970-01-01 00:00:00\\1", $result);
			$result = Safe::pregReplace("/\d\d-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}:\d{2}( [A-Z]{3,4})?/", "01-Jan-1970 00:00:00\\1", $result);
			$result = Safe::pregReplace("/\d\d-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}( [A-Z]{3,4})?/", "01-Jan-1970 00:00\\1", $result);
			$result = Safe::pregReplace("/\d\d-[A-Z][a-z]{2}-\d{4}/", "01-Jan-1970", $result);
			$result = Safe::pregReplace("/\d\d?(st|nd|rd) [A-Z][a-z]{2}, \d{2}:\d{2}/", "1st Jan, 00:00", $result);
			$result = Safe::pregReplace("/\b[12]\d{9}\b/", "1234567890", $result);
			$result = Safe::pregReplace("/\b[0-9a-fA-F]{40}\b/", "deadbeefdeadbeefdeadbeefdeadbeefdeadbeef", $result);
			$result = Safe::pregReplace("/\b[0-9a-fA-F]{32}\b/", "deadbeefdeadbeefdeadbeefdeadbeef", $result);
			$result = Safe::pregReplace("/(\s*\d+ (days|hrs|mins|secs))+/", "<duration>", $result);
			$this->output []= $result;
		}
	}
}
