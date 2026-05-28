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

final class FileHelpersToFileFacadeRector extends AbstractRector implements ConfigurableRectorInterface
{
    private const array DEFAULT_MAP = [
        'file_get_contents' => 'get',
        'file_put_contents' => 'put',
        'file_exists' => 'exists',
        'is_file' => 'isFile',
        'is_dir' => 'isDirectory',
        'unlink' => 'delete',
        'mkdir' => 'makeDirectory',
        'basename' => 'basename',
        'glob' => 'glob',
        'rename' => 'move',
        'copy' => 'copy',
        'is_readable' => 'isReadable',
        'is_writable' => 'isWritable',
        'dirname' => 'dirname',
        'chmod' => 'chmod',
        'filesize' => 'size',
        'filemtime' => 'lastModified',
        'symlink' => 'link',
        'rmdir' => 'deleteDirectory',
    ];

    private const array ARGUMENT_LIMITS = [
        'mkdir' => 3,
        'file_put_contents' => 3,
    ];

    /** @var array<string, string> */
    private array $map = self::DEFAULT_MAP;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace PHP file helper functions with Laravel File facade static calls.',
            [
                new ConfiguredCodeSample(
                    <<<'CODE_SAMPLE'
$contents = file_get_contents($path);
file_put_contents($path, $data);
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
$contents = \Illuminate\Support\Facades\File::get($path);
\Illuminate\Support\Facades\File::put($path, $data);
CODE_SAMPLE
                    ,
                    ['file_get_contents' => 'get', 'file_put_contents' => 'put']
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
        if ($name === null || ! isset($this->map[$name])) {
            return null;
        }

        if ($this->hasExcessArguments($name, $node)) {
            return null;
        }

        $method = $this->map[$name];

        return $this->nodeFactory->createStaticCall(
            'Illuminate\Support\Facades\File',
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
}
