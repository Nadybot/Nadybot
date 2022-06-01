<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Amp\CancellationToken;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Internal\ForbidCloning;
use Amp\Http\Client\Internal\ForbidSerialization;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;

use function Amp\call;
use function Amp\delay;

class RetryRateLimits implements ApplicationInterceptor {
	use ForbidCloning;
	use ForbidSerialization;

	public function request(
		Request $request,
		CancellationToken $cancellation,
		DelegateHttpClient $httpClient,
	): Promise {
		return call(function () use ($request, $cancellation, $httpClient) {
			do {
				/** @var Response */
				$response = yield $httpClient->request(clone $request, $cancellation);
				if ($response->getStatus() === 429) {
					$waitFor = (float)($response->getHeader("x-ratelimit-reset-after")??1);
					yield delay((int)ceil($waitFor * 1000));
				} else {
					return $response;
				}
			} while (true);
		});
	}
}
