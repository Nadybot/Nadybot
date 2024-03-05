<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\{delay};
use Amp\Http\Client\{ApplicationInterceptor, DelegateHttpClient, Request, Response, SocketException};
use Amp\Http\Http2\Http2ConnectionException as Http2Http2ConnectionException;
use Amp\{Cancellation, ForbidCloning as AmpForbidCloning, ForbidSerialization as AmpForbidSerialization};
use Nadybot\Core\Attributes as NCA;

final class HttpRetry implements ApplicationInterceptor {
	use AmpForbidCloning;
	use AmpForbidSerialization;

	#[NCA\Logger]
	private LoggerWrapper $logger;

	public function __construct(
		private int $retryLimit
	) {
	}

	public function request(
		Request $request,
		Cancellation $cancellation,
		DelegateHttpClient $httpClient
	): Response {
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
				return $httpClient->request(clone $request, $cancellation);
			} catch (SocketException | Http2Http2ConnectionException $exception) {
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
			delay($delay);
		} while ($attempt++ <= $this->retryLimit);

		throw $exception;
	}
}
