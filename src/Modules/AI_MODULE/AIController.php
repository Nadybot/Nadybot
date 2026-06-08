<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

use function Safe\json_encode;
use Amp\{CancelledException, TimeoutCancellation};
use Amp\Http\Client\{BufferedContent, HttpClientBuilder, Request, TimeoutException};
use BackedEnum;
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\{Attributes as NCA, CmdContext, CommandManager, Hydrator, ModuleInstance, Registry, Safe, Text};
use Nadybot\Core\Attributes\ExposeToAI;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\{StopExecutionException, UserException};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\{AccessLevel, CommandReply};
use Nadybot\Modules\AI_MODULE\Models\{FunctionCall, ReplyFormat, ToolCallChoice};
use Nadylib\Type;
use Nadylib\Type\Exception\AssertException;
use Psr\Log\LoggerInterface;
use ReflectionNamedType;
use Safe\DateTimeImmutable;
use Safe\Exceptions\JsonException;
use stdClass;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'ai',
		accessLevel: AccessLevel::Guest,
		description: 'Chat with an AI chatbot',
	)
]
class AIController extends ModuleInstance {
	private const GROQ = 'https://api.groq.com/openai/v1';
	private const OPEN_ROUTER = 'https://openrouter.ai/api/v1';
	private const CHATGPT = 'https://api.openai.com/v1';
	private const LOCAL = 'http://127.0.0.1:11434/v1';
	private const GEMINI = 'https://generativelanguage.googleapis.com/v1beta/openai';

	/** Drop old turns when the history exceeds this size */
	private const MAX_HISTORY_EXPIRE = 20;

	/** Maximum history size before triggering a summary compaction */
	private const MAX_HISTORY_COMPACT = 25;

	/** Number of recent messages to preserve during compaction */
	private const COMPACT_KEEP_MESSAGES = 5;

	/** Mode identifier: silently drop old turns */
	private const HISTORY_MODE_EXPIRE = 'expire';

	/** Mode identifier: replace old turns with a generated summary */
	private const HISTORY_MODE_COMPACT = 'compact';

	/** Prompt sent to the LLM to produce a conversation summary */
	private const COMPACT_SUMMARY_PROMPT = 'Summarize our conversation so far. '.
		'Keep all important facts, decisions, context and open questions. '.
		'Be concise but complete enough to continue the conversation.';

	/** Special LLM response that forwards the result of the last tool call */
	private const FORWARD_LAST_TOOL_OUTPUT = 'FORWARD_LAST_TOOL_OUTPUT';

	private const ADDED_SYSTEM_PROMPT = 'Every incoming message to you will be prefixed '.
		"with the sender's name in the format [Username] Message. ".
		'Please use this information to track who said what during the conversation, '.
		'but don\'t add any prefix yourself. '.
		'You are an assistant for the game Anarchy Online. '.
		'Your training data about this game is likely incomplete or outdated. '.
		"\n\n".
		'Before answering any question about game-specific content — such as spawns, '.
		'locations, NPCs, items, quests, or mechanics — ask yourself: '.
		'"Could help_topics have relevant or more accurate information about this?" '.
		'If so, you MUST call the help_topics tool instead of answering from your own knowledge. '.
		'If there is **any reasonable chance** it does, call the tool first. '.
		'Only skip the tool if the question is clearly unrelated to game content.'.
		"\n\n".
		'When replying with more than 3 lines of text, add a new line in front '.
		'that summarizes the content with maximum 40 characters (surrounded with [[ and ]]), so it can be used '.
		"as a teaser to the user.\n\n".
		'The results of your \'run_command\'-calls are **NOT** visible to the user. '.
		"You must decide:\n".
		'- If the result of the \'run_command\'-call is sufficient and self-explanatory: reply with only '.
		self::FORWARD_LAST_TOOL_OUTPUT.' '.
		"as the sole word of your reply, and ignore all other rules\n".
		'- If multiple calls to \'run_command\' were made, and the answer to the question asked is '.
		"spread over several calls, summarize the combined results.\n".
		"- If the 'run_command'-result needs context or clarification, add it before or after\n".
		'- If referring to items or spells from the original result, keep the surrounding '.
		'anchor-links, because these work in-game';
		// 'When the \'run_command\'-tool returns output, that output is already visible to the user. '.
		// 'If you have nothing meaningful to add beyond what \'run_command\' returned, '.
		// 'reply with exactly: DONE.';

	/** Which OpenAI-compatible API to use for chatting */
	#[NCA\Setting\Text(
		options: [
			'local' => self::LOCAL,
			'groq' => self::GROQ,
			'OpenRouter' => self::OPEN_ROUTER,
			'ChatGPT' => self::CHATGPT,
			'Google Gemini' => self::GEMINI,
		]
	)]
	public string $aiApiUrl = self::LOCAL;

	/** The API token (if using an API that requires it) */
	#[NCA\Setting\Text(accessLevel: AccessLevel::Superadmin, confidential: true)]
	public string $aiApiToken = '';

	/** The language model to use */
	#[Attributes\AiModel(accessLevel: AccessLevel::Superadmin)]
	public string $aiModel = '';

	/** The AI assistant role */
	#[NCA\Setting\Text(accessLevel: AccessLevel::Superadmin)]
	public string $aiPrompt = 'You are <myname>, a helpful assistant that answers briefly and concisely, but never ever uses Emojis.';

	/** Share AI history between all public channels */
	#[NCA\Setting\Boolean]
	public bool $sharedAiHistory = true;

	/** Allow the AI to call bot functions (with no rights checking) */
	#[NCA\Setting\Boolean]
	public bool $allowAiFunctionCalls = true;

	/** How the history is kept short */
	#[NCA\Setting\Options(
		options: [
			'expire old messages' => self::HISTORY_MODE_EXPIRE,
			'compact with summary' => self::HISTORY_MODE_COMPACT,
		]
	)]
	public string $aiHistoryMode = self::HISTORY_MODE_EXPIRE;

	#[NCA\Inject]
	private HttpClientBuilder $http;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/**
	 * Conversation histories per channel/DM key
	 *
	 * @var array<string,list<stdClass>>
	 */
	private array $conversationHistory = [];

	/**
	 * Active request flags per history key to block concurrent queries
	 *
	 * @var array<string,true>
	 */
	private array $activeKeys = [];

	/**
	 * @var array<int,Models\ToolFunction>
	 *
	 * @psalm-var list<Models\ToolFunction>
	 */
	private array $tools = [];

	/** @var array<string,ExposedFunction> */
	private array $toolFunctions = [];

	#[NCA\Setup]
	/**
	 * Scan all registered module instances for methods annotated with #[ExposeToAI]
	 * and register them as callable AI functions.
	 */
	public function setup(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$class = new \ReflectionClass($instance);
			foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$exposedAttrs = $method->getAttributes(NCA\ExposeToAI::class);
				if (!count($exposedAttrs)) {
					continue;
				}
				$attrObj = $exposedAttrs[0]->newInstance();
				$this->registerAiFunction($instance, $method, $attrObj->name);
			}
		}
	}

	/** Reset the LLM authentication token and model when the API URL changes. */
	#[NCA\SettingChangeHandler(setting: 'ai_api_url')]
	public function resetLLM(string $setting, string $old, string $new): void {
		if ($new !== $old) {
			$this->aiApiToken = '';
			$this->aiModel = '';
		}
	}

	/**
	 * Chat with an AI
	 *
	 * @psalm-suppress PropertyTypeCoercion
	 */
	#[NCA\HandlesCommand('ai')]
	#[NCA\Untestable]
	public function aiCommand(
		CmdContext $context,
		string $text
	): void {
		$key = $this->getHistoryKey($context);
		if (!isset($key)) {
			$context->reply('AI chat is not available in this context.');
			return;
		}
		if (!isset($this->conversationHistory[$key])) {
			$this->conversationHistory[$key] = [];
		}
		if (isset($this->activeKeys[$key])) {
			$context->reply('I can only process one query at a time.');
			return;
		}
		$this->activeKeys[$key] = true;
		try {
			if ($this->aiHistoryMode === self::HISTORY_MODE_EXPIRE) {
				$this->expireHistory($key);
			}
			if (count($this->conversationHistory[$key]) === 0 && strlen($this->aiPrompt) > 0) {
				$this->conversationHistory[$key] []= (object)[
					'role' => 'system',
					'content' => str_replace(
						'<myname>',
						$this->config->main->character,
						$this->aiPrompt
					)."\n" . self::ADDED_SYSTEM_PROMPT,
				];
			}
			$this->conversationHistory[$key] []= (object)[
				'role' => Models\Role::USER->value,
				'content' => sprintf('[%s] %s', $context->char->name, $text),
			];

			try {
				$result = $this->sendCommand($context, $this->aiModel, $key);
				if ($result->content === '' || $result->content === '""') {
					return;
				}
				$result = $this->addCommandsFooter($result);
				$result = $this->formatContentForBot($result);
			} catch (UserException $e) {
				$context->reply($e->getMessage());
				return;
			} catch (Exception $e) {
				$this->logger->error('Error during AI command execution: {error}', [
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
				$context->reply('An error occurred while processing your request. Please check the logs for more details.');
				return;
			}
			$this->conversationHistory[$key] []= (object)[
				'role' => Models\Role::ASSISTANT->value,
				'content' => $result->content,
			];
			$context->reply($result->content);
		} finally {
			unset($this->activeKeys[$key]);
		}
		if ($this->aiHistoryMode === self::HISTORY_MODE_COMPACT) {
			try {
				$this->compactWithSummary($key);
			} catch (Throwable $e) {
				$this->logger->error('Compacting conversation history failed: {error}', [
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}

	/** Format the AI reply to support some basic markdown */
	public function formatAiReply(string $reply): string {
		$formatter = new Botml\Formatter($this->logger);
		return trim($formatter->format($reply));
	}

	/**
	 * Send a command to the AI API and handle tool calls.
	 *
	 * Reads the current history for the key, resolves recursive tool calls,
	 * and returns the final text response.
	 */
	public function sendCommand(CmdContext $context, string $model, string $historyKey, ?string $apiURL=null): Models\SendCommandResult {
		$messages = $this->conversationHistory[$historyKey];
		assert(count($messages) > 0);
		[$body, $completion] = $this->executeRequest($model, $messages, $apiURL);
		if ($completion->choices[0] instanceof ToolCallChoice) {
			$decoded = Safe::jsonDecodeObj($body);
			if (
				// @mago-expect analysis:redundant-type-comparison,redundant-type-comparison,redundant-type-comparison,redundant-type-comparison,redundant-type-comparison
				!property_exists($decoded, 'choices')
				|| !is_array($decoded->choices)
				|| count($decoded->choices) === 0
				|| !is_object($decoded->choices[0])
				|| !property_exists($decoded->choices[0], 'message')
				|| !is_object($decoded->choices[0]->message)
			) {
				throw new UserException('Invalid tool call response received from the AI-Api.');
			}

			/** @var stdClass $llmToolCall */
			$llmToolCall = $decoded->choices[0]->message;
			$this->conversationHistory[$historyKey] []= $llmToolCall;

			/** @var list<string> */
			$commandsUsed = [];

			/** @var ToolCallChoice $toolCallChoice */
			$toolCallChoice = $completion->choices[0];
			foreach ($toolCallChoice->message->tool_calls as $call) {
				if ($call->function->name !== 'run_command') {
					continue;
				}
				$commandsUsed []= $this->formatToolCallDisplay($call->function);
			}
			foreach ($this->processToolCallChoice($context, $toolCallChoice) as $result) {
				/** @var stdClass $result */
				$this->conversationHistory[$historyKey] []= $result;
			}
			$nextResult = $this->sendCommand($context, $model, $historyKey, $apiURL);
			return new Models\SendCommandResult(
				$nextResult->content,
				$nextResult->format,
				array_merge($commandsUsed, $nextResult->commandsUsed),
			);
		}
		$content = $completion->getContent();
		if ($content === null) {
			throw new UserException('AI response contained no text content.');
		}
		if ($content === self::FORWARD_LAST_TOOL_OUTPUT) {
			$lastToolResult = $this->getLastToolResult($historyKey);
			if ($lastToolResult !== null) {
				return new Models\SendCommandResult($lastToolResult, Models\ReplyFormat::AOML);
			}
		}
		if (count(Safe::pregMatch('/<a href=[\'"]?text:\/\//s', $content))) {
			return new Models\SendCommandResult($content, ReplyFormat::AOML);
		}
		return new Models\SendCommandResult($content);
	}

	/**
	 * Get an ISO 8601 representation of the current date and time
	 *
	 * @return string the current date and time in ISO 8601 format
	 */
	#[ExposeToAI(name: 'get_date_time')]
	public function getCurrentTime(): string {
		$dateTime = new DateTimeImmutable('now');
		return $dateTime->format(\DateTime::ISO8601);
	}

	/**
	 * Do a websearch for a given search term, and give back a JSON structure with the results.
	 * The structure should be an array of objects with "title", "url" and "content" properties.
	 * In case of an error, the return value will be a string with the error message.
	 *
	 * @param string $query The search term to look up
	 *
	 * @return string|list<stdClass> Either a JSON structure with results, or an error message
	 */
	#[ExposeToAI(name: 'web_search')]
	public function webSearch(string $query): string|array {
		$client = $this->http->build();
		$request = new Request(
			uri: 'https://search.on.nadybot.org/search?' . http_build_query(['q' => $query, 'format' =>'json']),
			method: 'GET'
		);
		$request->setTransferTimeout(10);
		$request->setInactivityTimeout(6);
		try {
			$response = $client->request($request, new TimeoutCancellation(10));
			if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
				return "Error fetching search results for {$query}: HTTP {$response->getStatus()}";
			}
			$body = $response->getBody()->buffer(new TimeoutCancellation(5));
			$result = Safe::jsonDecodeObj($body);

			/** @var list<stdClass> */
			$cleanResult = [];
			if (!isset($result->results) || !is_array($result->results)) {
				return "Invalid response format for search results for {$query}.";
			}
			foreach ($result->results as $item) {
				assert($item instanceof stdClass);
				$cleanItem = new stdClass();
				$cleanItem->title = $item->title ?? '';
				$cleanItem->url = $item->url ?? '';
				$cleanItem->content = $item->content ?? '';
				$cleanResult []= $cleanItem;
			}
			return $cleanResult;
		} catch (CancelledException | TimeoutException) {
			return "Fetching search results for {$query} timed out.";
		} catch (Throwable $e) {
			$this->logger->error('Error fetching search results for {query}: {error} ({class})', [
				'query' => $query,
				'error' => $e->getMessage(),
				'class' => $e::class,
				'exception' => $e,
			]);
			return 'Error fetching search  results. Please check your logs.';
		}
	}

	/**
	 * Fetch the content of a website and return the content as pure HTML.
	 * In case of an error, the return value will be the error message.
	 *
	 * @param string $url The URL to retrieve
	 *
	 * @return string The website content or an error message
	 */
	#[ExposeToAI(name: 'fetch_url')]
	public function fetchURL(string $url): string {
		$client = $this->http->build();
		$request = new Request(uri: $url, method: 'GET');
		$request->setTransferTimeout(10);
		$request->setInactivityTimeout(5);
		try {
			$response = $client->request($request, new TimeoutCancellation(10));
			if ($response->getStatus() !== 200) {
				return "Error fetching URL {$url}: HTTP {$response->getStatus()}";
			}
			return $response->getBody()->buffer(new TimeoutCancellation(5));
		} catch (CancelledException | TimeoutException) {
			return "Fetching URL {$url} timed out.";
		} catch (Throwable $e) {
			$this->logger->error('Error fetching URL {url}: {error} ({class})', [
				'url' => $url,
				'error' => $e->getMessage(),
				'class' => $e::class,
				'exception' => $e,
			]);
			return "Error fetching URL {$url}. Please check your logs.";
		}
	}

	/**
	 * Execute a command as if a user had typed it
	 *
	 * @param string $command The command and all its parameters as string
	 *
	 * @return string The command output
	 *
	 * @throws StopExecutionException
	 */
	#[ExposeToAI('run_command')]
	public function executeCommand(CmdContext $context, string $command): string {
		$newContext = clone $context;
		$newContext->message = $command;
		$this->logger->notice('LLM runs !{command}', ['command' => $command]);
		$sendTo = new class ($context->sendto) implements CommandReply {
			public string $output = '';

			public function __construct(private ?CommandReply $orig) {
			}

			/** @param string|list<string> $msg */
			public function reply(string|array $msg): void {
				if (isset($this->orig)) {
					// $this->orig->reply($msg);
				}
				foreach ((array)$msg as $chunk) {
					$this->output .= $chunk . "\n";
				}
			}
		};
		$newContext->sendto = $sendTo;
		$this->commandManager->syncProcessCmd($newContext);
		return $sendTo->output;
	}

	/**
	 * Append a footer listing all commands used during tool calls.
	 *
	 * Only affects MARKDOWN results; AOML results are returned unchanged.
	 */
	private function addCommandsFooter(Models\SendCommandResult $result): Models\SendCommandResult {
		if ($result->format !== Models\ReplyFormat::MARKDOWN || count($result->commandsUsed) === 0) {
			return $result;
		}
		$commands = array_map(
			static fn (string $command): string => Text::makeChatcmd("<symbol>{$command}", "/tell <myname> {$command}"),
			$result->commandsUsed
		);
		$footer = "\n\nCommands used: " . implode(', ', $commands);
		return new Models\SendCommandResult(
			$result->content . $footer,
			$result->format,
			$result->commandsUsed,
		);
	}

	/** Convert a SendCommandResult to bot-ready AOML format. */
	private function formatContentForBot(Models\SendCommandResult $result): Models\SendCommandResult {
		return match ($result->format) {
			Models\ReplyFormat::AOML => $result,
			Models\ReplyFormat::MARKDOWN => $this->formatMarkdownForBot($result),
		};
	}

	/** Run the full markdown-to-AOML formatting pipeline. */
	private function formatMarkdownForBot(Models\SendCommandResult $result): Models\SendCommandResult {
		$formatted = $this->formatAiReply($result->content);
		$summaries = Safe::pregMatch('/^\[\[(.*?)\]\]\s*/s', $formatted);
		if (count($summaries) > 0) {
			$formatted = substr($formatted, strlen($summaries[0]));
		}
		if (substr_count($formatted, "\n") > 1 || strlen($formatted) > 300) {
			$summary = count($summaries) > 0 ? $summaries[1] : 'AI reply';
			$formatted = Text::makeBlob($summary, $formatted);
		}
		return new Models\SendCommandResult($formatted, Models\ReplyFormat::AOML);
	}

	/**
	 * Perform a single LLM request and return the raw body plus parsed response.
	 *
	 * @param non-empty-list<stdClass> $messages
	 *
	 * @return array{0: string, 1: Models\ChatCompletion}
	 */
	private function executeRequest(string $model, array $messages, ?string $apiURL=null, bool $forbidTools=false): array {
		$client = $this->http->build();
		$apiURL ??= $this->aiApiUrl;
		$uri = sprintf('%s/chat/completions', rtrim($apiURL, '/'));
		$this->logger->info('Using URI {uri}', ['uri' => $uri]);
		$request = new Request(uri: $uri, method: 'POST');
		if (strlen($model) < 1) {
			throw new UserException('No language model has been configured yet.');
		}
		$command = new Models\CompletionCommand(
			model: $model,
			messages: $messages,
		);
		$request->addHeader('Authorization', "Bearer {$this->aiApiToken}");
		$compact = Hydrator::serialize($command);
		$compact['messages'] = $messages;
		if (!$forbidTools && $this->allowAiFunctionCalls && count($this->tools) > 0) {
			$compact['tools'] = $this->tools;
		}
		$this->logger->debug('Sending command {command}', ['command' => $compact]);
		$json = json_encode($compact);
		$request->setBody(BufferedContent::fromString($json, 'application/json; charset=utf-8'));
		$request->setTransferTimeout(120);
		$request->setInactivityTimeout(60);
		try {
			$response = $client->request($request, new TimeoutCancellation(120));
			$this->logger->info('AI-Api HTTP-code is {code}', ['code' => $response->getStatus()]);
			$body = $response->getBody()->buffer(new TimeoutCancellation(60));
		} catch (CancelledException | TimeoutException $e) {
			throw new UserException('AI-Api timed out after 60s.', previous: $e);
		} catch (Throwable $e) {
			$this->logger->error('Error sending request to {url}: {error} ({class})', [
				'url' => $this->aiApiUrl,
				'error' => $e->getMessage(),
				'class' => $e::class,
				'sent' => $json,
				'exception' => $e,
			]);
			throw new UserException('Error sending request to the AI-Api. Please check your logs.', previous: $e);
		}
		$this->logger->debug('AI-Api result is {body}', ['body' => $body]);
		if ($response->getStatus() === 401) {
			$this->logger->error('Error sending request to {url}: {status} ({body})', [
				'url' => $this->aiApiUrl,
				'status' => $response->getStatus(),
				'sent' => $json,
				'body' => $body,
			]);
			throw new UserException('Error sending request to the AI-Api. Please check your logs.');
		}
		if ($response->getStatus() === 400 && $body !== '') {
			$errors = null;
			// If the body contains more than one error, we only show the first one
			try {
				$errors = Safe::jsonDecode($body, Type\vec(Type\mixedDict()));
			} catch (JsonException) {
			}
			try {
				if ($errors !== null) {
					/** @psalm-suppress PossiblyUndefinedArrayOffset */
					$error = Hydrator::hydrate(Models\ErrorResponse::class, $errors[0]);
				} else {
					$error = Hydrator::hydrateString(Models\ErrorResponse::class, $body);
				}
				$this->logger->error('Error from {url}: {error}', [
					'url' => $this->aiApiUrl,
					'error' => $error->error->message,
					'sent' => $json,
				]);
			} catch (Throwable $e) {
				$this->logger->error('Unparseable answer from {url}: {body}', [
					'url' => $this->aiApiUrl,
					'body' => $body,
					'sent' => $json,
					'exception' => $e,
				]);
			}
			throw new UserException('Error sending request to the AI-Api. Please check your logs.');
		}
		if ($response->getStatus() === 429) {
			throw new UserException('You exceeded the limits available for your AI-Api token.');
		}
		if ($response->getStatus() !== 200 || $body === '') {
			try {
				$error = Hydrator::hydrateString(Models\ErrorResponse::class, $body);
				$this->logger->error('Error from {url}: {error}', [
					'url' => $this->aiApiUrl,
					'error' => $error->error->message,
				]);
			} catch (Throwable $e) {
				$this->logger->error('Error from {url}: {code} - {body}', [
					'url' => $this->aiApiUrl,
					'code' => $response->getStatus(),
					'body' => $body,
					'sent' => $json,
					'exception' => $e,
				]);
			}
			throw new UserException('Error chatting with the AI, check logs for details.');
		}
		try {
			$completion = Hydrator::hydrateString(Models\ChatCompletion::class, $body);
			$this->logger->info('AI response is {completion}', ['completion' => $completion]);
		} catch (AssertException | JsonException | UnableToHydrateObject $e) {
			throw new UserException("Invalid response received from the AI-Api: {$e->getMessage()}", previous: $e);
		}
		return [$body, $completion];
	}

	/**
	 * Register a reflected PHP method as an AI-callable function.
	 *
	 * Generates the JSON Schema signature from the method's doc block and parameter types.
	 *
	 * @param object            $instance The module instance that owns the method.
	 * @param \ReflectionMethod $method   The method to expose to the AI.
	 * @param string            $name     The function name seen by the AI.
	 */
	private function registerAiFunction(object $instance, \ReflectionMethod $method, string $name): void {
		$comment = $method->getDocComment();
		if ($comment === false) {
			$this->logger->warning('Cannot add {function} as AI function, because it lacks a doc block', [
				'function' => $name,
			]);
			return;
		}
		$cleanComment = trim(Safe::pregReplace("|^/\*\*(.*)\*/|s", '$1', $comment));
		$cleanComment = Safe::pregReplace("/^[ \t]*\*[ \t]*/m", '', $cleanComment);
		$functionDescripton = trim(Safe::pregReplace('/\n@.*/s', '', $cleanComment));

		$params = $method->getParameters();
		$required = [];

		/** @var array<string,int> */
		$paramOrder = [];

		/** @var array<string, Models\FunctionProperty> */
		$functionProperties = [];

		$i = 0;
		foreach ($params as $param) {
			$type = $param->getType();
			// Skip CmdContext parameters as these will automatically be filled out when calling
			if ($type instanceof ReflectionNamedType && $type->getName() === CmdContext::class) {
				continue;
			}
			$paramDescription =  'no documentation available for this parameter';
			$paramOrder[$param->name] = $i;
			$i++;
			$matches = Safe::pregMatch('/@param[^$]+\$' . preg_quote($param->name, '/') . '\s+(.+)/s', $cleanComment);
			if (count($matches) !== 2) {
				continue;
			}
			$lines = explode("\n", $matches[1]);
			for ($l = 1; $l < count($lines); $l++) {
				if (substr($lines[$l], 0, 1) === '@' || $lines[$l] === '') {
					break;
				}
				$lines[0] .= ' ' . trim($lines[$l]);
			}
			$paramDescription = $lines[0];
			$functionProperties[$param->name] = $this->guessParamType($param, $paramDescription);
			if (!$param->isOptional()) {
				$required []= $param->name;
			}
		}
		$this->tools []= new Models\ToolFunction(
			function: new Models\FunctionSignature(
				name: $name,
				description: $functionDescripton,
				parameters: new Models\FunctionParameters(
					properties: $functionProperties,
					required: $required,
				)
			)
		);
		$this->toolFunctions[$name] = new ExposedFunction(
			name: $name,
			function: $method->getClosure($instance),
			paramOrder: $paramOrder
		);
	}

	/**
	 * Map a PHP reflection parameter to the corresponding JSON Schema property type.
	 *
	 * Supports backed enums, bool, float, string and int. Throws for unsupported types.
	 *
	 * @param \ReflectionParameter $param       The parameter to inspect.
	 * @param string               $description The parameter description extracted from the doc block.
	 *
	 * @return Models\FunctionProperty A FunctionProperty subclass representing the JSON Schema type.
	 *
	 * @throws UnsupportedTypeException If the parameter type is unsupported or the enum has no cases.
	 */
	private function guessParamType(\ReflectionParameter $param, string $description): Models\FunctionProperty {
		$type = $param->getType();
		if (!($type instanceof \ReflectionNamedType)) {
			throw new UnsupportedTypeException(
				'AI interfaces only support distinct parameter types, invalid type for '.
					($param->getDeclaringClass()->name ?? '') . '::' . $param->getDeclaringFunction()->name.
					'($' . $param->name . ')'
			);
		}
		$typeName = $type->getName();
		if (!$type->isBuiltin() && is_a($typeName, BackedEnum::class, true)) {
			// @mago-expect analysis:possibly-static-access-on-interface
			$cases = $typeName::cases();
			if (count($cases) === 0) {
				throw new UnsupportedTypeException(
					"AI interfaces only support enums with cases, enum {$typeName} has no cases for ".
						($param->getDeclaringClass()->name ?? '') . '::' . $param->getDeclaringFunction()->name.
						'($' . $param->name . ')'
				);
			}
			if (is_int($cases[0]->value)) {
				/** @var non-empty-list<int> */
				$values = array_column($cases, 'value');
				return new Models\FunctionPropertyIntEnum(
					description: $description,
					enum: $values,
				);
			}

			/** @var non-empty-list<string> */
			$values = array_column($cases, 'value');
			return new Models\FunctionPropertyStringEnum(
				description: $description,
				enum: $values,
			);
		}
		switch ($typeName) {
			case 'bool':
				return new Models\FunctionPropertyBoolean(description: $description);
			case 'float':
				return new Models\FunctionPropertyFloat(description: $description);
			case 'string':
				return new Models\FunctionPropertyString(description: $description);
			case 'int':
				return new Models\FunctionPropertyInt(description: $description);
			default:
				throw new UnsupportedTypeException(
					"AI interfaces only support specific parameter types, invalid type '{$typeName}' for ".
						($param->getDeclaringClass()->name ?? '') . '::' . $param->getDeclaringFunction()->name.
						'($' . $param->name . ')'
				);
		}
	}

	/**
	 * This function is used to process the tool call choices from the OpenAI API response.
	 *
	 * @param ToolCallChoice $choice The choice object containing the tool call message.
	 *
	 * @return list<stdClass>
	 *
	 * @psalm-suppress MoreSpecificReturnType
	 * @psalm-suppress LessSpecificReturnStatement
	 */
	private function processToolCallChoice(CmdContext $context, ToolCallChoice $choice): array {
		/** @var list<stdClass> */
		$result = [];
		$calls = $choice->message->tool_calls;
		foreach ($calls as $call) {
			$result []= (object)[
				'role' => Models\Role::TOOL->value,
				'tool_call_id' => $call->id,
				'content' => $this->processFunctionCall($context, $call->function),
			];
		}
		return $result;
	}

	/**
	 * Execute a single AI-requested function call and JSON-serialize the result.
	 *
	 * Looks up the registered function, injects the CmdContext if required,
	 * and encodes the return value (objects are serialized, everything else is JSON-encoded).
	 *
	 * @param CmdContext   $context The command context for injection.
	 * @param FunctionCall $call    The function name and arguments from the AI.
	 *
	 * @return string The JSON-encoded result, or an error message.
	 */
	private function processFunctionCall(CmdContext $context, FunctionCall $call): string {
		$arguments = Safe::jsonDecode($call->arguments, Type\dict(Type\string(), Type\mixed()));
		if (!array_key_exists($call->name, $this->toolFunctions)) {
			return "Unknown function \"{$call->name}\"";
		}
		$functionSpec = $this->toolFunctions[$call->name];
		foreach ($arguments as $name => $value) {
			if (!array_key_exists($name, $functionSpec->paramOrder)) {
				return "Unknown parameter \"{$name}\" to function {$call->name}";
			}
		}
		$this->logger->info('Calling {function} with arguments {arguments}', [
			'function' => $functionSpec->name,
			'arguments' => $arguments,
		]);
		$refFunc = new \ReflectionFunction($functionSpec->function);
		$params = $refFunc->getParameters();
		for ($i = 0; $i < count($params); $i++) {
			$type = $params[$i]->getType();
			if ($type instanceof ReflectionNamedType && $type->getName() === CmdContext::class) {
				array_splice($arguments, $i, 0, [$context]);
			}
		}
		$result = call_user_func($functionSpec->function, ...$arguments);
		if (is_object($result) && !($result instanceof stdClass)) {
			return json_encode(Hydrator::serialize($result), \JSON_UNESCAPED_SLASHES);
		}
		return json_encode($result, \JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Build a human-readable string for a tool call to show in the footer.
	 *
	 * For run_command the actual bot command is returned, for everything else the function name.
	 */
	private function formatToolCallDisplay(FunctionCall $call): string {
		if ($call->name === 'run_command') {
			$args = Safe::jsonDecode($call->arguments, Type\dict(Type\string(), Type\mixed()));
			$command = $args['command'] ?? null;
			if (is_string($command)) {
				return $command;
			}
		}
		return $call->name;
	}

	/**
	 * Get the content of the most recent tool result in the conversation history.
	 *
	 * @return ?string The content, or null if no tool result exists.
	 */
	private function getLastToolResult(string $historyKey): ?string {
		$messages = $this->conversationHistory[$historyKey] ?? [];
		for ($i = count($messages) - 1; $i >= 0; $i--) {
			if (isset($messages[$i]->role) && $messages[$i]->role === Models\Role::TOOL->value) {
				$content = $messages[$i]->content ?? null;
				return is_string($content) ? $content : null;
			}
		}
		return null;
	}

	/**
	 * Find the index of the next message with role "user".
	 *
	 * @param list<stdClass> $messages
	 * @param int            $start    The search position to start from
	 *
	 * @return ?int The index, or null if none exists.
	 */
	private function searchNextUserMessage(array $messages, int $start): ?int {
		$count = count($messages);
		$search = Models\Role::USER->value;
		for ($i = $start; $i < $count; $i++) {
			if (isset($messages[$i]->role) && $messages[$i]->role === $search) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Find the index of the last message with role "user".
	 *
	 * @param list<stdClass> $messages
	 * @param ?int           $before   Search no further than this index.
	 *
	 * @return ?int The index, or null if none exists.
	 */
	private function searchLastUserMessage(array $messages, ?int $before=null): ?int {
		$end = $before ?? count($messages) - 1;
		$search = Models\Role::USER->value;
		for ($i = $end; $i >= 0; $i--) {
			if (isset($messages[$i]->role) && $messages[$i]->role === $search) {
				return $i;
			}
		}
		return null;
	}

	/** Drop the oldest complete turn from the history. */
	private function deleteOldestHistoryEntry(string $key): bool {
		$messages = $this->conversationHistory[$key];
		$firstUser = $this->searchNextUserMessage($messages, 1);
		if ($firstUser === null) {
			return false;
		}
		$secondUser = $this->searchNextUserMessage($messages, $firstUser + 1);
		if ($secondUser === null) {
			// firstUser is the last user in the history (current turn).
			return false;
		}
		array_splice($this->conversationHistory[$key], $firstUser, $secondUser - $firstUser);
		return true;
	}

	/** Drop the oldest complete turns to keep the history short. */
	private function expireHistory(string $key): void {
		while (count($this->conversationHistory[$key]) > self::MAX_HISTORY_EXPIRE) {
			if (!$this->deleteOldestHistoryEntry($key)) {
				break;
			}
		}
	}

	/**
	 * Replace the oldest complete turns with a generated summary.
	 *
	 * The most recent messages are preserved so pronouns and context remain usable.
	 */
	private function compactWithSummary(string $key): void {
		while (count($this->conversationHistory[$key]) > self::MAX_HISTORY_COMPACT) {
			if (!$this->compactOldestBlock($key)) {
				break;
			}
		}
	}

	/**
	 * Summarize the oldest block of the history and replace it with a summary turn.
	 *
	 * @return bool Whether a block was compacted.
	 */
	private function compactOldestBlock(string $key): bool {
		$messages = $this->conversationHistory[$key];
		$count = count($messages);

		/**
		 * Index of the first message to *keep* (not summarize).
		 * Everything between index 1 and $splitIndex-1 goes to the summary.
		 */
		$splitIndex = $this->searchLastUserMessage($messages, $count - self::COMPACT_KEEP_MESSAGES);
		if ($splitIndex === null || $splitIndex <= 1) {
			return false;
		}

		$summaryMessages = array_slice($messages, 1, $splitIndex - 1);
		assert(count($summaryMessages) > 0);
		$summaryMessages []= (object)[
			'role' => Models\Role::USER->value,
			'content' => self::COMPACT_SUMMARY_PROMPT,
		];

		/** @psalm-suppress ArgumentTypeCoercion */
		[, $completion] = $this->executeRequest($this->aiModel, $summaryMessages, forbidTools: true);
		$summaryText = $completion->getContent();
		if ($summaryText === null) {
			throw new UserException('Summary generation returned no content.');
		}
		$this->logger->info('AI history compacted to {compacted}', [
			'compacted' => $summaryText,
		]);

		$recentMessages = array_slice($messages, $splitIndex);
		$this->setHistory($key, $summaryText, $recentMessages);
		return true;
	}

	/**
	 * Replace the history of a key with a system prompt, a summary turn and recent context.
	 *
	 * @param list<stdClass> $recentMessages
	 */
	private function setHistory(string $key, string $summary, array $recentMessages): void {
		/** @var list<stdClass> $newHistory */
		$newHistory = [];
		$newHistory []= $this->conversationHistory[$key][0];
		$newHistory []= (object)[
			'role' => Models\Role::USER->value,
			'content' => self::COMPACT_SUMMARY_PROMPT,
		];
		$newHistory []= (object)[
			'role' => Models\Role::ASSISTANT->value,
			'content' => $summary,
		];
		foreach ($recentMessages as $msg) {
			$newHistory []= $msg;
		}

		/** @psalm-suppress PropertyTypeCoercion */
		$this->conversationHistory[$key] = $newHistory;
	}

	/** Get the conversation key in the history to allow or prevent shared history */
	private function getHistoryKey(CmdContext $context): ?string {
		if ($context->isDM()) {
			return '@' . $context->char->name;
		}
		if ($this->sharedAiHistory) {
			return 'pub';
		}
		if ($context->source === Source::ORG) {
			return 'org';
		}
		if ($context->source === Source::PRIV) {
			return 'priv';
		}
		return $context->source;
	}
}
