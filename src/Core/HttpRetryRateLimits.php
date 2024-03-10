<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\delay;
use Amp\Http\Client\{ApplicationInterceptor, DelegateHttpClient, Request, Response};
use Amp\{Cancellation, ForbidCloning as AmpForbidCloning, ForbidSerialization as AmpForbidSerialization};

class HttpRetryRateLimits implements ApplicationInterceptor {
	use AmpForbidCloning;
	use AmpForbidSerialization;

	public function request(
		Request $request,
		Cancellation $cancellation,
		DelegateHttpClient $httpClient,
	): Response {
		while (true) {
			$response = $httpClient->request(clone $request, $cancellation);
			if ($response->getStatus() === 429) {
				$waitFor = (float)($response->getHeader("x-ratelimit-reset-after")??(random_int(10, 50)/10));
				delay($waitFor);
			} else {
				return $response;
			}
		}
	}
}
