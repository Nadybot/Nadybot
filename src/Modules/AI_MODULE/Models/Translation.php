<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/**
 * Represents a translation result
 */
class Translation {
	use StringableTrait;

	/**
	 * @param string $request_id      The ID of the translation request
	 * @param string $translated_text The translated text
	 * @param string $source_lang     The source language (e.g. "en" for English)
	 * @param string $target_lang     The target language (e.g. "de" for German
	 */
	public function __construct(
		public readonly string $request_id,
		public readonly string $translated_text,
		public readonly string $source_lang,
		public readonly string $target_lang,
	) {
	}
}
