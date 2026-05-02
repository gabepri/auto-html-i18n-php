<?php

declare(strict_types=1);

namespace AutoDomI18n;

enum EntryStatus: string
{
    case Resolved = 'resolved';
    case Reported = 'reported';
}
