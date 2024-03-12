<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;

class ApiResponse {
	public static function create(mixed $args=null): Response {
		$response = new Response(status: HttpStatus::OK);
		if (isset($args)) {
			$response->setBody(JsonExporter::encode($args));
		}
		return $response;
	}
}
