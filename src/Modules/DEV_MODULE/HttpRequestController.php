<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Amp\Dns\DnsException;
use Amp\Http\Client\{HttpClientBuilder, InvalidRequestException, Request};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
};
use Safe\Exceptions\JsonException;
use Throwable;

/**
 * @author Nadyita
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "httprequest",
		accessLevel: "mod",
		description: "Test http/https requests"
	)
]
class HttpRequestController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	/** Load the given URL and show the result */
	#[NCA\HandlesCommand("httprequest")]
	public function httprequestCommand(CmdContext $context, string $url): void {
		$client = $this->builder->build();
		try {
			$response = $client->request(new Request($url));
			$body = $response->getBody()->buffer();
		} catch (InvalidRequestException) {
			$context->reply("<highlight>{$url}<end> is not a valid http/https URL.");
			return;
		} catch (DnsException $e) {
			$context->reply("<highlight>{$url}<end> is not a valid domain.");
			return;
		} catch (Throwable $e) {
			$context->reply("Error retrieving data: ". $e->getMessage());
			return;
		}
		$blob = "<header2>Headers<end>\n";
		$blob .= "<tab>HTTP/" . $response->getProtocolVersion() . " ".
			$response->getStatus() . " " . $response->getReason() . "\n";
		foreach ($response->getHeaderPairs() as $header) {
			[$field, $value] = $header;
			$blob .= "<tab>{$field}: <highlight>{$value}<end>\n";
		}
		if ($body !== '') {
			$blob .= "\n<pagebreak><header2>Body<end>";
			try {
				$decoded = \Safe\json_decode($body);
				$body = \Safe\json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
			} catch (JsonException $e) {
			}
			$lines = \Safe\preg_split("/\r?\n/", htmlspecialchars($body));
			foreach ($lines as $line) {
				if (strlen($line) > 500) {
					$blob .= "\n<pagebreak><tab>" . wordwrap($line, 75, "\n<tab>", true);
				} else {
					$blob .= "\n<pagebreak><tab>{$line}";
				}
			}
		}
		$msg = $this->text->makeBlob("Reply received", $blob, "Server reply");
		$context->reply($msg);
	}
}
