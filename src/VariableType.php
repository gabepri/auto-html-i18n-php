<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

enum VariableType: string
{
    case IgnoreWord = 'ignoreWord';
    case Number = 'number';
    case Date = 'date';
    case Url = 'url';
    case Email = 'email';
    case Symbol = 'symbol';
    case Comment = 'comment';
    case Markup = 'markup';
}
