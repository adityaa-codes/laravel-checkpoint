<?php

declare(strict_types=1);

class User
{
    public function getName(): string { return 'Alice'; }
}

$user = new User();
$result = $user !== null ? $user->getName() : null;
$result2 = null !== $user ? $user->getName() : null;
$result3 = $user === null ? null : $user->getName();
-----
<?php

declare(strict_types=1);

class User
{
    public function getName(): string { return 'Alice'; }
}

$user = new User();
$result = $user?->getName();
$result2 = $user?->getName();
$result3 = $user?->getName();
