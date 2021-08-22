<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Nadybot\Core\DB;
use Nadybot\Core\MessageHub;
use Nadybot\Core\DBSchema\RouteHopColor;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 */
class MessageHubAPI {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public DB $db;

	/**
	 * List all hop colors
	 * @Api("/hop/color")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='RouteHopColor[]', desc='The hop color definitions')
	 */
	public function apiGetHopColors(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(MessageHub::$colors->toArray());
	}

	/**
	 * List all hop formats
	 * @Api("/hop/format")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='RouteHopFormat[]', desc='The hop format definitions')
	 */
	public function apiGetHopFormats(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(Source::$format->toArray());
	}
}
