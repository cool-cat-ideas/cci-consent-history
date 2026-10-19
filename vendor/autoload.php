<?php

spl_autoload_register(static function (string $class): void {
    $namespacePrefix = 'Module\\CciConsentHistory\\';
    if (strncmp($class, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($namespacePrefix));
    $classFile = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($classFile)) {
        require_once $classFile;
    }
});
