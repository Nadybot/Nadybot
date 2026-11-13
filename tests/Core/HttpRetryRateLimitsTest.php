<?php declare(strict_types=1);

namespace Nadybot\Tests\Core;

use Amp\Http\Client\{Request, Response};
use Nadybot\Core\HttpRetryRateLimits;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpRetryRateLimits::class)]
class HttpRetryRateLimitsTest extends TestCase {
	/** @return array<string,array{array<string,string>,float}> */
	public static function headerProvider(): array {
		return [
			'discord' => [['x-ratelimit-reset-after' => '1.25'], 1.25],
			'retry-after seconds' => [['retry-after' => '30'], 30.0],
			'retry-after zero' => [['retry-after' => '0'], 0.0],
			'discord wins' => [['x-ratelimit-reset-after' => '2', 'retry-after' => '30'], 2.0],
		];
	}

	/** @param array<string,string> $headers */
	#[Test]
	#[DataProvider('headerProvider')]
	public function itUsesTheRateLimitHeaders(array $headers, float $expected): void {
		$this->assertSame($expected, HttpRetryRateLimits::getWaitTime(self::response($headers)));
	}

	/** @return array<string,array{array<string,string>}> */
	public static function fallbackProvider(): array {
		return [
			'no header' => [[]],
			'retry-after as date' => [['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']],
			'retry-after negative' => [['retry-after' => '-5']],
		];
	}

	/** @param array<string,string> $headers */
	#[Test]
	#[DataProvider('fallbackProvider')]
	public function itFallsBackToARandomDelay(array $headers): void {
		$wait = HttpRetryRateLimits::getWaitTime(self::response($headers));
		$this->assertGreaterThanOrEqual(1.0, $wait);
		$this->assertLessThanOrEqual(5.0, $wait);
	}

	/** @param array<string,string> $headers */
	private static function response(array $headers): Response {
		return new Response('1.1', 429, 'Too Many Requests', $headers, '', new Request('https://example.com/'));
	}
}
