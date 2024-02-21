<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\{call, delay};
use Amp\Http\Client\Internal\{ForbidCloning, ForbidSerialization};
use Amp\Http\Client\{ApplicationInterceptor, DelegateHttpClient, Request, Response};
use Amp\{CancellationToken, Promise};

class HttpRetryRateLimits implements ApplicationInterceptor {
	use ForbidCloning;
	use ForbidSerialization;

	public function request(
		Request $request,
		CancellationToken $cancellation,
		DelegateHttpClient $httpClient,
	): Promise {
		return call(function () use ($request, $cancellation, $httpClient) {
			while (true) {
				/** @var Response */
				$response = yield $httpClient->request(clone $request, $cancellation);
				if ($response->getStatus() === 429) {
					$waitFor = (float)($response->getHeader("x-ratelimit-reset-after")??(random_int(10, 50)/10));
					yield delay((int)ceil($waitFor * 1000));
				} else {
					return $response;
				}
			}
		});
	}
}
