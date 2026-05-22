<?php

namespace Zyos\InstallBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Zyos\InstallBundle\ValidatorInterface;

/**
 * Extension class for the ZyosInstallBundle.
 *
 * This class is responsible for loading and configuring the ZyosInstallBundle within a Symfony application.
 * It extends Symfony's Extension base class and implements the necessary methods to process configuration,
 * register services, and set up container parameters. The extension handles the integration of the bundle's
 * validation system, configuration processing, and service registration.
 *
 * Main responsibilities:
 * - Loading and processing bundle configuration from YAML files
 * - Registering validator autoconfiguration for custom validators
 * - Setting up container parameters based on configuration
 * - Loading bundle services from configuration files
 * - Providing the bundle alias for Symfony's dependency injection system
 *
 * The extension follows Symfony's best practices for bundle configuration and service registration,
 * ensuring seamless integration with the framework's dependency injection container.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\DependencyInjection
 */
class InstallExtension extends Extension {

    /**
     * Tag name used for validator autoconfiguration.
     *
     * This constant defines the tag name that is automatically applied to all classes implementing
     * the ValidatorsInterface. The tag allows the bundle to collect and use custom validators
     * through Symfony's dependency injection container autoconfiguration mechanism.
     *
     * Validators tagged with this name are automatically discovered and registered for use in
     * the bundle's validation system without requiring manual service definition.
     *
     * @var string
     */
    private const string VALIDATOR_TAG = 'zyos_install.validators';

    /**
     * Name of the services configuration file.
     *
     * This constant specifies the filename of the YAML configuration file that contains the bundle's
     * service definitions. The file is loaded from the bundle's Resources/config directory and defines
     * all services required for the bundle to function properly.
     *
     * @var string
     */
    private const string SERVICES_CONFIG = 'services.yaml';

    /**
     * Relative path to the bundle's configuration directory.
     *
     * This constant defines the relative path from the bundle's root directory to the configuration
     * directory where YAML service files are stored. It is used in conjunction with FileLocator to
     * locate and load configuration files during the extension loading process.
     *
     * @var string
     */
    private const string CONFIG_DIR = '/Resources/config';

    /**
     * Default path for installation resources.
     *
     * This constant defines the default location where the bundle will look for installation-related
     * resources such as configuration files, templates, and other assets. The path uses Symfony's
     * kernel.project_dir parameter to ensure it resolves correctly regardless of the project structure.
     *
     * The default path points to the src/Resources/install directory within the project, which is
     * the standard location for installation-specific resources in a Symfony application.
     *
     * @var string
     */
    private const string DEFAULT_PATH = '%kernel.project_dir%/src/Resources/install';

    /**
     * Default base path for the lockfile.
     *
     * This constant defines the default base directory where the installation lockfile will be stored.
     * The lockfile is used to prevent re-running installation processes in locked environments.
     * The actual lockfile path is constructed by appending '/lockfile.lock' to this base path.
     *
     * @var string
     */
    private const string DEFAULT_LOCKFILE = '%kernel.project_dir%/src/Resources/zyos-install-bundle';

    /**
     * Loads the bundle configuration and registers services and parameters.
     *
     * This method is the main entry point for the extension loading process. It orchestrates the
     * entire configuration loading workflow by calling helper methods to:
     * - Register validator autoconfiguration for custom validators
     * - Resolve and process the configuration from multiple sources
     * - Register container parameters based on the resolved configuration
     * - Load bundle services from YAML configuration files
     *
     * The method follows a clear sequence of operations to ensure that all dependencies are properly
     * set up before services are loaded. This order is critical for the bundle to function correctly.
     *
     * @param array $configs An array of configuration arrays from different sources (e.g., config files, environment)
     * @param ContainerBuilder $container The Symfony dependency injection container to configure
     * @return void
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void {

        $this->registerValidatorAutoconfiguration($container);

        $config = $this->resolveConfiguration($configs, $container);

        $this->registerParameters($container, $config);
        $this->loadServices($container);
    }

    /**
     * Registers autoconfiguration for validator classes.
     *
     * This method sets up Symfony's dependency injection container to automatically discover and
     * configure classes that implement the ValidatorsInterface. By registering autoconfiguration,
     * any class that implements the interface will be automatically tagged with the VALIDATOR_TAG,
     * allowing the bundle to collect and use these validators without requiring manual service
     * definitions in configuration files.
     *
     * This approach follows Symfony's best practices for extensible bundles, allowing developers
     * to create custom validators by simply implementing the interface and letting the container
     * handle the registration automatically.
     *
     * @param ContainerBuilder $container The Symfony dependency injection container to configure
     * @return void
     */
    private function registerValidatorAutoconfiguration(ContainerBuilder $container): void {

        $container
            ->registerForAutoconfiguration(ValidatorInterface::class)
            ->addTag(self::VALIDATOR_TAG);
    }

    /**
     * Resolves and processes the bundle configuration.
     *
     * This method handles the configuration resolution process by retrieving the configuration
     * tree definition from the Configuration class and processing the provided configuration arrays.
     * It merges configurations from multiple sources (e.g., config files, environment variables)
     * and validates them against the configuration tree defined in the Configuration class.
     *
     * The method leverages Symfony's configuration processing capabilities to ensure that the
     * final configuration array is properly merged, validated, and normalized according to the
     * rules defined in the Configuration class.
     *
     * @param array $configs An array of configuration arrays from different sources
     * @param ContainerBuilder $container The Symfony dependency injection container
     * @return array The processed and validated configuration array
     */
    private function resolveConfiguration(array $configs, ContainerBuilder $container): array {

        $configuration = $this->getConfiguration($configs, $container);
        return $this->processConfiguration($configuration, $configs);
    }

    /**
     * Registers container parameters based on the resolved configuration.
     *
     * This method sets up all necessary container parameters that will be used throughout the bundle.
     * Parameters are registered with the 'zyos_install.' prefix to avoid conflicts with other bundles.
     * Each parameter is extracted from the configuration array with appropriate default values to ensure
     * the bundle can function even if some configuration options are not explicitly set.
     *
     * Registered parameters include:
     * - zyos_install.path: Base path for installation resources
     * - zyos_install.environments: Array of environments where the bundle is active
     * - zyos_install.locks: Array of environments that should be locked after installation
     * - zyos_install.lockfile: Path to the installation lockfile
     * - zyos_install.install: Array of Symfony commands to execute during deployment
     * - zyos_install.validate: Array of filesystem validation rules
     * - zyos_install.filesystem: Array of filesystem operations to perform
     * - zyos_install.cli: Array of CLI commands to execute
     *
     * @param ContainerBuilder $container The Symfony dependency injection container to configure
     * @param array $config The processed configuration array
     * @return void
     */
    private function registerParameters(ContainerBuilder $container, array $config): void {

        $basePath = $this->getParameter('path', $config, self::DEFAULT_PATH);

        $container->setParameter('zyos_install.path',         $basePath);
        $container->setParameter('zyos_install.environments', $this->getParameter('environments', $config, ['dev', 'prod']));
        $container->setParameter('zyos_install.locks',        $this->getParameter('locks',        $config, ['prod']));
        $container->setParameter('zyos_install.lockfile',     $this->buildLockfilePath($config));
        $container->setParameter('zyos_install.install',      $this->getParameter('install',      $config, []));
        $container->setParameter('zyos_install.validate',     $this->getParameter('validate',     $config, []));
        $container->setParameter('zyos_install.filesystem',   $this->getParameter('filesystem',   $config, []));
        $container->setParameter('zyos_install.cli',          $this->getParameter('cli',          $config, []));
    }

    /**
     * Builds the full path to the installation lockfile.
     *
     * This method constructs the absolute path to the lockfile used to prevent re-running installation
     * processes in locked environments. The lockfile path is built by appending '/lockfile.lock' to the
     * base path retrieved from the configuration. If the base path is not configured, it defaults to
     * the DEFAULT_LOCKFILE constant value.
     *
     * The lockfile serves as a critical safety mechanism to ensure that installation processes cannot
     * be accidentally re-executed in production environments after the initial deployment, preventing
     * potential data corruption or unintended system modifications.
     *
     * @param array $config The processed configuration array
     * @return string The full path to the lockfile (e.g., '/path/to/resources/lockfile.lock')
     */
    private function buildLockfilePath(array $config): string {

        $basePath = $this->getParameter('path', $config, self::DEFAULT_LOCKFILE);
        return sprintf('%s/lockfile.lock', $basePath);
    }

    /**
     * Loads bundle services from the YAML configuration file.
     *
     * This method is responsible for loading the bundle's service definitions from the services.yaml
     * configuration file. It uses Symfony's YamlFileLoader to parse the YAML file and register all
     * defined services in the dependency injection container.
     *
     * The FileLocator is configured to look for configuration files in the bundle's Resources/config
     * directory, which is the standard location for Symfony bundle configuration files. This ensures
     * that the bundle's services are properly registered and available for dependency injection throughout
     * the application.
     *
     * @param ContainerBuilder $container The Symfony dependency injection container to register services in
     * @return void
     * @throws Exception
     */
    private function loadServices(ContainerBuilder $container): void {

        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__) . self::CONFIG_DIR));
        $loader->load(self::SERVICES_CONFIG);
    }

    /**
     * Retrieves a configuration parameter with a fallback to a default value.
     *
     * This method is a helper utility for safely retrieving values from the configuration array.
     * It checks if the specified key exists in the configurations array and returns its value if present.
     * If the key does not exist, it returns the provided default value instead.
     *
     * This pattern is commonly used throughout the extension to provide sensible defaults for
     * configuration options that users may not explicitly set, ensuring the bundle can function
     * with minimal configuration while still allowing full customization.
     *
     * @param string $key The configuration key to retrieve
     * @param array $configurations The configuration array to search in
     * @param mixed $default The default value to return if the key is not found (defaults to null)
     * @return mixed The configuration value if the key exists, otherwise the default value
     */
    private function getParameter(string $key, array $configurations, mixed $default = null): mixed {
        return array_key_exists($key, $configurations) ? $configurations[$key] : $default;
    }

    /**
     * Returns the bundle alias used in Symfony configuration.
     *
     * This method returns the alias that identifies this extension in Symfony's configuration system.
     * The alias is used as the prefix in configuration files (e.g., zyos_install.yaml) and is the
     * standard way Symfony refers to bundles in its dependency injection container.
     *
     * The alias follows Symfony's naming convention: it is derived from the extension class name
     * by removing the 'Extension' suffix, converting to lowercase, and replacing camelCase with underscores.
     * In this case, 'InstallExtension' becomes 'zyos_install'.
     *
     * @return string The bundle alias 'zyos_install'
     */
    public function getAlias(): string {
        return 'zyos_install';
    }
}
