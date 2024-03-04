<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Amp\ByteStream\{ReadableBuffer, ReadableStream};
use Amp\Http\Client\HttpContent;

final class DiscordBody implements HttpContent {
	public function __construct(private string $json) {
	}

	public function getContentType(): ?string {
		return 'application/json; charset=utf-8';
	}

	public function getContent(): ReadableStream {
		return new ReadableBuffer($this->json);
	}

	public function getContentLength(): int {
		return \strlen($this->json);
	}
}
