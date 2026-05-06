<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

enum FinishReason: string {
	/** The model hit a natural stop point or a provided stop sequence */
	case Stop = 'stop';

	/** The maximum number of tokens specified in the request was reached */
	case Length = 'length';

	/** The model called a function. */
	case FunctionCall = 'function_call';

	/** Content was omitted due to a flag from the content filters */
	case ContentFilter = 'content_filter';

	/** The model called one or more tools. */
	case ToolCalls = 'tool_calls';
}
