<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
	Nadybot,
};

#[NCA\Instance]
class KubernetesController extends ModuleInstance {
	/** Enable Kubernetes endpoints at /livez and /readyz */
	#[NCA\Setting\Boolean(accessLevel: "admin")]
	public bool $kubernetesEndpoints = true;

	#[NCA\Inject]
	private Nadybot $chatBot;

	/** Query if the bot is running as it is supposed to */
	#[
		NCA\HttpGet("/livez"),
		NCA\HttpOwnAuth,
	]
	public function getLivezEndpoint(Request $request, HttpProtocolWrapper $server): void {
		if (!$this->kubernetesEndpoints) {
			$server->httpError(new Response(
				Response::NOT_FOUND,
			), $request);
			return;
		}
		$server->sendResponse(new Response(
			Response::OK,
			['Content-Type' => "text/plain"],
			"Bot is up"
		), $request, true);
	}

	/** Query if the bot is ready to accept traffic */
	#[
		NCA\HttpGet("/readyz"),
		NCA\HttpOwnAuth,
	]
	public function getReadyzEndpoint(Request $request, HttpProtocolWrapper $server): void {
		if (!$this->kubernetesEndpoints) {
			$server->httpError(new Response(
				Response::NOT_FOUND,
			), $request);
			return;
		}
		if ($this->chatBot->isReady()) {
			$server->sendResponse(new Response(
				Response::OK,
				['Content-Type' => "text/plain"],
				"Bot is ready"
			), $request, true);
		} else {
			$server->httpError(new Response(
				Response::NOT_FOUND,
			), $request);
		}
	}
}
