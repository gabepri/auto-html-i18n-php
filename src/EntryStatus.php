<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

enum EntryStatus: string
{
    case Resolved = 'resolved';
    case Reported = 'reported';
}
