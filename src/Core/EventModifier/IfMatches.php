<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use function Safe\preg_match;

use ErrorException;
use Exception;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\RoutableEvent,
	Safe,
	Types\EventModifier,
};
use Nadybot\Core\Types\ParamType;

/**
 * This modifier will only route messages if they contain
 * a certain text.
 */
#[NCA\EventModifier(name: 'if-matches')]
class IfMatches implements EventModifier {
	/**
	 * @param list<string> $text          The text that needs to be in the message.
	 *                                    If more than one is given, any of the texts must match, not all.
	 * @param bool         $caseSensitive Determines if the comparison is done case sensitive or not
	 * @param bool         $isRegexp      If set to true, text is a regular expression to match against.
	 * @param bool         $inverse       If set to true, this will inverse the logic
	 *                                    and drop all messages matching the given text.
	 */
	public function __construct(
		#[NCA\Param(type: ParamType::StringArray)] protected array $text,
		#[NCA\Param(name: 'case-sensitive')] protected bool $caseSensitive=false,
		#[NCA\Param(name: 'regexp')] protected bool $isRegexp=false,
		#[NCA\Param] protected bool $inverse=false
	) {
		foreach ($text as $match) {
			try {
				if ($isRegexp) {
					preg_match(chr(1) . $match . chr(1) . 'si', '');
				}
			} catch (ErrorException $e) {
				$error = Safe::pregReplace("/^preg_match\(\): (Compilation failed: )?/", '', $e->getMessage());
				throw new Exception(
					message: "Invalid regular expression '{$match}': {$error}.",
					previous: $e,
				);
			}
		}
	}

	/** {@inheritDoc} */
	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		// We only check messages, not events
		if ($event->getEvent() !== $event::TYPE_MESSAGE) {
			return $event;
		}
		$message = $event->getData();
		$matches = $this->matches($message);
		if ($matches === $this->inverse) {
			return null;
		}
		return $event;
	}

	/** Check if a given message matches the configured criteria */
	protected function matches(string $message): bool {
		foreach ($this->text as $text) {
			if ($this->isRegexp) {
				$modifier = 's';
				if ($this->caseSensitive) {
					$modifier .= 'i';
				}
				if (preg_match(chr(1) . $text . chr(1) . "{$modifier}", $message) === 1) {
					return true;
				}
			} elseif ($this->caseSensitive) {
				if (str_contains($message, $text)) {
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
}
