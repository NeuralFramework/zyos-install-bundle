<?php

namespace Zyos\InstallBundle\DependencyInjection;

use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration class for the ZyosInstallBundle.
 *
 * This class defines the configuration tree structure for the bundle, allowing developers to configure
 * various aspects of the installation and deployment process through Symfony's configuration system.
 * It implements the ConfigurationInterface to provide a standardized way of defining configuration options.
 *
 * The configuration includes settings for:
 * - Base path for installation resources
 * - Environments where the bundle should be active
 * - Environments that should be locked after installation
 * - Symfony commands to execute during deployment
 * - Filesystem validation rules
 * - Filesystem operations (mirror, symlink, directory creation)
 * - CLI command execution
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\DependencyInjection
 */
class Configuration implements ConfigurationInterface {

    /**
     * Default path for the installation resources directory.
     *
     * This constant defines the default location where the bundle will look for installation-related
     * resources such as configuration files, templates, and other assets. The path uses Symfony's
     * kernel.project_dir parameter to ensure it resolves correctly regardless of the project structure.
     *
     * The default path points to the src/Resources/zyos-install-bundle directory within the project,
     * which is the standard location for bundle-specific resources in a Symfony application.
     *
     * @var string
     */
    private const string DEFAULT_PATH = '%kernel.project_dir%/src/Resources/zyos-install-bundle';

    /**
     * Default environments where the bundle should be active.
     *
     * This constant defines the default list of Symfony environments in which the ZyosInstallBundle
     * should execute its installation and validation processes. By default, the bundle is configured
     * to run in both development (dev) and production (prod) environments.
     *
     * These environments can be overridden in the bundle's configuration file (zyos_install.yaml)
     * to customize where the bundle should be active. The bundle will skip all operations in
     * environments not included in this list.
     *
     * @var array<string>
     */
    private const array DEFAULT_ENVIRONMENTS = ['dev', 'prod'];

    /**
     * Default environments that should be locked after installation.
     *
     * This constant defines the default list of Symfony environments that should be locked after
     * the installation process completes. Locking an environment prevents further installation
     * operations from running, which is a security measure to ensure that production deployments
     * cannot be accidentally modified after the initial setup.
     *
     * By default, only the production (prod) environment is locked. This can be customized in the
     * bundle's configuration to include additional environments if needed.
     *
     * @var array<string>
     */
    private const array DEFAULT_LOCKS = ['prod'];

    /**
     * Builds and returns the configuration tree builder for the bundle.
     *
     * This method is the main entry point for defining the configuration structure of the ZyosInstallBundle.
     * It creates a TreeBuilder instance with the root node name 'zyos_install' and then calls various
     * private methods to define each section of the configuration tree.
     *
     * The configuration tree is built by calling the following methods in order:
     * - path(): Defines the base path for installation resources
     * - environments(): Defines the environments where the bundle is active
     * - locks(): Defines the environments that should be locked after installation
     * - install(): Defines Symfony commands to execute during deployment
     * - validate(): Defines filesystem validation rules
     * - filesystem(): Defines filesystem operations (mirror, symlink, directory)
     * - cliCommand(): Defines CLI command execution settings
     *
     * @return TreeBuilder The configured tree builder instance containing all configuration nodes
     */
    public function getConfigTreeBuilder(): TreeBuilder {

        $treeBuilder = new TreeBuilder('zyos_install');
        $rootNode    = $treeBuilder->getRootNode();

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
     * Defines the configuration node for the base installation path.
     *
     * This method configures the 'path' node in the configuration tree, which specifies the directory
     * where the bundle will look for installation-related resources. The path can be customized in the
     * bundle's configuration file, and if not provided or left empty, it defaults to the value defined
     * in the DEFAULT_PATH constant.
     *
     * The configuration node includes:
     * - A scalar node named 'path'
     * - Normalization logic that sets the default path if the value is empty
     * - A default value of '%kernel.project_dir%/src/Resources/zyos-install-bundle'
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the path node will be added
     * @return void
     */
    private function path(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->scalarNode('path')
                ->info('Configuration path')
                ->beforeNormalization()
                    ->ifEmpty()->then(fn() => self::DEFAULT_PATH)
                ->end()
                ->defaultValue(self::DEFAULT_PATH)
                ->end()
            ->end();
    }

    /**
     * Defines the configuration node for execution environments.
     *
     * This method configures the 'environments' node in the configuration tree, which specifies the
     * Symfony environments where the ZyosInstallBundle should be active and execute its installation
     * and validation processes. The bundle will skip all operations in environments not included in this list.
     *
     * The configuration node includes:
     * - An array node named 'environments' containing environment names (e.g., 'dev', 'prod', 'test')
     * - Normalization logic that ensures 'prod' is always included in the environment list
     * - Support for both string (single environment) and array (multiple environments) input
     * - A default value of ['dev', 'prod']
     * - Requirement that at least one environment must be specified
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the environments node will be added
     * @return void
     */
    private function environments(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->arrayNode('environments')
                    ->info('Execution environments')
                    ->beforeNormalization()
                        ->always(fn($v) => $this->normalizeEnvironmentArray($v, self::DEFAULT_ENVIRONMENTS))
                    ->end()
                    ->prototype('scalar')->end()
                    ->defaultValue(self::DEFAULT_ENVIRONMENTS)
                    ->requiresAtLeastOneElement()
                ->end()
            ->end();
    }

    /**
     * Defines the configuration node for environment locking.
     *
     * This method configures the 'locks' node in the configuration tree, which specifies the Symfony
     * environments that should be locked after the installation process completes. Locking an environment
     * prevents further installation operations from running, which is a security measure to ensure that
     * production deployments cannot be accidentally modified after the initial setup.
     *
     * The configuration node includes:
     * - An array node named 'locks' containing environment names to lock
     * - Normalization logic that ensures 'prod' is always included in the lock list
     * - Support for both string (single environment) and array (multiple environments) input
     * - A default value of ['prod']
     * - Requirement that at least one environment must be specified
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the locks node will be added
     * @return void
     */
    private function locks(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->arrayNode('locks')
                    ->info('Environments to be locked after installation')
                    ->beforeNormalization()
                        ->always(fn($v) => $this->normalizeEnvironmentArray($v, self::DEFAULT_LOCKS))
                    ->end()
                    ->prototype('scalar')->end()
                    ->defaultValue(self::DEFAULT_LOCKS)
                    ->requiresAtLeastOneElement()
                ->end()
            ->end();
    }

    /**
     * Defines the configuration node for Symfony command execution during deployment.
     *
     * This method configures the 'install' node in the configuration tree, which specifies the Symfony
     * console commands that should be executed during the application deployment process. This allows
     * developers to automate common deployment tasks such as cache clearing, database migrations, asset
     * compilation, and other maintenance operations.
     *
     * Each command entry can be configured with:
     * - command: The Symfony console command to execute (e.g., 'cache:clear', 'doctrine:migrations:migrate')
     * - arguments: An array of command arguments and options
     * - priority: Execution order (lower values execute first, default: 1)
     * - enable: Whether the command should be executed (default: true)
     * - if_error: Error handling strategy ('none' to continue, 'stop' to halt, default: 'stop')
     * - environments: Array of environments where this command should run
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the install node will be added
     * @return void
     */
    private function install(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->arrayNode('install')
                    ->info('Symfony commands executed during application deployment')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('command')->defaultNull()->end()
                            ->append($this->buildEmptyableArrayNode('arguments'))
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
     * Defines the configuration node for filesystem validation rules.
     *
     * This method configures the 'validate' node in the configuration tree, which specifies validation
     * rules for paths, files, and other filesystem entries. These validations ensure that required
     * files and directories exist and meet specific criteria before the installation process proceeds.
     *
     * Each validation entry can be configured with:
     * - filepath: The path to the filesystem entry to validate (required)
     * - type: The type of entry to validate ('directory', 'file', 'request', 'custom', required)
     * - enable: Whether this validation should be executed (default: true)
     * - environments: Array of environments where this validation should run
     * - validations: Array of specific validation rules to apply, each with:
     *   - name: The name of the validation rule
     *   - parameters: Array of parameters for the validation rule
     *
     * The validations array supports both string shorthand (just the rule name) and array format
     * with parameters for more complex configurations.
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the validate node will be added
     * @return void
     */
    private function validate(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->arrayNode('validate')
                    ->info('Validate paths, files, and other filesystem entries')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('filepath')->isRequired()->end()
                            ->enumNode('type')->isRequired()->values(['directory', 'file', 'request', 'custom'])->end()
                            ->booleanNode('enable')->defaultTrue()->end()
                            ->append($this->getEnvironments())
                            ->arrayNode('validations')
                                ->arrayPrototype()
                                    ->beforeNormalization()
                                        ->ifString()->then(fn($v) => ['name' => $v])
                                    ->end()
                                    ->children()
                                        ->scalarNode('name')->defaultNull()->end()
                                        ->append($this->buildEmptyableArrayNode('parameters'))
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Defines the configuration node for filesystem operations.
     *
     * This method configures the 'filesystem' node in the configuration tree, which specifies filesystem
     * operations to perform during the installation process. These operations include mirroring directories,
     * creating symbolic links, and creating directories. This allows developers to automate the setup of
     * required filesystem structures as part of the deployment process.
     *
     * Each filesystem entry can be configured with:
     * - name: A descriptive name for the filesystem operation
     * - source: The source path for the operation (required for 'mirror' and 'symlink' types)
     * - destination: The destination path for the operation (required for 'mirror' and 'symlink' types)
     * - enable: Whether this operation should be executed (default: true)
     * - priority: Execution order (lower values execute first, default: 1)
     * - type: The type of operation ('mirror', 'symlink', 'directory', required)
     * - if_error: Error handling strategy ('none' to continue, 'stop' to halt, default: 'stop')
     * - environments: Array of environments where this operation should run
     *
     * The configuration includes validation logic to ensure that:
     * - 'directory' type requires 'source' but not 'destination'
     * - 'mirror' and 'symlink' types require both 'source' and 'destination'
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the filesystem node will be added
     * @return void
     */
    private function filesystem(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->arrayNode('filesystem')
                    ->beforeNormalization()
                        ->ifEmpty()->then(fn() => [])
                        ->ifString()->then(fn($v) => empty($v) ? [] : [$v])
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->defaultNull()->end()
                            ->scalarNode('source')->defaultNull()->end()
                            ->scalarNode('destination')->defaultNull()->end()
                            ->booleanNode('enable')->defaultTrue()->end()
                            ->integerNode('priority')->defaultValue(1)->end()
                            ->enumNode('type')->isRequired()->values(['mirror', 'symlink', 'directory'])->end()
                            ->enumNode('if_error')->values(['none', 'stop'])->defaultValue('stop')->end()
                            ->append($this->getEnvironments())
                        ->end()
                        ->validate()
                            ->always(fn($v) => $this->validateFilesystemEntry($v))
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Defines the configuration node for CLI command execution.
     *
     * This method configures the 'cli' node in the configuration tree, which specifies shell commands
     * that should be executed during the installation process. This allows developers to run arbitrary
     * shell commands as part of the deployment workflow, providing flexibility for operations that are
     * not covered by Symfony console commands.
     *
     * Each CLI command entry can be configured with:
     * - command: The shell command to execute (supports both string and array format)
     * - enable: Whether this command should be executed (default: true)
     * - priority: Execution order (lower values execute first, default: 1)
     * - environments: Array of environments where this command should run
     * - if_error: Error handling strategy ('none' to continue, 'stop' to halt, default: 'stop')
     *
     * The command node supports flexible input formats:
     * - Empty values are converted to empty arrays
     * - String values are converted to single-element arrays
     * - Array values are used as-is
     *
     * @param ArrayNodeDefinition $rootNode The root node of the configuration tree to which the cli node will be added
     * @return void
     */
    private function cliCommand(ArrayNodeDefinition $rootNode): void {

        $rootNode
            ->children()
                ->arrayNode('cli')
                ->beforeNormalization()
                    ->ifEmpty()->then(fn() => [])
                    ->ifString()->then(fn($v) => empty($v) ? [] : [$v])
                ->end()
                ->arrayPrototype()
                    ->children()
                        ->arrayNode('command')
                            ->addDefaultsIfNotSet()
                            ->normalizeKeys(false)
                            ->ignoreExtraKeys(false)
                            ->beforeNormalization()
                                ->castToArray()
                                    ->ifEmpty()->then(fn() => [])
                                    ->ifString()->then(fn($v) => empty($v) ? [] : [$v])
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
     * Builds and returns a reusable environments node definition.
     *
     * This method creates a reusable configuration node for specifying environments where a particular
     * configuration entry should be active. This node is used in multiple places throughout the configuration
     * tree (install, validate, filesystem, cli) to provide consistent environment filtering across all
     * bundle operations.
     *
     * The environments node includes:
     * - Normalization logic that converts empty values to empty arrays
     * - Support for string (single environment) to array conversion
     * - Scalar prototype for environment names
     * - Validation that prevents empty environment arrays
     * - Requirement that at least one environment must be specified
     *
     * @return ArrayNodeDefinition|NodeDefinition The configured environments node definition
     */
    private function getEnvironments(): ArrayNodeDefinition|NodeDefinition {

        $node = new TreeBuilder('environments')->getRootNode();

        $node
            ->info('Environments where this entry is active')
            ->beforeNormalization()
                ->ifEmpty()->then(fn() => [])
                ->ifString()->then(fn($v) => empty($v) ? [] : [$v])
            ->end()
            ->prototype('scalar')->end()
            ->cannotBeEmpty()
            ->isRequired()
            ->requiresAtLeastOneElement();

        return $node;
    }

    /**
     * Builds and returns a reusable emptyable array node definition.
     *
     * This method creates a reusable configuration node for array values that can be empty.
     * This node is used in multiple places throughout the configuration tree (arguments, parameters)
     * to provide consistent handling of array configuration options that may be empty or omitted.
     *
     * The emptyable array node includes:
     * - Default value setting if not explicitly configured
     * - Key normalization disabled to preserve original keys
     * - Extra keys allowed (not ignored)
     * - Automatic casting to array format
     * - Normalization that converts empty values to empty arrays
     *
     * @param string $name The name of the array node to create
     * @return ArrayNodeDefinition|NodeDefinition The configured emptyable array node definition
     */
    private function buildEmptyableArrayNode(string $name): ArrayNodeDefinition|NodeDefinition {

        $node = new TreeBuilder($name)->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->normalizeKeys(false)
            ->ignoreExtraKeys(false)
            ->beforeNormalization()
            ->castToArray()
            ->ifEmpty()->then(fn() => [])
            ->end();

        return $node;
    }

    /**
     * Validates a filesystem entry configuration based on its type.
     *
     * This method performs validation logic on filesystem configuration entries to ensure that
     * the required fields are present based on the operation type. It enforces different validation
     * rules for different filesystem operation types to prevent misconfiguration.
     *
     * Validation rules by type:
     * - 'directory': Requires 'source' field, forbids 'destination' field
     * - 'mirror': Requires both 'source' and 'destination' fields
     * - 'symlink': Requires both 'source' and 'destination' fields
     *
     * The method uses helper assertion methods to check each condition and throws
     * InvalidArgumentException with descriptive error messages when validation fails.
     *
     * @param array $entry The filesystem entry configuration to validate
     * @return array The validated filesystem entry (unchanged if validation passes)
     * @throws InvalidArgumentException When the entry configuration is invalid for its type
     */
    private function validateFilesystemEntry(array $entry): array {

        $type        = $entry['type'];
        $hasSource      = !empty($entry['source']);
        $hasDestination = !empty($entry['destination']);

        if ($type === 'directory') {
            $this->assertSourcePresent($hasSource, $type);
            $this->assertDestinationAbsent($hasDestination, $type);
        }

        if (in_array($type, ['symlink', 'mirror'], true)) {
            $this->assertBothPathsPresent($hasSource, $hasDestination, $type);
        }

        return $entry;
    }

    /**
     * Asserts that the source field is present for a filesystem entry.
     *
     * This method validates that the 'source' field is present and non-empty for filesystem
     * entries that require it. This is used to enforce configuration requirements for specific
     * filesystem operation types.
     *
     * @param bool $hasSource Whether the source field is present and non-empty
     * @param string $type The filesystem operation type being validated
     * @return void
     * @throws InvalidArgumentException When the source field is not present but is required
     */
    private function assertSourcePresent(bool $hasSource, string $type): void {

        if (!$hasSource) {
            throw new InvalidArgumentException(
                sprintf('The "source" option is required when "type" is "%s".', $type)
            );
        }
    }

    /**
     * Asserts that the destination field is absent for a filesystem entry.
     *
     * This method validates that the 'destination' field is not present for filesystem
     * entries that should not have it. This is used to enforce configuration restrictions
     * for specific filesystem operation types where a destination path is not applicable.
     *
     * @param bool $hasDestination Whether the destination field is present and non-empty
     * @param string $type The filesystem operation type being validated
     * @return void
     * @throws InvalidArgumentException When the destination field is present but should not be
     */
    private function assertDestinationAbsent(bool $hasDestination, string $type): void {

        if ($hasDestination) {
            throw new InvalidArgumentException(
                sprintf('The "destination" option cannot be used when "type" is "%s".', $type)
            );
        }
    }

    /**
     * Asserts that both source and destination fields are present for a filesystem entry.
     *
     * This method validates that both the 'source' and 'destination' fields are present and
     * non-empty for filesystem entries that require them. This is used to enforce configuration
     * requirements for filesystem operation types that need both a source and destination path.
     *
     * @param bool $hasSource Whether the source field is present and non-empty
     * @param bool $hasDestination Whether the destination field is present and non-empty
     * @param string $type The filesystem operation type being validated
     * @return void
     * @throws InvalidArgumentException When either source or destination field is missing
     */
    private function assertBothPathsPresent(bool $hasSource, bool $hasDestination, string $type): void {

        if (!$hasSource || !$hasDestination) {
            throw new InvalidArgumentException(
                sprintf('Both "source" and "destination" options are required when "type" is "%s".', $type)
            );
        }
    }

    /**
     * Normalizes an environment configuration value to an array format.
     *
     * This method handles the normalization of environment configuration values, which can be
     * provided in various formats (string, array, or null). It ensures that the final result is
     * always an array of environment names with 'prod' included as a safety measure.
     *
     * Normalization logic:
     * - If the value is a string, it delegates to normalizeStringEnvironment()
     * - If the value is an array, it delegates to ensureProdIncluded()
     * - If the value is neither (e.g., null), it returns the default array
     *
     * This method is used by both the 'environments' and 'locks' configuration nodes to ensure
     * consistent handling of environment lists throughout the configuration.
     *
     * @param mixed $value The environment configuration value to normalize (string, array, or other)
     * @param array $default The default array to return if the value is not a string or array
     * @return array The normalized array of environment names
     */
    private function normalizeEnvironmentArray(mixed $value, array $default): array {

        if (is_string($value)) {
            return $this->normalizeStringEnvironment($value);
        }

        if (is_array($value)) {
            return $this->ensureProdIncluded($value);
        }

        return $default;
    }

    /**
     * Normalizes a single environment string to an array with 'prod' included.
     *
     * This method converts a single environment name string into an array format,
     * ensuring that 'prod' is always included. This is a safety measure to prevent
     * accidental exclusion of the production environment from bundle operations.
     *
     * Normalization logic:
     * - If the value is 'prod', returns ['prod']
     * - If the value is any other environment name, returns ['prod', value]
     *
     * This ensures that production environment is always included in the environment
     * list, which is critical for deployment safety and preventing misconfiguration.
     *
     * @param string $value The environment name to normalize
     * @return array The normalized array of environment names with 'prod' included
     */
    private function normalizeStringEnvironment(string $value): array {
        return $value === 'prod' ? [$value] : ['prod', $value];
    }

    /**
     * Ensures that the 'prod' environment is included in the environments array.
     *
     * This method checks if the production environment ('prod') is present in the
     * provided environments array. If it is not present, it prepends 'prod' to the
     * array to ensure that production environment operations are always included.
     *
     * This is a critical safety measure to prevent accidental exclusion of the
     * production environment from bundle operations, which could lead to incomplete
     * deployments or missing critical setup steps in production.
     *
     * @param array $environments The array of environment names to check
     * @return array The environments array with 'prod' guaranteed to be included
     */
    private function ensureProdIncluded(array $environments): array {

        return in_array('prod', $environments, true)
            ? $environments
            : array_merge(['prod'], $environments);
    }
}
