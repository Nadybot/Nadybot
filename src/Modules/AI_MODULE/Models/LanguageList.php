<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\StringableTrait;

/**
 * Represents a list of languages available for translation
 */
class LanguageList {
	use StringableTrait;

	/**
	 * @param int        $count     Amount of supported languages
	 * @param Language[] $languages A list of supported languages
	 *
	 * @psalm-param list<Language> $languages
	 */
	public function __construct(
		public readonly int $count,
		#[CastListToType(Language::class)] public readonly array $languages,
	) {
	}
}
