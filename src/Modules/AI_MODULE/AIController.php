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
use Nadybot\Core\{Attributes as NCA, CmdContext, Hydrator, ModuleInstance, Registry, Safe, Text};
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Modules\AI_MODULE\Models\{FunctionCall, Role, ToolCallChoice, ToolResultMessage};
use Nadylib\Type;
use Nadylib\Type\Exception\AssertException;
use Psr\Log\LoggerInterface;
use Safe\Exceptions\JsonException;
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

	#[NCA\Inject]
	private HttpClientBuilder $http;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/** @var array<string,list<Models\Message>> */
	private array $conversationHistory = [];

	/**
	 * @var array<int,Models\ToolFunction>
	 *
	 * @psalm-var list<Models\ToolFunction>
	 */
	private array $tools = [];

	/** @var array<string,ExposedFunction> */
	private array $toolFunctions = [];

	#[NCA\Setup]
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

	#[NCA\SettingChangeHandler(setting: 'ai_api_url')]
	public function resetLLM(string $setting, string $old, string $new): void {
		if ($new !== $old) {
			$this->aiApiToken = '';
			$this->aiModel = '';
		}
	}

	/** Chat with an AI */
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
		$size = count($this->conversationHistory[$key]);
		if ($size > 20) {
			array_splice($this->conversationHistory[$key], 1, $size-20);
		}
		if (count($this->conversationHistory[$key]) === 0 && strlen($this->aiPrompt) > 0) {
			$this->conversationHistory[$key] []= new Models\Message(
				role: Role::SYSTEM,
				content: str_replace($this->aiPrompt, '<myname>', $this->config->main->character),
			);
		}
		$this->conversationHistory[$key] []= new Models\Message(
			role: Role::USER,
			content: $text,
		);

		/** @psalm-suppress InvalidArgument */
		$command = new Models\CompletionCommand(
			model: $this->aiModel,
			messages: $this->conversationHistory[$key],
		);
		try {
			$reply = trim($this->sendCommand($command));
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
		$this->conversationHistory[$key] []= new Models\Message(
			role: Role::ASSISTANT,
			content: $reply,
		);
		$reply = $this->formatAiReply($reply);
		if (substr_count($reply, "\n") > 1 || strlen($reply) > 300) {
			$reply = Text::makeBlob('Reply', $reply, 'AI reply');
		}
		$context->reply($reply);
	}

	/** Format the AI reply to support some basic markdown */
	public function formatAiReply(string $reply): string {
		$formatter = new Botml\Formatter($this->logger);
		return trim($formatter->format($reply));
	}

	public function sendCommand(Models\CompletionCommand $command, ?string $apiURL=null): string {
		$client = $this->http->build();
		$apiURL ??= $this->aiApiUrl;
		$uri = sprintf('%s/chat/completions', rtrim($apiURL, '/'));
		$this->logger->info('Using URI {uri}', ['uri' => $uri]);
		$request = new Request(uri: $uri, method: 'POST');
		if (strlen($this->aiApiToken) < 1) {
			// return 'No GPT-Token has been configured yet.';
		}
		if (strlen($command->model) < 1) {
			throw new UserException('No language model has been configured yet.');
		}
		if (!count($command->tools)) {
			$command = $this->addTools($command);
		}
		$request->addHeader('Authorization', "Bearer {$this->aiApiToken}");
		$this->logger->debug('Sending command {command}', ['command' => $command]);
		$compact = Hydrator::serialize($command);
		for ($i = 0; $i < count($command->messages); $i++) {
			if ($command->messages[$i] instanceof \stdClass) {
				$compact['messages'][$i] = $command->messages[$i];
			}
		}
		for ($i = 0; $i < count($command->tools); $i++) {
			$tool = $command->tools[$i];
			if ($tool instanceof Models\ToolFunction) {
				if (count($tool->function->parameters->properties) === 0) {
					$compact['tools'][$i]['function']['parameters']['properties'] = new \stdClass();
				}
			}
		}
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
				'exception' => $e,
			]);
			throw new UserException('Error sending request to the AI-Api. Please check your logs.', previous: $e);
		}
		$this->logger->debug('AI-Api result is {body}', ['body' => $body]);
		if ($response->getStatus() === 401) {
			$this->logger->error('Error sending request to {url}: {status} ({body})', [
				'url' => $this->aiApiUrl,
				'status' => $response->getStatus(),
				'body' => $body,
			]);
			throw new UserException('Error sending request to the AI-Api. Please check your logs.');
		}
		if ($response->getStatus() === 400 && $body !== '') {
			try {
				$error = Hydrator::hydrateString(Models\ErrorResponse::class, $body);
				$this->logger->error('Error from {url}: {error}', [
					'url' => $this->aiApiUrl,
					'error' => $error->error->message,
				]);
			} catch (Throwable $e) {
				$this->logger->error('Unparseable answer from {url}: {body}', [
					'url' => $this->aiApiUrl,
					'body' => $body,
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
		if ($completion->choices[0] instanceof ToolCallChoice) {
			$messages = $command->messages;
			$messages []= Safe::jsonDecodeObj($body)->choices[0]->message;
			foreach ($this->processToolCallChoice($completion->choices[0]) as $result) {
				$messages []= $result;
			}
			$newCommand = new Models\CompletionCommand(
				model: $command->model,
				temperature: $command->temperature,
				messages: $messages
			);
			return $this->sendCommand($newCommand, $apiURL);
		}
		return $completion->choices[0]->message->content;
	}

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

	private function guessParamType(\ReflectionParameter $param, string $description): Models\FunctionProperty {
		$type = $param->getType();
		if (!($type instanceof \ReflectionNamedType)) {
			throw new \Exception(
				'AI interfaces only support distinct parameter types, invalid type for '.
					$param->getDeclaringClass()->name . '::' . $param->getDeclaringFunction()->name.
					'($' . $param->name . ')'
			);
		}
		$typeName = $type->getName();
		if (!$type->isBuiltin() && is_a($typeName, BackedEnum::class, true)) {
			if (is_int($typeName::cases()[0]->value)) {
				return new Models\FunctionPropertyIntEnum(
					description: $description,
					enum: array_column($typeName::cases(), 'value')
				);
			}
			return new Models\FunctionPropertyStringEnum(
				description: $description,
				enum: array_column($typeName::cases(), 'value')
			);
		}
		switch ($typeName) {
			case 'bool': return new Models\FunctionPropertyBoolean(description: $description);
			case 'float': return new Models\FunctionPropertyFloat(description: $description);
			case 'string': return new Models\FunctionPropertyString(description: $description);
			case 'int': return new Models\FunctionPropertyInt(description: $description);
			default: throw new \Exception(
				"AI interfaces only support specific parameter types, invalid type '{$typeName}' for ".
					$param->getDeclaringClass()->name . '::' . $param->getDeclaringFunction()->name.
					'($' . $param->name . ')'
			);
		}
	}

	/**
	 * @return ToolResultMessage[]
	 *
	 * @psalm-return list<ToolResultMessage>
	 */
	private function processToolCallChoice(ToolCallChoice $choice): array {
		$result = [];
		$calls = $choice->message->tool_calls;
		foreach ($calls as $call) {
			$result []= new ToolResultMessage(
				tool_call_id: $call->id,
				content: $this->processFunctionCall($call->function),
			);
		}
		return $result;
	}

	private function processFunctionCall(FunctionCall $call): string {
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
		$result = call_user_func($functionSpec->function, ...$arguments);
		return json_encode(Hydrator::serialize($result), \JSON_UNESCAPED_SLASHES);
		return json_encode(
			match ($call->name) {
				'whois' => Hydrator::serialize($this->playerManager->byName($arguments['name'], $arguments['dimension'] ?? null)),
				default => "Unknown function \"{$call->name}\""
			},
			\JSON_UNESCAPED_SLASHES,
		);
	}

	private function addTools(Models\CompletionCommand $command): Models\CompletionCommand {
		$result = clone $command;
		$result->tools = $this->tools;
		// 	new Models\ToolFunction(
		// 		function: new Models\FunctionSignature(
		// 			name: 'whois',
		// 			description: 'Get information about a character in the game',
		// 			parameters: new Models\FunctionParameters(
		// 				properties: [
		// 					'name' => new Models\FunctionPropertyString(
		// 						description: 'The name of the character',
		// 					),
		// 					'dimension' => new Models\FunctionPropertyInt(
		// 						description: 'The AnarchyOnline-dimension for this character, if not the current one',
		// 					),
		// 				],
		// 				required: ['name'],
		// 			),
		// 		),
		// 	),
		// ];
		return $result;
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
