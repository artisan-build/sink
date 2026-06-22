<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Mcp;

use ArtisanBuild\SinkServer\Mcp\Tools\AssertCountTool;
use ArtisanBuild\SinkServer\Mcp\Tools\BodyMatchesTool;
use ArtisanBuild\SinkServer\Mcp\Tools\CountMessagesTool;
use ArtisanBuild\SinkServer\Mcp\Tools\LinksTool;
use ArtisanBuild\SinkServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\SinkServer\Mcp\Tools\ListRecentTool;
use ArtisanBuild\SinkServer\Mcp\Tools\MessageDetailTool;
use ArtisanBuild\SinkServer\Mcp\Tools\PurgeTool;
use ArtisanBuild\SinkServer\Mcp\Tools\RecipientsTool;
use ArtisanBuild\SinkServer\Mcp\Tools\StatsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Sink')]
#[Version('1.0.0')]
#[Instructions('Body-blind, assertion-oriented email testing tools. Tools expose envelope metadata, recipients, links, grouped counts, safe body match booleans, and scoped purge operations. No tool returns rendered or raw email body text.')]
final class SinkMcpServer extends Server
{
    protected array $tools = [
        ListAppsTool::class,
        ListRecentTool::class,
        CountMessagesTool::class,
        RecipientsTool::class,
        AssertCountTool::class,
        StatsTool::class,
        MessageDetailTool::class,
        LinksTool::class,
        BodyMatchesTool::class,
        PurgeTool::class,
    ];
}
