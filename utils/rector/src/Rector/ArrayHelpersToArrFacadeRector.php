<?php

declare(strict_types=1);

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class ArrayHelpersToArrFacadeRector extends AbstractRector
{
    private const string NESTED_SKIP_ATTR = 'array_helpers_nested_skip';

    private const array SIMPLE = [
        'array_unique' => 'unique',
        'array_values' => 'values',
        'array_keys' => 'keys',
        'array_reverse' => 'reverse',
        'array_flip' => 'flip',
    ];

    private const array ALL_HANDLED = [
        'array_unique', 'array_values', 'array_keys', 'array_reverse', 'array_flip',
        'array_filter', 'array_map', 'array_slice', 'array_merge',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace PHP native array functions with Laravel Collection methods ending with all().',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
$values = array_values(array_unique($items));
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$values = collect($items)->unique()->values()->all();
CODE_SAMPLE
                ),
            ]
        );
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

        if (in_array($name, self::ALL_HANDLED, true)) {
            $this->markNestedHandled($node);
        }

        $result = $this->convert($node, $name);
        if ($result === null) {
            return null;
        }

        if ($node->getAttribute(self::NESTED_SKIP_ATTR) === true) {
            return $result;
        }

        return new MethodCall($result, new Identifier('all'));
    }

    private function markNestedHandled(FuncCall $outerNode): void
    {
        $outerName = $this->getName($outerNode->name);
        $argIndex = $outerName === 'array_map' ? 1 : 0;

        $innerArg = $outerNode->args[$argIndex] ?? null;
        if (! $innerArg instanceof Arg) {
            return;
        }

        $inner = $innerArg->value;
        if (! $inner instanceof FuncCall) {
            return;
        }

        $innerName = $this->getName($inner->name);
        if ($innerName !== null && in_array($innerName, self::ALL_HANDLED, true)) {
            $inner->setAttribute(self::NESTED_SKIP_ATTR, true);
        }
    }

    private function convert(FuncCall $node, string $name): ?Expr
    {
        $result = match (true) {
            isset(self::SIMPLE[$name]) => $this->simpleConvert($node, self::SIMPLE[$name]),
            $name === 'array_filter' => $this->convertArrayFilter($node),
            $name === 'array_map' => $this->convertArrayMap($node),
            $name === 'array_slice' => $this->convertArraySlice($node),
            $name === 'array_merge' => $this->convertArrayMerge($node),
            $name === 'array_search' => $this->convertArraySearch($node),
            $name === 'array_key_exists' => $this->convertArrayKeyExists($node),
            $name === 'array_column' => $this->convertArrayColumn($node),
            $name === 'join' || $name === 'implode' => $this->convertJoin($node),
            $name === 'in_array' => $this->convertInArray($node),
            $name === 'array_is_list' => $this->nodeFactory->createStaticCall('Illuminate\Support\Arr', 'isList', $node->args),
            default => null,
        };

        if (! $result instanceof Expr) {
            return null;
        }

        return $result;
    }

    private function getArgValue(FuncCall $node, int $index): ?Expr
    {
        if (! isset($node->args[$index])) {
            return null;
        }

        $arg = $node->args[$index];
        if (! $arg instanceof Arg) {
            return null;
        }

        return $arg->value;
    }

    private function simpleConvert(FuncCall $node, string $method): ?MethodCall
    {
        $value = $this->getArgValue($node, 0);
        if ($value === null) {
            return null;
        }

        return $this->nodeFactory->createMethodCall(
            $this->wrapInCollect($value),
            $method
        );
    }

    private function convertArrayFilter(FuncCall $node): ?MethodCall
    {
        $value = $this->getArgValue($node, 0);
        if ($value === null) {
            return null;
        }

        $collect = $this->wrapInCollect($value);

        if (count($node->args) === 1) {
            return $this->nodeFactory->createMethodCall($collect, 'filter');
        }

        return $this->nodeFactory->createMethodCall($collect, 'filter', [$node->args[1]]);
    }

    private function convertArrayMap(FuncCall $node): ?MethodCall
    {
        $value = $this->getArgValue($node, 1);
        if ($value === null) {
            return null;
        }

        $collect = $this->wrapInCollect($value);

        return $this->nodeFactory->createMethodCall($collect, 'map', [$node->args[0]]);
    }

    private function convertArraySlice(FuncCall $node): ?MethodCall
    {
        $value = $this->getArgValue($node, 0);
        if ($value === null) {
            return null;
        }

        $collect = $this->wrapInCollect($value);

        $methodArgs = [$node->args[1]];
        if (isset($node->args[2])) {
            $methodArgs[] = $node->args[2];
        }

        return $this->nodeFactory->createMethodCall($collect, 'slice', $methodArgs);
    }

    private function convertArraySearch(FuncCall $node): ?MethodCall
    {
        $value = $this->getArgValue($node, 1);
        if ($value === null) {
            return null;
        }

        $collect = $this->wrapInCollect($value);

        return $this->nodeFactory->createMethodCall($collect, 'search', [$node->args[0]]);
    }

    private function convertArrayKeyExists(FuncCall $node): Node
    {
        return $this->nodeFactory->createStaticCall(
            'Illuminate\Support\Arr',
            'exists',
            [$node->args[1], $node->args[0]]
        );
    }

    private function convertArrayColumn(FuncCall $node): Node
    {
        return $this->nodeFactory->createStaticCall(
            'Illuminate\Support\Arr',
            'pluck',
            $node->args
        );
    }

    private function convertJoin(FuncCall $node): Node
    {
        return $this->nodeFactory->createStaticCall(
            'Illuminate\Support\Arr',
            'join',
            [$node->args[1], $node->args[0]]
        );
    }

    private function convertInArray(FuncCall $node): Node
    {
        $value = $this->getArgValue($node, 1);
        if ($value === null) {
            return $node;
        }

        $collect = $this->wrapInCollect($value);
        $method = isset($node->args[2]) ? 'containsStrict' : 'contains';

        return $this->nodeFactory->createMethodCall($collect, $method, [$node->args[0]]);
    }

    private function wrapInCollect(Expr $expr): Expr
    {
        if ($expr instanceof MethodCall) {
            return $expr;
        }

        if ($expr instanceof FuncCall) {
            $name = $this->getName($expr->name);
            if ($name !== null && in_array($name, self::ALL_HANDLED, true)) {
                return $expr;
            }
        }

        return $this->nodeFactory->createFuncCall('collect', [new Arg($expr)]);
    }

    private function convertArrayMerge(FuncCall $node): Expr
    {
        $value = $this->getArgValue($node, 0);
        if ($value === null) {
            return $this->nodeFactory->createFuncCall('collect', [[]]);
        }

        $result = $this->wrapInCollect($value);

        for ($i = 1; $i < count($node->args); $i++) {
            $arg = $node->args[$i];
            if (! $arg instanceof Arg) {
                continue;
            }
            $result = $this->nodeFactory->createMethodCall($result, 'merge', [$arg->value]);
        }

        return $result;
    }
}
