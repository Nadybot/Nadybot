<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Amp\ByteStream\{InMemoryStream, InputStream};
use Amp\Http\Client\RequestBody;
use Amp\{Promise, Success};

final class DiscordBody implements RequestBody {
	public function __construct(private string $json) {
	}

	/** @return Promise<array<string,string>> */
	public function getHeaders(): Promise {
		return new Success(['content-type' => 'application/json; charset=utf-8']);
	}

	public function createBodyStream(): InputStream {
		return new InMemoryStream($this->json);
	}

	/** @return Promise<int> */
	public function getBodyLength(): Promise {
		return new Success(\strlen($this->json));
	}
}
