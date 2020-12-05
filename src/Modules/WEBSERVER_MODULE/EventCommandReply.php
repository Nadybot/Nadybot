<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;

class EventCommandReply implements CommandReply {
	protected EventManager $eventManager;
	protected string $uuid;

	public function __construct(EventManager $eventManager, string $uuid) {
		$this->eventManager = $eventManager;
		$this->uuid = $uuid;
	}

	public function reply($msg): void {
		$event = new CommandReplyEvent();
		$event->msgs = array_map([$this, "parseOldFormat"], (array)$msg);
		$event->uuid = $this->uuid;
		$event->type = "cmdreply";
		$this->eventManager->fireEvent($event);
	}

	public function formatMsg(string $message): string {
		$colors = [
			"header" => "<h1>",
			"header2" => "<h2>",
			"highlight" => "<strong>",
			"black" => "#000000",
			"white" => "#FFFFFF",
			"yellow" => "#FFFF00",
			"blue" => "#8CB5FF",
			"green" => "#00DE42",
			"red" => "#FF0000",
			"orange" => "#FCA712",
			"grey" => "#C3C3C3",
			"cyan" => "#00FFFF",
			"violet" => "#8F00FF",
	
			"neutral" => "#111111",
			"omni" => "#222222",
			"clan" => "#333333",
			"unknown" => "#444444",
	
		];
	
		$symbols = [
			"<myname>" => "Ich",
			"<myguild>" => "Gilde",
			"<tab>" => "<tab />",
			"<symbol>" => "",
			"<br>" => "<br />",
		];
	
		$stack = [];
		$message = preg_replace_callback(
			"/<(end|" . join("|", array_keys($colors)) . ")>/",
			function(array $matches) use (&$stack, $colors): string {
				if ($matches[1] === "end") {
					return "</" . array_pop($stack) . ">";
				}
				$tag = $colors[$matches[1]];
				if (substr($tag, 0, 1) === "#") {
					$stack []= "color";
					return "<color value=\"$tag\">";
				}
				$stack []= preg_replace("/[<>]/", "", $tag);
				return $tag;
			},
			$message
		);
		$message = preg_replace("/<a href=['\"]?itemref:\/\/(\d+)\/(\d+)\/(\d+)['\"]?>(.*?)<\/a>/s", "<item lowid=$1 highid=$2 ql=$3>$4</item>", $message);
		$message = preg_replace("/<a href='chatcmd:\/\/\/tell\s+<myname>\s+(.*?)'>(.*?)<\/a>/s", "<command cmd=\"$1\">$2</command>", $message);
		$message = preg_replace("/<a href='chatcmd:\/\/\/start\s+(.*?)'>(.*?)<\/a>/s", "<a href=\"$1\">$2</a>", $message);
		$message = preg_replace("/<a href='chatcmd:\/\/\/(.*?)'>(.*?)<\/a>/s", "<command cmd=\"$1\">$2</command>", $message);
		$message = str_ireplace(array_keys($symbols), array_values($symbols), $message);
		$message = preg_replace("/<img src=['\"]?rdb:\/\/\d+['\"]?>/s", "<img rdb=\"$1\" />", $message);
		$message = preg_replace("/<font color=[\"']?(#.{6})[\"']>/", "<color value=\"$1\">", $message);
		$message = preg_replace("/<\/font>/", "</color>", $message);
		return $message;
	}
	
	public function parseOldFormat(string $message): object {
		$parts = [];
		$id = 0;
		$message = preg_replace_callback(
			"/<a href=\"text:\/\/(.+?)\">(.*?)<\/a>/s",
			function (array $matches) use (&$parts, &$id): string {
				$parts[++$id] = $this->formatMsg(preg_replace("/^<font.*?>(<end>)?/", "", html_entity_decode($matches[1])));
				return "<popup id=\"$id\">" . $this->formatMsg($matches[2]) . "</popup>";
			},
			$message
		);
	
		return (object)[
		  "message" => $this->formatMsg($message),
		  "popups" => (object)$parts
		];
	}
}
