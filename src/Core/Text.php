<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\preg_match_all;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;

#[NCA\Instance]
class Text {
	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * Create an interactive string from a list of commands and titles
	 *
	 * @param array<string,string> $links An array in the form ["title" => "chat command (/tell ...)"]
	 *
	 * @return string A string that combines all links into one
	 */
	public function makeHeaderLinks(array $links): string {
		$output = '';
		foreach ($links as $title => $command) {
			$output .= " ::: " . $this->makeChatcmd($title, $command, 'style="text-decoration:none;"') . " ::: ";
		}
		return $output;
	}

	/**
	 * Wraps a block in a before and after part
	 *
	 * @param string          $before String before the link
	 * @param string|string[] $blob   The blob to wrap
	 * @param string|null     $after  The optional string after the blob
	 *
	 * @return string[]
	 */
	public function blobWrap(string $before, string|array $blob, ?string $after=""): array {
		$blob = (array)$blob;
		foreach ($blob as &$page) {
			$page = "{$before}{$page}{$after}";
		}
		return $blob;
	}

	/**
	 * Creates an info window, supporting pagination
	 *
	 * @param string      $name    The text part of the clickable link
	 * @param string      $content The content of the info window
	 * @param string|null $header  If set, use $header as header, otherwise $name
	 *
	 * @return string|string[] The string with link and reference or an array of strings if the message would be too big
	 */
	public function makeBlob(string $name, string $content, ?string $header=null, ?string $permanentHeader=""): string|array {
		$header ??= $name;
		$permanentHeader ??= "";

		// trim extra whitespace from beginning and ending
		$content = trim($content);

		// escape double quotes
		$content = str_replace('"', '&quot;', $content);
		$header = str_replace('"', '&quot;', $header);

		// $content = $this->formatMessage($content);

		// if the content is blank, add a space so the blob will at least appear
		if ($content == '') {
			$content = ' ';
		}

		$pageSize = ($this->settingManager->getInt("max_blob_size")??0) - strlen($permanentHeader);
		$pages = $this->paginate($content, $pageSize, ["<pagebreak>", "\n", " "]);
		$num = count($pages);

		if ($num === 1) {
			$page = $pages[0];
			$headerMarkup = "<header>{$header}<end>\n\n{$permanentHeader}";
			$page = "<a href=\"text://".($this->settingManager->getString("default_window_color")??"").$headerMarkup.$page."\">{$name}</a>";
			return $page;
		}
		$addHeaderRanges = $this->settingManager->getBool("add_header_ranges") ?? false;
		$i = 1;
		foreach ($pages as $key => $page) {
			$headerInfo = "";
			if ($addHeaderRanges
				&& preg_match_all(
					"/<header2>([^<]+)<end>/",
					$page,
					$headers,
					PREG_OFFSET_CAPTURE
				)
			) {
				if (isset($headers) && $headers[1][0][1] === 9) {
					$from = $headers[1][0][0];
					$to = $headers[1][count($headers[1])-1][0];
					$headerInfo = " - {$from}";
					if ($to !== $from) {
						$headerInfo .= " -&gt; {$to}";
					}
				}
			}

			$headerMarkup = "<header>{$header} (Page {$i} / {$num})<end>\n\n{$permanentHeader}";
			$page = "<a href=\"text://".($this->settingManager->getString("default_window_color")??"").$headerMarkup.$page."\">{$name}</a> (Page <highlight>{$i} / {$num}<end>{$headerInfo})";
			$pages[$key] = $page;
			$i++;
		}
		return $pages;
	}

	/**
	 * Creates an info window
	 *
	 * @param string $name    The text part of the clickable link
	 * @param string $content The content of the info window
	 *
	 * @return string|string[] The string with link and reference or an array of strings if the message would be too big
	 */
	public function makeLegacyBlob(string $name, string $content): string|array {
		// escape double quotes
		$content = str_replace('"', '&quot;', $content);

		// $content = $this->formatMessage($content);

		$pages = $this->paginate($content, $this->settingManager->getInt("max_blob_size")??0, ["<pagebreak>", "\n", " "]);
		$num = count($pages);

		if ($num == 1) {
			$page = $pages[0];
			$page = "<a href=\"text://".($this->settingManager->getString("default_window_color")??"").$page."\">{$name}</a>";
			return $page;
		}
		$i = 1;
		foreach ($pages as $key => $page) {
			if ($i > 1) {
				$header = "<header>{$name} (Page {$i} / {$num})<end>\n\n";
			} else {
				$header = '';
			}
			$page = "<a href=\"text://".($this->settingManager->getString("default_window_color")??"").$header.$page."\">{$name}</a> (Page <highlight>{$i} / {$num}<end>)";
			$pages[$key] = $page;
			$i++;
		}
		return $pages;
	}

	/**
	 * Convert a single long string into multiple pages of maximum $maxLength size
	 *
	 * @param string   $input     The text to paginate
	 * @param int      $maxLength The maximum allowed length of one page
	 * @param string[] $symbols   An array of strings at which we allow page breaks
	 *
	 * @return string[] An array of strings with the resulting pages
	 */
	public function paginate(string $input, int $maxLength, array $symbols): array {
		if (count($symbols) === 0) {
			$this->logger->error("Could not successfully page blob due to lack of paging symbols");
			return (array)$input;
		}

		$pageSize = 0;
		$currentPage = '';
		$result = [];
		$symbol = array_shift($symbols);
		if (!strlen($symbol)) {
			$this->logger->error("Could not successfully page blob due to lack of paging symbols");
			return (array)$input;
		}

		// @phpstan-ignore-next-line
		$lines = explode($symbol, $input);
		foreach ($lines as $line) {
			// retain new lines and spaces in output
			if ($symbol == "\n" || $symbol == " ") {
				$line .= $symbol;
			}

			$lineLength = strlen($line);
			if ($lineLength > $maxLength) {
				if ($pageSize != 0) {
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

	/**
	 * Creates a chatcmd link
	 *
	 * @param string $name    The name the link will show
	 * @param string $content The chatcmd to execute
	 * @param string $style   (optional) any styling you want applied to the link, e.g. color="..."
	 *
	 * @return string The link
	 */
	public function makeChatcmd(string $name, string $content, ?string $style=""): string {
		$style ??= "";
		if ($style !== "") {
			$style .= " ";
		}
		$content = str_replace("'", '&#39;', $content);
		return "<a {$style}href='chatcmd://{$content}'>{$name}</a>";
	}

	/**
	 * Creates a user link
	 *
	 * This adds support for right clicking usernames in chat,
	 * providing you with a menu of options (ignore etc.)
	 * (see 18.1 AO patchnotes)
	 *
	 * @param string $user  The name of the user to create a link for
	 * @param string $style (optional) any styling you want applied to the link, e.g. color="..."
	 *
	 * @return string The link to the user
	 */
	public function makeUserlink(string $user, string $style=""): string {
		if ($style !== "") {
			$style .= " ";
		}
		return "<a {$style}href=user://{$user}>{$user}</a>";
	}

	/**
	 * Creates a link to an item in a specific QL
	 *
	 * @param int    $lowId  The Item ID of the low QL version
	 * @param int    $highId The Imtem ID of the high QL version
	 * @param int    $ql     The QL to show the  item at
	 * @param string $name   The name of the item as it should appear in the created link
	 *
	 * @return string A link to the given item
	 */
	public function makeItem(int $lowId, int $highId, int $ql, string $name): string {
		return "<a href='itemref://{$lowId}/{$highId}/{$ql}'>{$name}</a>";
	}

	/**
	 * Creates an image
	 *
	 * @param int    $imageId The id of the image, e.g. 205508
	 * @param string $db      (optional) image database to use, default is the resource database "rdb"
	 *
	 * @return string The image as <img> tag
	 */
	public function makeImage(int $imageId, string $db="rdb"): string {
		return "<img src='{$db}://{$imageId}'>";
	}

	/** @return array<string,string> */
	public function getColors(): array {
		return [
			"<header>" => str_replace("'", "", $this->settingManager->getString('default_header_color')??""),
			"<header2>" => str_replace("'", "", $this->settingManager->getString('default_header2_color')??""),
			"<highlight>" => str_replace("'", "", $this->settingManager->getString('default_highlight_color')??""),
			"<on>" => str_replace("'", "", $this->settingManager->getString('default_enabled_color')??""),
			"<off>" => str_replace("'", "", $this->settingManager->getString('default_disabled_color')??""),
			"<black>" => "<font color=#000000>",
			"<white>" => "<font color=#FFFFFF>",
			"<yellow>" => "<font color=#FFFF00>",
			"<blue>" => "<font color=#8CB5FF>",
			"<green>" => "<font color=#00DE42>",
			"<red>" => "<font color=#FF0000>",
			"<orange>" => "<font color=#FCA712>",
			"<grey>" => "<font color=#C3C3C3>",
			"<cyan>" => "<font color=#00FFFF>",
			"<violet>" => "<font color=#8F00FF>",

			"<neutral>" => $this->settingManager->getString('default_neut_color')??"",
			"<omni>" => $this->settingManager->getString('default_omni_color')??"",
			"<clan>" => $this->settingManager->getString('default_clan_color')??"",
			"<unknown>" => $this->settingManager->getString('default_unknown_color')??"",
		];
	}

	/**
	 * Formats a message with colors, bot name, symbol, by replacing special tags
	 *
	 * @param string $message The message to format
	 *
	 * @return string The formatted message
	 */
	public function formatMessage(string $message): string {
		$array = array_merge(
			$this->getColors(),
			[
				"<myname>" => $this->config->name,
				"<myguild>" => $this->config->orgName,
				"<tab>" => "    ",
				"<end>" => "</font>",
				"<symbol>" => $this->settingManager->getString("symbol")??"!",
				"<br>" => "\n",
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
	public function stripColors(string $message): string {
		$colors = [];
		foreach ($this->getColors() as $key => $color) {
			$colors[$key] = "";
		}

		$array = array_merge(
			$colors,
			[
				"<myname>" => $this->config->name,
				"<myguild>" => $this->config->orgName,
				"<tab>" => "    ",
				"<end>" => "",
				"<symbol>" => $this->settingManager->getString("symbol")??"!",
				"<br>" => "\n",
			]
		);

		$message = str_ireplace(array_keys($array), array_values($array), $message);

		return $message;
	}

	/**
	 * Align a number to $digits number of digits by prefixing it with black zeroes
	 *
	 * @param int    $number   The number to align
	 * @param int    $digits   To how many digits to align
	 * @param string $colortag (optional) The color/tag to assign, e.g. "highlight"
	 * @param bool   $grouping (optional) Set to group in chunks of thousands/millions, etc.
	 *
	 * @return string The zero-prefixed $number
	 */
	public function alignNumber(?int $number, int $digits, ?string $colortag=null, bool $grouping=false): string {
		if ($number === null) {
			if ($grouping) {
				$digits += floor($digits / 3);
			}
			return sprintf("<black>%0{$digits}d<end>", 0);
		}
		$prefixedNumber = sprintf("%0{$digits}d", $number);
		if ($grouping) {
			$prefixedNumber = substr(strrev(chunk_split(strrev($prefixedNumber), 3, ",")), 1);
		}
		if (is_string($colortag)) {
			if ($number == 0) {
				$prefixedNumber = preg_replace('/(0)$/', "<{$colortag}>$1<end>", $prefixedNumber);
			} else {
				$prefixedNumber = preg_replace('/([1-9][\d,]*)$/', "<{$colortag}>$1<end>", $prefixedNumber);
			}
		}
		$alignedNumber = preg_replace("/^([0,]+)(?!$)/", "<black>$1<end>", $prefixedNumber);
		return $alignedNumber;
	}

	/**
	 * Convert a list of string into a 1, 2, 3, 4 and 5 enumeration
	 *
	 * @param string $words The words to enumerate
	 *
	 * @return string The enumerated string
	 */
	public function enumerate(string ...$words): string {
		$last = array_pop($words);
		if (count($words) === 0) {
			return $last;
		}
		$commas = join(", ", $words);
		return join(" and ", [$commas, $last]);
	}

	/**
	 * Run an sprintf format on an array of strings
	 *
	 * @param string $format  The sprintf-style format
	 * @param string $strings The words to change
	 *
	 * @return string[] The formatted array
	 */
	public function arraySprintf(string $format, string ...$strings): array {
		return array_map(
			function (string $text) use ($format): string {
				return sprintf($format, $text);
			},
			$strings
		);
	}

	public function removePopups(string $message, bool $removeLinks=false): string {
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1\s*>(.*?)<\/a>/is",
			function (array $matches) use ($removeLinks): string {
				if ($removeLinks) {
					return chr(1);
				}
				return $matches[3];
			},
			$message
		);
		if ($removeLinks) {
			$message = preg_replace("/(?<=\.)\s+" . chr(1) . "/s", "", $message);
			$message = preg_replace("/\s*\[" . chr(1) . "\]/s", "", $message);
			$message = preg_replace("/\s*" . chr(1) . "/s", "", $message);
		}
		return $message;
	}

	/** @return string[] */
	public function getPopups(string $message): array {
		$popups = [];
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1\s*>(.*?)<\/a>/is",
			function (array $matches) use (&$popups): string {
				$popups []= $matches[2];
				return "";
			},
			$message
		);
		return $popups;
	}

	/** Return the pluralized version of $word if $amount is not 1 */
	public function pluralize(string $word, int $amount): string {
		if ($amount === 1) {
			return $word;
		}
		$exceptions = [
			"tomato" => "tomatoes",
			"potato" => "potatoes",
			"veto" => "vetoes",
			"echo" => "echoes",
			"hero" => "heroes",
			"cargo" => "cargoes",
			"man" => "men",
			"woman" => "women",
			"person" => "people",
			"child" => "children",
			"mouse" => "mice",
			"tooth" => "teeth",
			"goose" => "geese",
			"foot" => "feet",
			"ox" => "oxen",
			"die" => "dice",
			"phenomenon" => "phenomena",
			"criterion" => "criteria",
			"thief" => "thieves",
			"wife" => "wives",
			"knife" => "knives",
			"shelf" => "shelves",
			"leaf" => "leaves",
			"sheep" => "sheep",
			"deer" => "deer",
			"fish" => "fish",
			"species" => "species",
		];
		if (isset($exceptions[strtolower($word)])) {
			$plural = $exceptions[strtolower($word)];
			return substr($word, 0, 1) . substr($plural, 1);
		}
		$plural = "s";
		if (preg_match("/[^aeiou]y$/", $word)) {
			$word = substr($word, 0, strlen($word) -1);
			$plural = "ies";
		} elseif (preg_match("/[ei]x$/", $word)) {
			$word = substr($word, 0, strlen($word) -2);
			$plural = "ices";
		} elseif (str_ends_with($word, "is")) {
			$word = substr($word, 0, strlen($word) -1);
			$plural = "es";
		} elseif (str_ends_with($word, "us")) {
			$word = substr($word, 0, strlen($word) -2);
			$plural = "i";
		} elseif (str_ends_with($word, "fe")) {
			$word = substr($word, 0, strlen($word) -1);
			$plural = "ves";
		} elseif (preg_match("/([cs]h|[sxz])$/", $word)) {
			$plural = "es";
		}
		return $word . $plural;
	}

	/**
	 * Render {token}, {?token:} and {!token} placeholder-based text
	 *
	 * @param string                        $text   The text containing placeholders
	 * @param array<string,string|int|null> $tokens All possibly usable tokens
	 *
	 * @return string The rendered text
	 */
	public function renderPlaceholders(string $text, array $tokens): string {
		// First, we try to replace {?token:<whatever>} and
		// {!token:<whatever>} with either an empty string or <whatever>
		// If the token isn't found, don't touch the text
		do {
			$lastText = $text;
			$text = preg_replace_callback(
				'/\{(?<tag>[a-zA-Z-]+|[!?][a-zA-Z-]+:((?:[^{}]|(?R)))+)\}/',
				function (array $matches) use ($tokens): string {
					$action = substr($matches["tag"], 0, 1);
					if ($action !== "?" && $action !== "!") {
						return (string)($tokens[$matches[1]] ?? "");
					}
					$parts = explode(":", substr($matches["tag"], 1), 2);
					if (count($parts) !== 2) {
						return $matches[0];
					}
					if (isset($tokens[$parts[0]]) === ($action === "?")) {
						return $parts[1];
					}
					return "";
				},
				$text
			);
		} while (str_contains($text, "{") && $lastText !== $text);

		return $text;
	}
}
