<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use EventSauce\ObjectHydrator\PropertyCasters\CastToArrayWithKey;
use Nadybot\Core\SemanticVersion;

class UpdateNotification {
	public function __construct(
		public string $message,
		#[CastToArrayWithKey("version")]
		public ?SemanticVersion $minVersion=null,
		#[CastToArrayWithKey("version")]
		public ?SemanticVersion $maxVersion=null,
	) {
	}
}
