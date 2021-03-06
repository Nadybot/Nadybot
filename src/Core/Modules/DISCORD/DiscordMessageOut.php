<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

class DiscordMessageOut {
	public string $content;
	public $nonce = null;
	public bool $tts = false;
	public ?string $file = null;
	public array $embeds = [];
	public ?object $allowed_mentions = null;
	public ?object $message_reference = null;

	public function __construct(string $content) {
		$this->content = $content;
	}

	public function toJSON(): string {
		return json_encode($this, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	}
}
