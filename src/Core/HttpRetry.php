<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\delay;
use Amp\{Cancellation, ForbidCloning as AmpForbidCloning, ForbidSerialization as AmpForbidSerialization};
use Amp\Http\Client\{ApplicationInterceptor, DelegateHttpClient, HttpException, Request, Response};
use Nadybot\Core\Attributes as NCA;

/** This will automatically retry HTTP-requests on HTTP exceptions */
final class HttpRetry implements ApplicationInterceptor {
	use AmpForbidCloning;
	use AmpForbidSerialization;

	#[NCA\Logger]
	private LoggerWrapper $logger;

	/** @param int $retryLimit How often shall we retry the requests? */
	public function __construct(
		private int $retryLimit
	) {
	}

	/** {@inheritDoc} */
	public function request(
		Request $request,
		Cancellation $cancellation,
		DelegateHttpClient $httpClient
	): Response {
		$attempt = 1;

		do {
			if ($attempt > 1) {
				// @phpstan-ignore-next-line
				$this->logger->info('Retrying {url}, try {try}/{maxtries}', [
					'url' => $request->getUri()->__toString(),
					'try' => $attempt,
					'maxtries' => $this->retryLimit,
				]);
			}
			try {
				return $httpClient->request(clone $request, $cancellation);
			} catch (HttpException $exception) {
				if (!$request->isIdempotent()) {
					throw $exception;
				}

				// Request can safely be retried.
			}
			$base = 0.25 * pow(2, $attempt);
			$delay = ($base / 2) + (mt_rand() / mt_getrandmax()) * ($base / 2);
			$this->logger->info('Retrying {url} in {delay}ms', [
				'url' => $request->getUri()->__toString(),
				'delay' => $delay,
				'exception' => $exception,
			]);
			delay($delay);
		} while ($attempt++ <= $this->retryLimit);

		throw $exception;
	}
}
