<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\Config\BotConfig;
use Psr\Log\LoggerInterface;

class Blob implements \Stringable {
	public const LITERAL = "\x00lit\x00";
	private LoggerInterface $logger;
	private SettingManager $settingManager;

	final public function __construct(
		public string $text='',
		public bool $paginate=true,
		?LoggerInterface $logger=null,
		?SettingManager $settingManager=null,
	) {
		$this->logger = $logger ??= new LoggerWrapper('Core\\Blob');
		$this->settingManager = $settingManager ??= Registry::getInstance(SettingManager::class);
	}

	public function __toString(): string {
		return $this->text;
	}

	public static function create(
		string $text='',
		bool $paginate=true,
		?LoggerInterface $logger=null,
		?SettingManager $settingManager=null,
	): static {
		return new static(
			text: $text,
			paginate: $paginate,
			logger: $logger,
			settingManager: $settingManager,
		);
	}

	public function isEmpty(): bool {
		return strlen($this->text) === 0;
	}

	public function getText(): string {
		$text = str_replace(static::LITERAL, '', $this->text);
		$text = Safe::pregReplace('/<permheader>(.*?)<\/permheader>/s', '$1', $text);
		$text = str_replace('<pagebreak>', '', $text);
		return $text;
	}

	/**
	 * @param string|string[] $text
	 *
	 * @psalm-param string|list<string> $text
	 *
	 * @return string|string[]
	 *
	 * @psalm-return string|list<string>
	 */
	public static function renderMulti(
		string|array $text,
		?int $pageSize=null,
		bool $formatMessage=true,
		bool $renderColors=true
	): string|array {
		if (is_string($text)) {
			return static::create($text)->render($pageSize, $formatMessage, $renderColors);
		}
		$result = [];
		foreach ($text as $page) {
			$subpages = (array)static::create($page)->render($pageSize, $formatMessage, $renderColors);
			$result = array_merge($result, $subpages);
		}
		return $result;
	}

	/**
	 * @return string|string[]
	 *
	 * @psalm-return string|list<string>
	 */
	public function render(?int $pageSize=null, bool $formatMessage=true, bool $renderColors=true): string|array {
		$pageSize ??= ($this->settingManager->getInt('max_blob_size') ?? 0);
		$text = str_replace(static::LITERAL, '', $this->text, $count);
		if ($count > 0) {
			return $this->getText();
		}
		$matches = Safe::pregMatchOffsetAll(
			'/(?<block><a href="text:\/\/(?<popup>.+?)">(?<link>.*?)<\/a>)/s',
			$text
		);
		$lastPosition = 0;
		$resultBlocks = [];
		$hasPaging = false;
		$blocks = $matches['block'] ?? [];
		for ($i = 0; $i < count($blocks); $i++) {
			$blockStart = $matches['block'][$i][1];
			$preText = substr($text, $lastPosition, $blockStart - $lastPosition);
			if ($formatMessage) {
				$preText = $this->formatMessage($preText, $renderColors);
			}
			$resultBlocks []= $preText;
			$lastPosition = $blockStart + strlen($matches['block'][$i][0]);
			$popup = $matches['popup'][$i][0];
			$link = $matches['link'][$i][0];
			$splitPopup = $this->processPopup($pageSize, $link, $popup, $formatMessage, $renderColors);
			if (is_array($splitPopup) && $hasPaging) {
				throw new Exception('Cannot process more than 1 paging popup');
			}
			$hasPaging = is_array($splitPopup);
			$resultBlocks []= $splitPopup;
		}
		$resultBlocks []= substr($text, $lastPosition);
		$result = [];
		$continue = false;
		do {
			$resultLine = [];
			for ($i = 0; $i < count($resultBlocks); $i++) {
				$resultLine []= is_array($resultBlocks[$i]) ? array_shift($resultBlocks[$i]) : $resultBlocks[$i];
				if (is_array($resultBlocks[$i])) {
					$continue = count($resultBlocks[$i]) > 0;
				}
			}
			$result []= implode('', $resultLine);
		} while ($continue);
		return $result;
	}

	public static function make(
		string $name,
		string $content,
		?string $header=null,
		?string $permanentHeader=null
	): static {
		$header ??= $name;

		// trim extra whitespace from beginning and ending
		$content = trim($content);

		// escape double quotes
		$content = str_replace('"', '&quot;', $content);
		$header = str_replace('"', '&quot;', $header);

		// if the content is blank, add a space so the blob will at least appear
		if ($content === '') {
			$content = ' ';
		}

		$headerMarkup = "<header>{$header}<end>\n\n";
		if (isset($permanentHeader) && strlen($permanentHeader)) {
			$headerMarkup .= "<permheader>{$permanentHeader}</permheader>";
		}
		$page = "<a href=\"text://{$headerMarkup}{$content}\">{$name}</a>";
		return new static(text: $page);
	}

	/**
	 * @return string|string[]
	 *
	 * @psalm-return string|list<string>
	 */
	private function processPopup(int $pageSize, string $link, string $popup, bool $formatMessage, bool $renderColors): string|array {
		$headers = Safe::pregMatch(
			"/<header>(?<header>.+?)<end>\n\n(?:<permheader>(?<permheader>.*?)<\/permheader>)?/s",
			$popup
		);
		$header = '';
		$permheader = '';
		if (count($headers)) {
			$header = $headers['header'] ?? '';
			$permheader = $headers['permheader'] ?? '';
			$popup = substr($popup, strlen($headers[0]));
		}
		if ($formatMessage) {
			$popup = $this->formatMessage($popup, $renderColors);
			$header = $this->formatMessage($header, $renderColors);
			$permheader = $this->formatMessage($permheader, $renderColors);
		}
		$pages = $this->paginate(
			$popup,
			$pageSize - strlen($header) - strlen($permheader),
			['<pagebreak>', "\n", ' ']
		);
		$num = count($pages);

		if ($num === 1) {
			return '<a href="text://'.
				($this->settingManager->getString('default_window_color') ?? '').
				"<header>{$header}<end>\n\n{$permheader}{$pages[0]}\">{$link}</a>";
		}
		$i = 1;
		foreach ($pages as $key => $page) {
			$pages[$key] = '<a href="text://'.
				($this->settingManager->getString('default_window_color') ?? '').
				"<header>{$header} (Page {$i} / {$num})<end>\n\n{$permheader}".
				"{$page}\">".
				"{$link} (Page {$i} / {$num})</a>";
			$i++;
		}
		return $pages;
	}

	/** @return array<string,string> */
	private function getColors(): array {
		return [
			'<header>' => str_replace("'", '', $this->settingManager->getString('default_header_color')??''),
			'<header2>' => str_replace("'", '', $this->settingManager->getString('default_header2_color')??''),
			'<highlight>' => str_replace("'", '', $this->settingManager->getString('default_highlight_color')??''),
			'<on>' => str_replace("'", '', $this->settingManager->getString('default_enabled_color')??''),
			'<off>' => str_replace("'", '', $this->settingManager->getString('default_disabled_color')??''),
			'<black>' => '<font color=#000000>',
			'<white>' => '<font color=#FFFFFF>',
			'<yellow>' => '<font color=#FFFF00>',
			'<blue>' => '<font color=#8CB5FF>',
			'<green>' => '<font color=#00DE42>',
			'<red>' => '<font color=#FF0000>',
			'<orange>' => '<font color=#FCA712>',
			'<grey>' => '<font color=#C3C3C3>',
			'<cyan>' => '<font color=#00FFFF>',
			'<violet>' => '<font color=#8F00FF>',

			'<neutral>' => $this->settingManager->getString('default_neut_color')??'',
			'<omni>' => $this->settingManager->getString('default_omni_color')??'',
			'<clan>' => $this->settingManager->getString('default_clan_color')??'',
			'<unknown>' => $this->settingManager->getString('default_unknown_color')??'',
		];
	}

	private function formatMessage(string $message, bool $renderColors): string {
		if ($renderColors === false) {
			return $this->stripColors($message);
		}
		$config = Registry::getInstance(BotConfig::class);
		$array = array_merge(
			$this->getColors(),
			[
				'<myname>' => $config->main->character,
				'<myguild>' => $config->general->orgName,
				'<tab>' => '    ',
				'<end>' => '</font>',
				'<symbol>' => $this->settingManager->getString('symbol')??'!',
				'<br>' => "\n",
			]
		);

		$message = str_ireplace(array_keys($array), array_values($array), $message);

		return $message;
	}

	/**
	 * Strips a message from all its colors
	 *
	 * @param string $message The message to format
	 *
	 * @return string The formatted message
	 */
	private function stripColors(string $message): string {
		$config = Registry::getInstance(BotConfig::class);
		$colors = [];
		foreach ($this->getColors() as $key => $color) {
			$colors[$key] = '';
		}

		$array = array_merge(
			$colors,
			[
				'<myname>' => $config->main->character,
				'<myguild>' => $config->general->orgName,
				'<tab>' => '    ',
				'<end>' => '',
				'<symbol>' => $this->settingManager->getString('symbol')??'!',
				'<br>' => "\n",
			]
		);

		$message = str_ireplace(array_keys($array), array_values($array), $message);

		return $message;
	}

	/**
	 * Convert a single long string into multiple pages of maximum $maxLength size
	 *
	 * @param string   $input     The text to paginate
	 * @param int      $maxLength The maximum allowed length of one page
	 * @param string[] $symbols   An array of strings at which we allow page breaks
	 *
	 * @psalm-param list<string> $symbols
	 *
	 * @return string[] An array of strings with the resulting pages
	 *
	 * @psalm-return list<string>
	 */
	private function paginate(string $input, int $maxLength, array $symbols): array {
		if (count($symbols) === 0) {
			$this->logger->error('Could not successfully page blob due to lack of paging symbols');
			return (array)$input;
		}

		$pageSize = 0;
		$currentPage = '';
		$result = [];
		$symbol = array_shift($symbols);
		if (!strlen($symbol)) {
			$this->logger->error('Could not successfully page blob due to lack of paging symbols');
			return (array)$input;
		}

		/** @var non-empty-string $symbol */

		$lines = explode($symbol, $input);
		foreach ($lines as $line) {
			// retain new lines and spaces in output
			if ($symbol === "\n" || $symbol === ' ') {
				$line .= $symbol;
			}

			$lineLength = strlen($line);
			if ($lineLength > $maxLength) {
				if ($pageSize !== 0) {
					$result []= $currentPage;
					$currentPage = '';
					$pageSize = 0;
				}

				$newResult = $this->paginate($line, $maxLength, $symbols);
				$result = array_merge($result, $newResult);
			} elseif ($pageSize + $lineLength < $maxLength) {
				$currentPage .= $line;
				$pageSize += $lineLength;
			} else {
				$result []= $currentPage;
				$currentPage = $line;
				$pageSize = $lineLength;
			}
		}

		if ($pageSize > 0) {
			$result []= $currentPage;
		}

		return $result;
	}
}
