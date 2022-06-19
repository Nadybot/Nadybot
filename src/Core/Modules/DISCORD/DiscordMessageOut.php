<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Safe\Exceptions\JsonException;

class DiscordMessageOut {
	public string $content;
	public mixed $nonce = null;
	public ?bool $tts = null;
	public ?string $file = null;

	/** @var null|\Nadybot\Core\Modules\DISCORD\DiscordEmbed[] */
	public ?array $embeds = null;

	/** @var null|\Nadybot\Core\Modules\DISCORD\DiscordActionRowComponent[] */
	public ?array $components = null;
	public ?object $allowed_mentions = null;
	public ?object $message_reference = null;
	public ?int $flags = null;

	public function __construct(string $content) {
		$this->content = $content;
	}

	public function toJSON(): string {
		try {
			$string = DiscordAPIClient::encode($this);
			return $string;
		} catch (JsonException $e) {
			$replacement = clone $this;
			$replacement->content = "I contain invalid characters";
			$replacement->file = null;
			return DiscordAPIClient::encode($replacement);
		}
	}
}
