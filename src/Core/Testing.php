<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\yaml_parse;

use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\{NonExistingTestException, ParseTestException};
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
		$command = Safe::pregReplace('/^!/', '', $command);
		$command = str_replace('<myname>', strtolower($this->config->main->character), $command);
		return new CmdContext(
			charName: $this->config->general->superAdmins[0],
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

	/** Run a single test case and return whether the output matches */
	private function runTest(TestCase $test): TestResult {
		if (!$this->evaluateCondition($test->condition)) {
			$this->logger->notice('  [S] {test}', ['test' => $test->getName()]);
			return TestResult::Skipped;
		}

		$reply = new MockCommandReply();
		$cmdContext = $this->getContext($test->command, $reply);
		$this->commandManager->syncProcessCmd($cmdContext);
		$output = $reply->getOutput();
		foreach ($test->expect as $expect) {
			$expectResult = Safe::pregMatches(chr(1) . $expect . chr(1) . 's', $output);
			if ($expectResult === false) {
				$this->logger->notice(
					"  [✖] {test}\n".
					"         Cannot find \"{expected}\" in output:\n".
					'         {output}',
					[
						'test' => $test->getName(),
						'expected' => $expect,
						'output' => implode("\n         ", explode("\n", $output)),
					]
				);
				return TestResult::Failure;
			}
		}
		$this->logger->notice('  [✔] {test}', ['test' => $test->getName()]);
		return TestResult::Success;
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
		foreach ($group->tests as $test) {
			$result = $result->add($this->runTest($test));
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
