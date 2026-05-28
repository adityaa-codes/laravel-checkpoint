<?php

declare(strict_types=1);

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Cast;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class RemoveUnnecessaryTypeCastsRector extends AbstractRector
{
    /** @var array<class-string<Cast>, class-string<Type>> */
    private const array CAST_TYPE_MAP = [
        Cast\String_::class => StringType::class,
        Cast\Int_::class => IntegerType::class,
        Cast\Bool_::class => BooleanType::class,
        Cast\Array_::class => ArrayType::class,
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove type casts where the expression is already the target type.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
function getName(): string { return 'hello'; }
$name = (string) getName();
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
function getName(): string { return 'hello'; }
$name = getName();
CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [
            Cast\String_::class,
            Cast\Int_::class,
            Cast\Bool_::class,
            Cast\Array_::class,
            Cast\Double::class,
        ];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof Cast) {
            return null;
        }

        $castClass = $node::class;
        $expectedTypeClass = self::CAST_TYPE_MAP[$castClass] ?? null;

        if ($expectedTypeClass === null) {
            return null;
        }

        $innerType = $this->getType($node->expr);

        if (is_a($innerType, $expectedTypeClass, true)) {
            return $node->expr;
        }

        return null;
    }
}
