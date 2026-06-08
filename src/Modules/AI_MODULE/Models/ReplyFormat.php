<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

/**
 * Represents the formatting of a sendCommand result
 */
enum ReplyFormat: string {
	/** The content is raw Markdown from the LLM and needs bot-formatting */
	case MARKDOWN = 'markdown';

	/** The content is already in AOML (bot markup) and must not be reformatted */
	case AOML = 'aoml';
}
