<?php

namespace Zyos\InstallBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * LockFileCreator
 *
 * Service responsible for creating the lock file used by the ZyosInstallBundle installation process.
 *
 * This service handles the creation of the lock file that prevents multiple executions of the
 * installation process in the same environment. The lock file is created only for environments
 * that are configured to require lock control, and only if the file does not already exist.
 *
 * The service follows a defensive approach by performing several checks before attempting to
 * create the lock file:
 * - Verifies that the current environment requires lock control
 * - Checks that the lock file does not already exist
 * - Ensures that the base directory path exists before attempting to create the file
 * - Handles potential I/O exceptions gracefully with informative error messages
 *
 * The service uses the short-circuit evaluation pattern (&&) to ensure that the base path
 * is created successfully before attempting to write the lock file. If any step fails,
 * the process stops and an error message is displayed to the user.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Service
 */
class LockFileCreator {

    /**
     * Configuration key that defines the list of environments requiring lock control.
     *
     * This constant represents the name of the key in the Symfony parameter container
     * that contains an array with the names of the environments (e.g.: ['prod', 'staging'])
     * for which the lock file must be created after a successful installation.
     *
     * The value must be defined in the bundle configuration file under the path
     * zyos_install.locks. If this key is not present or the current environment is not
     * in the list, the lock file will not be created.
     *
     * @var string
     */
    private const string KEY_LOCKS = 'zyos_install.locks';

    /**
     * Configuration key that defines the path of the lock file.
     *
     * This constant represents the name of the key in the Symfony parameter container
     * that contains the absolute or relative path to the lock file that will be created
     * to prevent multiple executions of the installation process in the same environment.
     *
     * The lock file is created using the touch() method of the Filesystem component,
     * which creates an empty file. The existence of this file serves as a flag indicating
     * that the installation process has already been completed for the current environment.
     *
     * The value must be defined in the bundle configuration file under the path
     * zyos_install.lockfile. If this key is not present, the service will fail when
     * attempting to create the lock file.
     *
     * @var string
     */
    private const string KEY_LOCKFILE = 'zyos_install.lockfile';

    /**
     * Configuration key that defines the base path for installation files.
     *
     * This constant represents the name of the key in the Symfony parameter container
     * that contains the absolute or relative path to the directory where installation-related
     * files will be stored, including the lock file.
     *
     * This path is used to ensure that the directory structure exists before attempting
     * to create the lock file. If the directory does not exist, the service will attempt
     * to create it using the mkdir() method of the Filesystem component.
     *
     * The value must be defined in the bundle configuration file under the path
     * zyos_install.path. If this key is not present, the service will fail when
     * attempting to verify or create the directory.
     *
     * @var string
     */
    private const string KEY_PATH = 'zyos_install.path';

    /**
     * Constructor of the LockFileCreator service.
     *
     * Initializes the service with the necessary dependencies to access the bundle
     * configuration and to perform operations on the file system.
     *
     * The service uses dependency injection through the constructor to receive:
     * - ParameterBagInterface: Provides access to the bundle configuration parameters,
     *   specifically the KEY_LOCKS, KEY_LOCKFILE, and KEY_PATH keys that define the
     *   behavior and location of the lock file creation process.
     * - Filesystem: Provides an abstraction layer over the file system to
     *   create directories and files independently of the underlying operating system.
     *
     * Both dependencies are marked as readonly to guarantee the immutability of the
     * service state after its construction, following best design practices in PHP 8.2+.
     *
     * @param ParameterBagInterface $parameterBag Symfony parameter container that allows
     *                                            accessing the bundle configuration, including
     *                                            the configuration keys for locks, lockfile path,
     *                                            and base installation path.
     * @param Filesystem $filesystem Symfony component that provides abstract operations
     *                               over the file system, used to create directories and
     *                               the lock file.
     */
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly Filesystem            $filesystem
    ) {

    }

    /**
     * Creates the lock file for the specified environment if required.
     *
     * This method is the main entry point of the service and coordinates the creation
     * of the lock file following a defensive approach with multiple validation steps.
     *
     * The method follows this execution flow:
     * 1. First checks if the current environment requires lock control by verifying if
     *    it is in the list of locked environments. If not, the method returns immediately
     *    without creating any file.
     * 2. Then checks if the lock file already exists. If it does, the method returns
     *    immediately to avoid overwriting an existing lock file.
     * 3. If both checks pass, the method ensures that the base directory path exists
     *    by calling ensureBasePathExists(), which will create the directory if necessary.
     * 4. Finally, if the base path exists or was successfully created, the method
     *    writes the lock file by calling writeLockFile().
     *
     * The method uses short-circuit evaluation (&&) to ensure that the lock file is
     * only created if the base path exists or was successfully created. If any step
     * fails, the process stops and an error message is displayed to the user via the
     * SymfonyStyle component.
     *
     * This method does not return a value; success or failure is communicated through
     * error messages displayed to the user when exceptions occur.
     *
     * @param string $environment Name of the current application environment (e.g.:
     *                            'dev', 'prod', 'staging'). This parameter is used to
     *                            determine if the environment requires lock file creation
     *                            according to the configuration defined in KEY_LOCKS.
     * @param SymfonyStyle $io Symfony Console component that provides an enhanced
     *                          interface for console input/output. It is used to
     *                          display error messages when file operations fail.
     * @return void This method does not return a value. Success or failure is communicated
     *              through error messages displayed to the user.
     */
    public function create(string $environment, SymfonyStyle $io): void {

        if (!$this->environmentRequiresLock($environment)) {
            return;
        }

        if ($this->lockfileAlreadyExists()) {
            return;
        }

        $this->ensureBasePathExists($io)
        && $this->writeLockFile($io);
    }

    /**
     * Determines if the specified environment requires lock control.
     *
     * This method checks whether the given environment is in the list of environments
     * that require lock file creation. The list is retrieved from the bundle configuration
     * using the KEY_LOCKS parameter.
     *
     * The method uses the in_array() function with strict type comparison (true as the
     * third parameter) to ensure that the environment name matches exactly in both value
     * and type, preventing potential issues with loose type comparisons.
     *
     * This is a private helper method used internally by the create() method to determine
     * whether the lock file creation process should proceed for the current environment.
     *
     * @param string $environment Name of the environment to check (e.g.: 'dev', 'prod',
     *                            'staging'). This value is compared against the list of
     *                            environments configured in KEY_LOCKS.
     * @return bool Returns true if the environment is in the list of environments requiring
     *              lock control, false otherwise. The return value determines whether the
     *              lock file creation process should proceed.
     */
    private function environmentRequiresLock(string $environment): bool {
        return in_array($environment, $this->parameterBag->get(self::KEY_LOCKS), true);
    }

    /**
     * Checks if the lock file already exists in the file system.
     *
     * This method verifies the existence of the lock file at the path specified in the
     * bundle configuration (KEY_LOCKFILE parameter). The existence of the lock file
     * indicates that the installation process has already been completed for the
     * current environment.
     *
     * The method uses the exists() method of the Filesystem component, which provides
     * an operating system-independent abstraction for checking file existence. This
     * ensures consistent behavior across different platforms (Windows, Linux, macOS).
     *
     * This is a private helper method used internally by the create() method to prevent
     * overwriting an existing lock file, which could lead to allowing multiple installation
     * executions in the same environment.
     *
     * @return bool Returns true if the lock file exists at the configured path, false
     *              otherwise. The return value is used to determine whether the lock
     *              file creation process should be skipped.
     */
    private function lockfileAlreadyExists(): bool {
        return $this->filesystem->exists($this->parameterBag->get(self::KEY_LOCKFILE));
    }

    /**
     * Ensures that the base installation directory exists, creating it if necessary.
     *
     * This method verifies the existence of the base directory path specified in the
     * bundle configuration (KEY_PATH parameter). If the directory does not exist, the
     * method attempts to create it using the mkdir() method of the Filesystem component.
     *
     * The method follows a defensive approach:
     * 1. First checks if the directory already exists. If it does, returns true immediately
     *    without attempting any file operations.
     * 2. If the directory does not exist, attempts to create it using mkdir().
     * 3. If the directory creation succeeds, returns true to indicate that the base path
     *    is ready for file operations.
     * 4. If the directory creation fails due to an IOException (e.g., permission denied,
     *    disk full, invalid path), displays a detailed error message to the user and
     *    returns false to indicate failure.
     *
     * The error message provides clear context to the user about:
     * - The exact path of the directory that could not be created
     * - The likely cause of the failure (write permission on parent directory)
     * - The technical details from the exception for debugging purposes
     *
     * This is a private helper method used internally by the create() method to ensure
     * that the directory structure exists before attempting to create the lock file.
     *
     * @param SymfonyStyle $io Symfony Console component used to display error messages
     *                          in the console when directory creation fails. The use of
     *                          SymfonyStyle allows formatting the message with appropriate
     *                          colors and styles for errors, improving the user experience.
     * @return bool Returns true if the directory exists or was successfully created,
     *              false if the directory could not be created due to an IOException.
     *              The return value is used in short-circuit evaluation to determine
     *              whether the lock file creation should proceed.
     */
    private function ensureBasePathExists(SymfonyStyle $io): bool {

        $basePath = $this->parameterBag->get(self::KEY_PATH);

        if ($this->filesystem->exists($basePath)) {
            return true;
        }

        try {
            $this->filesystem->mkdir($basePath);
            return true;
        } catch (IOException $exception) {
            $io->error(sprintf(
                'Could not create the base installation directory "%s". '
                . 'Check that the process has write permission on the parent directory. '
                . 'Details: %s',
                $basePath,
                $exception->getMessage()
            ));
            return false;
        }
    }

    /**
     * Creates the lock file at the configured path.
     *
     * This method creates an empty lock file at the path specified in the bundle
     * configuration (KEY_LOCKFILE parameter). The lock file serves as a flag indicating
     * that the installation process has been successfully completed for the current
     * environment, preventing multiple executions.
     *
     * The method uses the touch() method of the Filesystem component, which creates an
     * empty file or updates the modification time if the file already exists. Since this
     * method is only called after verifying that the lock file does not exist (via
     * lockfileAlreadyExists()), it will always create a new file.
     *
     * The method handles potential IOException exceptions that may occur during file
     * creation, such as:
     * - Permission denied on the target directory
     - Disk full or insufficient space
     * - Invalid path or path too long
     * - Read-only file system
     *
     * If the file creation fails, the method displays a detailed error message to the
     * user and returns false to indicate failure. The error message provides:
     * - The exact path of the lock file that could not be created
     * - The likely cause of the failure (write permission on the directory)
     * - The technical details from the exception for debugging purposes
     *
     * This is a private helper method used internally by the create() method to perform
     * the actual file creation after ensuring that the base directory exists.
     *
     * @param SymfonyStyle $io Symfony Console component used to display error messages
     *                          in the console when file creation fails. The use of
     *                          SymfonyStyle allows formatting the message with appropriate
     *                          colors and styles for errors, improving the user experience.
     * @return bool Returns true if the lock file was successfully created, false if the
     *              file could not be created due to an IOException. The return value is
     *              used to communicate the success or failure of the operation to the
     *              calling method.
     */
    private function writeLockFile(SymfonyStyle $io): bool {

        $lockfilePath = $this->parameterBag->get(self::KEY_LOCKFILE);

        try {
            $this->filesystem->touch($lockfilePath);
            return true;
        } catch (IOException $exception) {
            $io->error(sprintf(
                'Could not create the lock file "%s". '
                . 'Check that the process has write permission on the directory. '
                . 'Details: %s',
                $lockfilePath,
                $exception->getMessage()
            ));
            return false;
        }
    }
}
