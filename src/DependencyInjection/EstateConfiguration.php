<?php

namespace Base\Estate\DependencyInjection;

use Base\Estate\Enum\Activity;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class EstateConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('estate');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('activities')
                    ->info('What the business does, combinable: assets (its own property), ownership (a shared ownership), management (for third parties), transaction (sales). The back office shows what belongs to them.')
                    ->enumPrototype()->values(array_map(fn (Activity $activity) => $activity->value, Activity::cases()))->end()
                    ->defaultValue([Activity::ASSETS->value])
                    ->requiresAtLeastOneElement()
                ->end()
                ->scalarNode('user_class')->defaultValue('App\Entity\User')
                    ->info('The application\'s user entity: invitations create accounts of this class.')->end()
                ->scalarNode('from_email')->defaultValue('%env(default::MAILER_CONTACT)%')
                    ->info('Who the e-mails to tenants, associates and contractors come from.')->end()
                ->scalarNode('payment_gateway')->defaultValue('stripe')
                    ->info('The omnitrade gateway rents are paid online through. Empty: no online payment.')->end()
                ->arrayNode('payment_methods')->scalarPrototype()->end()->defaultValue(['card', 'sepa_debit'])
                    ->info('What a tenant may pay with online, as omnitrade names it.')->end()
                ->integerNode('invitation_days')->min(1)->defaultValue(14)
                    ->info('How long an invitation link stays valid.')->end()
                ->integerNode('reminder_days')->min(1)->defaultValue(5)
                    ->info('How many days after its due date an unpaid rent is marked late and reminded (estate:rent:late).')->end()
                ->scalarNode('documents_route')->defaultNull()
                    ->info('The application\'s documents page, linked from the associates\' space. Null: none.')->end()
            ->end();

        return $treeBuilder;
    }
}
