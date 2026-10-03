<?php

namespace Base\Estate\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class EstateExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): EstateConfiguration
    {
        return new EstateConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new EstateConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // estate.user_class, estate.from_email, estate.payment_gateway...
        $this->setConfiguration($container, $config, $configuration->getConfigTreeBuilder()->buildTree()->getName());
    }
}
