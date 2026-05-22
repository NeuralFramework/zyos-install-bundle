<?php

namespace Zyos\InstallBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Zyos\InstallBundle\ParameterBag;

/**
 * ConfigurationValidator
 *
 * Service responsible for validating and filtering configuration entries used by the ZyosInstallBundle
 * installation process.
 *
 * This service handles the validation and filtering of configuration entries based on environment
 * and enablement status. It ensures that only configuration entries that are relevant to the current
 * environment and explicitly enabled are processed during the installation.
 *
 * The service follows a chain validation pattern where each validation step returns null if
 * it should continue to the next step, or a non-null ParameterBag to signal that the process
 * should stop. This pattern allows for early termination when configuration is missing or when
 * no relevant entries are found.
 *
 * The validation and filtering process consists of the following steps:
 * 1. Verification that the configuration key exists in the parameter container
 * 2. Filtering of configuration entries to include only those applicable to the current environment
 * 3. Optional filtering of enabled entries to process only active configurations
 *
 * This service is essential for ensuring that the installation process only operates on
 * configuration entries that are explicitly configured for the current environment and enabled
 * by the administrator, preventing unintended operations in production or other sensitive environments.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Service
 */
class ConfigurationValidator {

    /**
     * Field name that defines the list of environments for a configuration entry.
     *
     * This constant represents the name of the field in each configuration entry array
     * that contains an array of environment names (e.g.: ['dev', 'prod', 'staging'])
     * for which the configuration entry is applicable.
     *
     * The field is used during the filtering process to determine which configuration
     * entries should be included based on the current environment. Only entries that
     * include the current environment in their environments array will be processed.
     *
     * This field is mandatory for all configuration entries. If a configuration entry
     * does not include this field or the field is empty, the entry will be excluded from
     * processing for all environments.
     *
     * @var string
     */
    private const string FIELD_ENVIRONMENTS = 'environments';

    /**
     * Field name that defines the enablement status of a configuration entry.
     *
     * This constant represents the name of the field in each configuration entry array
     * that contains a boolean value indicating whether the entry is enabled (true) or
     * disabled (false).
     *
     * The field is used during the filtering process to determine which configuration
     * entries should be processed. Only entries with the enable field set to true will
     * be included in the final filtered result.
     *
     * This field allows administrators to temporarily disable specific configuration
     * entries without removing them from the configuration file, providing a convenient
     * way to manage the installation process without modifying the configuration structure.
     *
     * @var string
     */
    private const string FIELD_ENABLE = 'enable';

    /**
     * Constructor of the ConfigurationValidator service.
     *
     * Initializes the service with the necessary dependency to access the bundle
     * configuration.
     *
     * The service uses dependency injection through the constructor to receive:
     * - ParameterBagInterface: Provides access to the bundle configuration parameters,
     *   allowing the service to retrieve configuration entries by their keys.
     *
     * The dependency is marked as readonly to guarantee the immutability of the
     * service state after its construction, following best design practices in PHP 8.2+.
     *
     * @param ParameterBagInterface $parameterBag Symfony parameter container that allows
     *                                            accessing the bundle configuration,
     *                                            including all configuration keys and
     *                                            their associated entries.
     */
    public function __construct(private readonly ParameterBagInterface $parameterBag) {

    }

    /**
     * Validates and filters configuration entries for the specified environment.
     *
     * This method is the main entry point for configuration validation and performs
     * the initial filtering of configuration entries based on the current environment.
     *
     * The method follows a chain validation pattern using the null coalescing operator (??):
     * 1. First calls loadConfigurationKey() to verify that the configuration key exists.
     *    If the key does not exist, an error is displayed and an empty ParameterBag is
     *    returned to signal that the process should stop.
     * 2. If the key exists, calls filterByEnvironment() to filter the configuration entries
     *    to include only those applicable to the current environment.
     *
     * The method returns a ParameterBag containing the filtered configuration entries,
     * or null if no entries are found for the current environment. The null return value
     * is used to signal that there are no relevant entries to process, allowing the
     * calling code to handle this scenario appropriately (e.g., display a success message
     * and exit gracefully).
     *
     * This method does not filter by enablement status; that is handled separately by
     * the filterEnabled() method, allowing for a two-stage filtering process where
     * environment filtering is performed first, followed by enablement filtering.
     *
     * @param string $configKey The configuration key to retrieve from the parameter container.
     *                          This key corresponds to a specific configuration section in the
     *                          bundle configuration (e.g., 'zyos_install.commands', 'zyos_install.files').
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This parameter is used to filter
     *                            configuration entries based on their FIELD_ENVIRONMENTS field.
     * @param SymfonyStyle $io Symfony Console component that provides an enhanced
     *                          interface for console input/output. It is used to
     *                          display error and success messages during the validation process.
     * @return ParameterBag|null Returns a ParameterBag containing the configuration entries
     *                          filtered for the current environment, or null if no entries are
     *                          found for the environment or if the configuration key is missing.
     *                          The null return value signals that there are no relevant entries
     *                          to process.
     */
    public function validate(string $configKey, string $environment, SymfonyStyle $io): ?ParameterBag {

        return $this->loadConfigurationKey($configKey, $io)
            ?? $this->filterByEnvironment(
                new ParameterBag($this->parameterBag->get($configKey)),
                $environment,
                $io
            );
    }

    /**
     * Filters configuration entries to include only enabled entries.
     *
     * This method performs a secondary filtering operation on a ParameterBag of configuration
     * entries, retaining only those entries that have the enable field set to true. This allows
     * administrators to control which configuration entries are processed without removing them
     * from the configuration file.
     *
     * The method uses the filter() method of the ParameterBag with a closure that checks the
     * FIELD_ENABLE field of each entry. Only entries where the enable field is exactly true
     * (using strict equality) are included in the filtered result.
     *
     * If no enabled entries are found, the method displays a success message indicating that
     * there are no active entries to run for the current environment and returns null. This
     * allows the calling code to handle this scenario gracefully, typically by exiting the
     * installation process with a success status.
     *
     * This method is designed to be called after the validate() method, which performs the
     * initial environment-based filtering. The combination of both methods provides a two-stage
     * filtering process that ensures only relevant and enabled configuration entries are processed.
     *
     * @param ParameterBag $parameters A ParameterBag containing configuration entries that have
     *                                 already been filtered by environment (typically from the
     *                                 validate() method). This ParameterBag should contain entries
     *                                 with the FIELD_ENABLE field.
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This parameter is used in the
     *                            success message when no enabled entries are found.
     * @param SymfonyStyle $io Symfony Console component that provides an enhanced
     *                          interface for console input/output. It is used to
     *                          display success messages when no enabled entries are found.
     * @return ParameterBag|null Returns a ParameterBag containing only the enabled configuration
     *                          entries, or null if no enabled entries are found. The null return
     *                          value signals that there are no active entries to process.
     */
    public function filterEnabled(ParameterBag $parameters, string $environment, SymfonyStyle $io): ?ParameterBag {

        $enabled = $parameters->filter(
            fn(array $entry) => $entry[self::FIELD_ENABLE] === true
        );

        if ($enabled->count() === 0) {
            $io->success(sprintf('No active entries to run for environment [%s].', $environment));
            return null;
        }

        return $enabled;
    }

    /**
     * Validates that the configuration key exists in the parameter container.
     *
     * This method performs the first validation step in the chain: verifies that the specified
     * configuration key exists in the Symfony parameter container. This validation is fundamental
     * because without the configuration key, no configuration entries can be retrieved or processed.
     *
     * The method uses the has() method of ParameterBagInterface to verify the existence of the
     * key without attempting to access its value, which avoids exceptions for missing keys.
     *
     * If the key is present, the method returns null to indicate that the validation passed and
     * allows the next step in the chain (filterByEnvironment) to proceed. If the key is not
     * present, the method displays a detailed error message in the console indicating which key
     * is missing and how to correct the problem in the configuration.
     *
     * When the key is not present, the method returns an empty ParameterBag (new ParameterBag([]))
     * instead of null. This is a deliberate design choice to signal that the validation process
     * should stop. In the chain pattern used by the validate() method, a non-null return value
     * from this step prevents the next step from being executed, effectively stopping the process.
     *
     * The error message provides clear context to the user about:
     * - The exact name of the missing configuration key
     * - Where this key should be declared in the bundle configuration
     *
     * @param string $configKey The configuration key to verify in the parameter container.
     *                          This key corresponds to a specific configuration section in the
     *                          bundle configuration (e.g., 'zyos_install.commands', 'zyos_install.files').
     * @param SymfonyStyle $io Symfony Console component used to display error messages
     *                          in the console when the configuration key is not found. The use of
     *                          SymfonyStyle allows formatting the message with appropriate colors
     *                          and styles for errors, improving the user experience.
     * @return ParameterBag|null Returns null if the configuration key is present (indicating
     *                          that the validation passed and the process should continue), or an
     *                          empty ParameterBag if the key is not present (indicating that the
     *                          process should stop). The empty non-null ParameterBag is used as a
     *                          signal in the chain pattern to prevent further processing.
     */
    private function loadConfigurationKey(string $configKey, SymfonyStyle $io): ?ParameterBag {

        if ($this->parameterBag->has($configKey)) {
            return null;
        }

        $io->error(sprintf(
            'Configuration key "%s" could not be found. '
            . 'Ensure it is declared in your zyos_install bundle configuration.',
            $configKey
        ));

        return new ParameterBag([]);
    }

    /**
     * Filters configuration entries to include only those applicable to the specified environment.
     *
     * This method performs the second validation step in the chain: filters a ParameterBag of
     * configuration entries to include only those that have the current environment in their
     * FIELD_ENVIRONMENTS array. This ensures that only configuration entries explicitly configured
     * for the current environment are processed.
     *
     * The method follows a defensive approach:
     * 1. First checks if the ParameterBag is empty (count === 0). If it is, displays a success
     *    message indicating that no configuration entries were found and returns null.
     * 2. If the ParameterBag is not empty, uses the filter() method with a closure that checks
     *    the FIELD_ENVIRONMENTS field of each entry using in_array() with strict type comparison.
     *    This ensures that the environment name matches exactly in both value and type.
     * 3. If the filtered result is empty (no entries match the environment), displays a success
     *    message indicating that no entries were found for the specific environment and returns null.
     * 4. If the filtered result contains entries, returns the filtered ParameterBag for further
     *    processing.
     *
     * The use of strict type comparison (true as the third parameter of in_array) is important
     * to prevent potential issues with loose type comparisons that could lead to unexpected behavior.
     *
     * This method is designed to be called after loadConfigurationKey() in the chain pattern.
     * The null return values are used to signal that there are no relevant entries to process,
     * allowing the calling code to handle this scenario gracefully.
     *
     * @param ParameterBag $allEntries A ParameterBag containing all configuration entries for a
     *                                 specific configuration key. This ParameterBag should contain
     *                                 entries with the FIELD_ENVIRONMENTS field.
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This parameter is used to filter
     *                            configuration entries based on their FIELD_ENVIRONMENTS field.
     * @param SymfonyStyle $io Symfony Console component used to display success messages
     *                          when no configuration entries are found. The use of SymfonyStyle
     *                          allows formatting the message with appropriate colors and styles,
     *                          improving the user experience.
     * @return ParameterBag|null Returns a ParameterBag containing only the configuration entries
     *                          applicable to the current environment, or null if no entries are
     *                          found for the environment or if the input ParameterBag is empty.
     *                          The null return value signals that there are no relevant entries
     *                          to process.
     */
    private function filterByEnvironment(ParameterBag $allEntries, string $environment, SymfonyStyle $io): ?ParameterBag {

        if ($allEntries->count() === 0) {
            $io->success('No configuration entries found.');
            return null;
        }

        $forEnvironment = $allEntries->filter(
            fn(array $entry) => in_array($environment, $entry[self::FIELD_ENVIRONMENTS], true)
        );

        if ($forEnvironment->count() === 0) {
            $io->success(sprintf('No configuration entries found for environment [%s].', $environment));
            return null;
        }

        return $forEnvironment;
    }
}
