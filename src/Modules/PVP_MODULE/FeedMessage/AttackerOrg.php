<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class AttackerOrg {
	public function __construct(
		public string $name,
		public string $faction,
		public ?int $id,
	) {
	}
}
