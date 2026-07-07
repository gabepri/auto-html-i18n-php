<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

/** How the library will treat a translation string when consuming it. */
enum TranslationFormat: string
{
    case Icu = 'icu';
    case Simple = 'simple';
    case Plain = 'plain';
}
