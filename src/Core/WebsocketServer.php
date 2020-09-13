<?php declare(strict_types=1);

namespace Nadybot\Core;

class WebsocketServer extends WebsocketBase {
	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public SettingManager $settingManager;

	/** Pointer to the notifier for the socket */
	public ?SocketNotifier $notifier = null;

	/** Timeout for inactivity in seconds */
	protected int $timeout = 5;
	protected int $frameSize = 4096;
	protected int $port = 8000;

	/** @var array<string,WebsocketServerConnection> */
	protected array $clients = [];

	/** @var array */
	protected array $authorizations = [];

	/**
	 * Authorize player $player to login to Websocket for $duration seconds
	 * After logged in, authorization is no longer required
	 */
	public function authorize(string $player, int $duration=3600): string {
		do {
			$uuid = bin2hex(openssl_random_pseudo_bytes(12, $strong));
		} while (!$strong || isset($this->authorizations[$uuid]));
		$this->authorizations[$uuid] = [$player, time() + $duration];
		return $uuid;
	}

	public function checkAuthorization(string $token): bool {
		if (!isset($this->authorizations[$token])) {
			return false;
		}
		return $this->authorizations[$token][1] >= time();
	}

	public function clearExpiredAuthorizations(): void {
		foreach ($this->authorizations as $uuid => $data) {
			if ($data[1] > time()) {
				unset($this->authorizations[$uuid]);
			}
		}
	}

	public function listen(): bool {
		$certPath = $this->settingManager->get('certificate_path');
		if (empty($certPath)) {
			$certPath = $this->generateCertificate();
		}
		$context = stream_context_create();
		stream_context_set_option($context, 'ssl', 'local_cert', $certPath);
		stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
		stream_context_set_option($context, 'ssl', 'verify_peer', false);

		$this->socket = stream_socket_server(
			"tcp://0.0.0.0:$this->port",
			$errno,
			$errstr,
			STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
			$context
		);
		stream_set_blocking($this->socket, false);

		if ($this->socket === false) {
			$error = "Could not open listening socket: {$errstr} ({$errno})";
			$this->logger->log('ERROR', $error);
			$this->throwError($errno, $error);
			return false;
		}

		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			[$this, "clientConnected"]
		);
		$this->socketManager->addSocketNotifier($this->notifier);

		$this->logger->log('INFO', "Websocket Server listening on port {$this->port}");
		return true;
	}

	public function clientConnected() {
		stream_set_blocking($this->socket, true);
		$clientSocket = @stream_socket_accept($this->socket, 0, $peerName);
		stream_set_blocking($this->socket, false);
		if ($this->socket === false) {
			$error = 'Server failed to connect.';
			$this->logger->log('ERROR', $error);
			$this->throwError(WebsocketErrorEvent::CONNECT_ERROR, "Client failed to connect properly");
			return;
		}
		$client = new WebsocketServerConnection($this, $clientSocket, $peerName, $this->frameSize);
		$this->clients[$client->getUUID()] = $client;
		Registry::injectDependencies($client);
		foreach ($this->eventCallbacks as $event => $callback) {
			$client->on($event, $callback);
		}
		$client->listen();
	}
	
	public function removeClient(WebsocketServerConnection $client): void {
		unset($this->clients[$client->getUUID()]);
	}

	public function __destruct() {
		if ($this->isConnected()) {
			fclose($this->socket);
		}
		$this->socket = null;
	}

	public function getPort() {
		return $this->port;
	}

	public function send(string $data, string $opcode='text', bool $masked=true): void {
		foreach ($this->clients as $uuid => $client) {
			$client->send($data, $opcode, $masked);
		}
	}

	public function generateCertificate(): string {
		$dn = [
			"countryName" => "XX",
			"localityName" => "Anarchy Online",
			"commonName" => gethostname(),
			"organizationName" => $this->chatBot->vars['name'],
		];
		if (!empty($this->chatBot->vars['my_guild'])) {
			$dn["organizationName"] = $this->chatBot->vars['my_guild'];
		}
		
		$privKey = openssl_pkey_new();
		$cert    = openssl_csr_new($dn, $privKey);
		$cert    = openssl_csr_sign($cert, null, $privKey, 365, null, time());
		
		$pem = [];
		openssl_x509_export($cert, $pem[0]);
		openssl_pkey_export($privKey, $pem[1]);
		$pem = implode("", $pem);
		
		// Save PEM file
		$pemfile = '/tmp/server.pem';
		file_put_contents($pemfile, $pem);
		return $pemfile;
	}

	/**
	 * @return array<string,WebsocketServerConnection>
	 */
	public function getClients(): array {
		return $this->clients;
	}
}
