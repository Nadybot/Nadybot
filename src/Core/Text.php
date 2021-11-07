<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 */
class Text {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * Create an interactive string from a list of commands and titles
	 *
	 * @param array<string,string> $links An array in the form ["title" => "chat command (/tell ...)"]
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
	 * Creates an info window, supporting pagination
	 *
	 * @param string $name The text part of the clickable link
	 * @param string $content The content of the info window
	 * @param string|null $header If set, use $header as header, otherwise $name
	 * @return string|string[] The string with link and reference or an array of strings if the message would be too big
	 */
	public function makeBlob(string $name, string $content, ?string $header=null, ?string $permanentHeader="") {
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

		$pageSize = $this->settingManager->getInt("max_blob_size") - strlen($permanentHeader);
		$pages = $this->paginate($content, $pageSize, ["<pagebreak>", "\n", " "]);
		$num = count($pages);

		if ($num === 1) {
			$page = $pages[0];
			$headerMarkup = "<header>$header<end>\n\n$permanentHeader";
			$page = "<a href=\"text://".$this->settingManager->get("default_window_color").$headerMarkup.$page."\">$name</a>";
			return $page;
		} else {
			$i = 1;
			foreach ($pages as $key => $page) {
				$headerMarkup = "<header>$header (Page $i / $num)<end>\n\n$permanentHeader";
				$page = "<a href=\"text://".$this->settingManager->get("default_window_color").$headerMarkup.$page."\">$name</a> (Page <highlight>$i / $num<end>)";
				$pages[$key] = $page;
				$i++;
			}
			return $pages;
		}
	}

	/**
	 * Creates an info window
	 *
	 * @param string $name The text part of the clickable link
	 * @param string $content The content of the info window
	 * @return string|string[] The string with link and reference or an array of strings if the message would be too big
	 */
	public function makeLegacyBlob(string $name, string $content) {
		// escape double quotes
		$content = str_replace('"', '&quot;', $content);

		// $content = $this->formatMessage($content);

		$pages = $this->paginate($content, $this->settingManager->getInt("max_blob_size"), ["<pagebreak>", "\n", " "]);
		$num = count($pages);

		if ($num == 1) {
			$page = $pages[0];
			$page = "<a href=\"text://".$this->settingManager->get("default_window_color").$page."\">$name</a>";
			return $page;
		} else {
			$i = 1;
			foreach ($pages as $key => $page) {
				if ($i > 1) {
					$header = "<header>$name (Page $i / $num)<end>\n\n";
				} else {
					$header = '';
				}
				$page = "<a href=\"text://".$this->settingManager->get("default_window_color").$header.$page."\">$name</a> (Page <highlight>$i / $num<end>)";
				$pages[$key] = $page;
				$i++;
			}
			return $pages;
		}
	}

	/**
	 * Convert a single long string into multiple pages of maximum $maxLength size
	 *
	 * @param string $input The text to paginate
	 * @param int $maxLength The maximum allowed length of one page
	 * @param string[] $symbols An array of strings at which we allow page breaks
	 * @return string[] An array of strings with the resulting pages
	 */
	public function paginate(string $input, int $maxLength, array $symbols): array {
		if (count($symbols) == 0) {
			$this->logger->log('ERROR', "Could not successfully page blob due to lack of paging symbols");
			return (array)$input;
		}

		$pageSize = 0;
		$currentPage = '';
		$result = [];
		$symbol = array_shift($symbols);

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

				$newResult = $this->paginate($line, (int)$maxLength, $symbols);
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
	 * @param string $name The name the link will show
	 * @param string $content The chatcmd to execute
	 * @param string $style (optional) any styling you want applied to the link, e.g. color="..."
	 * @return string The link
	 */
	public function makeChatcmd(string $name, string $content, ?string $style=""): string {
		if ($style !== "") {
			$style .= " ";
		}
		$content = str_replace("'", '&#39;', $content);
		return "<a {$style}href='chatcmd://$content'>$name</a>";
	}

	/**
	 * Creates a user link
	 *
	 * This adds support for right clicking usernames in chat,
	 * providing you with a menu of options (ignore etc.)
	 * (see 18.1 AO patchnotes)
	 *
	 * @param string $name The name of the user to create a link for
	 * @param string $style (optional) any styling you want applied to the link, e.g. color="..."
	 * @return string The link to the user
	 */
	public function makeUserlink(string $user, string $style=""): string {
		if ($style !== "") {
			$style .= " ";
		}
		return "<a {$style}href=user://$user>$user</a>";
	}

	/**
	 * Creates a link to an item in a specific QL
	 *
	 * @param int $lowId The Item ID of the low QL version
	 * @param int $highId The Imtem ID of the high QL version
	 * @param int $ql The QL to show the  item at
	 * @param string $name The name of the item as it should appear in the created link
	 * @return string A link to the given item
	 */
	public function makeItem(int $lowId, int $highId, int $ql, string $name): string {
		return "<a href='itemref://{$lowId}/{$highId}/{$ql}'>{$name}</a>";
	}

	/**
	 * Creates an image
	 * @param int $imageId The id of the image, e.g. 205508
	 * @param string $db (optional) image database to use, default is the resource database "rdb"
	 * @return string The image as <img> tag
	 */
	public function makeImage(int $imageId, string $db="rdb"): string {
		return "<img src='{$db}://{$imageId}'>";
	}

	/**
	 * Formats a message with colors, bot name, symbol, by replacing special tags
	 *
	 * @param string $message The message to format
	 * @return string The formatted message
	 */
	public function formatMessage(string $message): string {
		$array = [
			"<header>" => str_replace("'", "", $this->settingManager->get('default_header_color')),
			"<header2>" => str_replace("'", "", $this->settingManager->get('default_header2_color')),
			"<highlight>" => str_replace("'", "", $this->settingManager->get('default_highlight_color')),
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

			"<neutral>" => $this->settingManager->get('default_neut_color'),
			"<omni>" => $this->settingManager->get('default_omni_color'),
			"<clan>" => $this->settingManager->get('default_clan_color'),
			"<unknown>" => $this->settingManager->get('default_unknown_color'),

			"<myname>" => $this->chatBot->vars["name"],
			"<myguild>" => $this->chatBot->vars["my_guild"],
			"<tab>" => "    ",
			"<end>" => "</font>",
			"<symbol>" => $this->settingManager->get("symbol"),
			"<br>" => "\n"
		];

		$message = str_ireplace(array_keys($array), array_values($array), $message);

		return $message;
	}

	/**
	 * Align a number to $digits number of digits by prefixing it with black zeroes
	 *
	 * @param int $number The number to align
	 * @param int $digits To how many digits to align
	 * @param string $colortag (optional) The color/tag to assign, e.g. "highlight"
	 * @param bool $grouping (optional) Set to group in chunks of thousands/millions, etc.
	 * @return string The zero-prefixed $number
	 */
	public function alignNumber(?int $number, int $digits, ?string $colortag=null, bool $grouping=false): string {
		if ($number === null) {
			return sprintf("<black>%0{$digits}d<end>", $number);
		}
		$prefixedNumber = sprintf("%0${digits}d", $number);
		if ($grouping) {
			$prefixedNumber = substr(strrev(chunk_split(strrev($prefixedNumber), 3, ",")), 1);
		}
		if (is_string($colortag)) {
			if ($number == 0) {
				$prefixedNumber = preg_replace('/(0)$/', "<$colortag>$1<end>", $prefixedNumber);
			} else {
				$prefixedNumber = preg_replace('/([1-9][\d,]*)$/', "<$colortag>$1<end>", $prefixedNumber);
			}
		}
		$alignedNumber = preg_replace("/^([0,]+)(?!$)/", "<black>$1<end>", $prefixedNumber);
		return $alignedNumber;
	}

	/**
	 * Convert a list of string into a 1, 2, 3, 4 and 5 enumeration
	 * @param string[] $words The words to enumerate
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
	 * @param string $format The sprintf-style format
	 * @param string[] $strings The words to change
	 * @return string[] The formatted array
	 */
	public function arraySprintf(string $format, string ...$strings): array {
		return array_map(
			function(string $text) use ($format): string {
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

	/** Return the pluralized version of $word if $amount is not 1 */
	public function pluralize(string $word, int $amount): string {
		return $word . (($amount === 1) ? "" : "s");
	}
}
