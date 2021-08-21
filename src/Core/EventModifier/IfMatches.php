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
 *	type='string[]',
 *	description='The text that needs to be in the message.
 *	If more than one is given, any of the texts must match, not all.',
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
	/** @var string[] */
	protected array $text = [];
	protected bool $caseSensitive = false;
	protected bool $isRegexp = false;
	protected bool $inverse = false;

	public function __construct(array $text, bool $caseSensitive=false, bool $isRegexp=false, bool $inverse=false) {
		$this->text = $text;
		$this->caseSensitive = $caseSensitive;
		$this->inverse = $inverse;
		$this->isRegexp = $isRegexp;
		foreach ($text as $match) {
			if ($isRegexp && @preg_match(chr(1) . $match . chr(1) . "si", "") === false) {
				$error = error_get_last()["message"];
				$error = preg_replace("/^preg_match\(\): (Compilation failed: )?/", "", $error);
				throw new Exception("Invalid regular expression '{$match}': {$error}.");
			}
		}
	}

	protected function matches(string $message): bool {
		foreach ($this->text as $text) {
			if ($this->isRegexp) {
				$modifier = "s";
				if ($this->caseSensitive) {
					$modifier .= "i";
				}
				if (preg_match(chr(1) . $text . chr(1) . "{$modifier}", $message) !== 1) {
					return true;
				}
			} elseif ($this->caseSensitive) {
				if (strpos($message, $text) !== false) {
					return true;
				}
			} else {
				if (stripos($message, $text) !== false) {
					return true;
				}
			}
		}
		return false;
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		// We only require prefixes for messages, the rest is passed through
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$message = $event->getData();
		$matches = $this->matches($message);
		if ($matches === $this->inverse) {
			return null;
		}
		return $event;
	}
}
