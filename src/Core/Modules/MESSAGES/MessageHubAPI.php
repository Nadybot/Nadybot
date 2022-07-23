<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	MessageHub,
	ModuleInstance,
	Routing\Source,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	HttpProtocolWrapper,
	Request,
	Response,
};

/**
 * @author Nadyita (RK5)
 */
#[NCA\Instance]
class MessageHubAPI extends ModuleInstance {
	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public DB $db;

	/** List all hop colors */
	#[
		NCA\Api("/hop/color"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "RouteHopColor[]", desc: "The hop color definitions")
	]
	public function apiGetHopColors(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(MessageHub::$colors->toArray());
	}

	/** List all hop formats */
	#[
		NCA\Api("/hop/format"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "RouteHopFormat[]", desc: "The hop format definitions")
	]
	public function apiGetHopFormats(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(Source::$format->toArray());
	}
}
