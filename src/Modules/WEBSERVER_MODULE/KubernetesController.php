<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Http,
	ModuleInstance,
	Nadybot,
};

#[NCA\Instance]
class KubernetesController extends ModuleInstance {
	/** Enable Kubernetes endpoints at /livez and /readyz */
	#[NCA\Setting\Boolean(accessLevel: 'admin')]
	public bool $kubernetesEndpoints = true;

	#[NCA\Inject]
	private Nadybot $chatBot;

	/** Query if the bot is running as it is supposed to */
	#[
		Http\HttpGet('/livez'),
		Http\HttpOwnAuth,
	]
	public function getLivezEndpoint(Request $request): Response {
		if (!$this->kubernetesEndpoints) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return new Response(
			status: HttpStatus::OK,
			headers: ['Content-Type' => 'text/plain'],
			body: 'Bot is up'
		);
	}

	/** Query if the bot is ready to accept traffic */
	#[
		Http\HttpGet('/readyz'),
		Http\HttpOwnAuth,
	]
	public function getReadyzEndpoint(Request $request): Response {
		if (!$this->kubernetesEndpoints) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		if ($this->chatBot->isReady()) {
			return new Response(
				status: HttpStatus::OK,
				headers: ['Content-Type' => 'text/plain'],
				body: 'Bot is ready'
			);
		}
		return new Response(status: HttpStatus::NOT_FOUND);
	}
}
