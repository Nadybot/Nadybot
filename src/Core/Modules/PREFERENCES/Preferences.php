<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ModuleInstance,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	WebserverController,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations
]
class Preferences extends ModuleInstance {
	public const DB_TABLE = "preferences_<myname>";

	#[NCA\Inject]
	private DB $db;

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
			->pluckStrings("value")
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

	/** Get the value of a setting */
	#[
		NCA\Api("/setting/%s"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "string", desc: "The stored value"),
		NCA\ApiResult(code: 204, desc: "No value stored")
	]
	public function apiSettingGetEndpoint(Request $request, string $key): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? "_";
		$result = $this->get($user, $key);
		if ($result === null) {
			return new Response(status: HttpStatus::NO_CONTENT);
		}
		return ApiResponse::create($result);
	}

	/** Create a new setting */
	#[
		NCA\Api("/setting/%s"),
		NCA\POST,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 201, desc: "The new setting was stored successfully"),
		NCA\ApiResult(code: 409, desc: "There is already a setting stored"),
		NCA\ApiResult(code: 415, desc: "You tried to pass more than just a simple string"),
		NCA\RequestBody(class: "string", desc: "The data you want to store", required: true)
	]
	public function apiSettingPostEndpoint(Request $request, string $key): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? "_";
		$result = $this->get($user, $key);
		if ($result !== null) {
			return new Response(
				status: HttpStatus::CONFLICT,
				headers: ['Content-type' => 'text/plain'],
				body: "The given setting already exists"
			);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_string($body)) {
			return new Response(
				status: HttpStatus::UNSUPPORTED_MEDIA_TYPE,
				headers: ['Content-type' => 'text/plain'],
				body: "Only plain strings supported"
			);
		}
		$this->save($user, $key, $body);
		return new Response(status: HttpStatus::CREATED);
	}

	/** Store a setting */
	#[
		NCA\Api("/setting/%s"),
		NCA\PUT,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 204, desc: "The new setting was stored successfully"),
		NCA\ApiResult(code: 415, desc: "You tried to pass more than just a simple string"),
		NCA\RequestBody(class: "string", desc: "The data you want to store", required: true)
	]
	public function apiSettingPutEndpoint(Request $request, string $key): Response {
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_string($body)) {
			return new Response(
				status: HttpStatus::UNSUPPORTED_MEDIA_TYPE,
				headers: ['Content-type' => 'text/plain'],
				body: "Only plain strings supported"
			);
		}
		$user = $request->getAttribute(WebserverController::USER) ?? "_";
		$this->save($user, $key, $body);
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Delete a setting */
	#[
		NCA\Api("/setting/%s"),
		NCA\DELETE,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 204, desc: "The new setting was deleted successfully"),
		NCA\ApiResult(code: 409, desc: "No setting found for that key")
	]
	public function apiSettingDeleteEndpoint(Request $request, string $key): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? "_";
		$result = $this->delete($user, $key);
		if (!$result) {
			return new Response(
				status: HttpStatus::CONFLICT,
				headers: ['Content-type' => 'text/plain'],
				body: "The given setting doesn't exist"
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}
}
