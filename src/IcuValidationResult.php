<?php

declare(strict_types=1);

namespace AutoHtmlI18n;

/** Result of dry-run validating a translation string against variables. */
final class IcuValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly TranslationFormat $format,
        /** Failure reason when invalid; wording is engine-specific. */
        public readonly ?string $error = null,
        /** What consumption would render, present when valid. */
        public readonly ?string $output = null,
    ) {
    }

    /**
     * @return array{valid:bool,format:string,error?:string,output?:string}
     */
    public function toArray(): array
    {
        $out = ['valid' => $this->valid, 'format' => $this->format->value];
        if ($this->error !== null) {
            $out['error'] = $this->error;
        }
        if ($this->output !== null) {
            $out['output'] = $this->output;
        }
        return $out;
    }
}
