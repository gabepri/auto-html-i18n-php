<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

enum TextDirection: string
{
    case Ltr = 'ltr';
    case Rtl = 'rtl';

    /** Languages written right-to-left in their default script. */
    private const RTL_LANGUAGES = [
        'ar' => true, // Arabic
        'arc' => true, // Aramaic
        'ckb' => true, // Sorani Kurdish
        'dv' => true, // Divehi
        'fa' => true, // Persian
        'glk' => true, // Gilaki
        'he' => true, // Hebrew
        'iw' => true, // Hebrew (legacy code)
        'ji' => true, // Yiddish (legacy code)
        'ks' => true, // Kashmiri
        'mzn' => true, // Mazanderani
        'nqo' => true, // N'Ko
        'pnb' => true, // Western Punjabi
        'ps' => true, // Pashto
        'sd' => true, // Sindhi
        'ug' => true, // Uyghur
        'ur' => true, // Urdu
        'ydd' => true, // Eastern Yiddish
        'yi' => true, // Yiddish
    ];

    /** ISO 15924 script codes written right-to-left. */
    private const RTL_SCRIPTS = [
        'adlm' => true, // Adlam
        'arab' => true, // Arabic
        'aran' => true, // Arabic (Nastaliq)
        'hebr' => true, // Hebrew
        'mand' => true, // Mandaic
        'mend' => true, // Mende Kikakui
        'nkoo' => true, // N'Ko
        'rohg' => true, // Hanifi Rohingya
        'samr' => true, // Samaritan
        'syrc' => true, // Syriac
        'thaa' => true, // Thaana
        'yezi' => true, // Yezidi
    ];

    /**
     * Resolves the writing direction of a locale tag (BCP 47; underscore
     * separators are tolerated). An explicit script subtag wins over the
     * language's default: `ar-Latn` is ltr, `az-Arab` is rtl.
     */
    public static function forLocale(string $locale): self
    {
        $subtags = preg_split('/[-_]/', strtolower($locale)) ?: [];
        foreach (array_slice($subtags, 1) as $subtag) {
            if (preg_match('/^[a-z]{4}$/', $subtag) === 1) {
                return isset(self::RTL_SCRIPTS[$subtag]) ? self::Rtl : self::Ltr;
            }
        }
        return isset(self::RTL_LANGUAGES[$subtags[0] ?? '']) ? self::Rtl : self::Ltr;
    }
}
