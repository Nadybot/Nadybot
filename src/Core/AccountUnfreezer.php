<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_decode;

use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\Interceptor\SetRequestHeader;
use Amp\Http\Client\{HttpClient, HttpClientBuilder, Request, SocketException, TimeoutException};
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\{CancelledException, TimeoutCancellation};

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Revolt\EventLoop;
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
	private BotConfig $config;

	#[NCA\Inject]
	private HttpClientBuilder $http;

	public function unfreeze(?int $subscriptionId): bool {
		$result = false;
		$this->logger->warning('Account frozen, trying to unfreeze');

		$client = $this->getUnfreezeClient();

		do {
			$lastResult = self::UNFREEZE_TEMP_ERROR;
			$proxyText = $this->config->autoUnfreeze?->useNadyproxy ? "Proxy" : "Unfreezing";
			try {
				$lastResult = $this->unfreezeWithClient($client, $subscriptionId);
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
		EventLoop::run();
		return $result;
	}

	protected function getSessionCookie(HttpClient $client): string {
		$request = new Request(self::LOGIN_URL, "GET");
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);

		$response = $client->request($request, new TimeoutCancellation(10));

		if ($response->getStatus() !== 200) {
			$this->logger->error('Unable to login to get session cookie: {code}', [
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
	}

	protected function loginToAccount(HttpClient $client, string $cookie): void {
		$login = strtolower($this->config->main->login);
		$user = strtolower($this->config->autoUnfreeze?->login ?? $login);
		$password = $this->config->autoUnfreeze?->password ?? $this->config->main->password;
		$request = new Request(self::LOGIN_URL, "POST");
		$request->setBody(http_build_query([
			"nickname" => $user,
			"password" => $password,
		]));
		$request->addHeader("Cookie", $cookie);
		$request->addHeader("Content-Type", "application/x-www-form-urlencoded");
		$request->addHeader("Referer", self::LOGIN_URL);
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);

		$response = $client->request($request, new TimeoutCancellation(10));

		if ($response->getStatus() !== 302) {
			$errorMsg = "HTTP-Code " . $response->getStatus();
			if ($response->getStatus() === 200) {
				$body = $response->getBody()->buffer();
				if (preg_match('/<div class="alert alert-danger">(.+?)<\/div>/s', $body, $matches)) {
					$errorMsg = trim(strip_tags($matches[1]));
				}
			}
			$this->logger->error('Unable to login to the account management website: {error}', [
				"error" => $errorMsg,
			]);
			throw new UnfreezeTmpException();
		}
	}

	protected function switchToAccount(HttpClient $client, string $cookie, int $accountId): void {
		$request = new Request(sprintf(self::SUBSCRIPTION_URL, $accountId), "GET");
		$request->addHeader("Cookie", $cookie);
		$request->addHeader("Referer", self::LOGIN_URL);
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);

		$response = $client->request($request, new TimeoutCancellation(10));

		if ($response->getStatus() !== 302) {
			$this->logger->error('Unable to switch to the correct account: {code}', [
				"code" => $response->getStatus(),
			]);
			throw new UnfreezeTmpException();
		}
	}

	protected function loadAccountPage(HttpClient $client, string $cookie): string {
		$request = new Request(self::ACCOUNT_URL, "GET");
		$request->addHeader("Cookie", $cookie);
		$request->addHeader("Referer", self::LOGIN_URL);
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);

		$response = $client->request($request, new TimeoutCancellation(10));

		if ($response->getStatus() !== 200) {
			$this->logger->error('Unable to read account page: {code}', [
				"code" => $response->getStatus(),
			]);
			throw new UnfreezeTmpException();
		}
		return $response->getBody()->buffer();
	}

	protected function uncancelSub(HttpClient $client, string $cookie): void {
		$request = new Request(self::UNFREEZE_URL, "GET");
		$request->addHeader("Cookie", $cookie);
		$request->addHeader("Referer", self::ACCOUNT_URL);
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);

		$response = $client->request($request, new TimeoutCancellation(10));

		if ($response->getStatus() !== 302) {
			$this->logger->error('Unable to unfreeze account: {code}', [
				"code" => $response->getStatus(),
			]);
			throw new UnfreezeTmpException();
		}
	}

	protected function getSubscriptionId(HttpClient $client, string $cookie): int {
		$body = $this->loadAccountPage($client, $cookie);
		$login = strtolower($this->config->main->login);
		if (!preg_match(
			'/<li><a href="\/subscription\/(\d+)">' . preg_quote($login, '/') . '<\/a><\/li>/s',
			$body,
			$matches
		)) {
			throw new UnfreezeFatalException("Account {$login} not on this login.");
		}
		return (int)$matches[1];
	}

	protected function logout(HttpClient $client, string $cookie): void {
		$request = new Request(self::LOGOUT_URL, "GET");
		$request->addHeader("Cookie", $cookie);
		$request->addHeader("Referer", self::ACCOUNT_URL);
		$request->setTcpConnectTimeout(5);
		$request->setTlsHandshakeTimeout(5);
		$request->setTransferTimeout(5);

		$response = $client->request($request, new TimeoutCancellation(10));

		if ($response->getStatus() !== 302) {
			$this->logger->error('Error logging out: {code}', [
				"code" => $response->getStatus(),
			]);
		}
	}

	protected function unfreezeWithClient(HttpClient $client, ?int $subscriptionId): int {
		try {
			$sessionCookie = $this->getSessionCookie($client);
			$this->loginToAccount($client, $sessionCookie);

			$accountId = $this->getSubscriptionId($client, $sessionCookie);
			if (isset($subscriptionId) && $accountId !== $subscriptionId) {
				$this->logger->error("Subscription {subscription} is not managed via given login.", [
					"subscription" => $subscriptionId,
				]);
				return self::UNFREEZE_FAILURE;
			}
			$this->switchToAccount($client, $sessionCookie, $accountId);
			$mainBody = $this->loadAccountPage($client, $sessionCookie);
			if (!str_contains($mainBody, "Free Account")) {
				$this->logger->error("Refusing to unfreeze a paid account");
				return self::UNFREEZE_FAILURE;
			}
			$this->uncancelSub($client, $sessionCookie);
			$this->logout($client, $sessionCookie);
			return self::UNFREEZE_SUCCESS;
		} catch (UnfreezeTmpException) {
			return self::UNFREEZE_TEMP_ERROR;
		} catch (UnfreezeFatalException) {
			return self::UNFREEZE_FAILURE;
		}
	}

	protected function getUserAgent(): ?string {
		$this->logger->info("Getting most popular user agent");
		$client = $this->http->build();
		$request = new Request("https://raw.githubusercontent.com/microlinkhq/top-user-agents/master/src/index.json");

		$response = $client->request($request);
		if ($response->getStatus() !== 200) {
			return null;
		}
		$body = $response->getBody()->buffer();
		try {
			$json = json_decode($body, false);
			if (!is_array($json) || !isset($json[0]) || !is_string($json[0])) {
				return null;
			}
		} catch (JsonException) {
			return null;
		}
		return $json[0];
	}

	/** Get a HttpClient that uses the Nadybot proxy to unfreeze an account */
	private function getUnfreezeClient(): HttpClient {
		$this->userAgent ??= $this->getUserAgent();
		$this->userAgent ??= self::DEFAULT_UA;
		$this->logger->info("Using user agent {agent}", ["agent" => $this->userAgent]);
		$builder = $this->http->followRedirects(0)
				->intercept(new SetRequestHeader("User-Agent", $this->userAgent));
		if ($this->config->autoUnfreeze?->useNadyproxy !== false) {
			$builder = $builder->usingPool(
				new UnlimitedConnectionPool(
					new DefaultConnectionFactory(
						new Http1TunnelConnector(
							self::PROXY_HOST . ":" . self::PROXY_PORT
						)
					)
				)
			);
		}
		return $builder->build();
	}
}
