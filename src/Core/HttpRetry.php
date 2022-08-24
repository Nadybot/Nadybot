<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\{call, delay};
use Amp\Http\Client\Connection\{Http2ConnectionException, UnprocessedRequestException};
use Amp\Http\Client\Internal\{ForbidCloning, ForbidSerialization};
use Amp\Http\Client\{ApplicationInterceptor, DelegateHttpClient, Request, SocketException};
use Amp\{CancellationToken, Promise};
use Nadybot\Core\Attributes as NCA;

final class HttpRetry implements ApplicationInterceptor {
	use ForbidCloning;
	use ForbidSerialization;

	#[NCA\Logger]
	private LoggerWrapper $logger;

	public function __construct(
		private int $retryLimit
	) {
	}

	public function request(
		Request $request,
		CancellationToken $cancellation,
		DelegateHttpClient $httpClient
	): Promise {
		return call(function () use ($request, $cancellation, $httpClient) {
			$attempt = 1;

			do {
				if ($attempt > 1) {
					$this->logger->info("Retrying {url}, try {try}/{maxtries}", [
						"url" => $request->getUri()->__toString(),
						"try" => $attempt,
						"maxtries" => $this->retryLimit,
					]);
				}
				try {
					return yield $httpClient->request(clone $request, $cancellation);
				} catch (UnprocessedRequestException $exception) {
					// Request was deemed retryable by connection, so carry on.
				} catch (SocketException | Http2ConnectionException $exception) {
					if (!$request->isIdempotent()) {
						throw $exception;
					}

					// Request can safely be retried.
				}
				$delay = (int)ceil(250 * pow(2, $attempt));
				$this->logger->info("Retrying {url} in {delay}ms", [
					"url" => $request->getUri()->__toString(),
					"delay" => $delay,
				]);
				yield delay($delay);
			} while ($attempt++ <= $this->retryLimit);

			throw $exception;
		});
	}
}
