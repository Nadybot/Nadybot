<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use function Safe\preg_match;

use Amp\Http\Server\{Middleware, Request, RequestHandler, Response};

class PreGzipMiddleware implements Middleware {
	public function handleRequest(Request $request, RequestHandler $requestHandler): Response {
		$response = $requestHandler->handleRequest($request);
		$contentEncoding = $response->getHeader('content-encoding');
		if (isset($contentEncoding)) {
			return $response; // Another request handler or middleware has already encoded the response.
		}
		if (preg_match('/^(application\/javascript|text\/css)/', $response->getHeader('content-type') ?? 'none')) {
			$response->addHeader('content-encoding', 'gzip');
		}
		return $response;
	}
}
