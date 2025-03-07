<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\yaml_parse;

use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Channels\AbstractChannel;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\Exceptions\{NonExistingTestException, ParseTestException};
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Testing\{MockCommandReply, TestCase, TestCollection, TestGroup, TestResult};
use Nadybot\Core\Types\CommandReply;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Safe\Exceptions\YamlException;

class Testing {
	public function __construct(
		private LoggerInterface $logger,
		private Filesystem $fs,
		private BotConfig $config,
		private Nadybot $chatBot,
		private CommandManager $commandManager,
		private MessageHub $messageHub,
	) {
		if (!self::canRun()) {
			// @phpstan-ignore-next-line
			\fwrite(\STDERR, "Nadybot needs the a required PHP-extensions to run tests.\n");
			exit(1);
		}
	}

	/** Check if we have all required PHP extensions to run tests */
	public static function canRun(): bool {
		return extension_loaded('yaml');
	}

	/**
	 * Parse a given YAML test file into a PHP structure
	 *
	 * @param string $fileName The absolute path to the test definition
	 *
	 * @throws NonExistingTestException if the file cannot be found
	 * @throws ParseTestException       if the test definitions cannot be parsed
	 */
	public function parseTestFile(string $fileName): TestCollection {
		$this->logger->info('Loading {file}', ['file' => $fileName]);
		if (!$this->fs->exists($fileName)) {
			throw new NonExistingTestException("The test-file \"{$fileName}\" does not exist.");
		}
		$content = $this->fs->read($fileName);
		try {
			$this->logger->info('Parsing {file} into PHP', ['file' => $fileName]);
			$data = yaml_parse($content);
			$collection = Hydrator::literalHydrate(TestCollection::class, $data);
		} catch (YamlException | UnableToHydrateObject $e) {
			$this->logger->error('Error parsing {file}: {error} into PHP', [
				'file' => $fileName,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			throw new ParseTestException(message: $e->getMessage(), previous: $e);
		}
		return $collection;
	}

	/** Run all tests */
	public function run(): void {
		$result = TestResult::Success;
		try {
			$dirs = $this->getTestDirectories();
			$tests = $this->getTestsFromDirectories($dirs);
			foreach ($tests as $test) {
				$result = $result->add($this->runTestCollection($test));
			}
		} catch (\Throwable $e) {
			$this->logger->critical('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
			exit(1);
		}
		if ($result === TestResult::Failure) {
			exit(1);
		}
		exit(0);
	}

	/**
	 * get the context to execute a single command
	 *
	 * @param string $command The command to execute
	 */
	private function getContext(string $command, CommandReply $reply): CmdContext {
		$uid = $this->chatBot->getUid($this->config->general->superAdmins[0], true);
		if (!isset($uid)) {
			throw new Exception('Superuser does not exist.');
		}
		$superAdmin = $this->config->general->superAdmins[0];
		$command = Safe::pregReplace('/^!/', '', $command);
		return new CmdContext(
			charName: $superAdmin,
			sendto: $reply,
			charId: $uid,
			message: $command,
			permissionSet: 'msg',
			source: 'console',
			args: [],
			forceSync: false,
			isDM: true,
		);
	}

	/**
	 * Replace useful placeholders like <myname> and <superadmin>
	 *
	 * @param array<string,string> $placeholders Placeholders to replace
	 */
	private function replacePlaceholders(string $text, array $placeholders): string {
		$superAdmin = $this->config->general->superAdmins[0];
		$text = str_replace('<myname>', strtolower($this->config->main->character), $text);
		$text = str_replace('<Myname>', $this->config->main->character, $text);
		$text = str_replace('<superadmin>', $superAdmin, $text);
		foreach ($placeholders as $key => $value) {
			$text = str_replace('{' . $key . '}', $value, $text);
		}
		return $text;
	}

	/** Evaluates a condition string and checks if it evaluates to `true` or `false` */
	private function evaluateCondition(?string $condition): bool {
		if (!isset($condition)) {
			return true;
		}
		if ($condition === 'orgbot') {
			return strlen($this->config->general->orgName) > 0;
		}
		if ($condition === '!orgbot') {
			return strlen($this->config->general->orgName) === 0;
		}
		return false;
	}

	/**
	 * Run a single test case and return whether the output matches
	 *
	 * @param array<string,string> $placeholders
	 *
	 * @return array{TestResult,array<string,string>}
	 */
	private function runTest(TestCase $test, array $placeholders): array {
		if (!$this->evaluateCondition($test->condition)) {
			$this->logger->notice('  [S] {test}', ['test' => $test->getName()]);
			return [TestResult::Skipped, $placeholders];
		}

		$reply = new MockCommandReply();
		$command = $this->replacePlaceholders($test->command, $placeholders);
		$cmdContext = $this->getContext($command, $reply);
		if (isset($test->capture)) {
			$msgReceiver = new class ($this->messageHub) extends AbstractChannel {
				public string $msg = '';

				public function __construct(private MessageHub $messageHub) {
				}

				public function getChannelName(): string {
					return 'test-capture';
				}

				public function receive(RoutableEvent $event, string $destination): bool {
					$message = $this->getEventMessage($event, $this->messageHub);
					if (!isset($message)) {
						return false;
					}
					$this->msg .= $message;
					return true;
				}
			};
			$this->messageHub->registerMessageReceiver($msgReceiver);
			$dbRoute = new Route(
				source: $test->capture,
				destination: 'test-capture',
				two_way: false,
			);
			$msgRoute = new MessageRoute($dbRoute);
			Registry::injectDependencies($msgRoute);
			$this->messageHub->addRoute($msgRoute);
		}
		$this->commandManager->syncProcessCmd($cmdContext);
		if (isset($dbRoute)) {
			$this->messageHub->deleteRouteID($dbRoute->id);
		}
		$capturedMessage = '';
		if (isset($msgReceiver)) {
			$capturedMessage = $msgReceiver->msg;
			$this->messageHub->unregisterMessageReceiver('test-capture');
		}
		$output = $reply->getOutput();
		$errorIndent = '               ';
		foreach ($test->expect as $expect) {
			$expect = $this->replacePlaceholders($expect, $placeholders);
			try {
				$matches = Safe::pregMatch(chr(1) . $expect . chr(1) . 's', $output);
			} catch (\Throwable) {
				$this->logger->error('The regular expression »{expect}« is invalid', [
					'expect' => $expect,
				]);
				$matches = [];
			}
			if (!count($matches)) {
				$this->logger->error(
					"   [✖] {test}\n".
					"{$errorIndent}Cannot find \"{expected}\" in output:\n".
					"{$errorIndent}{output}",
					[
						'test' => $test->getName(),
						'expected' => $expect,
						'output' => implode("\n{$errorIndent}", explode("\n", $output)),
					]
				);
				return [TestResult::Failure, $placeholders];
			}
			if (count($matches) > 1) {
				$keys = array_filter(array_keys($matches), is_string(...));
				foreach ($keys as $key) {
					$placeholders[$key] = $matches[$key];
				}
			}
		}
		foreach ($test->unexpected as $unexpected) {
			$unexpected = $this->replacePlaceholders($unexpected, $placeholders);
			$unexpectResult = Safe::pregMatches(chr(1) . $unexpected . chr(1) . 's', $output);
			if ($unexpectResult === true) {
				$this->logger->error(
					"   [✖] {test}\n".
					"{$errorIndent}Did find \"{unexpected}\" in output:\n".
					"{$errorIndent}{output}",
					[
						'test' => $test->getName(),
						'unexpected' => $unexpected,
						'output' => implode("\n{$errorIndent}", explode("\n", $output)),
					]
				);
				return [TestResult::Failure, $placeholders];
			}
		}
		foreach ($test->captured as $expect) {
			$expect = $this->replacePlaceholders($expect, $placeholders);
			try {
				$matches = Safe::pregMatch(chr(1) . $expect . chr(1) . 's', $capturedMessage);
			} catch (\Throwable) {
				$this->logger->error('The regular expression »{expect}« is invalid', [
					'expect' => $expect,
				]);
				$matches = [];
			}
			if (!count($matches)) {
				$this->logger->error(
					"   [✖] {test}\n".
					"{$errorIndent}Cannot find \"{expected}\" in captured output:\n".
					"{$errorIndent}{output}",
					[
						'test' => $test->getName(),
						'expected' => $expect,
						'output' => implode("\n{$errorIndent}", explode("\n", $capturedMessage)),
					]
				);
				return [TestResult::Failure, $placeholders];
			}
			if (count($matches) > 1) {
				$keys = array_filter(array_keys($matches), is_string(...));
				foreach ($keys as $key) {
					$placeholders[$key] = $matches[$key];
				}
			}
		}
		$this->logger->notice('  [✔] {test}', ['test' => $test->getName()]);
		return [TestResult::Success, $placeholders];
	}

	private function runTestCollection(TestCollection $collection): TestResult {
		if (!$this->evaluateCondition($collection->condition)) {
			return TestResult::Skipped;
		}
		$this->logger->notice('Starting tests for {collection}', [
			'collection' => $collection->name,
		]);
		$result = TestResult::Success;
		foreach ($collection->groups as $testGroup) {
			$result = $result->add($this->runTestGroup($testGroup));
		}
		return $result;
	}

	private function runTestGroup(TestGroup $group): TestResult {
		if (!$this->evaluateCondition($group->condition)) {
			return TestResult::Skipped;
		}
		$this->logger->notice('Starting test group for {group}', [
			'group' => $group->name,
		]);
		$result = TestResult::Success;
		$placeholders = [];
		foreach ($group->tests as $test) {
			[$testResult, $placeholders] = $this->runTest($test, $placeholders);
			$result = $result->add($testResult);
		}
		return $result;
	}

	/**
	 * Parse the given directories for tests and parse and return them
	 *
	 * @param list<string> $dirs
	 *
	 * @return list<TestCollection>
	 */
	private function getTestsFromDirectories(array $dirs): array {
		$result = [];
		foreach ($dirs as $dir) {
			$tests = $this->getTestsFromDirectory($dir);
			$result = array_merge($result, $tests);
		}
		return $result;
	}

	/**
	 * Parse the given directory for tests and parse and return them
	 *
	 * @return list<TestCollection>
	 */
	private function getTestsFromDirectory(string $dir): array {
		/** @var list<TestCollection> */
		$tests = [];
		$files = $this->fs->listFiles($dir);
		foreach ($files as $file) {
			if (!str_ends_with($file, '.yaml') && !str_ends_with($file, '.yml')) {
				continue;
			}
			if (!$this->fs->isFile("{$dir}/{$file}")) {
				continue;
			}
			$tests []= $this->parseTestFile("{$dir}/{$file}");
		}
		return $tests;
	}

	/**
	 * Get a list of all directories in which we're supposed to find tests
	 *
	 * @return list<string>
	 */
	private function getTestDirectories(): array {
		/** @var array<string,true> */
		$dirs = [];
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$refClass = new ReflectionClass($instance);
			$testAttrs = $refClass->getAttributes(NCA\HasTests::class);
			if (!count($testAttrs)) {
				continue;
			}
			$tests = $testAttrs[0]->newInstance();
			$filename = $refClass->getFileName();
			if (!is_string($filename)) {
				continue;
			}
			$dirname = dirname($filename);
			$fullPath = $dirname . '/' . $tests->dir;
			if (!$this->fs->exists($fullPath)) {
				continue;
			}
			if (!$this->fs->isDirectory($fullPath)) {
				continue;
			}
			$dirs[$fullPath] = true;
		}
		return array_keys($dirs);
	}
}
