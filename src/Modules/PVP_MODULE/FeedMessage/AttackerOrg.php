<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class AttackerOrg {
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
