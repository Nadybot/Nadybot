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

#[
	NCA\EventModifier(name: 'if-matches'),
	NCA\Param(
		name: 'text',
		type: 'string[]',
		description: "The text that needs to be in the message.\n".
			'If more than one is given, any of the texts must match, not all.',
		required: true
	),
	NCA\Param(
		name: 'case-sensitive',
		type: 'bool',
		description: 'Determines if the comparison is done case sensitive or not',
		required: false
	),
	NCA\Param(
		name: 'regexp',
		type: 'bool',
		description: 'If set to true, text is a regular expression to match egainst.',
		required: false
	),
	NCA\Param(
		name: 'inverse',
		type: 'bool',
		description: "If set to true, this will inverse the logic\n".
			'and drop all messages matching the given text.',
		required: false
	)
]
/**
 * This modifier will only route messages if they contain
 * a certain text.
 */
class IfMatches implements EventModifier {
	/** @param list<string> $text */
	public function __construct(
		protected array $text,
		protected bool $caseSensitive=false,
		protected bool $isRegexp=false,
		protected bool $inverse=false
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
