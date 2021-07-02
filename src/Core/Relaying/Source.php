<?php declare(strict_types=1);

namespace Nadybot\Core\Relaying;

class Source {
	const ORG = "org";
	const PRIV = "priv";
	const DISCORD = "discord";
	const IRC = "irc";

	public string $name = "";
	public ?string $label = null;
	public string $type = "org";

	public function __construct(string $type, string $name, ?string $label=null) {
		$this->type = $type;
		$this->name = $name;
		$this->label = $label;
	}
}
