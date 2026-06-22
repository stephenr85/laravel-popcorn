<?php

namespace Rushing\Popcorn;

/**
 * How an invocable is reached. The same named capability can be answered by a
 * local PHP handler, a remote MCP tool, or a webhook — callers never care which.
 */
enum Binding: string
{
    case Local = 'local';
    case Mcp = 'mcp';
    case Webhook = 'webhook';
}
