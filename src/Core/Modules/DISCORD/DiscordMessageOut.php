<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Safe\Exceptions\JsonException;
use function Safe\json_encode;

class DiscordMessageOut {
	public string $content;
	public mixed $nonce = null;
	public bool $tts = false;
	public ?string $file = null;
	/** @var \Nadybot\Core\Modules\DISCORD\DiscordEmbed[] */
	public array $embeds = [];
	/** @var \Nadybot\Core\Modules\DISCORD\DiscordActionRowComponent[] */
	public array $components = [];
	public ?object $allowed_mentions = null;
	public ?object $message_reference = null;

	public function __construct(string $content) {
		$this->content = $content;
	}

	public function toJSON(): string {
		try {
			$string = json_encode($this, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
			return $string;
		} catch (JsonException $e) {
			$replacement = clone $this;
			$replacement->content = "I contain invalid characters";
			$replacement->file = null;
			return json_encode($replacement, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		}
	}
}
