<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Plain autowiring over src/, as omnibase/forge does. Entities, enums and
 * models are not services; the admin controllers are loaded only when
 * omnibase/admin is there, the books only when omnibase/ledger is, the
 * registers (CompanyRecords) only when glitchr/omnistate is, the official
 * indices (Index\OfficialIndex) only when omnistate's statistics are, a shared
 * ownership's, an owner's, a tenant's and a flatshare's cloud folders
 * (Drive\OwnershipFolders, Drive\MandatorFolders, Drive\LeaseFolders,
 * Drive\FlatshareFolders) only when omnibase/drive is.
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
            ...(class_exists('Base\\Ledger\\Service\\Posting') ? [] : [$src.'/Service/Books.php', $src.'/Ledger/', $src.'/Command/BooksUnitsCommand.php', $src.'/Command/OwnershipIncomeCommand.php']),
            ...(class_exists('Omnistate\\Omnistate') ? [] : [$src.'/Service/CompanyRecords.php', $src.'/Command/CompanyRefreshCommand.php']),
            // The IRL read from INSEE: omnistate's statistics (omnisense/omnistate with its statistics capability, omnistate/insee).
            ...(interface_exists('Omnistate\\Registry\\StatisticsRegistryInterface') && class_exists('Omnistate\\Insee\\Insee') ? [] : [$src.'/Index/']),
            ...(class_exists('Base\\Drive\\Share\\FileShare') ? [] : [$src.'/Drive/']),
            // Each person's space: omnibase/drive's providers, from the release that has them.
            ...(interface_exists('Base\\Drive\\Space\\SpaceProviderInterface') ? [] : [$src.'/Drive/EstateSpaces.php']),
            // A flatshare's group: omnibase/drive's groups, from the release that has them.
            ...(class_exists('Base\\Drive\\Entity\\ShareGroup') ? [] : [$src.'/Drive/FlatshareFolders.php']),
        ]);

    $services->load('Base\\Estate\\Controller\\Client\\', $src.'/Controller/Client/')
        ->tag('controller.service_arguments');

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src.'/Controller/Admin')) {
        $services->load('Base\\Estate\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
    }
};
