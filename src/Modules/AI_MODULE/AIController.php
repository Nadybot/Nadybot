<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

use function Safe\{json_decode, json_encode};
use Amp\{CancelledException, TimeoutCancellation};
use Amp\Http\Client\{BufferedContent, HttpClientBuilder, Request, TimeoutException};
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\{Attributes as NCA, CmdContext, Hydrator, ModuleInstance, Text};
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Modules\AI_MODULE\Models\Role;
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
		$request->addHeader('Authorization', "Bearer {$this->aiApiToken}");
		$this->logger->debug('Sending command {command}', ['command' => $command]);
		$json = json_encode(Hydrator::serialize($command));
		$request->setBody(BufferedContent::fromString($json, 'application/json; charset=utf-8'));
		$request->setTransferTimeout(120);
		$request->setInactivityTimeout(60);
		try {
			$response = $client->request($request, new TimeoutCancellation(120));
			$this->logger->info('Translation HTTP-code is {code}', ['code' => $response->getStatus()]);
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
		$this->logger->debug('Translation result is {body}', ['body' => $body]);
		if ($response->getStatus() === 401) {
			throw new UserException('Error sending request to the AI-Api. Please check your logs.');
		}
		if ($response->getStatus() === 400 && $body !== '') {
			try {
				$reply = json_decode($body, true);

				/** @psalm-suppress MixedArgument */
				$error = Hydrator::hydrate(Models\ErrorResponse::class, $reply);
				$this->logger->error('Error from {url}: {error}', [
					'url' => $this->aiApiUrl,
					'error' => $error->error->message,
				]);
			} catch (Throwable) {
			}
			throw new UserException('Error sending request to the AI-Api. Please check your logs.');
		}
		if ($response->getStatus() === 429) {
			throw new UserException('You exceeded the limits available for your AI-Api token.');
		}
		if ($response->getStatus() !== 200 || $body === '') {
			try {
				$reply = json_decode($body, true);

				/** @psalm-suppress MixedArgument */
				$error = Hydrator::hydrate(Models\ErrorResponse::class, $reply);
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
			$reply = json_decode($body, true);
			if (!is_array($reply)) {
				throw new JsonException('Wrong message format');
			}

			/**
			 * @psalm-suppress MixedArgumentTypeCoercion
			 *
			 * @mago-ignore analysis:less-specific-argument
			 */
			$completion = Hydrator::hydrate(Models\ChatCompletion::class, $reply);
			$this->logger->info('Translation result is {completion}', ['completion' => $completion]);
		} catch (JsonException | UnableToHydrateObject $e) {
			throw new UserException("Invalid response received from the AI-Api: {$e->getMessage()}", previous: $e);
		}
		return $completion->choices[0]->message->content;
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
