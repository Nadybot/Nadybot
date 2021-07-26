<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

class Source {
	public const ORG = "aoorg";
	public const PUB = "aopub";
	public const PRIV = "aopriv";
	public const TELL = "aotell";
	public const WEB = "web";
	public const DISCORD_GUILD = "discordguild";
	public const DISCORD_PRIV = "discordpriv";
	public const DISCORD_MSG = "discordmsg";
	public const TRADEBOT = "tradebot";
	public const IRC = "irc";

	public string $name = "";
	public ?string $label = null;
	public string $type = self::ORG;

	public function __construct(string $type, string $name, ?string $label=null) {
		$this->type = $type;
		$this->name = $name;
		$this->label = $label;
	}

	public function render(?Source $lastHop): ?string {
		switch ($this->type) {
			case static::PRIV:
				if (isset($lastHop) && $lastHop->type === static::ORG) {
					return "Guest";
				}
				return $this->label ?? $this->name;
			case static::DISCORD_GUILD:
				return null;
			case static::DISCORD_PRIV:
				return "Discord: " . ($this->label ?? $this->name);
			case static::DISCORD_MSG:
				return ($this->label ?? $this->name) . "@Discord";
			case static::TELL:
				return "@" . ($this->label ?? $this->name);
			case static::TRADEBOT:
				return null;
			default:
				return $this->label ?? $this->name;
		}
		return null;
	}
}
