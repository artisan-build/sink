<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkServer\Audit;

use ArtisanBuild\BuiltForCloud\Audit\AppAction;

enum SinkAction: string implements AppAction
{
    case MessageDeleted = 'message_deleted';
    case MessagesPurged = 'messages_purged';
}
