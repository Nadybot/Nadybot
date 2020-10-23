<?php declare(strict_types=1);

namespace Nadybot\Api;

use Addendum\ReflectionAnnotatedClass;
use Addendum\ReflectionAnnotatedMethod;
use Exception;
use Nadybot\Core\Annotations\ApiResult;
use Nadybot\Core\Annotations\DELETE;
use Nadybot\Core\Annotations\GET;
use Nadybot\Core\Annotations\POST;
use Nadybot\Core\Annotations\PUT;
use Nadybot\Core\Annotations\QueryParam;
use Nadybot\Core\Annotations\RequestBody;
use Nadybot\Core\BotRunner;
use Nadybot\Core\DBRow;
use Nadybot\Core\Registry;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

class ApiSpecGenerator {
	public function loadClasses() {
		foreach (glob(__DIR__ . "/../Core/Annotations/*.php") as $file) {
			require_once $file;
		}
		foreach (glob(__DIR__ . "/../Core/Modules/*/*.php") as $file) {
			require_once $file;
		}
		foreach (glob(__DIR__ . "/../Modules/*/*.php") as $file) {
			require_once $file;
		}
	}

	/**
	 * Return an array of [instancename => full class name] for all @Instances
	 * @return array<string,string>
	 */
	public function getInstances(): array {
		$classes = get_declared_classes();
		$instances = [];
		foreach ($classes as $className) {
			$reflection = new ReflectionAnnotatedClass($className);
			if ($reflection->hasAnnotation('Instance')) {
				if ($reflection->getAnnotation('Instance')->value !== null) {
					$name = $reflection->getAnnotation('Instance')->value;
				} else {
					$name = Registry::formatName($className);
				}
				$instances[$name] = $className;
			}
		}
		return $instances;
	}

	public function getPathMapping(): array {
		$instances = $this->getInstances();
		$paths = [];
		foreach ($instances as $short => $className) {
			$reflection = new ReflectionAnnotatedClass($className);
			/** @var ReflectionAnnotatedMethod[] $methods */
			$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
			foreach ($methods as $method) {
				if (!$method->hasAnnotation("Api")) {
					continue;
				}
				$apiAnnotation = $method->getAnnotation("Api");
				/** @var ReflectionParameter[] $params */
				$params = array_slice($method->getParameters(), 2);
				$path = preg_replace_callback(
					"/%[ds]/",
					function(array $matches) use (&$params) {
						$param = array_shift($params);
						return '{' . $param->getName() . '}';
					},
					$apiAnnotation->value
				);
				$paths[$path] ??= [];
				$paths[$path] []= $method;
			}
		}
		return $paths;
	}

	public function getFullClass(string $className): ?string {
		$classes = get_declared_classes();
		foreach ($classes as $class) {
			if ($class === $className || preg_match("/^Nadybot\\\\.*?\\\\\Q$className\E$/", $class)) {
				return $class;
			}
		}
		return null;
	}

	public function addSchema(array &$result, string $className): void {
		$className = preg_replace("/\[\]$/", "", $className);
		if (isset($result[$className])) {
			return;
		}
		$class = $this->getFullClass($className);
		if ($class === null) {
			return;
		}
		$refClass = new ReflectionClass($class);
		$refProps = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$description = $this->getDescriptionFromComment($refClass->getDocComment() ?: "");
		$newResult = [
			"type" => "object",
			"properties" => [],
		];
		if (strlen($description)) {
			$newResult["description"] = $description;
		}
		foreach ($refProps as $refProp) {
			if ($refProp->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			$nameAndType = $this->getNameAndType($refProp);
			if ($nameAndType === null) {
				continue;
			}
			if (substr($nameAndType[1], 0, 1) === "#") {
				$tmp = explode("/", $nameAndType[1]);
				$this->addSchema($result, end($tmp));
				$newResult["properties"][$nameAndType[0]] = [
					'$ref' => $nameAndType[1],
				];
			} else {
				$newResult["properties"][$nameAndType[0]] = [
					"type" => $nameAndType[1],
				];
			}
			$refType = $refProp->getType();
			if (!$refType || $refType->allowsNull()) {
				$newResult["properties"][$nameAndType[0]]["nullable"] = true;
			}
			if (count($nameAndType) > 2 && strlen($nameAndType[2])) {
				$newResult["properties"][$nameAndType[0]]["description"] = $nameAndType[2];
			}
			if ($nameAndType[1] === 'array') {
				$docBlock = $refProp->getDocComment();
				if ($docBlock === false) {
					throw new Exception("Untyped array found");
				}
				if (!preg_match("/@var\s+(.+?)\[\]/", $docBlock, $matches)) {
					throw new Exception("Untyped array found");
				}
				$parts = explode("\\", $matches[1]);
				$newResult["properties"][$nameAndType[0]]["items"] = [
					'$ref' => '#/components/schemas/' . end($parts)
				];
				$this->addSchema($result, end($parts));
			}
		}
		if ($refClass->getParentClass() !== false) {
			$parentClass = $refClass->getParentClass()->getName();
			if ($parentClass !== DBRow::class) {
				$parentParts = explode("\\", $parentClass);
				$this->addSchema($result, end($parentParts));
				$newResult = [
					"allOf" => [
						['$ref' => "#/components/schemas/" . end($parentParts)],
						$newResult,
					]
				];
			}
		}
		$result[$className] = $newResult;
	}

	protected function getRegularNameAndType(ReflectionProperty $refProp): array {
		$propName = $refProp->getName();
		if (!$refProp->hasType()) {
			return  [$propName, "mixed"];
		}
		/** @var ReflectionNamedType */
		$refType = $refProp->getType();
		if ($refType->isBuiltin()) {
			if ($refType->getName() === "int") {
				return [$propName, "integer"];
			}
			if ($refType->getName() === "bool") {
				return [$propName, "boolean"];
			}
			return [$propName, $refType->getName()];
		}
		$name = explode("\\", $refType->getName());

		return [$propName, "#/components/schemas/" . end($name)];
	}

	protected function getNameAndType(ReflectionProperty $refProperty): ?array {
		$docComment = $refProperty->getDocComment();
		if ($docComment === false) {
			return $this->getRegularNameAndType($refProperty);
		}
		if (preg_match('/@json:ignore/', $docComment)) {
			return null;
		}
		$description = $this->getDescriptionFromComment($docComment);
		if (preg_match('/@json:name=([^\s]+)/', $docComment, $matches)) {
			return [$matches[1], $this->getRegularNameAndType($refProperty)[1], $description];
		}
		return [...$this->getRegularNameAndType($refProperty), $description];
	}

	public function getInfoSpec(): array {
		return [
			'title' => 'Nadybot API',
			'description' => 'This API provides access to Nadybot function in a REST API',
			'license' => [
				'name' => 'GPL3',
				'url' => 'https://www.gnu.org/licenses/gpl-3.0.en.html',
			],
			'version' => BotRunner::$version,
		];
	}

	/** @param array<string,ReflectionAnnotatedMethod> $pathMapping */
	public function getSpec(array $mapping): array {
		$result = [
			"openapi" => "3.0.0",
			"info" => $this->getInfoSpec(),
			"servers" => [
				["url" => "/api"],
			],
			"components" => [
				"schemas" => [],
				"securitySchemes" => [
					"basicAuth" => [
						"type" => "http",
						"scheme" => "basic",
					]
				]
			]
		  
		];
		$newResult = [];
		foreach ($mapping as $path => $refMethods) {
			foreach ($refMethods as $refMethod) {
				$doc = $this->getMethodDoc($refMethod);
				$doc->path = $path;
				$newResult[$path] ??= [];
				$newResult[$path]["parameters"] = $this->getParamDocs($path, $refMethod);
				foreach ($doc->methods as $method) {
					$newResult[$path][$method] = [
						"security" => [["basicAuth" => []]],
						"description" => $doc->description,
						"responses" => [],
					];
					if (isset($doc->requestBody)) {
						$newResult[$path][$method]["requestBody"] = $this->getRequestBodyDefinition($doc->requestBody);
						if (isset($doc->requestBody->class)) {
							$this->addSchema($result["components"]["schemas"], $doc->requestBody->class);
						}
					}
					foreach ($doc->responses as $code => $response) {
						if (isset($response->class)) {
							$this->addSchema($result["components"]["schemas"], $response->class);
						}
						$newResult[$path][$method]["responses"][$code] = [
							"description" => $response->desc,
						];
						if (isset($response->class)) {
							$refClass = $this->getClassRef($response->class);
							$newResult[$path][$method]["responses"][$code]["content"] = [
								"application/json" => [
									"schema" => $refClass
								]
							];
						}
					}
				}
			}
			$result["paths"] = $newResult;
		}
		return $result;
	}

	public function getParamDocs(string $path, ReflectionAnnotatedMethod $method): array {
		if (!preg_match_all('/\{(.+?)\}/', $path, $matches)) {
			return [];
		}
		$result = [];
		foreach ($matches[1] as $param) {
			foreach ($method->getParameters() as $refParam) {
				if ($refParam->getName() !== $param) {
					continue;
				}
			}
			/** @var ReflectionNamedType */
			$refType = $refParam->getType();
			$paramResult = [
				"name" => $param,
				"required" => true,
				"in" => "path",
				"schema" => ["type" => $refType->getName()]
			];
			if ($refType->getName() === "int") {
				$paramResult["schema"]["type"] = "integer";
			}
			if ($refType->getName() === "bool") {
				$paramResult["schema"]["type"] = "boolean";
			}
			if (preg_match("/@param.*?\\$\Q$param\E\s+(.+)$/m", $method->getDocComment(), $matches)) {
				$matches[1] = preg_replace("/\*\//", "", $matches[1]);
				if ($matches[1]) {
					$paramResult["description"] = trim($matches[1]);
				}
			}
			$result []= $paramResult;
		}
		$annos = $method->getAnnotations();
		foreach ($annos as $anno) {
			if ($anno instanceof QueryParam && isset($anno->name)) {
				$result []= [
					"name" => $anno->name,
					"required" => $anno->required,
					"in" => $anno->in,
					"schema" => ["type" => $anno->type],
					"description" => $anno->desc,
				];
			}
		}
		return $result;
	}

	public function getDescriptionFromComment(string $comment): string {
		$comment = trim(preg_replace("|^/\*\*(.*)\*/$|s", '$1', $comment));
		$comment = preg_replace("|^\s*\*\s*|m", '', $comment);
		$comment = trim(preg_replace("|@.*$|s", '', $comment));
		$comment = str_replace("\n", " ", $comment);
		return $comment;
	}

	public function getMethodDoc(ReflectionAnnotatedMethod $method): PathDoc {
		$doc = new PathDoc();
		$comment = $method->getDocComment();
		$doc->description = $this->getDescriptionFromComment($comment);

		if (!$method->hasAnnotation("ApiResult")) {
			// throw new Exception("Method " . $method->getDeclaringClass()->getName() . '::' . $method->getName() . "() has no @ApiResult() defined");
		}
		foreach ($method->getAllAnnotations() as $anno) {
			if ($anno instanceof ApiResult) {
				$doc->responses[$anno->code] = $anno;
			} elseif ($anno instanceof RequestBody) {
				$doc->requestBody = $anno;
			} elseif ($anno instanceof GET) {
				$doc->methods []= "get";
			} elseif ($anno instanceof POST) {
				$doc->methods []= "post";
			} elseif ($anno instanceof PUT) {
				$doc->methods []= "put";
			} elseif ($anno instanceof DELETE) {
				$doc->methods []= "delete";
			}
		}
		return $doc;
	}

	public function getRequestBodyDefinition(RequestBody $requestBody): array {
		$result = [];
		if (isset($requestBody->desc)) {
			$result["description"] = $requestBody->desc;
		}
		if (isset($requestBody->required)) {
			$result["required"] = $requestBody->required;
		}
		$classes = explode("|", $requestBody->class);
		foreach ($classes as &$class) {
			$class = $this->getClassRef($class);
		}
		if (count($classes) > 1) {
			$classes = ["oneOf" => $classes];
		} else {
			$classes = $classes[0];
		}
		$result["content"] = [
			"application/json" => [
				"schema" => $classes,
			]
		];
		return $result;
	}

	protected function getClassRef(string $class): array {
		if (substr($class, -2) === '[]') {
			return ["type" => "array", "items" => $this->getSimpleClassRef(substr($class, 0, -2))];
		}
		return $this->getSimpleClassRef($class);
	}

	protected function getSimpleClassRef(string $class): array {
		if (in_array($class, ["string", "bool", "int", "float"])) {
			return ["type" => $class];
		}
		return ['$ref' => "#/components/schemas/{$class}"];
	}
}
