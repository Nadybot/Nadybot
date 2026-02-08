<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

use function Amp\delay;
use function Safe\json_decode;
use Amp\Http\Client\{BufferedContent, HttpClientBuilder, Request};
use Error;
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Hydrator,
	ModuleInstance,
	Nadybot,
	Safe,
	SettingManager,
	Text,
	Types\AccessLevel,
};
use Nadybot\Core\Attributes\Parameter\{Regexp, SpaceOptional};
use Nadybot\Core\Events\{ConnectEvent, RecvMsgEvent};
use Nadybot\Core\Exceptions\StopExecutionException;
use Nadybot\Core\Types\SettingMode;
use Psr\Log\LoggerInterface;
use Safe\Exceptions\JsonException;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'translate',
		accessLevel: AccessLevel::Guest,
		description: 'Translate text from one language into another',
		alias: 'trans',
	),
]
class TranslateController extends ModuleInstance {
	private const TRANSLATE_AI = 'https://translate.nadybot.org';

	private const TRANSLATE_BOT = 'Translatore';

	#[NCA\Inject]
	private HttpClientBuilder $http;

	#[NCA\Inject]
	private Nadybot $bot;

	#[NCA\Inject]
	private SettingManager $settings;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/** The default language to translate into */
	#[Attributes\Language]
	private string $defaultLanguage = 'en';

	/** @var array<string,string> */
	private array $languages = [];

	/** The API token for the translation service */
	#[NCA\Setting\Text(
		mode: SettingMode::NoEdit,
		confidential: true,
		accessLevel: AccessLevel::Superadmin,
	)]
	private string $translateApiToken = '';

	#[NCA\Setup]
	public function setup(): void {
		$this->logger->info('Using translation API at {url}', ['url' => self::TRANSLATE_AI]);
		try {
			$languages = $this->loadLanguages();
		} catch (Error) {
			$languages = null;
			$this->languages = [];
		}
		if (isset($languages)) {
			$this->logger->notice('Translation API supports {count} languages', ['count' => $languages->count]);
			foreach ($languages->languages as $language) {
				$this->languages[$language->short] = $language->long;
			}
		}
	}

	/** @return array<string,string> */
	public function getLanguages(): array {
		return $this->languages;
	}

	/** Translate between two arbitrary languages */
	#[NCA\HandlesCommand('translate')]
	#[NCA\Help\Example('<symbol>translate de..en Das ist keine gute Idee')]
	#[NCA\Untestable]
	public function translate2Command(
		CmdContext $context,
		#[Regexp('[a-z]{2}')] string $fromLanguage,
		#[SpaceOptional] #[Regexp("\.\.|-", example: '..')] string $delimiter,
		#[SpaceOptional] #[Regexp('[a-z]{2}')] string $toLanguage,
		string $text
	): void {
		$context->reply($this->translate($text, $toLanguage, $fromLanguage));
	}

	/** Translate from the given language into English */
	#[NCA\HandlesCommand('translate')]
	#[NCA\Help\Example('<symbol>translate de Das ist keine gute Idee')]
	#[NCA\Untestable]
	public function translate1Command(
		CmdContext $context,
		#[Regexp('[a-z]{2}')] string $fromLanguage,
		string $text
	): void {
		$context->reply($this->translate($text, $this->defaultLanguage, $fromLanguage));
	}

	/** List supported languages */
	#[NCA\HandlesCommand('translate')]
	#[NCA\Untestable]
	public function listLanguages(
		CmdContext $context,
		#[NCA\Parameter\Str('languages')] string $command,
	): void {
		if (count($this->languages) === 0) {
			$context->reply('No languages available for translation. Please check your logs.');
			return;
		}
		$lines = ['<header2>Supported languages:<end>'];
		foreach ($this->languages as $short => $long) {
			$lines[] = "<tab><highlight>{$short}<end> - {$long}";
		}
		$blob = implode("\n", $lines);
		$msg = Text::makeBlob(
			name: 'Supported languages (' . count($this->languages) . ')',
			content: $blob
		);
		$context->reply($msg);
	}

	/** Request a Translation-Api-Token */
	#[NCA\HandlesEvent]
	public function onConnect(ConnectEvent $event): void {
		if ($this->translateApiToken !== '') {
			return;
		}
		delay(rand(0, 300));
		$this->requestNewApiToken();
	}

	/** React to tells that give us the Translate Api-key */
	#[NCA\HandlesEvent]
	public function receiveMessageEvent(RecvMsgEvent $eventObj): void {
		if ($eventObj->sender !== self::TRANSLATE_BOT) {
			return;
		}
		$matches = Safe::pregMatch('/^translate set-api-token (.+)$/s', $eventObj->message);
		if (count($matches) === 0) {
			return;
		}
		$this->translateApiToken = $matches[1];
		$this->settings->save('translate_api_token', $matches[1]);
		$this->logger->info('Received Translate API token from {bot}: {token}', [
			'bot' => self::TRANSLATE_BOT,
			'token' => $this->translateApiToken,
		]);
		throw new StopExecutionException();
	}

	/**
	 * Autodetect a text's language and translate it into the default language
	 * To ignore treating the first word as a language code, start your text with a dash (-)
	 */
	#[NCA\HandlesCommand('translate')]
	#[NCA\Untestable]
	public function translate0Command(
		CmdContext $context,
		string $text
	): void {
		$context->reply($this->translate($text, $this->defaultLanguage));
	}

	/** Request a new Translation-Api-Token */
	private function requestNewApiToken(): void {
		$this->bot->sendRawTell(self::TRANSLATE_BOT, 'translate get-api-token');
	}

	private function loadLanguages(): ?Models\LanguageList {
		try {
			$response = $this->http->build()->request(new \Amp\Http\Client\Request(self::TRANSLATE_AI . '/v1/languages'));
			$status = $response->getStatus();
			if ($status !== 200) {
				$this->logger->error('Failed to load languages from translation API. Status: {status}', ['status' => $status]);
				return null;
			}
			$body = $response->getBody()->buffer();
		} catch (Exception) {
			return null;
		}
		try {
			$rawLanguages = json_decode($body, true);

			/** @psalm-suppress MixedArgument: */
			return Hydrator::hydrate(Models\LanguageList::class, $rawLanguages);
		} catch (JsonException | UnableToHydrateObject) {
			return null;
		}
	}

	/**
	 * Safe wrapper around the translate API catching errors
	 *
	 * @param string  $message      The message to translate
	 * @param string  $toLanguage   The language to translate into
	 * @param ?string $fromLanguage The language to translate from, or guess if unknown
	 *
	 * @return string The translated message
	 */
	private function translate(string $message, string $toLanguage, ?string $fromLanguage=null): string {
		if (strlen($this->translateApiToken) === 0) {
			return 'No API token available for translation. Please wait until one was requested.';
		}
		if (count($this->languages) === 0) {
			return 'No languages available for translation. Please check your logs.';
		}
		if (!isset($this->languages[$toLanguage])) {
			return "Unknown target language '<highlight>{$toLanguage}<end>'.";
		}
		if (isset($fromLanguage) && !isset($this->languages[$fromLanguage])) {
			$message = $fromLanguage . ' ' . $message;
			$fromLanguage = null;
		}
		$message = Safe::pregReplace('/^\s*-\s*/s', '', $message);
		$this->logger->notice('Translating {text} from {from} into {to}', [
			'text' => $message,
			'from' => $fromLanguage ?? 'auto-detect',
			'to' => $toLanguage,
		]);
		$request = new Request(self::TRANSLATE_AI . '/v1/translate', 'POST');
		$request->setBody(BufferedContent::fromJson([
			'text' => $message,
			'target_lang' => $toLanguage,
			'source_lang' => $fromLanguage,
		]));
		$request->addHeader('Authorization', 'Bearer ' . $this->translateApiToken);
		$request->setTransferTimeout(120);
		$request->setInactivityTimeout(60);
		$client = $this->http->build();
		$response = $client->request($request);
		$body = $response->getBody()->buffer();
		if ($response->getStatus() === 401) {
			// If the token is invalid, clear it and request a new one
			$this->translateApiToken = '';
			$this->settings->save('translate_api_token', '');
			$this->requestNewApiToken();
			return 'Translation API token is invalid. Requested a new token, please wait a moment and try again.';
		}

		if ($response->getStatus() !== 200) {
			$this->logger->error('Translation API returned status {status} with body {body}', [
				'status' => $response->getStatus(),
				'body' => $body,
			]);
			try {
				$rawBody = json_decode($body, true);

				/** @psalm-suppress MixedArgument */
				$error = Hydrator::hydrate(Models\TranslateError::class, $rawBody);
				return $error->error;
			} catch (JsonException | UnableToHydrateObject) {
				return 'An error occurred during translation. Please check your logs for details.';
			}
		}
		$rawTranslation = json_decode($body, true);
		if (!is_array($rawTranslation) || !isset($rawTranslation['translated_text'])) {
			$this->logger->error('Unexpected response format from translation API: {body}', ['body' => $body]);
			return 'An error occurred during translation. Please check your logs for details.';
		}

		/** @psalm-suppress MixedArgumentTypeCoercion */
		$translation = Hydrator::hydrate(Models\Translation::class, $rawTranslation);
		return $translation->translated_text;
	}
}
