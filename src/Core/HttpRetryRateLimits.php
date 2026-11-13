<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\delay;
use Amp\{Cancellation, ForbidCloning as AmpForbidCloning, ForbidSerialization as AmpForbidSerialization};
use Amp\Http\Client\{ApplicationInterceptor, DelegateHttpClient, Request, Response};

/**
 * Automatically retry HTTP requests on HTTP code 429
 *
 * Parses Discord's `X-Ratelimit-Reset-After`-header and the
 * standard `Retry-After`-header, if it contains seconds
 */
class HttpRetryRateLimits implements ApplicationInterceptor {
	use AmpForbidCloning;
	use AmpForbidSerialization;

	/** {@inheritDoc} */
	public function request(
		Request $request,
		Cancellation $cancellation,
		DelegateHttpClient $httpClient,
	): Response {
		while (true) {
			$response = $httpClient->request(clone $request, $cancellation);
			if ($response->getStatus() !== 429) {
				return $response;
			}
			delay(self::getWaitTime($response), true, $cancellation);
		}
	}

	/** Get the number of seconds to wait before retrying a rate-limited request */
	public static function getWaitTime(Response $response): float {
		$resetAfter = $response->getHeader('x-ratelimit-reset-after');
		if (isset($resetAfter) && is_numeric($resetAfter)) {
			return max(0, (float)$resetAfter);
		}
		$retryAfter = $response->getHeader('retry-after');
		if (isset($retryAfter) && ctype_digit(trim($retryAfter))) {
			return (float)trim($retryAfter);
		}
		return random_int(10, 50)/10;
	}
}
