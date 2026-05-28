<?php

declare(strict_types=1);

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NullCheckToNullsafeRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace ternary null checks with nullsafe operator.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
$result = $user !== null ? $user->getName() : null;
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$result = $user?->getName();
CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Ternary::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof Ternary) {
            return null;
        }

        if ($node->if === null) {
            return null;
        }

        $cond = $node->cond;

        if ($cond instanceof NotIdentical && $this->isNull($cond->right) && $this->isNull($node->else)) {
            $checkedVar = $cond->left;
            $successBranch = $node->if;
        } elseif ($cond instanceof NotIdentical && $this->isNull($cond->left) && $this->isNull($node->else)) {
            $checkedVar = $cond->right;
            $successBranch = $node->if;
        } elseif ($cond instanceof Identical && $this->isNull($cond->right) && $this->isNull($node->if)) {
            $checkedVar = $cond->left;
            $successBranch = $node->else;
        } elseif ($cond instanceof Identical && $this->isNull($cond->left) && $this->isNull($node->if)) {
            $checkedVar = $cond->right;
            $successBranch = $node->else;
        } else {
            return null;
        }

        if (! $successBranch instanceof MethodCall && ! $successBranch instanceof PropertyFetch) {
            return null;
        }

        if (! $this->sameVariable($checkedVar, $successBranch->var)) {
            return null;
        }

        if ($successBranch instanceof MethodCall) {
            return new NullsafeMethodCall($successBranch->var, $successBranch->name, $successBranch->args);
        }

        return new Expr\NullsafePropertyFetch($successBranch->var, $successBranch->name);
    }

    private function isNull(mixed $node): bool
    {
        return $node instanceof ConstFetch
            && strtolower((string) $node->name) === 'null';
    }

    private function sameVariable(Node $a, Node $b): bool
    {
        if ($a instanceof Variable && $b instanceof Variable) {
            return $a->name === $b->name;
        }

        return false;
    }
}
