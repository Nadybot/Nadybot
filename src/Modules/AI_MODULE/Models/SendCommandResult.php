<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/**
 * Result of a sendCommand call, carrying both the content and its format.
 */
final class SendCommandResult {
	use StringableTrait;

	public readonly string $content;
	public readonly ReplyFormat $format;

	/** @var list<string> */
	public readonly array $commandsUsed;

	/** @param list<string> $commandsUsed The bot commands that were executed during tool calls. */
	public function __construct(
		string $content,
		ReplyFormat $format=ReplyFormat::MARKDOWN,
		array $commandsUsed=[],
	) {
		$this->content = trim($content);
		$this->format = $format;
		$this->commandsUsed = $commandsUsed;
	}
}
