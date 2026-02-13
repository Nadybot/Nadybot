<?php declare(strict_types=1);

namespace Nadybot\Api;

use function Safe\glob;

use BackedEnum;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Http,
	BotRunner,
	DBRow,
	DBTable,
	Registry,
	Safe,
	Util,
};
use Nadybot\Core\Config\{AutoUnfreeze, Proxy};
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

use ReflectionUnionType;

/**
 * @psalm-type BaseSpecDef = array{"type"?: string, "$ref"?: string, "format"?: string, "pattern"?: string, "minLength"?: int, "maxLength"?: int}
 * @psalm-type SpecDef = BaseSpecDef|array{"oneOf": list<BaseSpecDef>}
 */
class ApiSpecGenerator {
	/** @var array<string,string> */
	private array $tags = [];

	/** @var list<string> */
	private array $classes = [];

	public function loadClasses(): void {
		/** @phpstan-ignore-next-line */
		foreach (glob(__DIR__ . '/../Core/DBSchema/*.php') as $file) {
			require_once $file;
		}

		/** @phpstan-ignore-next-line */
		foreach (glob(__DIR__ . '/../Core/Modules/*/*.php') as $file) {
			require_once $file;
		}

		/** @phpstan-ignore-next-line */
		foreach (glob(__DIR__ . '/../Core/Config/*.php') as $file) {
			require_once $file;
		}

		/** @phpstan-ignore-next-line */
		foreach (glob(__DIR__ . '/../Core/*.php') as $file) {
			require_once $file;
		}

		/** @phpstan-ignore-next-line */
		foreach (glob(__DIR__ . '/../Modules/*/*.php') as $file) {
			require_once $file;
		}
	}

	/**
	 * Return an array of [instancename => full class name] for all #[Instance]s
	 *
	 * @return array<string,string>
	 *
	 * @phpstan-return array<string,class-string>
	 */
	public function getInstances(): array {
		$classes = get_declared_classes();
		$instances = [];
		foreach ($classes as $className) {
			$reflection = new ReflectionClass($className);
			$instanceAttrs = $reflection->getAttributes(NCA\Instance::class);
			if (!count($instanceAttrs)) {
				continue;
			}

			$instanceObj = $instanceAttrs[0]->newInstance();
			$name = $instanceObj->name ?? Registry::formatName($className);
			$instances[$name] = $className;
		}
		return $instances;
	}

	/** @return array<string,list<ReflectionMethod>> */
	public function getPathMapping(): array {
		$instances = $this->getInstances();
		$paths = [];
		foreach ($instances as $short => $className) {
			$reflection = new ReflectionClass($className);
			$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
			foreach ($methods as $method) {
				$apiAttrs = $method->getAttributes(Http\Api::class);
				if (!count($apiAttrs)) {
					continue;
				}

				$apiAttr = $apiAttrs[0]->newInstance();

				$params = array_slice($method->getParameters(), 1);
				try {
					$path = Safe::pregReplaceCallback(
						'/%[ds]/',
						static function (array $matches) use (&$params): string {
							if (!count($params)) {
								throw new \Exception('No parameters left');
							}
							$param = array_shift($params);
							return '{' . $param->getName() . '}';
						},
						$apiAttr->path
					);
				} catch (\Throwable $e) {
					throw new \Exception('Error when trying to get params for ' . $apiAttr->path . ': ' . $e->getMessage(), 0, $e);
				}
				$paths[$path] ??= [];
				$paths[$path] []= $method;
			}
		}
		uksort(
			$paths,
			static function (string $a, string $b): int {
				return strcmp("{$a}/", "{$b}/");
			}
		);
		return $paths;
	}

	/** @phpstan-return null|class-string */
	public static function getFullClass(string $className, ?ReflectionProperty $refProp=null): ?string {
		$classes = get_declared_classes();
		foreach ($classes as $class) {
			if (is_subclass_of($class, \Attribute::class)) {
				continue;
			}
			if ($class === $className || Safe::pregMatches("/^Nadybot\\\\.*?\\\\\Q{$className}\E$/", $class)) {
				return $class;
			}
		}
		if (isset($refProp)) {
			$type = $refProp->getType();
			if ($type instanceof ReflectionNamedType) {
				$fullClass = $type->getName();
				if (class_exists($fullClass)) {
					return $fullClass;
				}
			}
		}
		return null;
	}

	/** @param array<string,array<mixed>> $result */
	public function addSchema(array &$result, string $className): void {
		$className = class_basename(Safe::pregReplace("/\[\]$/", '', $className));
		if (isset($result[$className])) {
			return;
		}
		$class = self::getFullClass($className);
		if ($class === null) {
			return;
		}
		if (is_a($class, BackedEnum::class, true)) {
			return;
		}
		$refClass = new ReflectionClass($class);
		$refProps = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		usort(
			$refProps,
			static function (ReflectionProperty $a, ReflectionProperty $b): int {
				return strnatcmp($a->getName(), $b->getName());
			}
		);
		$refDoc = $refClass->getDocComment();
		$description = $this->getDescriptionFromComment(is_string($refDoc) ? $refDoc : '');
		$newResult = [
			'type' => 'object',
			'properties' => [],
		];
		if (strlen($description)) {
			$newResult['description'] = $description;
		}
		foreach ($refProps as $refProp) {
			if ($refProp->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			$nameAndType = $this->getNameAndType($refProp);
			if ($nameAndType === null) {
				continue;
			}
			if (is_string($nameAndType[1]) && substr($nameAndType[1], 0, 1) === '#') {
				$tmp = explode('/', $nameAndType[1]);
				$this->classes []= Util::arrayLast($tmp);
				$newResult['properties'][$nameAndType[0]] = $this->getClassRef($nameAndType[1], $refProp);
			} elseif (is_array($nameAndType[1])) {
				$nameAndType[1] = array_values(array_diff($nameAndType[1], ['null']));
				$newResult['properties'][$nameAndType[0]] = [
					'oneOf' => array_map(fn (string $class): array => $this->getClassRef($class, $refProp), $nameAndType[1]),
				];
			} else {
				$newResult['properties'][$nameAndType[0]] = $this->getClassRef($nameAndType[1], $refProp);
			}
			$refType = $refProp->getType();
			if (!$refType || $refType->allowsNull()) {
				if (!($refType instanceof ReflectionNamedType) || !in_array($refType->getName(), [Proxy::class, AutoUnfreeze::class], true)) {
					$newResult['properties'][$nameAndType[0]]['nullable'] = true;
				}
			}
			if ($refType instanceof ReflectionNamedType && is_a($refType->getName(), BackedEnum::class, true)) {
				$enum = $refType->getName();
				$values = array_map(static fn (BackedEnum $x): string|int => $x->value, $enum::cases());
				$newResult['properties'][$nameAndType[0]]['enum'] = $values;
				if ($refType->allowsNull()) {
					$newResult['properties'][$nameAndType[0]]['enum'] []= null;
				}
			}
			if (isset($nameAndType[2]) && strlen($nameAndType[2])) {
				if (!isset($newResult['properties'][$nameAndType[0]]['$ref'])) {
					$newResult['properties'][$nameAndType[0]]['description'] = $nameAndType[2];
				}
			}
			if ($nameAndType[1] === 'array') {
				$docBlock = $refProp->getDocComment();
				if ($docBlock === false) {
					$docBlock = $refProp->getDeclaringClass()->getConstructor()?->getDocComment() ?? false;
					if ($docBlock === false) {
						throw new Exception("Untyped array found at {$class}::\$" . $refProp->name);
					}
					if (count($matches = Safe::pregMatch('/@param\s+(.*\$' . preg_quote($refProp->name, '/') . ')/m', $docBlock))) {
						$docBlock = '@var ' . $matches[1];
					}
				}
				if (!count($matches = Safe::pregMatch("/@json-var\s+(.+?)\[\]/", $docBlock))
					&& !count($matches = Safe::pregMatch("/@var\s+(.+?)\[\]/", $docBlock))
					&& !count($matches = Safe::pregMatch("/@var\s+list<(.+?)>/", $docBlock))) {
					throw new Exception("Untyped array found at {$class}::\$" . $refProp->name);
				}
				$parts = explode('\\', $matches[1]??'');
				$newResult['properties'][$nameAndType[0]]['items'] = self::getSimpleClassRef(Util::arrayLast($parts));
				$this->classes []= Util::arrayLast($parts);
			}
		}
		$parentClassObj = $refClass->getParentClass();
		if ($parentClassObj !== false) {
			$parentClass = $parentClassObj->getName();
			if (!in_array($parentClass, [DBRow::class, DBTable::class], true)) {
				$parentParts = explode('\\', $parentClass);
				$this->classes []= Util::arrayLast($parentParts);
				if (!count($newResult['properties'])) {
					$newResult = [
						'allOf' => [
							['$ref' => '#/components/schemas/' . Util::arrayLast($parentParts)],
						],
					];
				} else {
					$newResult = [
						'allOf' => [
							['$ref' => '#/components/schemas/' . Util::arrayLast($parentParts)],
							$newResult,
						],
					];
				}
				if (strlen($description)) {
					$newResult['description'] = $description;
				}

				/** @mago-ignore analysis:undefined-int-array-index */
				if (isset($newResult['allOf'][1])) {
					/** @mago-ignore analysis:possibly-undefined-string-array-index */
					unset($newResult['allOf'][1]['description']);
				}
			}
		}
		$result[$className] = $newResult;
	}

	/** @return array<string,mixed> */
	public function getInfoSpec(): array {
		return [
			'title' => 'Nadybot API',
			'description' => 'This API provides access to Nadybot functions in a REST API',
			'license' => [
				'name' => 'GPL3',
				'url' => 'https://www.gnu.org/licenses/gpl-3.0.en.html',
			],
			'contact' => [
				'name' => 'Support',
				'url' => 'https://discord.gg/aDR9UBxRfg',
			],
			'version' => BotRunner::getVersion(false),
		];
	}

	/**
	 * @param array<string,list<ReflectionMethod>> $mapping
	 *
	 * @return array<string,mixed>
	 */
	public function getSpec(array $mapping): array {
		$result = [
			'openapi' => '3.0.3',
			'info' => $this->getInfoSpec(),
			'servers' => [
				['url' => '/api'],
			],
			'tags' => [],
			'components' => [
				'schemas' => [],
				'securitySchemes' => [
					'basicAuth' => [
						'type' => 'http',
						'scheme' => 'basic',
						'description' => 'Login with user/password',
					],
				],
			],
		];
		$newResult = [];
		foreach ($mapping as $path => $refMethods) {
			foreach ($refMethods as $refMethod) {
				$doc = $this->getMethodDoc($refMethod, $path);
				$newResult[$path] ??= [];
				$newResult[$path]['parameters'] = $this->getParamDocs($path, $refMethod);
				foreach ($doc->methods as $method) {
					$newResult[$path][$method] = [
						'security' => [['basicAuth' => []]],
						'description' => $doc->description,
						'responses' => [],
					];
					if (isset($doc->requestBody)) {
						$newResult[$path][$method]['requestBody'] = $this->getRequestBodyDefinition($doc->requestBody);
						if (isset($doc->requestBody->class)) {
							$this->classes []= class_basename($doc->requestBody->class);
						}
					}
					if (count($doc->tags) > 0) {
						$newResult[$path][$method]['tags'] = $doc->tags;
					}
					foreach ($doc->responses as $code => $response) {
						if (isset($response->class)) {
							$this->classes []= $response->class;
						}
						$newResult[$path][$method]['responses'][$code] = [
							'description' => $response->desc,
						];
						if (isset($response->class)) {
							$refClass = $this->getClassRef($response->class);
							$newResult[$path][$method]['responses'][$code]['content'] = [
								'application/json' => [
									'schema' => $refClass,
								],
							];
						}
					}
				}
			}
			$result['paths'] = $newResult;
		}
		foreach ($this->tags as $tag => $description) {
			$result['tags'] []= [
				'name' => $tag,
				'description' => $description,
			];
		}
		while (null !== $class = array_shift($this->classes)) {
			$this->addSchema($result['components']['schemas'], $class);
		}

		/** @psalm-suppress RedundantFunctionCall */
		ksort($result['components']['schemas']);
		usort(
			$result['tags'],
			/**
			 * @param array{"name":string,"description":string} $entry1
			 * @param array{"name":string,"description":string} $entry2
			 */
			static function (array $entry1, array $entry2): int {
				return strnatcasecmp($entry1['name'], $entry2['name']);
			}
		);
		return $result;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 *
	 * @psalm-return list<array{"name": string, "required": bool, "in": string, "schema": array{"type": string}, "description"?: string}>
	 */
	public function getParamDocs(string $path, ReflectionMethod $method): array {
		$result = [];
		if (count($matches = Safe::pregMatchAll('/\{(.+?)\}/', $path)) > 0) {
			foreach ($matches[1] as $param) {
				foreach ($method->getParameters() as $refParam) {
					if ($refParam->getName() !== $param) {
						continue;
					}
				}

				/** @var ?\ReflectionParameter $refParam */
				if (!isset($refParam)) {
					continue;
				}

				/** @var ReflectionNamedType */
				$refType = $refParam->getType();
				$paramResult = [
					'name' => $param,
					'required' => true,
					'in' => 'path',
					'schema' => ['type' => $refType->getName()],
				];
				if ($refType->getName() === 'int') {
					$paramResult['schema']['type'] = 'integer';
				}
				if ($refType->getName() === 'bool') {
					$paramResult['schema']['type'] = 'boolean';
				}
				$docComment = $method->getDocComment();
				if (count($matches = Safe::pregMatch("/@param.*?\\$\Q{$param}\E\s+(.+)$/m", is_string($docComment) ? $docComment : ''))) {
					$matches[1] = Safe::pregReplace("/\*\//", '', $matches[1]);
					$paramResult['description'] = trim($matches[1]);
				}
				$result []= $paramResult;
			}
		}
		$qParamAttrs = $method->getAttributes(Http\QueryParam::class);
		foreach ($qParamAttrs as $qParamAttr) {
			$qParam = $qParamAttr->newInstance();
			$result []= [
				'name' => $qParam->name,
				'required' => $qParam->required,
				'in' => $qParam->in,
				'schema' => ['type' => $qParam->type],
				'description' => $qParam->desc,
			];
		}
		return $result;
	}

	public function getDescriptionFromComment(string $comment): string {
		$comment = trim(Safe::pregReplace("|^/\*\*(.*)\*/$|s", '$1', $comment));
		$comment = Safe::pregReplace("|^\s*\*\s*|m", '', $comment);
		$comment = trim(Safe::pregReplace('|@.*$|s', '', $comment));
		$comment = str_replace("\n", ' ', $comment);
		$comment = Safe::pregReplace("/\s*\*$/", '', $comment);
		return $comment;
	}

	public function getMethodDoc(ReflectionMethod $method, string $path): PathDoc {
		$comment = $method->getDocComment();
		$doc = new PathDoc(
			path: $path,
			description: is_string($comment) ? $this->getDescriptionFromComment($comment) : 'No documentation provided',
		);

		$apiResultAttrs = $method->getAttributes(Http\ApiResult::class);
		if (!count($apiResultAttrs)) {
			throw new Exception('Method ' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '() has no #[ApiResult] defined');
		}
		if (($fileName = $method->getFileName()) === false) {
			throw new Exception('Cannot determine file for' . $method->getDeclaringClass()->getName());
		}
		$dir = dirname($fileName);
		if (count($matches = Safe::pregMatch('{(?:/|^)([A-Z_]+)(?:/|$)}', $dir))) {
			$tag = strtolower(Safe::pregReplace('/_MODULE/', '', $matches[1]));
			$doc->tags = [$tag];
			$this->tags[$tag] = "Module \"{$matches[1]}\"";
		}
		foreach ($method->getAttributes() as $attr) {
			$attr = $attr->newInstance();
			if ($attr instanceof Http\ApiResult) {
				$doc->responses[$attr->code] = $attr;
			} elseif ($attr instanceof Http\ApiTag) {
				$doc->tags []= $attr->tag;
				if (!isset($this->tags[$attr->tag])) {
					$this->tags[$attr->tag] = "Functions for {$attr->tag}";
				}
			} elseif ($attr instanceof Http\RequestBody) {
				$doc->requestBody = $attr;
			} elseif ($attr instanceof Http\VERB) {
				$doc->methods []= strtolower(class_basename($attr));
			}
		}
		return $doc;
	}

	/**
	 * @return array<string,mixed>
	 *
	 * @phpstan-return array{"description"?: string, "required"?: bool, "content": array{"application/json": array{"schema": string|array<mixed>}}}
	 *
	 * @psalm-return array{"description"?: string, "required"?: bool, "content": array{"application/json": array{"schema": string|array<mixed>}}}
	 */
	public function getRequestBodyDefinition(Http\RequestBody $requestBody): array {
		$result = [];
		if (isset($requestBody->desc)) {
			$result['description'] = $requestBody->desc;
		}
		if (isset($requestBody->required)) {
			$result['required'] = $requestBody->required;
		}
		$classes = explode('|', $requestBody->class);
		foreach ($classes as &$class) {
			$class = $this->getClassRef($class);
		}
		if (count($classes) > 1) {
			$classes = ['oneOf' => $classes];
		} else {
			$classes = $classes[0];
		}
		$result['content'] = [
			'application/json' => [
				'schema' => $classes,
			],
		];
		return $result;
	}

	/**
	 * Return the name and the possible types of a property
	 *
	 * @return array{0: string, 1: string|list<string>}
	 */
	protected function getRegularNameAndType(ReflectionProperty $refProp): array {
		$propName = $refProp->getName();
		$types = $this->getTypesFromDoc($refProp);
		if (count($types) === 1) {
			return [$propName, $types[0]];
		} elseif (count($types) > 1) {
			return [$propName, $types];
		}
		if (!$refProp->hasType()) {
			$comment = $refProp->getDocComment();
			if ($comment === false) {
				$comment = $refProp->getDeclaringClass()->getConstructor()?->getDocComment() ?? false;
				if ($comment !== false) {
					if (count($matches = Safe::pregMatch('/@param\s+(.*\$' . preg_quote($refProp->name, '/') . ')/m', $comment))) {
						$comment = '@var ' . $matches[1];
					}
				}
			}
			if ($comment === false || !count($matches = Safe::pregMatch("/@var ([^\s]+)/s", $comment))) {
				return [$propName, 'mixed'];
			}

			$types = explode('|', $matches[1]??'');
			foreach ($types as &$type) {
				if ($type === 'int') {
					$type = 'integer';
				} elseif ($type === 'bool') {
					$type = 'boolean';
				}
			}
			// @phpstan-ignore return.type
			return [$propName, $types];
		}
		$refTypes = [];
		$refPropType = $refProp->getType();
		if ($refPropType instanceof ReflectionUnionType) {
			/** @var list<\ReflectionNamedType>|list<\ReflectionIntersectionType> */
			$refTypes = $refPropType->getTypes();
		} elseif ($refPropType instanceof ReflectionNamedType) {
			$refTypes = [$refPropType];
		} else {
			throw new Exception('Unknown ReflectionClass');
		}

		/** @var list<string> */
		$types = [];
		foreach ($refTypes as $refType) {
			// Cannot handle this now
			if ($refType instanceof \ReflectionIntersectionType) {
				continue;
			}
			if ($refType->isBuiltin()) {
				if ($refType->getName() === 'int') {
					$types []= 'integer';
				} elseif ($refType->getName() === 'bool') {
					$types []= 'boolean';
				} else {
					$types []= $refType->getName();
				}
			} else {
				$name = explode('\\', $refType->getName());
				$this->classes []= Util::arrayLast($name);
				$types []= Util::arrayLast($name);
			}
		}
		if (count($types) === 1) {
			return [$propName, $types[0]];
		}
		return [$propName, $types];
	}

	/**
	 * @return null|array<mixed>
	 *
	 * @psalm-return null|array{0: string, 1: string|list<string>, 2?: string}
	 */
	protected function getNameAndType(ReflectionProperty $refProperty): ?array {
		if (count($refProperty->getAttributes(NCA\JSON\Ignore::class))) {
			return null;
		}
		$docComment = $refProperty->getDocComment();
		if ($docComment === false) {
			$docComment = $refProperty->getDeclaringClass()->getConstructor()?->getDocComment() ?? false;
			if ($docComment !== false) {
				if (count($matches = Safe::pregMatch('/@param\s+.*\$' . preg_quote($refProperty->name, '/') . '\s(.*)/m', $docComment))) {
					$docComment = $matches[1];
				}
			}
		}
		$description = $this->getDescriptionFromComment(is_string($docComment) ? $docComment : '');
		$nameAttr = $refProperty->getAttributes(NCA\JSON\Name::class);
		if (count($nameAttr) > 0) {
			$nameObj = $nameAttr[0]->newInstance();
			return [$nameObj->name, $this->getRegularNameAndType($refProperty)[1], $description];
		}
		return [...$this->getRegularNameAndType($refProperty), $description];
	}

	/**
	 * @return array<string,string|array<string,string>>
	 *
	 * @psalm-return SpecDef|array{"type": "array", "items": SpecDef}
	 */
	protected function getClassRef(string $class, ?ReflectionProperty $refProp=null): array {
		$matches = [];
		if (
			count($matches = Safe::pregMatch('/^(.+)\[\]$/', $class))
			|| count($matches = Safe::pregMatch('/^array<int,(.+)>$/', $class))
			|| count($matches = Safe::pregMatch('/^array<string,(.+)>$/', $class))
			|| count($matches = Safe::pregMatch('/^array<array-key,(.+)>$/', $class))
			|| count($matches = Safe::pregMatch('/^array<(.+)>$/', $class))
			|| count($matches = Safe::pregMatch('/^list<(.+)>$/', $class))
		) {
			/** @var array{0:truthy-string,1:non-empty-string} $matches */
			return ['type' => 'array', 'items' => $this->getSimpleClassRef($matches[1], $refProp)];
		}
		return $this->getSimpleClassRef($class, $refProp);
	}

	/**
	 * @return array<string,string>
	 *
	 * @psalm-return SpecDef
	 */
	protected function getSimpleClassRef(string $class, ?ReflectionProperty $refProp=null): array {
		$class = Safe::pregReplace('/^\?/', '', $class);
		if ($class === 'scalar') {
			return ['oneOf' => [
				['type' => 'string'],
				['type' => 'integer'],
				['type' => 'boolean'],
			]];
		}
		if (in_array($class, ['boolean', 'integer', 'string', 'float'], true)) {
			return ['type' => $class];
		}
		if (in_array($class, ['bool', 'int'], true)) {
			return ['type' => str_replace(['int', 'bool'], ['integer', 'boolean'], $class)];
		}
		if ($class === 'class-string') {
			return ['type' => 'string'];
		}
		if ($class === 'UuidInterface') {
			return [
				'type' => 'string',
				'format' => 'uuid',
				'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
				'minLength' => 36,
				'maxLength' => 36,
			];
		}
		$fullClass = self::getFullClass($class, $refProp);
		if ($fullClass === null) {
			throw new Exception("Cannot infer full class for {$class}");
		}
		$class = $fullClass;
		if (is_a($class, \DateTimeInterface::class, true)) {
			return ['type' => 'integer'];
		} elseif (is_a($class, BackedEnum::class, true)) {
			/** @mago-ignore analysis:possibly-static-access-on-interface */
			$first = $class::cases()[0]->value;
			if (is_string($first)) {
				return ['type' => 'string'];
			}
			return ['type' => 'integer'];
		}
		$this->classes []= $class;
		return ['$ref' => '#/components/schemas/' . class_basename($class)];
	}

	/**
	 * Try to infer possible types of a property by its constructor
	 *
	 * @return list<string>
	 */
	private function getTypesFromConstructor(ReflectionProperty $refProp): array {
		$comment = $refProp->getDeclaringClass()->getConstructor()?->getDocComment() ?? false;
		if ($comment === false) {
			return [];
		}
		if (!count($matches = Safe::pregMatch('/@param\s+([^ ]+)\s+\$' . preg_quote($refProp->name, '/') . '/m', $comment))) {
			return [];
		}
		$types = safe::pregSplit('/\s*\|\s*/', trim($matches[1]));
		return $types;
	}

	/**
	 * Try to infer possible types of a property by docblocks
	 *
	 * @return list<string>
	 */
	private function getTypesFromDoc(ReflectionProperty $refProp): array {
		$comment = $refProp->getDocComment();
		if ($comment === false) {
			return $this->getTypesFromConstructor($refProp);
		}
		if (!count($matches = Safe::pregMatch("/@var ([^\s]+)/s", $comment))) {
			return $this->getTypesFromConstructor($refProp);
		}
		$types = safe::pregSplit('/\s*\|\s*/', trim($matches[1]));
		return $types;
	}
}
