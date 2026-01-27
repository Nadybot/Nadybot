<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\yaml_parse;

use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\DBSchema\{Alt, Member};
use Nadybot\Core\Exceptions\{NonExistingTestException, ParseTestException};
use Nadybot\Core\Testing\{CapturerFactory, MockCommandReply, TestCase, TestCollection, TestGroup, TestResult, TestResults};
use Nadybot\Core\Types\CommandReply;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Safe\Exceptions\YamlException;

class Testing {
	private const ERROR_INDENT = '               ';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private SubcommandManager $subcommandManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private DB $db;

	public function __construct() {
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
			if (!is_array($data)) {
				throw new ParseTestException(message: "Invalid test file format of {$fileName}.");
			}

			/** @var array<string,mixed> $data */
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
		if (BotRunner::getArguments()->testShowErrorsOnly) {
			$loggers = LegacyLogger::getLoggers();
			LegacyLogger::tempLogLevelOrderride('*', 'error');
			foreach ($loggers as $logger) {
				LegacyLogger::assignLogLevel($logger);
			}
		}
		$results = new TestResults();
		try {
			$dirs = $this->getTestDirectories();
			$tests = $this->getTestsFromDirectories($dirs);
			foreach ($tests as $test) {
				$results->addResults($this->runTestCollection($test));
				if ($this->db->table(Member::getTable())->count() > 0) {
					$this->logger->critical('Members left behind in database');
					exit(1);
				}
				if ($this->db->table(Alt::getTable())->count() > 0) {
					$this->logger->critical('Alts left behind in database');
					exit(1);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->critical('{error}', ['error' => $e->getMessage(), 'exception' => $e]);
			exit(1);
		}
		if (BotRunner::getArguments()->testShowErrorsOnly) {
			$loggers = LegacyLogger::getLoggers();
			LegacyLogger::tempLogLevelRemove();
			foreach ($loggers as $logger) {
				LegacyLogger::assignLogLevel($logger);
			}
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
		$untestedHandlers = $this->getUntestedFunctionHandlers($tests);
		if (count($untestedHandlers)) {
			$this->logger->warning(
				"Found {num_untested} untested function handlers:\n".
				'{indent}{handlers}',
				[
					'num_untested' => count($untestedHandlers),
					'handlers' => implode("\n" . self::ERROR_INDENT, $untestedHandlers),
					'indent' => self::ERROR_INDENT,
				]
			);
		}
		if ($results->numFailures > 0) {
			exit(1);
		}
		exit(0);
	}

	/** @return list<CommandHandler> */
	private function getAllCommandHandlers(string $channel): array {
		$handlers = [];
		$cmds = array_keys($this->commandManager->commands[$channel]);
		foreach ($cmds as $cmd) {
			if (isset($this->subcommandManager->subcommands[$cmd])) {
				foreach ($this->subcommandManager->subcommands[$cmd] as $handler) {
					if (isset($handler->permissions[$channel])) {
						$handlers []= new CommandHandler($handler->permissions[$channel]->access_level, ...explode(',', $handler->file));
					}
				}
			}
			if (isset($this->commandManager->commands[$channel][$cmd])) {
				$handlers []= $this->commandManager->commands[$channel][$cmd];
			}
		}
		return $handlers;
	}

	/**
	 * Check whether a given command handler is in the given list of paths
	 *
	 * @param string            $handler The handler as `<relative file>#<line number>`
	 * @param null|list<string> $paths   The paths to limit to
	 */
	private function isHandlerInPaths(string $handler, ?array $paths): bool {
		if (!isset($paths)) {
			return true;
		}
		foreach ($paths as $path) {
			if (str_starts_with($handler, $path . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get a list of all function handlers that the given tests would not call directly
	 *
	 * @param list<TestCollection> $collections List of all test collections
	 *
	 * @return list<string> A list of uncalled function handlers
	 */
	private function getUntestedFunctionHandlers(array $collections): array {
		$handlers = $this->getFunctionHandlerRegexes();
		$unfound = [];
		$testCommands = $this->getSimplifiedTestCommands($collections);
		$limitingPaths = $this->getUntestedFunctionsLimitingPaths();
		foreach ($handlers as $handler => $regexps) {
			if (!$this->isHandlerInPaths($handler, $limitingPaths)) {
				continue;
			}
			foreach ($regexps as $regex) {
				if (count($matches = Safe::pregMatch('/^(.)(.+?)\\1([a-z]*)$/', $regex->match)) === 0) {
					continue;
				}
				$mask = $matches[2];
				if (count($matches = Safe::pregMatch('/^\^?([a-z0-9_-]+)/', $mask)) === 0) {
					$cmdList = array_merge(...array_values($testCommands));
				} else {
					$cmdList = $testCommands[$matches[1]] ?? null;
				}
				if (!isset($cmdList)) {
					continue;
				}
				if ($this->isRegexpHandled($regex, $cmdList)) {
					continue 2;
				}
			}
			$unfound []= $handler;
		}
		return $unfound;
	}

	/**
	 * Get a list of paths to modules for which we limit testing to
	 *
	 * @return null|list<string>
	 */
	private function getUntestedFunctionsLimitingPaths(): ?array {
		$files = BotRunner::getArguments()->testFiles;
		if (!isset($files)) {
			return null;
		}
		$baseDir = BotRunner::getBasedir();
		$result = [];
		foreach ($files as $file) {
			$fullPath = $this->fs->realPath($file);
			$fullPath = substr($fullPath, strlen($baseDir) + 1);
			$modulePaths = [...$this->config->paths->modules, 'src/Core/Modules'];
			foreach ($modulePaths as $modulePath) {
				$fullModulePath = $this->fs->realPath($modulePath);
				if (!$this->fs->exists($fullModulePath)) {
					continue;
				}
				$fullModulePath = substr($fullModulePath, strlen($baseDir) + 1);
				if (str_starts_with($fullPath, $fullModulePath)) {
					$relPath = substr($fullPath, strlen($fullModulePath) + 1);
					$result []= $fullModulePath . '/' . explode('/', $relPath)[0];
				}
			}
		}
		return $result;
	}

	/**
	 * Check if a given regexp is handled by the list of `TestCollection`s
	 *
	 * @param list<string> $testCommands All ran commands grouped by main command
	 */
	private function isRegexpHandled(CommandRegexp $regexp, array $testCommands): bool {
		foreach ($testCommands as $testCommand) {
			if (Safe::pregMatches($regexp->match, $testCommand)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the placeholders of the test-commands, and group them by command
	 *
	 * @param list<TestCollection> $collections
	 *
	 * @return array<string,list<string>>
	 */
	private function getSimplifiedTestCommands(array $collections): array {
		$result = [];
		foreach ($collections as $collection) {
			foreach ($collection->groups as $group) {
				foreach ($group->tests as $test) {
					$command = Safe::pregReplace('/^!/', '', $test->command);
					$command = $this->replacePlaceholders($command, [], true);
					$command = str_replace(['{filename}'], 'test.log', $command);
					$command = str_replace(['{org_member}', '{attacker}'], 'Abcde', $command);
					$command = str_replace(['{id}', '{quote}'], '07067c15-3a1f-4a3f-9e96-3fdb7d500903', $command);
					$command = str_replace(['{org_id}', '{uid}', '{points}', '{loot_roll}'], '12345', $command);
					$command = str_replace('{field}', 'AEG 1', $command);
					$command = str_replace(['{def_org}', '{org}'], 'Testing Org', $command);
					$command = Safe::pregReplace('/\{[a-z0-9_]+_id\}/', '07067c15-3a1f-4a3f-9e96-3fdb7d500903', $command);
					$mainCommand = explode(' ', $command)[0];
					$result[$mainCommand] ??= [];
					$result[$mainCommand] []= $command;
					$command = Safe::pregReplace('/^runas [a-z0-9-]{4,12} /is', '', $command);
					$mainCommand = explode(' ', $command)[0];
					$result[$mainCommand] ??= [];
					$result[$mainCommand] []= $command;
				}
			}
		}
		return $result;
	}

	/**
	 * Get a list of all function handlers and their regexps
	 *
	 * @return array<string,list<CommandRegexp>> Regexps as `["<class>.<method>:line" => 'Regexp']`
	 */
	private function getFunctionHandlerRegexes(): array {
		// get all command handlers
		$handlers = $this->getAllCommandHandlers('msg');

		// filter command handlers by access level
		$handlers = array_filter($handlers, function (CommandHandler $handler): bool {
			return $this->accessManager->checkAccess($this->config->general->superAdmins[0], $handler->access_level);
		});

		// get calls for handlers
		/** @var list<string> */
		$calls = array_reduce(
			$handlers,
			static function (array $handlers, CommandHandler $handler): array {
				return array_merge($handlers, $handler->files);
			},
			[]
		);

		$calls = $this->commandManager->sortCalls($calls);

		// get regular expressions for calls
		$regexes = [];
		foreach ($calls as $call) {
			[$name, $method, $line] = explode('.', $call);
			$instance = Registry::tryGetInstance($name);
			if (!isset($instance)) {
				continue;
			}
			try {
				$reflectedMethod = new \ReflectionMethod($instance, $method);
				if (str_starts_with($reflectedMethod->getDeclaringClass()->getNamespaceName(), 'Nadybot\\User\\Modules')) {
					continue;
				}
				$commands = $reflectedMethod->getAttributes(NCA\HandlesCommand::class);
				if (!count($commands)) {
					continue;
				}
				if (count($reflectedMethod->getAttributes(NCA\Untestable::class)) > 0) {
					continue;
				}

				$commandObj = $commands[0]->newInstance();
				$command = $commandObj->command;
				$command = explode(' ', $command)[0];
				$key = $reflectedMethod->getDeclaringClass()->getFileName();
				if ($key === false) {
					$key = "{$name}.{$method}";
				} else {
					$key = substr($key, strlen(BotRunner::getBasedir()) + 1);
				}
				$key .= "#{$line} ({$reflectedMethod->getName()})";
				$regexes[$key] = $this->commandManager->retrieveRegexes($reflectedMethod);
			} catch (\ReflectionException $e) {
				continue;
			}
		}
		ksort($regexes, \SORT_NATURAL);
		return $regexes;
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
				$value = preg_quote($value, chr(0));
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
	 * Get the matching string from a given regular expression for a given text
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

		/** @var array<int,string> */
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
			$this->logger->notice('  [S] ({condition}) {test}', [
				'test' => $test->getName(),
				'condition' => $test->condition,
			]);
			return [TestResult::Skipped, $placeholders];
		}

		$reply = new MockCommandReply();
		$command = $this->replacePlaceholders($test->command, $placeholders, false);
		$cmdContext = $this->getContext($command, $reply);
		$capturedOutput = '';
		$capturer = null;
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
		// Handle unexpected captured output
		foreach ($test->unexpectedCaptured as $unexpected) {
			$unexpected = $this->replacePlaceholders($unexpected, $placeholders);
			$unexpectResult = count($this->getExpectMatches($unexpected, $capturedOutput)) > 0;
			if ($unexpectResult === true) {
				return [$this->logUnexpectedFind($test, $unexpected, $capturedOutput), $placeholders];
			}
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
