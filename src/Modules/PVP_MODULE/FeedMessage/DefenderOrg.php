<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class DefenderOrg {
	/** @var array<string,int|string|null> */
	public const EXAMPLE_TOKENS = [
		"def-org" => "Troet",
		"c-def-org" => "<neutral>Troet<end>",
		"def-faction" => "Neutral",
		"c-def-faction" => "<neutral>Neutral<end>",
	];

	public function __construct(
		public string $name,
		public string $faction,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		$faction = strtolower($this->faction);
		return [
				"def-org" => $this->name,
				"c-def-org" => "<{$faction}>{$this->name}<end>",
				"def-faction" => $this->faction,
				"c-def-faction" => "<{$faction}>{$this->faction}<end>",
		];
	}
}
