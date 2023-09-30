<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 14/04/22
     * Time: 12:14 a. m.
     */
    namespace Zyos\InstallBundle\DependencyInjection;

    use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
    use Symfony\Component\Config\Definition\Builder\NodeDefinition;
    use Symfony\Component\Config\Definition\Builder\TreeBuilder;
    use Symfony\Component\Config\Definition\ConfigurationInterface;

    /**
     * Class Configuration
     *
     * @package Zyos\InstallBundle\DependencyInjection
     */
    class Configuration implements ConfigurationInterface {

        /**
         * Generates the configuration tree builder.
         *
         * @return TreeBuilder
         */
        public function getConfigTreeBuilder(): TreeBuilder {

            $treeBuilder = new TreeBuilder('zyos_install');
            $rootNode = $treeBuilder->getRootNode();

            $this->path($rootNode);
            $this->environments($rootNode);
            $this->locks($rootNode);
            $this->install($rootNode);
            $this->validate($rootNode);
            $this->filesystem($rootNode);
            $this->cliCommand($rootNode);

            return $treeBuilder;
        }

        /**
         * Method path
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function path(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->scalarNode('path')->info('configuration path')
                    ->beforeNormalization()
                        ->ifEmpty()->then(function ($path) {
                            return '%kernel.project_dir%/src/Resources/zyos-install-bundle';
                        })
                    ->end()
                    ->defaultValue('%kernel.project_dir%/src/Resources/zyos-install-bundle')
                ->end();
        }

        /**
         * Method environments
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function environments(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->arrayNode('environments')->info('execution environments')
                    ->beforeNormalization()
                        ->always(function ($v) {
                            if (is_string($v)):
                                return 'prod' === $v ? [$v] : ['prod', $v];
                            elseif (is_array($v)):
                                return in_array('prod', $v) ? $v : array_merge(['prod'], $v);
                            else:
                                return ['dev', 'prod'];
                            endif;
                        })
                    ->end()
                    ->prototype('scalar')->end()
                    ->defaultValue(['dev', 'prod'])
                    ->requiresAtLeastOneElement()
                ->end();
        }

        /**
         * Method locks
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function locks(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->arrayNode('locks')->info('environments to be locked')
                    ->beforeNormalization()
                        ->always(function ($v) {
                            if (is_string($v)):
                                return 'prod' === $v ? [$v] : ['prod', $v];
                            elseif (is_array($v)):
                                return in_array('prod', $v) ? $v : array_merge(['prod'], $v);
                            else:
                                return ['prod'];
                            endif;
                        })
                    ->end()
                    ->prototype('scalar')->end()
                    ->defaultValue(['prod'])
                    ->requiresAtLeastOneElement()
                ->end();
        }

        /**
         * Method install
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function install(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->arrayNode('install')->info('Symfony commands that are executed for the application deployment')
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('command')->defaultNull()->end()
                                ->arrayNode('arguments')
                                    ->addDefaultsIfNotSet()
                                    ->normalizeKeys(false)
                                    ->ignoreExtraKeys(false)
                                    ->beforeNormalization()
                                        ->castToArray()
                                        ->ifEmpty()->then(function ($v) { return []; })
                                    ->end()
                                ->end()
                                ->integerNode('priority')->defaultValue(1)->end()
                                ->booleanNode('enable')->defaultTrue()->end()
                                ->enumNode('if_error')->values(['none', 'stop'])->defaultValue('stop')->end()
                                ->append($this->getEnvironments())
                            ->end()
                        ->end()
                    ->end()
                ->end();
        }

        /**
         * Method validate
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function validate(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->arrayNode('validate')->info('Validate paths, files and others')
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('filepath')->isRequired()->end()
                                ->enumNode('type')->isRequired()->values(['directory', 'file', 'request', 'custom'])->end()
                                ->booleanNode('enable')->defaultTrue()->end()
                                ->append($this->getEnvironments())
                                ->arrayNode('validations')
                                    ->arrayPrototype()
                                        ->beforeNormalization()
                                            ->ifString()->then(function ($v) { return ['name' => $v]; })
                                        ->end()
                                        ->children()
                                            ->scalarNode('name')->defaultNull()->end()
                                            ->arrayNode('parameters')->addDefaultsIfNotSet()
                                                ->normalizeKeys(false)
                                                ->ignoreExtraKeys(false)
                                                ->beforeNormalization()
                                                    ->castToArray()
                                                    ->ifEmpty()->then(function ($v) { return []; })
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end();
        }

        /**
         * Method filesystem
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function filesystem(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->arrayNode('filesystem')
                        ->beforeNormalization()
                            ->ifEmpty()->then(function ($v) { return []; })
                            ->ifString()->then(function ($v) { return empty($v) ? [] : [$v]; })
                        ->end()
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('name')->defaultNull()->end()
                                ->scalarNode('source')->isRequired()->cannotBeEmpty()->end()
                                ->scalarNode('destination')->isRequired()->cannotBeEmpty()->end()
                                ->booleanNode('enable')->defaultTrue()->end()
                                ->integerNode('priority')->defaultValue(1)->end()
                                ->enumNode('type')->isRequired()->values(['mirror', 'symlink', 'directory'])->end()
                                ->enumNode('if_error')->values(['none', 'stop'])->defaultValue('stop')->end()
                                ->append($this->getEnvironments())
                            ->end()
                        ->end()
                    ->end()
                ->end();
        }

        /**
         * Method cliCommand
         *
         * @param ArrayNodeDefinition $rootNode
         *
         * @return void
         */
        private function cliCommand(ArrayNodeDefinition $rootNode): void {

            $rootNode
                ->children()
                    ->arrayNode('cli')
                        ->beforeNormalization()
                            ->ifEmpty()->then(function ($v) { return []; })
                            ->ifString()->then(function ($v) { return empty($v) ? [] : [$v]; })
                        ->end()
                        ->arrayPrototype()
                            ->children()
                                ->arrayNode('command')->addDefaultsIfNotSet()
                                    ->normalizeKeys(false)
                                    ->ignoreExtraKeys(false)
                                    ->beforeNormalization()
                                        ->castToArray()
                                        ->ifEmpty()->then(function ($v) { return []; })
                                        ->ifString()->then(function ($v) { return empty($v) ? [] : [$v]; })
                                    ->end()
                                ->end()
                                ->booleanNode('enable')->defaultTrue()->end()
                                ->integerNode('priority')->defaultValue(1)->end()
                                ->append($this->getEnvironments())
                                ->enumNode('if_error')->values(['none', 'stop'])->defaultValue('stop')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end();
        }

        /**
         * Method getEnvironments
         *
         * @return ArrayNodeDefinition|NodeDefinition
         */
        private function getEnvironments(): ArrayNodeDefinition|NodeDefinition {

            $treeBuilder = new TreeBuilder('environments');
            $node = $treeBuilder->getRootNode();

            $node
                ->info('environments running')
                ->beforeNormalization()
                    ->ifEmpty()->then(function ($v) { return []; })
                    ->ifString()->then(function ($v) { return empty($v) ? [] : [$v]; })
                ->end()
                ->prototype('scalar')->end()
                ->cannotBeEmpty()
                ->isRequired()
                ->requiresAtLeastOneElement();
            return $node;
        }
    }