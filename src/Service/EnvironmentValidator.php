<?php

namespace Zyos\InstallBundle\Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * EnvironmentValidator
 *
 * Service responsible for validating the environment configuration used by the ZyosInstallBundle
 * installation process.
 *
 * This service performs critical validation to ensure that the current environment is properly
 * configured and allowed for the installation process. It verifies both the presence of the
 * configuration key that defines the allowed environments and that the current environment
 * is included in that list.
 *
 * The service follows a chain validation pattern where each validation step returns null if
 * it passes, or a failure code if it fails. This allows the validation process to stop at the
 * first failure point and provide immediate feedback to the user.
 *
 * The validation process consists of two main steps:
 * 1. Verification that the environments configuration key is present in the parameter container
 * 2. Verification that the current environment is in the list of allowed environments
 *
 * This service is essential for preventing installation processes from running in unauthorized
 * or misconfigured environments, which could lead to unintended consequences in production
 * or other sensitive environments.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Service
 */
class EnvironmentValidator {

    /**
     * Configuration key that defines the list of allowed environments.
     *
     * This constant represents the name of the key in the Symfony parameter container
     * that contains an array with the names of the environments (e.g.: ['dev', 'prod', 'staging'])
     * that are authorized to execute the installation process.
     *
     * The value must be defined in the bundle configuration file under the path
     * zyos_install.environments. If this key is not present, the validation will fail
     * immediately since it cannot be determined which environments are allowed.
     *
     * The list of allowed environments serves as a security measure to ensure that the
     * installation process can only be executed in pre-approved environments, preventing
     * accidental executions in production or other sensitive environments.
     *
     * @var string
     */
    private const string KEY_ENVIRONMENTS = 'zyos_install.environments';

    /**
     * Constructor of the EnvironmentValidator service.
     *
     * Initializes the service with the necessary dependency to access the bundle
     * configuration.
     *
     * The service uses dependency injection through the constructor to receive:
     * - ParameterBagInterface: Provides access to the bundle configuration parameters,
     *   specifically the KEY_ENVIRONMENTS key that defines the list of allowed
     *   environments for the installation process.
     *
     * The dependency is marked as readonly to guarantee the immutability of the
     * service state after its construction, following best design practices in PHP 8.2+.
     *
     * @param ParameterBagInterface $parameterBag Symfony parameter container that allows
     *                                            accessing the bundle configuration, including
     *                                            the configuration key for allowed environments.
     */
    public function __construct(private readonly ParameterBagInterface $parameterBag) {

    }

    /**
     * Executes the complete validation of the environment configuration.
     *
     * This method is the main entry point of the service and coordinates the execution of
     * all necessary validations in the correct order. It uses the null coalescing operator (??)
     * to chain the validations, so that the first validation that fails stops the process
     * and returns the corresponding error code.
     *
     * The validation order is critical and follows this logic:
     * 1. First verifies that the KEY_ENVIRONMENTS configuration key is present. This is the
     *    most basic validation since without knowing which environments are allowed, no
     *    other validation can proceed.
     * 2. Then verifies that the current environment is in the list of allowed environments.
     *    This ensures that the installation process is only executed in authorized environments.
     *
     * If all validations pass, the method returns Command::SUCCESS, indicating that the
     * environment is properly configured and authorized for the installation process.
     *
     * The method returns different error codes depending on the type of failure:
     * - Command::FAILURE: Returned when the configuration key is missing, indicating a
     *   configuration error that must be fixed before proceeding.
     * - Command::INVALID: Returned when the environment is not in the allowed list, indicating
     *   that the current environment is not authorized for the installation process.
     *
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This parameter is used to
     *                            verify if the environment is in the list of allowed
     *                            environments according to the configuration.
     * @param SymfonyStyle $io Symfony Console component that provides an enhanced
     *                          interface for console input/output. It is used to
     *                          display error messages when validations fail.
     * @return int Status code indicating the validation result. Returns
     *             Command::SUCCESS (0) if all validations pass, Command::FAILURE (1)
     *             if the configuration key is missing, or Command::INVALID (2) if the
     *             environment is not in the allowed list. The use of standard Symfony
     *             Console return codes allows seamless integration with console commands.
     */
    public function validate(string $environment, SymfonyStyle $io): int {

        return $this->validateConfigurationKeyPresent($io)
            ?? $this->validateEnvironmentAllowed($environment, $io)
            ?? Command::SUCCESS;
    }

    /**
     * Validates that the environments configuration key is present in the parameter container.
     *
     * This method performs the first critical validation of the process: verifies that the
     * bundle configuration includes the 'zyos_install.environments' key that defines which
     * environments are authorized to execute the installation process. This validation is
     * fundamental because without this information it cannot be determined if the current
     * environment should be allowed to proceed.
     *
     * The validation uses the has() method of ParameterBagInterface to verify the existence
     * of the key without attempting to access its value, which avoids exceptions for missing keys.
     *
     * If the key is present, the method returns null to indicate that the validation passed and
     * allows the next validation in the chain to continue. If the key is not present, it displays
     * a detailed error message in the console indicating which key is missing and how to correct
     * the problem in the configuration, then returns Command::FAILURE to stop the process.
     *
     * The error message provides clear context to the user about:
     * - The exact name of the missing configuration key
     * - Where this option must be declared in the configuration
     * - The purpose of this configuration (defining the "environments" option)
     *
     * @param SymfonyStyle $io Symfony Console component used to display error messages
     *                          in the console when the validation fails. The use of SymfonyStyle
     *                          allows formatting the message with appropriate colors and styles
     *                          for errors, improving the user experience.
     * @return int|null Returns null if the validation passes (the key is present), or
     *                  Command::FAILURE (1) if the validation fails (the key is not present).
     *                  The null return allows the continuation of the validation chain
     *                  via the null coalescing operator.
     */
    private function validateConfigurationKeyPresent(SymfonyStyle $io): ?int {

        if ($this->parameterBag->has(self::KEY_ENVIRONMENTS)) {
            return null;
        }

        $io->error(sprintf(
            'Bundle configuration key "%s" is missing. '
            . 'Ensure the "environments" option is declared in your zyos_install configuration.',
            self::KEY_ENVIRONMENTS
        ));

        return Command::FAILURE;
    }

    /**
     * Validates that the current environment is in the list of allowed environments.
     *
     * This method performs the second validation of the process: verifies that the current
     * environment is included in the list of environments configured in KEY_ENVIRONMENTS.
     * This validation is essential for ensuring that the installation process is only executed
     * in pre-approved environments, preventing accidental executions in unauthorized environments.
     *
     * The method retrieves the list of allowed environments from the configuration and uses
     * the in_array() function with strict type comparison (true as the third parameter) to
     * ensure that the environment name matches exactly in both value and type, preventing
     * potential issues with loose type comparisons.
     *
     * If the environment is in the allowed list, the method returns null to indicate that
     * the validation passed and allows the main method to return Command::SUCCESS. If the
     * environment is not in the allowed list, it displays a detailed error message in the
     * console that includes:
     * - The name of the environment that was not found
     * - The complete list of allowed environments for reference
     * - Instructions on where to check the configuration
     *
     * The method then returns Command::INVALID to indicate that the environment is not
     * authorized for the installation process. This specific return code distinguishes
     * this type of failure from a missing configuration key, allowing the calling code
     * to handle different error scenarios appropriately.
     *
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This value is compared against
     *                            the list of allowed environments configured in KEY_ENVIRONMENTS.
     * @param SymfonyStyle $io Symfony Console component used to display error messages
     *                          in the console when the validation fails. The use of SymfonyStyle
     *                          allows formatting the message with appropriate colors and styles
     *                          for errors, improving the user experience.
     * @return int|null Returns null if the validation passes (the environment is in the allowed
     *                  list), or Command::INVALID (2) if the validation fails (the environment
     *                  is not in the allowed list). The null return allows the main method to
     *                  return Command::SUCCESS at the end of the validation chain.
     */
    private function validateEnvironmentAllowed(string $environment, SymfonyStyle $io): ?int {

        $allowedEnvironments = $this->parameterBag->get(self::KEY_ENVIRONMENTS);

        if (in_array($environment, $allowedEnvironments, true)) {
            return null;
        }

        $io->error(sprintf(
            'Environment [%s] is not declared in the allowed list. '
            . 'Allowed environments: [%s]. '
            . 'Check the "environments" option in your zyos_install configuration.',
            $environment,
            implode(', ', $allowedEnvironments)
        ));

        return Command::INVALID;
    }
}
