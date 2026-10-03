<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Plain autowiring over src/, as omnibase/forge does. Entities, enums and
 * models are not services; the admin controllers are loaded only when
 * omnibase/admin is there, the books only when omnibase/ledger is, the
 * registers (CompanyRecords) only when glitchr/omnistate is.
 */
return function (ContainerConfigurator $configurator) {

    $src = dirname(__DIR__).'/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Estate\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Model/',
            $src.'/Exception/',
            $src.'/Controller/Admin/',
            $src.'/EstateBundle.php',
            ...(class_exists('Base\\Ledger\\Service\\Posting') ? [] : [$src.'/Service/Books.php', $src.'/Ledger/']),
            ...(class_exists('Omnistate\\Omnistate') ? [] : [$src.'/Service/CompanyRecords.php', $src.'/Command/CompanyRefreshCommand.php']),
        ]);

    $services->load('Base\\Estate\\Controller\\Client\\', $src.'/Controller/Client/')
        ->tag('controller.service_arguments');

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src.'/Controller/Admin')) {
        $services->load('Base\\Estate\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
    }
};
