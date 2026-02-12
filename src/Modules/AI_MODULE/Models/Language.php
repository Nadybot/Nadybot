<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/**
 * Represents a list of languages available for translation
 */
class Language {
	use StringableTrait;

	/**
	 * @param string $short The short code of the language (e.g. "en" for English)
	 * @param string $long  The full name of the language (e.g. "English")
	 */
	public function __construct(
		public readonly string $short,
		public readonly string $long,
	) {
	}
}
