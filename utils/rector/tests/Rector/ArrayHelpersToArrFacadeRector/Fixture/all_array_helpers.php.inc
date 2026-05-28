<?php

declare(strict_types=1);

function useNestedArrays(array $items): array
{
    return array_values(array_unique($items));
}

function filterList(array $data): array
{
    return array_filter($data, fn ($v) => $v > 0);
}

function transformItems(array $data): array
{
    return array_map(fn ($v) => $v * 2, $data);
}
-----
<?php

declare(strict_types=1);

function useNestedArrays(array $items): array
{
    return collect($items)->unique()->values()->all();
}

function filterList(array $data): array
{
    return collect($data)->filter(fn ($v) => $v > 0)->all();
}

function transformItems(array $data): array
{
    return collect($data)->map(fn ($v) => $v * 2)->all();
}
