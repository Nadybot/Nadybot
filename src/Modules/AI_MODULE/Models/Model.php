<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

/** A supported model */
class Model {
	use StringableTrait;

	/**
	 * @param string  $id           The name of the model
	 * @param string  $object       The object type, which is always "model".
	 * @param string  $owned_by     The organization or user that owns the model.
	 * @param ?int    $created      The creation timestamp of the model.
	 * @param ?string $display_name A human-readable name for the model, if available.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $object,
		public readonly string $owned_by,
		public readonly ?int $created=null,
		public readonly ?string $display_name=null,
	) {
	}
}
