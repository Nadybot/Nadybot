<?php declare(strict_types=1);

namespace Nadybot\Core;

class StdinCommandReply implements CommandReply {
	private Nadybot $chatBot;

	public function __construct(Nadybot $chatBot) {
		$this->chatBot = $chatBot;
	}

	public function reply($msg): void {
		foreach ((array)$msg as $text) {
			$text = $this->formatMsg($text);
			echo($this->chatBot->vars["name"] . ": {$text}\n");
		}
	}
	
	public function formatMsg(string $message) {
		$array = [
			"<header>" => "\e[1;4m",
			"<header2>" => "\e[4m",
			"<highlight>" => "\e[1m",
			"<black>" => "",
			"<white>" => "",
			"<yellow>" => "",
			"<blue>" => "",
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

			"<myname>" => $this->chatBot->vars["name"],
			"<myguild>" => $this->chatBot->vars["my_guild"],
			"<tab>" => "    ",
			"<end>" => "\e[0m",
			"<symbol>" => "",
			"<br>" => "\n"
		];

		$message = str_ireplace(array_keys($array), array_values($array), $message);
		$message = preg_replace("/<font color=[\"']?#.{6}[\"']>/", "", $message);
		$message = preg_replace("/<a href='chatcmd:\/\/\/(.*?)'>(.*?)<\/a>/s", "\e[4m" . "$2" . "\e[0m", $message);
		$message = preg_replace("/<a href=['\"]?itemref:\/\/\d+\/\d+\/\d+['\"]?>(.*?)<\/a>/s", "[item:$1]", $message);
		$message = preg_replace("/\n<img src=['\"]?rdb:\/\/\d+['\"]?>\n/s", "\n", $message);
		$message = preg_replace("/<img src=['\"]?rdb:\/\/\d+['\"]?>/s", "", $message);
		$parts = [];
		$message = preg_replace_callback(
			"/<a href=\"text:\/\/(.+?)\">(.*?)<\/a>/s",
			function(array $matches) use (&$parts): string {
				$parts []= html_entity_decode($matches[1]);
				return "\e[4m" . $matches[2] . "\e[0m";
			},
			$message
		);
		if (count($parts)) {
			$message .= "\n\n" . join("\n---------------------------------\n", $parts);
		}

		return $message;
	}
}
