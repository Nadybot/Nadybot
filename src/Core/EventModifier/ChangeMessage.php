<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Text,
};

#[
	NCA\EventModifier(
		name: "change-message",
		description: "This modifier allows you to modify the message of an\n".
			"event by replacing text, or adding a prefix."
	),
	NCA\Param(
		name: "add-prefix",
		type: "string",
		description: "If set, prefix the message with the given string. Note that it will\n".
			"not automatically add a space between prefix and message.",
		required: false
	),
	NCA\Param(
		name: "search",
		type: "string",
		description: "If set, search for the given string and replace it with the \"replace\" parameter",
		required: false
	),
	NCA\Param(
		name: "replace",
		type: "string",
		description: "If search is set, this is the text to replace with",
		required: false
	),
	NCA\Param(
		name: "regexp",
		type: "bool",
		description: "If set to true, do a regular expression search and replace",
		required: false
	)
]
class ChangeMessage implements EventModifier {
	#[NCA\Inject]
	public Text $text;

	protected ?string $addPrefix = null;
	protected ?string $search = null;
	protected ?string $replace = null;
	protected bool $isRegExp = false;

	public function __construct(?string $appPrefix=null, ?string $search=null, ?string $replace=null, bool $regexp=false) {
		$this->addPrefix = $appPrefix;
		$this->search = $search;
		$this->replace = $replace;
		$this->isRegExp = $regexp;
		if (isset($search) && !isset($replace)) {
			throw new Exception("Missing parameter 'replace'");
		}
		if (isset($search) && $regexp && @preg_match(chr(1) . $search . chr(1) . "si", "") === false) {
			$error = error_get_last()["message"]??"Unknown error";
			$error = preg_replace("/^preg_match\(\): (Compilation failed: )?/", "", $error);
			throw new Exception("Invalid regular expression '{$search}': {$error}.");
		}
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$baseEvent = $event->data??null;
			if (!isset($baseEvent) || !($baseEvent instanceof Base) || !isset($baseEvent->message)) {
				return $event;
			}
			$message = $baseEvent->message;
			$message = $this->alterMessage($message);
			$modifiedEvent = clone $event;
			if (!isset($modifiedEvent->data) || !is_object($modifiedEvent->data)) {
				return $event;
			}
			if (isset($modifiedEvent->data) && ($modifiedEvent->data instanceof Base)) {
				$modifiedEvent->data->message = $message;
			}
			return $modifiedEvent;
		}
		$message = $event->getData();
		if (!isset($message)) {
			return null;
		}
		$message = $this->alterMessage($message);
		$modifiedEvent = clone $event;
		$modifiedEvent->setData($message);
		return $modifiedEvent;
	}

	protected function alterMessage(string $message): string {
		if (isset($this->search, $this->replace)) {
			if ($this->isRegExp) {
				$message = preg_replace(
					chr(1) . $this->search . chr(1) . "s",
					$this->replace,
					$message
				);
			} else {
				$message = str_replace($this->search, $this->replace, $message);
			}
		}
		if (isset($this->addPrefix)) {
			$message = "{$this->addPrefix}{$message}";
		}
		return $message;
	}
}
