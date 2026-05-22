<?php

namespace Zyos\InstallBundle\Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * LockFileValidator
 *
 * Service responsible for validating the configuration and state of the lock file
 * used by the ZyosInstallBundle installation process.
 *
 * This service performs a series of critical validations before allowing the
 * installation process to continue, ensuring that:
 * - The bundle configuration contains the necessary keys for lock management
 * - The lock file does not exist for the current environment when it is configured
 *   to use lock control
 *
 * The service uses the chain validation pattern, where each individual validation
 * returns null if it passes, or a failure code if it fails. This allows stopping the
 * installation process at the first failure point encountered.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Service
 */
class LockFileValidator {

    /**
     * Configuration key that defines the list of environments requiring lock control.
     *
     * This constant represents the name of the key in the Symfony parameter container
     * that contains an array with the names of the environments (e.g.: ['prod', 'staging'])
     * for which the existence of the lock file must be verified before allowing
     * the execution of the installation process.
     *
     * The value must be defined in the bundle configuration file under the path
     * zyos_install.locks. If this key is not present, the installation process will fail
     * immediately since it cannot be determined which environments require lock control.
     *
     * @var string
     */
    private const string KEY_LOCKS = 'zyos_install.locks';

    /**
     * Configuration key that defines the path of the lock file.
     *
     * This constant represents the name of the key in the Symfony parameter container
     * that contains the absolute or relative path to the file used as a locking mechanism
     * to prevent multiple executions of the installation process in the same environment.
     *
     * The lock file is automatically created when the installation process completes
     * successfully for the environments configured in KEY_LOCKS. If this file already exists
     * when attempting to execute a new installation, the process will fail as a security measure
     * to prevent overwrites or accidental executions.
     *
     * The value must be defined in the bundle configuration file under the path
     * zyos_install.lockfile. If this key is not present, the installation process will fail
     * immediately since it cannot be determined where to create or verify the lock file.
     *
     * @var string
     */
    private const string KEY_LOCKFILE = 'zyos_install.lockfile';

    /**
     * Constructor of the LockFileValidator service.
     *
     * Initializes the service with the necessary dependencies to access the bundle
     * configuration and to perform operations on the file system.
     *
     * The service uses dependency injection through the constructor to receive:
     * - ParameterBagInterface: Provides access to the bundle configuration parameters,
     *   specifically the KEY_LOCKS and KEY_LOCKFILE keys that define the behavior
     *   of lock control.
     * - Filesystem: Provides an abstraction layer over the file system to
     *   verify the existence of the lock file independently of the underlying
     *   operating system.
     *
     * Both dependencies are marked as readonly to guarantee the immutability of the
     * service state after its construction, following best design practices in PHP 8.2+.
     *
     * @param ParameterBagInterface $parameterBag Symfony parameter container that allows
     *                                            accessing the bundle configuration, including
     *                                            the configuration keys for locks and the path
     *                                            of the lock file.
     * @param Filesystem $filesystem Symfony component that provides abstract operations
     *                               over the file system, used to verify the
     *                               existence of the lock file.
     */
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly Filesystem            $filesystem
    ) {

    }

    /**
     * Executes the complete validation of the configuration and lock file state.
     *
     * This method is the main entry point of the service and coordinates the execution of
     * all necessary validations in the correct order. It uses the null coalescing operator (??)
     * to chain the validations, so that the first validation that fails stops the process
     * and returns the corresponding error code.
     *
     * The validation order is critical and follows this logic:
     * 1. First verifies that the KEY_LOCKS configuration key is present. This is the
     *    most basic validation since without knowing which environments require locking,
     *    no other validation can proceed.
     * 2. Then verifies that the KEY_LOCKFILE configuration key is present. Without the
     *    lock file path, the final existence validation cannot be performed.
     * 3. Finally, verifies that the lock file does not exist for the current environment,
     *    but only if the environment is in the list of locked environments.
     *
     * If all validations pass, the method returns Command::SUCCESS, indicating that the
     * installation process can continue safely.
     *
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This parameter is used to
     *                            determine if the environment requires lock control according
     *                            to the configuration defined in KEY_LOCKS.
     * @param SymfonyStyle $io Symfony Console component that provides an enhanced
     *                          interface for console input/output. It is used to
     *                          display error messages when any validation fails.
     * @return int Status code indicating the validation result. Returns
     *             Command::SUCCESS (0) if all validations pass, or Command::FAILURE (1)
     *             if any validation fails. The use of standard Symfony Console return codes
     *             allows seamless integration with console commands.
     */
    public function validate(string $environment, SymfonyStyle $io): int {

        return $this->validateLocksKeyPresent($io)
            ?? $this->validateLockfileKeyPresent($io)
            ?? $this->validateLockfileNotExists($environment, $io)
            ?? Command::SUCCESS;
    }

    /**
     * Validates that the KEY_LOCKS configuration key is present in the parameter container.
     *
     * This method performs the first critical validation of the process: verifies that the
     * bundle configuration includes the 'zyos_install.locks' key that defines which environments
     * require lock control. This validation is fundamental because without this information it
     * cannot be determined if the current environment should be subject to lock file verifications.
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
     * - The exact name of the missing key
     * - Where this option must be declared in the configuration
     * - The purpose of this configuration (defining the "locks" option)
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
    private function validateLocksKeyPresent(SymfonyStyle $io): ?int {

        if ($this->parameterBag->has(self::KEY_LOCKS)) {
            return null;
        }

        $io->error(sprintf(
            'Bundle configuration key "%s" is missing. '
            . 'Ensure the "locks" option is declared in your zyos_install configuration.',
            self::KEY_LOCKS
        ));

        return Command::FAILURE;
    }

    /**
     * Validates that the KEY_LOCKFILE configuration key is present in the parameter container.
     *
     * This method performs the second critical validation of the process: verifies that the
     * bundle configuration includes the 'zyos_install.lockfile' key that defines the path of
     * the file that will be used as a locking mechanism to prevent multiple executions of the
     * installation process.
     *
     * This validation is essential because without knowing the lock file path, it is not possible
     * to perform the final validation that verifies if the file already exists. The path can be
     * absolute or relative to the project root directory, according to the user's configuration.
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
     * - The exact name of the missing key
     * - Where this option must be declared in the configuration
     * - The purpose of this configuration (defining the "lockfile" option)
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
    private function validateLockfileKeyPresent(SymfonyStyle $io): ?int {

        if ($this->parameterBag->has(self::KEY_LOCKFILE)) {
            return null;
        }

        $io->error(sprintf(
            'Bundle configuration key "%s" is missing. '
            . 'Ensure the "lockfile" option is declared in your zyos_install configuration.',
            self::KEY_LOCKFILE
        ));

        return Command::FAILURE;
    }

    /**
     * Validates that the lock file does not exist for the current environment when it requires control.
     *
     * This method performs the final and most specific validation of the process: verifies that the
     * lock file does not exist in the file system, but only for environments that are configured
     * to require lock control. This validation is the last line of defense against
     * accidental or duplicate executions of the installation process in production environments.
     *
     * The validation logic follows these steps:
     * 1. Obtains the list of environments that require lock control from the configuration
     *    (KEY_LOCKS key).
     * 2. Obtains the lock file path from the configuration (KEY_LOCKFILE key).
     * 3. Verifies if the current environment is in the list of locked environments. If it is not,
     *    returns null immediately since no validation is required for this environment.
     * 4. If the environment is locked, verifies if the lock file exists in the file system.
     *    If it does not exist, returns null since it is safe to proceed with the installation.
     * 5. If the file exists, displays a detailed error message explaining the situation
     *    and providing instructions on how to proceed, then returns Command::FAILURE.
     *
     * The error message is especially informative because this is the validation that will most
     * commonly cause failures in real scenarios. The message explains:
     * - The exact path of the existing lock file
     * - The environment for which the file exists
     * - The reason for the failure (the installation process has already been completed)
     * - How to proceed if you wish to re-run the installation (manually delete the file)
     *
     * This validation uses the exists() method of the Filesystem component, which provides
     * an operating system-independent abstraction to verify file existence.
     *
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). It is used to verify if this
     *                            environment is in the list of environments that require lock
     *                            control according to the configuration.
     * @param SymfonyStyle $io Symfony Console component used to display error messages
     *                          in the console when the validation fails. The use of SymfonyStyle
     *                          allows formatting the message with appropriate colors and styles
     *                          for errors, improving the user experience.
     * @return int|null Returns null if the validation passes (the environment does not require
     *                  locking or the file does not exist), or Command::FAILURE (1) if the
     *                  validation fails (the lock file exists for an environment that requires
     *                  control). The null return allows the main method to return Command::SUCCESS
     *                  at the end of the validation chain.
     */
    private function validateLockfileNotExists(string $environment, SymfonyStyle $io): ?int {

        $lockedEnvironments = $this->parameterBag->get(self::KEY_LOCKS);
        $lockfilePath       = $this->parameterBag->get(self::KEY_LOCKFILE);

        if (!in_array($environment, $lockedEnvironments, true)) {
            return null;
        }

        if (!$this->filesystem->exists($lockfilePath)) {
            return null;
        }

        $io->error(sprintf(
            'Lock file "%s" already exists for environment [%s]. '
            . 'The install process has already been completed for this environment. '
            . 'Remove the lock file manually if you need to re-run the installation.',
            $lockfilePath,
            $environment
        ));

        return Command::FAILURE;
    }
}
