<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\call;
use function Safe\json_decode;

use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\Interceptor\SetRequestHeader;
use Amp\Http\Client\{HttpClient, HttpClientBuilder, Request, Response, SocketException, TimeoutException};
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Socket\SocketAddress;
use Amp\{CancelledException, Loop, Promise, TimeoutCancellationToken};
use Generator;

use Nadybot\Core\Attributes as NCA;
use Safe\Exceptions\JsonException;
use Throwable;

#[NCA\Instance]
class AccountUnfreezer {
	public const LOGIN_URL = "https://account.anarchy-online.com/";
	public const ACCOUNT_URL = "https://account.anarchy-online.com/account/";
	public const SUBSCRIPTION_URL = "https://account.anarchy-online.com/subscription/%s";
	public const UNFREEZE_URL = "https://account.anarchy-online.com/uncancel_sub";
	public const LOGOUT_URL = "https://account.anarchy-online.com/log_out";

	public const DEFAULT_UA = "Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/110.0";

	private const UNFREEZE_FAILURE = 0;
	private const UNFREEZE_SUCCESS = 1;
	private const UNFREEZE_TEMP_ERROR = 2;

	private const PROXY_HOST = 'proxy.nadybot.org';
	private const PROXY_PORT = 22222;

	protected ?string $userAgent = null;

	#[NCA\Logger]
	private LoggerWrapper $logger;

	#[NCA\Inject]
	private ConfigFile $config;

	#[NCA\Inject]
	private HttpClientBuilder $http;

	public function unfreeze(): bool {
		$result = false;
		Loop::run(function () use (&$result): Generator {
			$this->logger->warning('Account frozen, trying to unfreeze');

			/** @var HttpClient */
			$client = yield $this->getUnfreezeClient();

			do {
				$lastResult = self::UNFREEZE_TEMP_ERROR;
				$proxyText = $this->config->autoUnfreezeUseNadyproxy ? "Proxy" : "Unfreezing";
				try {
					$lastResult = yield $this->unfreezeWithClient($client);
				} catch (CancelledException) {
					$this->logger->notice("{$proxyText} not working or too slow. Retrying.");
				} catch (SocketException) {
					$this->logger->notice("{$proxyText} not working. Retrying.");
				} catch (TimeoutException) {
					$this->logger->notice("{$proxyText} not working. Retrying.");
				} catch (Throwable $e) {
					$this->logger->notice("{$proxyText} giving an error: {error}.", [
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

	/** @return Promise <string> */
	protected function getSessionCookie(HttpClient $client): Promise {
		return call(function () use ($client): Generator {
			$request = new Request(self::LOGIN_URL, "GET");
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request($request, new TimeoutCancellationToken(10000));

			if ($response->getStatus() !== 200) {
				$this->logger->error('Unable to login to the account management website: {code}', [
					"code" => $response->getStatus(),
				]);
				throw new UnfreezeTmpException();
			}
			$cookies = $response->getHeaderArray('Set-Cookie');
			$cookieValues = [];
			foreach ($cookies as $cookie) {
				$cookieParts = preg_split("/;\s*/", $cookie);
				if ($cookieParts !== false) {
					$cookieValues []= $cookieParts[0];
				}
			}
			return join("; ", $cookieValues);
		});
	}

	/** @return Promise<void> */
	protected function loginToAccount(HttpClient $client, string $cookie): Promise {
		return call(function () use ($client, $cookie): Generator {
			$login = strtolower($this->config->login);
			$user = strtolower($this->config->autoUnfreezeLogin ?? $login);
			$password = $this->config->autoUnfreezePassword ?? $this->config->password;
			$request = new Request(self::LOGIN_URL, "POST");
			// $request = new Request('http://127.0.0.1:1234', "POST");
			$request->setBody(http_build_query([
				"nickname" => $user,
				"password" => $password,
			]));
			$request->addHeader("Cookie", $cookie);
			$request->addHeader("Content-Type", "application/x-www-form-urlencoded");
			$request->addHeader("Referer", self::LOGIN_URL);
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request($request, new TimeoutCancellationToken(10000));

			if ($response->getStatus() !== 302) {
				$this->logger->error('Unable to login to the account management website: {code}', [
					"code" => $response->getStatus(),
				]);
				throw new UnfreezeTmpException();
			}
		});
	}

	/** @return Promise<void> */
	protected function switchToAccount(HttpClient $client, string $cookie, int $accountId): Promise {
		return call(function () use ($client, $cookie, $accountId): Generator {
			$request = new Request(sprintf(self::SUBSCRIPTION_URL, $accountId), "GET");
			$request->addHeader("Cookie", $cookie);
			$request->addHeader("Referer", self::LOGIN_URL);
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request($request, new TimeoutCancellationToken(10000));

			if ($response->getStatus() !== 302) {
				$this->logger->error('Unable to switch to the correct account: {code}', [
					"code" => $response->getStatus(),
				]);
				throw new UnfreezeTmpException();
			}
		});
	}

	/** @return Promise<string> */
	protected function loadAccountPage(HttpClient $client, string $cookie): Promise {
		return call(function () use ($client, $cookie): Generator {
			$request = new Request(self::ACCOUNT_URL, "GET");
			$request->addHeader("Cookie", $cookie);
			$request->addHeader("Referer", self::LOGIN_URL);
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request($request, new TimeoutCancellationToken(10000));

			if ($response->getStatus() !== 200) {
				$this->logger->error('Unable to read account page: {code}', [
					"code" => $response->getStatus(),
				]);
				throw new UnfreezeTmpException();
			}
			return $response->getBody()->buffer();
		});
	}

	/** @return Promise<void> */
	protected function uncancelSub(HttpClient $client, string $cookie): Promise {
		return call(function () use ($client, $cookie): Generator {
			$request = new Request(self::UNFREEZE_URL, "GET");
			$request->addHeader("Cookie", $cookie);
			$request->addHeader("Referer", self::ACCOUNT_URL);
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request($request, new TimeoutCancellationToken(10000));

			if ($response->getStatus() !== 302) {
				$this->logger->error('Unable to unfreeze account: {code}', [
					"code" => $response->getStatus(),
				]);
				throw new UnfreezeTmpException();
			}
		});
	}

	/** @return Promise<int> */
	protected function getSubscriptionId(HttpClient $client, string $cookie): Promise {
		return call(function () use ($client, $cookie): Generator {
			/** @var string */
			$body = yield $this->loadAccountPage($client, $cookie);
			$login = strtolower($this->config->login);
			if (!preg_match(
				'/<li><a href="\/subscription\/(\d+)">' . preg_quote($login, '/') . '<\/a><\/li>/s',
				$body,
				$matches
			)) {
				throw new UnfreezeFatalException("Account {$login} not on this login.");
			}
			return (int)$matches[1];
		});
	}

	/** @return Promise <string> */
	protected function logout(HttpClient $client, string $cookie): Promise {
		return call(function () use ($client, $cookie): Generator {
			$request = new Request(self::LOGOUT_URL, "GET");
			$request->addHeader("Cookie", $cookie);
			$request->addHeader("Referer", self::ACCOUNT_URL);
			$request->setTcpConnectTimeout(5000);
			$request->setTlsHandshakeTimeout(5000);
			$request->setTransferTimeout(5000);

			/** @var Response */
			$response = yield $client->request($request, new TimeoutCancellationToken(10000));

			if ($response->getStatus() !== 302) {
				$this->logger->error('Error logging out: {code}', [
					"code" => $response->getStatus(),
				]);
			}
		});
	}

	/** @return Promise<int> */
	protected function unfreezeWithClient(
		HttpClient $client,
	): Promise {
		return call(function () use ($client): Generator {
			try {
				$sessionCookie = yield $this->getSessionCookie($client);
				yield $this->loginToAccount($client, $sessionCookie);

				/** @var int */
				$accountId = yield $this->getSubscriptionId($client, $sessionCookie);
				yield $this->switchToAccount($client, $sessionCookie, $accountId);
				$mainBody = yield $this->loadAccountPage($client, $sessionCookie);
				if (!str_contains($mainBody, "Free Account")) {
					$this->logger->error("Refusing to unfreeze a paid account");
					return self::UNFREEZE_FAILURE;
				}
				yield $this->uncancelSub($client, $sessionCookie);
				yield $this->logout($client, $sessionCookie);
				return self::UNFREEZE_SUCCESS;
			} catch (UnfreezeTmpException) {
				return self::UNFREEZE_TEMP_ERROR;
			} catch (UnfreezeFatalException) {
				return self::UNFREEZE_FAILURE;
			}
		});
	}

	/** @return Promise<?string> */
	protected function getUserAgent(): Promise {
		return call(function (): Generator {
			$this->logger->info("Getting most popular user agent");
			$client = $this->http->build();
			$request = new Request("https://raw.githubusercontent.com/Kikobeats/top-user-agents/master/index.json");

			/** @var Response */
			$response = yield $client->request($request);
			if ($response->getStatus() !== 200) {
				return null;
			}
			$body = yield $response->getBody()->buffer();
			try {
				$json = json_decode($body, false);
				if (!is_array($json) || !isset($json[0]) || !is_string($json[0])) {
					return null;
				}
			} catch (JsonException) {
				return null;
			}
			return $json[0];
		});
	}

	/**
	 * Get a HttpClient that uses the Nadybot proxy to unfreeze an account
	 *
	 * @return Promise<HttpClient>
	 */
	private function getUnfreezeClient(): Promise {
		return call(function (): Generator {
			$this->userAgent ??= yield $this->getUserAgent();
			$this->userAgent ??= self::DEFAULT_UA;
			$this->logger->info("Using user agent {agent}", ["agent" => $this->userAgent]);
			$builder = $this->http->followRedirects(0)
					->intercept(new SetRequestHeader("User-Agent", $this->userAgent));
			if ($this->config->autoUnfreezeUseNadyproxy !== false) {
				$builder = $builder->usingPool(
					new UnlimitedConnectionPool(
						new DefaultConnectionFactory(
							new Http1TunnelConnector(
								new SocketAddress(self::PROXY_HOST, self::PROXY_PORT)
							)
						)
					)
				);
			}
			return $builder->build();
		});
	}
}
