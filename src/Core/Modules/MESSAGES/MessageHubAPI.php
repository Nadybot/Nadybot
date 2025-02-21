<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Amp\Http\Server\{Request, Response};
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Http,
	MessageHub,
	ModuleInstance,
	Routing\Source,
	Types\AccessLevel,
};
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;

/**
 * @author Nadyita (RK5)
 */
#[NCA\Instance]
class MessageHubAPI extends ModuleInstance {
	/** List all hop colors */
	#[
		Http\Api('/hop/color'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'RouteHopColor[]', desc: 'The hop color definitions')
	]
	public function apiGetHopColors(Request $request): Response {
		return ApiResponse::create(MessageHub::$colors->toArray());
	}

	/** List all hop formats */
	#[
		Http\Api('/hop/format'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'RouteHopFormat[]', desc: 'The hop format definitions')
	]
	public function apiGetHopFormats(Request $request): Response {
		return ApiResponse::create(Source::$format->toArray());
	}
}
