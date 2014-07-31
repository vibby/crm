<?php

namespace OroCRM\Bundle\ChannelBundle\DependencyInjection;

use OroCRM\Bundle\ChannelBundle\Provider\SettingsProvider;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ChannelConfiguration implements ConfigurationInterface
{
    const ROOT_NODE_NAME = 'orocrm_channel';

    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $root        = $treeBuilder->root(self::ROOT_NODE_NAME);
        $root
            ->children()
                ->arrayNode(SettingsProvider::DATA_PATH)->isRequired()->cannotBeEmpty()
                    ->prototype('array')
                        ->children()
                            ->scalarNode('name')
                                ->isRequired()->cannotBeEmpty()
                            ->end()
                            ->arrayNode('dependent')
                                ->prototype('scalar')->cannotBeEmpty()->end()
                            ->end()
                            ->arrayNode('navigation_items')
                                ->prototype('scalar')->cannotBeEmpty()->end()
                            ->end()
                            ->arrayNode('dependencies')
                                ->prototype('scalar')->cannotBeEmpty()->end()
                            ->end()
                            ->scalarNode('dependencies_condition')
                                ->defaultValue('AND')->cannotBeEmpty()
                                ->validate()->ifNotInArray(['OR', 'AND'])
                                    ->thenInvalid('Invalid param %s')
                                ->end()
                            ->end()
                            ->arrayNode('belongs_to')->cannotBeEmpty()
                                ->children()
                                    ->scalarNode('integration')->cannotBeEmpty()->end()
                                    ->scalarNode('connector')->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('channel_types')->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('entities')
                                ->prototype('scalar')->cannotBeEmpty()->end()
                            ->end()
                            ->scalarNode('integration_type')->cannotBeEmpty()->end()
                            ->scalarNode('customer_identity')->cannotBeEmpty()->end()
                            ->scalarNode('is_customer_identity_user_defined')->defaultTrue()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
