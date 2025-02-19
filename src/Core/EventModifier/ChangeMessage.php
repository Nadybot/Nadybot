<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use ErrorException;
use Exception;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Safe,
	Types\EventModifier,
};

/**
 * This modifier allows you to modify the message of an
 * event by replacing text, or adding a prefix.
 */
#[NCA\EventModifier(name: 'change-message')]
class ChangeMessage implements EventModifier {
	/**
	 * @param string|null $addPrefix If set, prefix the message with the given string. Note that it will
	 *                               not automatically add a space between prefix and message
	 * @param string|null $search    If set, search for the given string and replace it with the "replace" parameter
	 * @param string|null $replace   If search is set, this is the text to replace with
	 * @param bool        $isRegExp  If set to true, do a regular expression search and replace
	 */
	public function __construct(
		#[NCA\Param(name: 'add-prefix')] protected ?string $addPrefix=null,
		#[NCA\Param] protected ?string $search=null,
		#[NCA\Param] protected ?string $replace=null,
		#[NCA\Param(name: 'regexp')] protected bool $isRegExp=false,
	) {
		if (isset($search) && !isset($replace)) {
			throw new Exception("Missing parameter 'replace'");
		}
		try {
			if (isset($search) && $isRegExp) {
				Safe::exceptionWrapper(preg_match(...), chr(1) . $search . chr(1) . 'si', '');
			}
		} catch (ErrorException $e) {
			$error = Safe::pregReplace("/^preg_match\(\): (Compilation failed: )?/", '', $e->getMessage());
			throw new Exception(
				message: "Invalid regular expression '{$search}': {$error}.",
				previous: $e
			);
		}
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		if ($event->getEvent() !== $event::TYPE_MESSAGE) {
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
				$message = Safe::pregReplace(
					chr(1) . $this->search . chr(1) . 's',
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
