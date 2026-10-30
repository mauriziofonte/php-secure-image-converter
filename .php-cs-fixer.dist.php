<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * PSR-12 plus a few rules that keep the code base uniform. No rule set that rewrites code to syntax newer than
 * PHP 7.2 (@PHP74Migration, @PHP80Migration, ...) may be enabled: the library supports PHP 7.2.5 to 8.5.
 */
$finder = Finder::create()
    ->in([__DIR__ . '/tests'])
    ->append([__FILE__, __DIR__ . '/tools/ensure-tool.php']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => ['statements' => ['return', 'throw']],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'native_function_casing' => true,
        'no_empty_phpdoc' => true,
        'no_extra_blank_lines' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_unused_imports' => true,
        'no_whitespace_before_comma_in_array' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_indent' => true,
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'single_quote' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
