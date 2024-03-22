<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use function Safe\preg_match;
use Exception;

use Nadybot\Core\{
	Attributes as NCA,
	EventModifier,
	Routing\RoutableEvent,
	Safe,
};

#[
	NCA\EventModifier(
		name: 'if-matches',
		description: "This modifier will only route messages if they contain\n".
			'a certain text.'
	),
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
class IfMatches implements EventModifier {
	/** @var string[] */
	protected array $text = [];
	protected bool $caseSensitive = false;
	protected bool $isRegexp = false;
	protected bool $inverse = false;

	/** @param string[] $text */
	public function __construct(array $text, bool $caseSensitive=false, bool $isRegexp=false, bool $inverse=false) {
		$this->text = $text;
		$this->caseSensitive = $caseSensitive;
		$this->inverse = $inverse;
		$this->isRegexp = $isRegexp;
		foreach ($text as $match) {
		// @phpstan-ignore-next-line
			if ($isRegexp && @\preg_match(chr(1) . $match . chr(1) . 'si', '') === false) {
				$error = error_get_last()['message'] ?? 'Unknown error';
				$error = Safe::pregReplace("/^preg_match\(\): (Compilation failed: )?/", '', $error);
				throw new Exception("Invalid regular expression '{$match}': {$error}.");
			}
		}
	}

	public function modify(?RoutableEvent $event=null): ?RoutableEvent {
		if (!isset($event)) {
			return $event;
		}
		// We only check messages, not events
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
