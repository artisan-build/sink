<?php

declare(strict_types=1);

namespace ArtisanBuild\SinkContracts;

enum Truncation: string
{
    case None = 'none';
    case AttachmentsDropped = 'attachments_dropped';
    case HeadersOnly = 'headers_only';
}
