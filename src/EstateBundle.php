<?php

namespace Base\Estate;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Property management on top of omnibase: the organisations that own or
 * manage homes (an SCI, an agency), their buildings and units, their
 * associates, leases and tenants, rent calls and receipts, contractors and
 * work orders - and a private space for each of those people.
 */
class EstateBundle extends AbstractBaseBundle
{
    // Own singleton storage rather than sharing AbstractBaseBundle's (see ForgeBundle).
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /** The bundle root is the package root: ./templates is @Estate, ./src/Entity is mapped. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->setMapping($this->getPath().'/src/Entity', 'Base\Estate\Entity', 'App\Entity\Estate');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Estate\Repository', 'App\Repository\Estate');
    }
}
