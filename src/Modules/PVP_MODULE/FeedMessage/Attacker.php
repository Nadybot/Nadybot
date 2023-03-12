<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class Attacker {
	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		...AttackerOrg::EXAMPLE_TOKENS,
		'att-name' => 'Nady',
		'c-att-name' => '<highlight>Nady<end>',
		'att-level' => 220,
		'c-att-level' => '<highlight>220<end>',
		'att-ai-level' => 30,
		'c-att-ai-level' => '<green>30<end>',
		'att-prof' => 'Bureaucrat',
		'c-att-prof' => '<highlight>Bureaucrat<end>',
		'att-profession' => 'Bureaucrat',
		'c-att-profession' => '<highlight>Bureaucrat<end>',
		'att-org-rank' => 'Advisor',
		'c-att-org-rank' => '<highlight>Advisor<end>',
		'att-gender' => 'Female',
		'c-att-gender' => '<highlight>Female<end>',
		'att-breed' => 'Nano',
		'c-att-breed' => '<highlight>Nano<end>',
		'att-faction' => 'Clan',
		'c-att-faction' => '<clan>Clan<end>',
	];

	public function __construct(
		public string $name,
		public ?int $character_id,
		public ?int $level,
		public ?int $ai_level,
		public ?string $profession,
		public ?string $org_rank,
		public ?string $gender,
		public ?string $breed,
		public ?string $faction,
		public ?AttackerOrg $org,
	) {
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		$tokens = [
			'att-name' => $this->name,
			'c-att-name' => "<highlight>{$this->name}<end>",
			'att-level' => $this->level,
			'c-att-level' => isset($this->level)
				? "<highlight>{$this->level}<end>"
				: null,
			'att-ai-level' => $this->ai_level,
			'c-att-ai-level' => isset($this->ai_level)
				? "<green>{$this->ai_level}<end>"
				: null,
			'att-prof' => $this->profession,
			'att-profession' => $this->profession,
			'c-att-prof' => isset($this->profession)
				? "<highlight>{$this->profession}<end>"
				: null,
			'c-att-profession' => isset($this->profession)
				? "<highlight>{$this->profession}<end>"
				: null,
			'att-org-rank' => $this->org_rank,
			'c-att-org-rank' => isset($this->org_rank)
				? "<highlight>{$this->org_rank}<end>"
				: null,
			'att-gender' => $this->gender,
			'c-att-gender' => isset($this->gender)
				? "<highlight>{$this->gender}<end>"
				: null,
			'att-breed' => $this->breed,
			'c-att-breed' => isset($this->breed)
				? "<highlight>{$this->breed}<end>"
				: null,
			'att-faction' => $this->faction,
			'c-att-faction' => isset($this->faction)
				? "<" . strtolower($this->faction) . ">{$this->faction}<end>"
				: null,
		];
		if (isset($this->org)) {
			$tokens = array_merge($tokens, $this->org->getTokens());
		} else {
			if (isset($this->faction)) {
				$tokens["c-att-name"] = "<" . strtolower($this->faction) . ">".
					$tokens['att-name'] . "<end>";
			} else {
				$tokens["c-att-name"] = "<unknown>{$tokens['att-name']}<end>";
			}
		}
		return $tokens;
	}
}
