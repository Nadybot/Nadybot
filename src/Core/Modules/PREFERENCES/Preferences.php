<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DB;
use Nadybot\Core\Instance;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations
]
class Preferences extends Instance {
	public const DB_TABLE = "preferences_<myname>";

		#[NCA\Inject]
	public DB $db;

	#[NCA\Setup]
	public function setup(): void {
	}

	public function save(string $sender, string $name, string $value): void {
		$sender = ucfirst(strtolower($sender));
		$name = strtolower($name);

		$this->db->table(self::DB_TABLE)
			->updateOrInsert(
				["sender" => $sender, "name" => $name],
				["sender" => $sender, "name" => $name, "value" => $value],
			);
	}

	public function get(string $sender, string $name): ?string {
		$sender = ucfirst(strtolower($sender));
		$name = strtolower($name);
		return $this->db->table(self::DB_TABLE)
			->where("sender", $sender)
			->where("name", $name)
			->select("value")
			->pluckAs("value", "string")
			->first();
	}

	public function delete(string $sender, string $name): bool {
		$sender = ucfirst(strtolower($sender));
		$name = strtolower($name);
		return $this->db->table(self::DB_TABLE)
			->where("sender", $sender)
			->where("name", $name)
			->delete() !== 0;
	}

	/**
	 * Get the value of a setting
	 */
	#[
		NCA\Api("/setting/%s"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "string", desc: "The stored value"),
		NCA\ApiResult(code: 204, desc: "No value stored")
	]
	public function apiSettingGetEndpoint(Request $request, HttpProtocolWrapper $server, string $key): Response {
		$result = $this->get($request->authenticatedAs??"_", $key);
		if ($result === null) {
			return new Response(Response::NO_CONTENT);
		}
		return new ApiResponse($result);
	}

	/**
	 * Create a new setting
	 */
	#[
		NCA\Api("/setting/%s"),
		NCA\POST,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 201, desc: "The new setting was stored successfully"),
		NCA\ApiResult(code: 409, desc: "There is already a setting stored"),
		NCA\ApiResult(code: 415, desc: "You tried to pass more than just a simple string"),
		NCA\RequestBody(class: "string", desc: "The data you want to store", required: true)
	]
	public function apiSettingPostEndpoint(Request $request, HttpProtocolWrapper $server, string $key): Response {
		$result = $this->get($request->authenticatedAs??"_", $key);
		if ($result !== null) {
			return new Response(Response::CONFLICT, ['Content-type' => 'text/plain'], "The given setting already exists");
		}
		if (!is_string($request->decodedBody)) {
			return new Response(Response::UNSUPPORTED_MEDIA_TYPE, ['Content-type' => 'text/plain'], "Only plain strings supported");
		}
		$this->save($request->authenticatedAs??"_", $key, $request->decodedBody);
		return new Response(Response::CREATED);
	}

	/**
	 * Store a setting
	 */
	#[
		NCA\Api("/setting/%s"),
		NCA\PUT,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 204, desc: "The new setting was stored successfully"),
		NCA\ApiResult(code: 415, desc: "You tried to pass more than just a simple string"),
		NCA\RequestBody(class: "string", desc: "The data you want to store", required: true)
	]
	public function apiSettingPutEndpoint(Request $request, HttpProtocolWrapper $server, string $key): Response {
		if (!is_string($request->decodedBody)) {
			return new Response(Response::UNSUPPORTED_MEDIA_TYPE, [], "Only plain strings supported");
		}
		$this->save($request->authenticatedAs??"_", $key, $request->decodedBody);
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Delete a setting
	 */
	#[
		NCA\Api("/setting/%s"),
		NCA\DELETE,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 204, desc: "The new setting was deleted successfully"),
		NCA\ApiResult(code: 409, desc: "No setting found for that key")
	]
	public function apiSettingDeleteEndpoint(Request $request, HttpProtocolWrapper $server, string $key): Response {
		$result = $this->delete($request->authenticatedAs??"_", $key);
		if (!$result) {
			return new Response(Response::CONFLICT, ['Content-type' => 'text/plain'], "The given setting doesn't exist");
		}
		return new Response(Response::NO_CONTENT);
	}
}
