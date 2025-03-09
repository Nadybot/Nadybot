<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\yaml_parse;

use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\{NonExistingTestException, ParseTestException};
use Nadybot\Core\Testing\{CapturerFactory, MockCommandReply, TestCase, TestCollection, TestGroup, TestResult, TestResults};
use Nadybot\Core\Types\CommandReply;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Safe\Exceptions\YamlException;

class Testing {
	private const ERROR_INDENT = '               ';

	public function __construct(
		private LoggerInterface $logger,
		private Filesystem $fs,
		private BotConfig $config,
		private Nadybot $chatBot,
		private CommandManager $commandManager,
		private EventManager $eventManager,
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
		$results = new TestResults();
		try {
			$dirs = $this->getTestDirectories();
			$tests = $this->getTestsFromDirectories($dirs);
			foreach ($tests as $test) {
				$results->addResults($this->runTestCollection($test));
			}
		} catch (\Throwable $e) {
			$this->logger->critical('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
			exit(1);
		}
		$this->logger->notice(
			"Test results:\n".
			"         Success: {num_success}\n".
			"         Skipped: {num_skipped}\n".
			'         Failure: {num_failure}',
			[
				'num_success' => $results->numSuccesses,
				'num_skipped' => $results->numSkipped,
				'num_failure' => $results->numFailures,
			]
		);
		if ($results->numFailures > 0) {
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
	private function replacePlaceholders(string $text, array $placeholders, bool $forRegexp=true): string {
		$superAdmin = $this->config->general->superAdmins[0];
		$text = str_replace('<myname>', strtolower($this->config->main->character), $text);
		$text = str_replace('<Myname>', $this->config->main->character, $text);
		$text = str_replace('<superadmin>', $superAdmin, $text);
		foreach ($placeholders as $key => $value) {
			if ($forRegexp) {
				$value = preg_quote($value);
			}
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

	/** @return TestResult::Failure */
	private function logUnfoundExpectation(TestCase $test, string $expect, string $output): TestResult {
		$this->logger->error(
			"   [✖] {test}\n".
			"{indent}Cannot find \"{expected}\" in output:\n".
			'{indent}{output}',
			[
				'indent' => self::ERROR_INDENT,
				'test' => $test->getName(),
				'expected' => $expect,
				'output' => implode("\n" . self::ERROR_INDENT, explode("\n", $output)),
			]
		);
		return TestResult::Failure;
	}

	/** @return TestResult::Failure */
	private function logUnexpectedFind(TestCase $test, string $unexpected, string $output): TestResult {
		$this->logger->error(
			"   [✖] {test}\n".
			"{indent}Did find \"{unexpected}\" in output:\n".
			'{indent}{output}',
			[
				'indent' => self::ERROR_INDENT,
				'test' => $test->getName(),
				'unexpected' => $unexpected,
				'output' => implode("\n" . self::ERROR_INDENT, explode("\n", $output)),
			]
		);
		return TestResult::Failure;
	}

	/**
	 * Get the matching string from a given regular exprfession for a given text
	 *
	 * @param string $expect The regular expression to use
	 * @param string $output The text to run it on
	 *
	 * @return string[] A non-empty array with matches, or an empty one if no matches
	 */
	private function getExpectMatches(string $expect, string $output): array {
		try {
			$matches = Safe::pregMatch(chr(1) . $expect . chr(1) . 's', $output);
		} catch (\Throwable) {
			$this->logger->error('The regular expression »{expect}« is invalid', [
				'expect' => $expect,
			]);
			$matches = [];
		}
		return $matches;
	}

	/**
	 * If `$matches` contains any named matches, add them to `$placeholders` and return them
	 *
	 * @param string[]             $matches
	 * @param array<string,string> $placeholders
	 *
	 * @return array<string,string>
	 */
	private function addNamedMatchesToPlaceholders(array $matches, array $placeholders): array {
		if (count($matches) <= 1) {
			return $placeholders;
		}
		$keys = array_filter(array_keys($matches), is_string(...));
		foreach ($keys as $key) {
			$placeholders[$key] = $matches[$key];
		}
		return $placeholders;
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
		$command = $this->replacePlaceholders($test->command, $placeholders, false);
		$cmdContext = $this->getContext($command, $reply);
		$capturedOutput = '';
		if (isset($test->capture)) {
			$capturer = CapturerFactory::fromPattern($test->capture);
			$capturer->register($this->eventManager);
		}
		$this->commandManager->syncProcessCmd($cmdContext);
		if (isset($capturer)) {
			$capturedOutput = $capturer->getOutput();
			$capturer->unregister($this->eventManager);
		}
		$output = $reply->getOutput();

		// Handle expected output
		foreach ($test->expect as $expect) {
			$expect = $this->replacePlaceholders($expect, $placeholders);
			$matches = $this->getExpectMatches($expect, $output);
			if (!count($matches)) {
				return [$this->logUnfoundExpectation($test, $expect, $output), $placeholders];
			}
			$placeholders = $this->addNamedMatchesToPlaceholders($matches, $placeholders);
		}
		// Handle unexpected output
		foreach ($test->unexpected as $unexpected) {
			$unexpected = $this->replacePlaceholders($unexpected, $placeholders);
			$unexpectResult = count($this->getExpectMatches($unexpected, $output)) > 0;
			if ($unexpectResult === true) {
				return [$this->logUnexpectedFind($test, $unexpected, $output), $placeholders];
			}
		}
		// Handle expected captured output
		foreach ($test->captured as $expect) {
			$expect = $this->replacePlaceholders($expect, $placeholders);
			$matches = $this->getExpectMatches($expect, $capturedOutput);
			if (!count($matches)) {
				return [$this->logUnfoundExpectation($test, $expect, $capturedOutput), $placeholders];
			}
			$placeholders = $this->addNamedMatchesToPlaceholders($matches, $placeholders);
		}
		$this->logger->notice('  [✔] {test}', ['test' => $test->getName()]);
		return [TestResult::Success, $placeholders];
	}

	private function runTestCollection(TestCollection $collection): TestResults {
		$results = new TestResults();
		if (!$this->evaluateCondition($collection->condition)) {
			return $results->addTest(TestResult::Skipped);
		}
		$this->logger->notice('Starting tests for {collection}', [
			'collection' => $collection->name,
		]);
		foreach ($collection->groups as $testGroup) {
			$results->addResults($this->runTestGroup($testGroup));
		}
		return $results;
	}

	private function runTestGroup(TestGroup $group): TestResults {
		$results = new TestResults();
		if (!$this->evaluateCondition($group->condition)) {
			return $results->addTest(TestResult::Skipped);
		}
		$this->logger->notice('Starting test group for {group}', [
			'group' => $group->name,
		]);
		$placeholders = [];
		foreach ($group->tests as $test) {
			[$testResult, $placeholders] = $this->runTest($test, $placeholders);
			$results->addTest($testResult);
		}
		return $results;
	}

	/**
	 * Parse the given directories for tests and parse and return them
	 *
	 * @param list<string> $dirs
	 *
	 * @return list<TestCollection>
	 */
	private function getTestsFromDirectories(array $dirs): array {
		$exclusiveTests = $this->getExclusiveTests();
		$result = [];
		foreach ($dirs as $dir) {
			$tests = $this->getTestsFromDirectory($dir, $exclusiveTests);
			$result = array_merge($result, $tests);
		}
		return $result;
	}

	/** @return ?list<string> */
	private function getExclusiveTests(): ?array {
		$onlyTests = BotRunner::getArguments()->testFiles;
		if (!isset($onlyTests)) {
			return null;
		}
		$limitTo = [];
		foreach ($onlyTests as $onlyTest) {
			$limitTo []= $this->fs->realPath($onlyTest);
		}
		return $limitTo;
	}

	/**
	 * Parse the given directory for tests and parse and return them
	 *
	 * @param ?list<string> $exclusive If set, only return these tests
	 *
	 * @return list<TestCollection>
	 */
	private function getTestsFromDirectory(string $dir, ?array $exclusive): array {
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
			if (isset($exclusive) && !in_array("{$dir}/{$file}", $exclusive, true)) {
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
