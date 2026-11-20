<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'PhxVm\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (str_contains($relative, '\\')) return;
    $path = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($path)) require $path;
});
