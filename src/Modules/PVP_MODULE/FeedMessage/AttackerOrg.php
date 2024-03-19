<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\StringableTrait;

class AttackerOrg {
	use StringableTrait;

	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		"att-org-name" => "Team Rainbow",
		"c-att-org-name" => "<clan>Team Rainbow<end>",
		"att-org" => "Team Rainbow",
		"c-att-org" => "<clan>Team Rainbow<end>",
		"att-org-faction" => 'Clan',
		"c-att-org-faction" => '<clan>Clan<end>',
	];

	public function __construct(
		public string $name,
		public string $faction,
		public ?int $id,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		return [
			"att-org-name" => $this->name,
			"c-att-org-name" => "<" . strtolower($this->faction) . ">{$this->name}<end>",
			"att-org" => $this->name,
			"c-att-org" => "<" . strtolower($this->faction) . ">{$this->name}<end>",
			"att-org-faction" => $this->faction,
			"c-att-org-faction" => "<" . strtolower($this->faction) . ">{$this->faction}<end>",
		];
	}
}
