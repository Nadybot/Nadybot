<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use function Safe\preg_replace;
use Amp\Socket\ResourceSocket;
use Nadybot\Core\{
	Attributes as NCA,
	CommandReply,
	Config\BotConfig,
};

use Throwable;

class SocketCommandReply implements CommandReply {
	#[NCA\Inject]
	private BotConfig $config;

	public function __construct(private ResourceSocket $socket) {
	}

	public function reply($msg): void {
		foreach ((array)$msg as $text) {
			$text = $this->formatMsg($text);
			try {
				$this->socket->write("{$text}\n");
			} catch (Throwable) {
			}
		}
	}

	public function formatMsg(string $message): string {
		$array = [
			"<myname>" => $this->config->main->character,
			"<myguild>" => $this->config->general->orgName,
			"<tab>" => "    ",
			"<symbol>" => "",
			"<center>" => "",
			"</center>" => "",
			"<u>" => "",
			"</u>" => "",
			"<i>" => "",
			"</i>" => "",
			"<br>" => "\n",
			"<br/>" => "\n",
			"<br />" => "\n",
		];

		$message = preg_replace_callback(
			"/<black>(.*?)<end>/",
			function (array $matches): string {
				if (function_exists('mb_strlen')) {
					return str_repeat(" ", mb_strlen($matches[1]));
				}
				return str_repeat(" ", strlen($matches[1]));
			},
			$message
		);
		$message = str_ireplace(array_keys($array), array_values($array), $message);
		$message = preg_replace("/<a\s+href=['\"]?user:\/\/[^'\">]+['\"]?\s*>(.*?)<\/a>/s", "<link>$1</link>", $message);
		$message = preg_replace("/<a\s+href=['\"]?skillid:\/\/\d+['\"]?\s*>(.*?)<\/a>/s", "[skill:<link>$1</link>]", $message);
		$message = preg_replace("/<a\s+href=['\"]chatcmd:\/\/\/(.*?)['\"]\s*>(.*?)<\/a>/s", "<link>$2</link>", $message);
		$message = preg_replace("/<a\s+href=['\"]?itemref:\/\/\d+\/\d+\/\d+['\"]?\s*>(.*?)<\/a>/s", "[item:<link>$1</link>]", $message);
		$message = preg_replace("/<a\s+href=['\"]?itemid:\/\/53019\/\d+['\"]?\s*>(.*?)<\/a>/s", "[nano:<link>$1</link>]", $message);
		$message = preg_replace("/<p\s*>/is", "\n", $message);
		$message = preg_replace("/<\/p\s*>/is", "", $message);
		$message = preg_replace("/\n<img\s+src=['\"]?tdb:\/\/id:[A-Z0-9_]+['\"]?\s*>\n/s", "\n", $message);
		$message = preg_replace("/\n<img\s+src=['\"]?rdb:\/\/\d+['\"]?\s*>\n/s", "\n", $message);
		$message = preg_replace("/<img\s+src=['\"]?tdb:\/\/id:[A-Z0-9_]+['\"]?\s*>/s", "", $message);
		$message = preg_replace("/<img\s+src=['\"]?rdb:\/\/\d+['\"]?\s*>/s", "", $message);
		$message = preg_replace("/\n\[item:<link><\/link>]\n/s", "\n", $message);
		$message = str_replace("\n", "\n ", $this->handleColors($message, true));
		$parts = [];
		$message = html_entity_decode(
			preg_replace_callback(
				"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1\s*>(.*?)<\/a>/s",
				function (array $matches) use (&$parts): string {
					$parts[] = html_entity_decode($this->handleColors($matches[2], true), ENT_QUOTES);
					return $this->handleColors("<link>{$matches[3]}</link>", false);
				},
				$message
			),
			ENT_QUOTES
		);
		if (count($parts)) {
			$message .= "\n\n" . join("\n" . str_repeat("-", 75) . "\n", $parts);
		}

		return $message;
	}

	private function handleColors(string $text, bool $clearEOL): string {
		$array = [
			"<header>" => "",
			"<header2>" => "",
			"<highlight>" => "",
			"<link>" => "",
			"</link>" => "",
			"</font>" => "",
			"<black>" => "",
			"<white>" => "",
			"<yellow>" => "",
			"<blue>" => "",
			"<on>" => "",
			"<off>" => "",
			"<green>" => "",
			"<red>" => "",
			"<orange>" => "",
			"<grey>" => "",
			"<cyan>" => "",
			"<violet>" => "",

			"<neutral>" => "",
			"<omni>" => "",
			"<clan>" => "",
			"<unknown>" => "",
			"<end>" => "",
		];
		$text = str_ireplace(array_keys($array), array_values($array), $text);
		$text = preg_replace("/<font\s+color\s*=\s*[\"']?#.{6}[\"']?>/is", "", $text);
		return $text;
	}
}
