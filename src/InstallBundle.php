<?php

namespace Zyos\InstallBundle;

use LogicException;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zyos\InstallBundle\DependencyInjection\InstallExtension;

/**
 * InstallBundle
 *
 * This Symfony bundle provides installation and configuration management capabilities for the Zyos ecosystem.
 * It serves as the main entry point for integrating installation-related services, configurations, and
 * functionality into a Symfony application.
 *
 * The bundle extends the base Symfony Bundle class and provides a custom dependency injection extension
 * to handle the loading and configuration of bundle-specific services and parameters. It is designed
 * to facilitate the setup process of applications by providing structured installation workflows,
 * configuration validation, and environment-specific setup routines.
 *
 * Key responsibilities:
 * - Registering the custom dependency injection extension (InstallExtension)
 * - Providing the container extension for service configuration
 * - Exposing bundle configuration through Symfony's dependency injection container
 * - Serving as the integration point for installation-related functionality
 *
 * Usage:
 * This bundle is typically registered in the application's bundles.php file and automatically
 * loads its configuration from config/packages/zyos_install.yaml. The bundle does not require
 * manual instantiation as it is managed by the Symfony kernel.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle
 */
class InstallBundle extends Bundle {

    /**
     * Returns the bundle's container extension.
     *
     * This method overrides the parent class method to provide a custom dependency injection
     * extension specifically for the InstallBundle. The extension is responsible for loading
     * the bundle's configuration from YAML, XML, or PHP files and registering the corresponding
     * services and parameters in the Symfony dependency injection container.
     *
     * The InstallExtension handles the parsing of configuration files defined under
     * config/packages/zyos_install.yaml (or other supported formats) and processes the
     * defined settings to create and configure the necessary services for installation
     * management functionality.
     *
     * By returning a dedicated extension instance, this bundle ensures that its configuration
     * is properly isolated and follows Symfony's best practices for bundle configuration
     * management. The extension also provides validation of configuration options and
     * handles the merging of configuration from different environments (dev, prod, test).
     *
     * Method behavior:
     * - Creates a new instance of InstallExtension
     * - Returns the extension to be used by the Symfony kernel
     * - The extension will be automatically called during container compilation
     *
     * @return ExtensionInterface|null The dependency injection extension for this bundle,
     *                                 or null if the bundle does not provide a custom extension.
     *                                 In this case, it always returns a new InstallExtension instance.
     *
     * @throws LogicException Thrown if the extension cannot be instantiated due to
     *                        configuration errors or missing dependencies.
     *
     * @see InstallExtension The custom extension class that handles bundle configuration
     * @see Bundle::getContainerExtension() Parent method documentation
     */
    public function getContainerExtension(): ?ExtensionInterface {
        return new InstallExtension();
    }
}
