<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Safe\Exceptions\JsonException;

class DiscordMessageOut {
	public mixed $nonce = null;
	public ?bool $tts = null;
	public ?string $file = null;

	/** @var ?DiscordEmbed[] */
	public ?array $embeds = null;

	/** @var ?list<DiscordActionRowComponent> */
	public ?array $components = null;
	public ?DiscordAllowedMentions $allowed_mentions = null;
	public ?DiscordMessageReference $message_reference = null;
	public ?int $flags = null;

	public function __construct(public string $content) {
	}

	public function toJSON(): string {
		try {
			$string = DiscordAPIClient::encode($this);
			return $string;
		} catch (JsonException) {
			$replacement = clone $this;
			$replacement->content = 'I contain invalid characters';
			$replacement->file = null;
			return DiscordAPIClient::encode($replacement);
		}
	}

	/** @return list<self> */
	public function split(): array {
		$totalLength = 0;
		if (!isset($this->embeds)) {
			return [$this];
		}
		for ($e = 0; $e < count($this->embeds); $e++) {
			$embed = $this->embeds[$e];
			$totalLength += strlen($embed->title ?? '');
			$totalLength += strlen($embed->description ?? '');
			if (!isset($embed->fields)) {
				continue;
			}
			for ($i = 0; $i < count($embed->fields); $i++) {
				$field = $embed->fields[$i];
				$totalLength += strlen($field->name ?? '');
				$totalLength += strlen($field->value ?? '');
				if ($totalLength >= 6_000) {
					$msg2 = clone $this;

					/** @var list<DiscordEmbedField> */
					$fields = array_splice($embed->fields, $i);
					$danglingEmbed = clone $embed;

					$danglingEmbed->fields = $fields;

					/** @var list<DiscordEmbed> */
					$embeds = array_values(array_splice($this->embeds, $e + 1));
					$msg2->embeds = [$danglingEmbed, ...$embeds];
					return [$this, $msg2];
				}
			}
		}
		return [$this];
	}
}
