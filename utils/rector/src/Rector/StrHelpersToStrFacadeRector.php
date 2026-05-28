<?php

declare(strict_types=1);

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Webmozart\Assert\Assert;

final class StrHelpersToStrFacadeRector extends AbstractRector implements ConfigurableRectorInterface
{
    private const array DEFAULT_MAP = [
        'str_replace' => 'replace',
        'strlen' => 'length',
        'strtolower' => 'lower',
        'strtoupper' => 'upper',
        'ucfirst' => 'ucfirst',
        'lcfirst' => 'lcfirst',
        'substr' => 'substr',
        'mb_substr' => 'substr',
        'mb_strlen' => 'length',
        'trim' => 'trim',
        'ltrim' => 'ltrim',
        'rtrim' => 'rtrim',
        'substr_count' => 'substrCount',
        'preg_replace' => 'replaceMatches',
        'base64_decode' => 'fromBase64',
        'str_repeat' => 'repeat',
        'str_starts_with' => 'startsWith',
        'str_ends_with' => 'endsWith',
        'str_contains' => 'contains',
        'preg_match' => 'isMatch',
    ];

    private const array ARGUMENT_LIMITS = [
        'trim' => 1,
        'ltrim' => 1,
        'rtrim' => 1,
        'preg_match' => 2,
        'preg_replace' => 3,
    ];

    /** @var array<string, string> */
    private array $map = self::DEFAULT_MAP;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace PHP native string functions with Laravel Str facade static calls.',
            [
                new ConfiguredCodeSample(
                    <<<'CODE_SAMPLE'
$trimmed = trim($input);
$len = strlen($name);
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$trimmed = \Illuminate\Support\Str::trim($input);
$len = \Illuminate\Support\Str::length($name);
CODE_SAMPLE
                    ,
                    ['trim' => 'trim', 'strlen' => 'length']
                ),
            ]
        );
    }

    public function configure(array $configuration): void
    {
        Assert::allStringNotEmpty(array_keys($configuration));
        Assert::allStringNotEmpty(array_values($configuration));
        /** @var array<string, string> $merged */
        $merged = array_merge(self::DEFAULT_MAP, $configuration);
        $this->map = $merged;
    }

    public function getNodeTypes(): array
    {
        return [FuncCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof FuncCall) {
            return null;
        }

        $name = $this->getName($node->name);
        if ($name === null) {
            return null;
        }

        if ($name === 'explode') {
            return $this->convertExplode($node);
        }

        if ($name === 'preg_split') {
            return $this->convertPregSplit($node);
        }

        if ($name === 'json_encode') {
            return $this->nodeFactory->createStaticCall(
                'Illuminate\Support\Js',
                'encode',
                $node->args
            );
        }

        if (! isset($this->map[$name])) {
            return null;
        }

        if ($this->hasExcessArguments($name, $node)) {
            return null;
        }

        $method = $this->map[$name];

        return $this->nodeFactory->createStaticCall(
            'Illuminate\Support\Str',
            $method,
            $node->args
        );
    }

    private function hasExcessArguments(string $name, FuncCall $node): bool
    {
        if (! isset(self::ARGUMENT_LIMITS[$name])) {
            return false;
        }

        return count($node->args) > self::ARGUMENT_LIMITS[$name];
    }

    private function convertExplode(FuncCall $node): Node
    {
        return $this->nodeFactory->createMethodCall(
            $this->nodeFactory->createStaticCall(
                'Illuminate\Support\Str',
                'of',
                [$node->args[1]]
            ),
            'explode',
            [$node->args[0]]
        );
    }

    private function convertPregSplit(FuncCall $node): Node
    {
        return $this->nodeFactory->createMethodCall(
            $this->nodeFactory->createMethodCall(
                $this->nodeFactory->createStaticCall(
                    'Illuminate\Support\Str',
                    'of',
                    [$node->args[1]]
                ),
                'split',
                [$node->args[0]]
            ),
            'all'
        );
    }
}
