<?php
/**
 * Copyright © Mage-OS. All rights reserved.
 * See LICENSE.txt for license details.
 */

/**
 * Bootstrap for standalone unit tests (CI).
 *
 * Registers an autoloader that generates stub Factory classes and extension
 * attribute interfaces on the fly, so tests can mock them without running
 * setup:di:compile.
 */
declare(strict_types=1);

spl_autoload_register(function (string $className): void {
    if (str_ends_with($className, 'ExtensionInterface')) {
        // Foo\BarExtensionInterface for an extensible Foo\BarInterface, as Magento's code generator creates it
        $sourceName = substr($className, 0, -strlen('ExtensionInterface')) . 'Interface';
        if (!interface_exists($sourceName)
            || !is_subclass_of($sourceName, \Magento\Framework\Api\ExtensibleDataInterface::class)
        ) {
            return;
        }
        $body = " extends \\Magento\\Framework\\Api\\ExtensionAttributesInterface\n{\n}\n";
        $kind = 'interface';
    } elseif (str_ends_with($className, 'Factory')) {
        $sourceName = substr($className, 0, -strlen('Factory'));
        if ($sourceName === '' || !class_exists($sourceName) && !interface_exists($sourceName)) {
            return;
        }
        $body = "\n{\n    public function create(array \$data = [])\n    {\n    }\n}\n";
        $kind = 'class';
    } else {
        return;
    }

    $parts = explode('\\', $className);
    $shortName = array_pop($parts);
    $namespace = implode('\\', $parts);

    $code = '';
    if ($namespace !== '') {
        $code .= "namespace {$namespace};\n\n";
    }
    $code .= "{$kind} {$shortName}{$body}";

    // eval is intentional: generates stub classes for test mocking,
    // same approach as Magento's GeneratedClassesAutoloader
    eval($code); // phpcs:ignore Squiz.PHP.Eval
});
