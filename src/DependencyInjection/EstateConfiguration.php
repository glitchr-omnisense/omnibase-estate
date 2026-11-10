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
                ->arrayNode('signature')
                    ->info('Leases and inventories signed electronically, with omnisense/omnisign (Signature\\LeaseSignatures).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('gateway')->defaultNull()
                            ->info('The omnisign gateway (omnisign.gateways.<name>) leases are signed through: docuseal (self-hosted) in development, yousign in production. Null: no electronic signature.')->end()
                        ->scalarNode('template')->defaultNull()
                            ->info('A template of the provider\'s to sign instead of the lease\'s PDF and its signature page: DocuSeal\'s open-source edition signs its own templates only. Its roles: Bailleur, Locataire 1, Locataire 2..., Garant 1...')->end()
                        ->scalarNode('inventory_template')->defaultNull()
                            ->info('The same for the inventory of fixtures (roles Bailleur, Locataire 1...).')->end()
                        ->enumNode('level')->values(['simple', 'advanced'])->defaultValue('simple')
                            ->info('The eIDAS level asked: simple (any provider), advanced (Yousign, DocuSign).')->end()
                    ->end()
                ->end()
                ->arrayNode('story')
                    ->info('The homes\' stories: the ready-to-post images made for the networks.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('font')->defaultNull()
                            ->info('A TTF/OTF file for the images\' titles and labels (bold). Null: DejaVu Sans Bold where the system has it.')->end()
                        ->scalarNode('font_regular')->defaultNull()
                            ->info('A TTF/OTF file for the images\' second line. Null: the bold font\'s regular sibling, else the bold one.')->end()
                        ->scalarNode('mark')->defaultNull()
                            ->info('The company\'s mark on the images\' band: a PNG or JPEG file (transparent PNG best). Null: none, the company\'s name is written.')->end()
                        ->scalarNode('color')->defaultValue('#1f2328')
                            ->info('The band\'s and the labels\' colour, #rrggbb.')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
