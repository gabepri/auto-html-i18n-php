<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        // Every file in src/ and tests/ already declares strict types; keep it that way.
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
