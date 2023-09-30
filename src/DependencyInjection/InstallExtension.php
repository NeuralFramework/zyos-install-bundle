<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 14/04/22
     * Time: 12:09 a. m.
     */
    namespace Zyos\InstallBundle\DependencyInjection;

    use Symfony\Component\Config\FileLocator;
    use Symfony\Component\DependencyInjection\ContainerBuilder;
    use Symfony\Component\DependencyInjection\Exception\BadMethodCallException;
    use Symfony\Component\DependencyInjection\Extension\Extension;
    use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
    use Zyos\InstallBundle\ValidatorsInterface;

    /**
     * Class InstallExtension
     *
     * @package Zyos\InstallBundle\DependencyInjection
     */
    class InstallExtension extends Extension {

        /**
         * Loads a specific configuration.
         *
         * @throws \InvalidArgumentException When provided tag is not defined in this extension
         * @throws \Exception
         */
        public function load(array $configs, ContainerBuilder $container): void {

            $container->registerForAutoconfiguration(ValidatorsInterface::class)->addTag('zyos_install.validators');

            $configuration = $this->getConfiguration($configs, $container);
            $array = $this->processConfiguration($configuration, $configs);

            $container->setParameter('zyos_install.path', $this->getParameter('path', $array, '%kernel.project_dir%/src/Resources/install'));
            $container->setParameter('zyos_install.environments', $this->getParameter('environments', $array, ['dev', 'prod']));
            $container->setParameter('zyos_install.locks', $this->getParameter('locks', $array, ['prod']));
            $container->setParameter('zyos_install.lockfile', sprintf('%s/lockfile.lock', $this->getParameter('path', $array, '%kernel.project_dir%/src/Resources/zyos-install-bundle')));
            $container->setParameter('zyos_install.install', $this->getParameter('install', $array, []));
            $container->setParameter('zyos_install.validate', $this->getParameter('validate', $array, []));
            $container->setParameter('zyos_install.filesystem', $this->getParameter('filesystem', $array, []));
            $container->setParameter('zyos_install.cli', $this->getParameter('cli', $array, []));

            $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__).'/Resources/config'));
            $loader->load('services.yaml');
        }

        /**
         * Method getParameter
         *
         * @param string $key
         * @param array $configurations
         * @param null $default
         *
         * @return mixed
         */
        private function getParameter(string $key, array $configurations, $default = null): mixed {
            return array_key_exists($key, $configurations) ? $configurations[$key] : $default;
        }

        /**
         * Returns the recommended alias to use in XML.
         *
         * This alias is also the mandatory prefix to use when using YAML.
         *
         * This convention is to remove the "Extension" postfix from the class
         * name and then lowercase and underscore the result. So:
         *
         *     AcmeHelloExtension
         *
         * becomes
         *
         *     acme_hello
         *
         * This can be overridden in a sub-class to specify the alias manually.
         *
         * @throws BadMethodCallException When the extension name does not follow conventions
         */
        public function getAlias(): string {
            return 'zyos_install';
        }
    }