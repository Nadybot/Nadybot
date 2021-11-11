<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use JsonException;
use Nadybot\Core\CmdContext;
use Nadybot\Core\Http;
use Nadybot\Core\HttpResponse;
use Nadybot\Core\Text;

/**
 * @author Nadyita
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'httprequest',
 *		accessLevel = 'mod',
 *		description = 'Test http/https requests'
 *	)
 */
class HttpRequestController {
	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Text $text;

	/**
	 * @HandlesCommand("httprequest")
	 */
	public function httprequestCommand(CmdContext $context, string $url): void {
		$parts = parse_url(html_entity_decode($url));
		if (!is_array($parts)) {
			$context->reply("<highlight>{$url}<end> is not a valid URL.");
			return;
		}
		$client = $this->http->get(($parts["scheme"]??"http") . "://" . ($parts["host"]??"127.0.0.1") . ($parts["path"]??""));
		if (isset($parts["query"])) {
			$params = [];
			$groups = explode("&", $parts["query"]);
			foreach ($groups as $group) {
				$kv = explode("=", $group, 2);
				if (count($kv) === 2) {
					$params[$kv[0]] = $kv[1];
				} else {
					$params[$kv[0]] = "";
				}
			}
			$client->withQueryParams($params);
		}
		$client->withCallback([$this, "handleResponse"], $context);
	}

	public function handleResponse(?HttpResponse $response, CmdContext $context): void {
		if (!isset($response)) {
			$context->reply("No response received");
			return;
		}
		if ($response->error) {
			$context->reply("Error received: <highlight>{$response->error}<end>.");
			return;
		}
		$blob = "<header2>Headers<end>\n";
		foreach ($response->headers as $header => $value) {
			$blob .= "<tab>{$header}: <highlight>{$value}<end>\n";
		}
		$blob .= "\n<pagebreak><header2>Body<end>";
		$response->body ??= "The body is empty";
		try {
			$decoded = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
			$response->body = json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		} catch (JsonException $e) {
		}
		$lines = preg_split("/\r?\n/", htmlspecialchars($response->body));
		foreach ($lines as $line) {
			if (strlen($line) > 500) {
				$blob .= "\n<pagebreak><tab>" . wordwrap($line, 75, "\n<tab>", true);
			} else {
				$blob .= "\n<pagebreak><tab>{$line}";
			}
		}
		$msg = $this->text->makeBlob("Reply received", $blob, "Server reply");
		$context->reply($msg);
	}
}
