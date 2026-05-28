<?php

declare(strict_types=1);

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ResourceType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class RemoveRedundantTypeChecksRector extends AbstractRector
{
    private const array TYPE_MAP = [
        'is_array' => ArrayType::class,
        'is_string' => StringType::class,
        'is_int' => IntegerType::class,
        'is_integer' => IntegerType::class,
        'is_bool' => BooleanType::class,
        'is_float' => FloatType::class,
        'is_double' => FloatType::class,
        'is_real' => FloatType::class,
        'is_null' => NullType::class,
        'is_object' => ObjectType::class,
        'is_resource' => ResourceType::class,
        'is_callable' => CallableType::class,
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace redundant is_* type checks with true/false when PHPStan proves the type.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
function greet(string $name): string
{
    if (is_string($name)) {
        return 'Hello ' . $name;
    }
    return '';
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
function greet(string $name): string
{
    if (true) {
        return 'Hello ' . $name;
    }
    return '';
}
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
        if ($name === null || ! isset(self::TYPE_MAP[$name])) {
            return null;
        }

        if ($node->args === []) {
            return null;
        }

        $firstArg = $node->args[0];
        if (! $firstArg instanceof Arg) {
            return null;
        }

        $expectedTypeClass = self::TYPE_MAP[$name];
        $argType = $this->getType($firstArg->value);

        if (! $argType instanceof UnionType && is_a($argType, $expectedTypeClass, true)) {
            return new ConstFetch(new Name('true'));
        }

        if ($this->isNeverCompatible($argType, $expectedTypeClass)) {
            return new ConstFetch(new Name('false'));
        }

        return null;
    }

    private function isNeverCompatible(Type $type, string $expectedClass): bool
    {
        if ($type instanceof UnionType) {
            return false;
        }

        if ($expectedClass === NullType::class) {
            return false;
        }

        return $type->isNull()->yes();
    }
}
