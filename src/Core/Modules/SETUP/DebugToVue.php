<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use Amp\Http\Server\{Request, RequestHandler, Response};

/**
 * Description: Fetch all files from the VueJS debug server
 *
 * @author Nadyita (RK5)
 */
class DebugToVue implements RequestHandler {
	public function __construct(
		private int $port=8_081,
	) {
	}

	public function handleRequest(Request $request): Response {
		$builder = new \Amp\Http\Client\HttpClientBuilder();
		$client = $builder->build();
		$response = $client->request(new \Amp\Http\Client\Request(
			$request->getUri()->withPort($this->port),
			$request->getMethod(),
			$request->getBody()->buffer(),
		));
		return new \Amp\Http\Server\Response(
			status: $response->getStatus(),
			headers: $response->getHeaders(),
			body: $response->getBody(),
			trailers: null,
		);
	}
}
