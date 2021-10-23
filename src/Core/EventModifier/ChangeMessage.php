<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Exception;
use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Text;

/**
 * @EventModifier("change-message")
 * @Description("This modifier allows you to modify the message of an
 *  event by replacing text, or adding a prefix.")
 * @Param(
 *	name='add-prefix',
 *	type='string',
 *	description='If set, prefix the message with the given string. Note that it will
 *	not automatically add a space between prefix and message.',
 *	required=false
 * )
 * @Param(
 *	name='search',
 *	type='string',
 *	description='If set, search for the given string and replace it with the "replace" parameter',
 *	required=false
 * )
 * @Param(
 *	name='replace',
 *	type='string',
 *	description='If search is set, this is the text to replace with',
 *	required=false
 * )
 * @Param(
 *	name='regexp',
 *	type='bool',
 *	description='If set to true, do a regular expression search and replace',
 *	required=false
 * )
 */
class ChangeMessage implements EventModifier {
	/** @Inject */
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
		if ($regexp && @preg_match(chr(1) . $search . chr(1) . "si", "") === false) {
			$error = error_get_last()["message"];
			$error = preg_replace("/^preg_match\(\): (Compilation failed: )?/", "", $error);
			throw new Exception("Invalid regular expression '{$search}': {$error}.");
		}
	}

	protected function alterMessage(string $message): string {
		if (isset($this->search)) {
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

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$message = $event->getData()->message??null;
			if (!isset($message)) {
				return $event;
			}
			$message = $this->alterMessage($message);
			$modifiedEvent = clone $event;
			$modifiedEvent->data->message = $message;
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
}