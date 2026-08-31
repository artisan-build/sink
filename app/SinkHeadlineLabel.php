<?php

namespace App;

use ArtisanBuild\BuiltForCloud\Vitals\HeadlineLabel;

enum SinkHeadlineLabel: string implements HeadlineLabel
{
    case RetainedMessages = 'retained-messages';
}
