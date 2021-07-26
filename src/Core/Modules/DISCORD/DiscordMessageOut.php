<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use JsonException;

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
		try {
			$string = json_encode($this, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
			return $string;
		} catch (JsonException $e) {
			$regex = <<<'END'
			/
			  (
				(?: [\x00-\x7F]                 # single-byte sequences   0xxxxxxx
				|   [\xC0-\xDF][\x80-\xBF]      # double-byte sequences   110xxxxx 10xxxxxx
				|   [\xE0-\xEF][\x80-\xBF]{2}   # triple-byte sequences   1110xxxx 10xxxxxx * 2
				|   [\xF0-\xF7][\x80-\xBF]{3}   # quadruple-byte sequence 11110xxx 10xxxxxx * 3
				){1,100}                        # ...one or more times
			  )
			| .                                 # anything else
			/x
			END;
			$this->content = preg_replace($regex, '$1', $this->content);
			return json_encode($this, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		}
	}
}
