<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\call;
use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\{HttpClient, HttpClientBuilder, Request, Response, SocketException, TimeoutException};
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Socket\SocketAddress;
use Amp\{CancelledException, Loop, Promise, TimeoutCancellationToken};
use Generator;

use Nadybot\Core\Attributes as NCA;
use Throwable;

#[NCA\Instance]
class AccountUnfreezer {
	public const LOGIN_URL = "https://register.funcom.com/account";
	public const UNFREEZE_URL = "https://register.funcom.com/account/subscription/ctrl/anarchy/%s/reactivate";

	private const UNFREEZE_FAILURE = 0;
	private const UNFREEZE_SUCCESS = 1;
	private const UNFREEZE_TEMP_ERROR = 2;

	private const PROXY_HOST = 'proxy.nadybot.org';
	private const PROXY_PORT = 22222;

	#[NCA\Logger]
	private LoggerWrapper $logger;

	#[NCA\Inject]
	private HttpClientBuilder $http;

	public function unfreeze(string $login, string $password, string $user): bool {
		$result = false;
		Loop::run(function () use (&$result, $login, $password, $user): Generator {
			$this->logger->warning('Account frozen, trying to unfreeze');

			do {
				$client = $this->getUnfreezeClient();
				$lastResult = self::UNFREEZE_TEMP_ERROR;
				try {
					$lastResult = yield $this->unfreezeWithClient($client, $login, $password, $user);
				} catch (CancelledException) {
					$this->logger->notice("Proxy not working or too slow. Retrying.");
				} catch (SocketException) {
					$this->logger->notice("Proxy not working. Retrying.");
				} catch (TimeoutException) {
					$this->logger->notice("Proxy not working. Retrying.");
				} catch (Throwable $e) {
					$this->logger->notice("Proxy giving an error: {error}.", [
						"error" => $e->getMessage(),
					]);
				}
			} while ($lastResult === self::UNFREEZE_TEMP_ERROR);
			if ($lastResult === self::UNFREEZE_SUCCESS) {
				$this->logger->notice("Account unfrozen successfully.");
				$result = true;
			}
			Loop::stop();
			return $result;
		});
		return $result;
	}

	/** @return Promise<int> */
	protected function unfreezeWithClient(
		HttpClient $client,
		string $login,
		string $password,
		string $user,
	): Promise {
		return call(function () use ($client, $login, $password, $user): Generator {
			$login = strtolower($login);
			$user = strtolower($user);
			$request = new Request(self::LOGIN_URL, "POST");
			$request->setBody(http_build_query([
				"__ac_name" => $login,
				"__ac_password" => $password,
			]));
			$request->addHeader("Content-Type", "application/x-www-form-urlencoded");
			$request->addHeader("Referer", self::LOGIN_URL);
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request(
				$request,
				new TimeoutCancellationToken(10000)
			);

			if ($response->getStatus() !== 302) {
				$this->logger->error('Unable to login to the account management website: {code}', [
					"code" => $response->getStatus(),
				]);
				return self::UNFREEZE_TEMP_ERROR;
			}
			$cookies = $response->getHeaderArray('Set-Cookie');
			$cookieValues = [];
			foreach ($cookies as $cookie) {
				$cookieParts = preg_split("/;\s*/", $cookie);
				if ($cookieParts !== false) {
					$cookieValues []= $cookieParts[0];
				}
			}

			$request = new Request(sprintf(self::UNFREEZE_URL, $user), "POST");
			$request->setBody(http_build_query(["process" => 'submit']));
			$request->addHeader("Content-Type", "application/x-www-form-urlencoded");
			$request->addHeader("Referer", sprintf(self::UNFREEZE_URL, $user));
			$request->addHeader("Cookie", implode("; ", $cookieValues));

			/** @var Response */
			$response = yield $client->request($request);
			if ($response->getStatus() === 500) {
				$this->logger->warning("There was an error unfreezing the account");
				return self::UNFREEZE_TEMP_ERROR;
			}
			if ($response->getStatus() !== 200) {
				$this->logger->warning("There was an error unfreezing the account");
				return self::UNFREEZE_FAILURE;
			}
			$body = yield $response->getBody()->buffer();
			if (strpos($body, "<div>Subscription Reactivated</div>") !== false) {
				return self::UNFREEZE_SUCCESS;
			}
			if (strpos($body, "<div>This account is not cancelled or frozen</div>") !== false) {
				$this->logger->notice("According to Funcom, the account isn't frozen.");
				return self::UNFREEZE_SUCCESS;
			}
			$this->logger->warning("There was an error unfreezing the account");
			return self::UNFREEZE_FAILURE;
		});
	}

	/** Get a HttpClient that uses the Nadybot proxy to unfreeze an account */
	private function getUnfreezeClient(): HttpClient {
		return $this->http
			->followRedirects(0)
			->usingPool(
				new UnlimitedConnectionPool(
					new DefaultConnectionFactory(
						new Http1TunnelConnector(
							new SocketAddress(self::PROXY_HOST, self::PROXY_PORT)
						)
					)
				)
			)
			->build();
	}
}
