<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Exception;
use Nadybot\Core\EventModifier;
use Nadybot\Core\Routing\RoutableEvent;

/**
 * @EventModifier("if-matches")
 * @Description("This modifier will only route messages if they contain
 *	a certain text.")
 * @Param(
 *	name='text',
 *	type='string',
 *	description='The text that needs to be in the message',
 *	required=true
 * )
 * @Param(
 *	name='case-sensitive',
 *	type='bool',
 *	description='Determines if the comparison is done case sensitive or not',
 *	required=false
 * )
 * @Param(
 *	name='regexp',
 *	type='bool',
 *	description='If set to true, text is a regular expression to match egainst.',
 *	required=false
 * )
 * @Param(
 *	name='inverse',
 *	type='bool',
 *	description='If set to true, this will inverse the logic
 *	and drop all messages matching the given text.',
 *	required=false
 * )
 */
class IfMatches implements EventModifier {
	protected string $text = "";
	protected bool $caseSensitive = false;
	protected bool $isRegexp = false;
	protected bool $inverse = false;

	public function __construct(string $text, bool $caseSensitive=false, bool $isRegexp=false, bool $inverse=false) {
		$this->text = $text;
		$this->caseSensitive = $caseSensitive;
		$this->inverse = $inverse;
		$this->isRegexp = $isRegexp;
		if ($isRegexp && @preg_match(chr(1) . $text . chr(1) . "si", "") === false) {
			$error = error_get_last()["message"];
			$error = preg_replace("/^preg_match\(\): (Compilation failed: )?/", "", $error);
			throw new Exception("Invalid regular expression: {$error}.");
		}
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$message = $event->getData();
		if ($this->isRegexp) {
			$modifier = "s";
			if ($this->caseSensitive) {
				$modifier .= "i";
			}
			$matches = preg_match(chr(1) . $this->text . chr(1) . "{$modifier}", $message) !== 1;
		} elseif ($this->caseSensitive) {
			$matches = strpos($message, $this->text) !== false;
		} else {
			$matches = stripos($message, $this->text) !== false;
		}
		if ($matches === $this->inverse) {
			return null;
		}
		return $event;
	}
}
