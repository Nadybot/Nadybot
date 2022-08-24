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

	/** @return array<self> */
	public function split(): array {
		$totalLength = 0;
		if (!isset($this->embeds)) {
			return [$this];
		}
		for ($e = 0; $e < count($this->embeds); $e++) {
			$embed = $this->embeds[$e];
			$totalLength += strlen($embed->title ?? "");
			$totalLength += strlen($embed->description ?? "");
			if (!isset($embed->fields)) {
				continue;
			}
			for ($i = 0; $i < count($embed->fields); $i++) {
				$field = $embed->fields[$i];
				$totalLength += strlen($field->name ?? "");
				$totalLength += strlen($field->value ?? "");
				if ($totalLength >= 6000) {
					$msg2 = clone $this;
					$fields = array_splice($embed->fields, $i);
					$danglingEmbed = clone $embed;
					$danglingEmbed->fields = $fields;
					$embeds = array_splice($this->embeds, $e + 1);
					$msg2->embeds = [$danglingEmbed, ...$embeds];
					return [$this, $msg2];
				}
			}
		}
		return [$this];
	}
}
