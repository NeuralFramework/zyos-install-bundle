<?php

namespace Zyos\InstallBundle\Validations;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ExistsValidations
 *
 * Concrete validator implementation that checks whether a specified file or directory
 * exists on the filesystem. This validator extends the AbstractFilepathValidation
 * base class to provide specific validation logic for verifying the presence of
 * filesystem resources as part of the Zyos Install Bundle's validation pipeline.
 *
 * This validator is essential for ensuring that required files, configuration files,
 * directories, or other filesystem resources are present before proceeding with
 * installation or deployment processes. It can validate both files and directories,
 * making it versatile for various validation scenarios such as checking for required
 * configuration files, verifying that cache directories exist, or ensuring that
 * necessary assets are present.
 *
 * The validator leverages Symfony's Filesystem component to perform the existence
 * check, providing a reliable and cross-platform implementation that handles
 * different operating systems and filesystem types correctly. The Filesystem
 * component is injected via constructor dependency injection, promoting testability
 * and following SOLID principles.
 *
 * The validation process follows these steps:
 * 1. Validates that the 'filepath' argument is present and non-empty (inherited from parent)
 * 2. Extracts the filepath value from the arguments array
 * 3. Checks if the path exists on the filesystem using Symfony's Filesystem component
 * 4. Returns true if the path exists, false otherwise
 *
 * This validator is typically used in configuration to ensure that critical files
 * or directories are present before the application attempts to use them. Common
 * use cases include validating environment configuration files, checking for the
 * existence of required directories (e.g., cache, logs, uploads), or verifying
 * that asset files are available.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Validations
 */
class ExistsValidations extends AbstractFilepathValidation {

    /**
     * Constructs a new ExistsValidations instance with the required Filesystem dependency.
     *
     * This constructor uses PHP 8 constructor property promotion to declare and initialize
     * the readonly Filesystem property in a single step. The Filesystem component from
     * Symfony is injected as a dependency, allowing the validator to perform filesystem
     * operations in a testable and decoupled manner.
     *
     * The Filesystem component provides a cross-platform abstraction for filesystem
     * operations, ensuring that the validator works consistently across different
     * operating systems (Windows, Linux, macOS) and handles edge cases such as
     * symbolic links, file permissions, and path normalization correctly.
     *
     * By using dependency injection, this class follows the Dependency Inversion Principle
     * and can be easily tested with mock Filesystem implementations. The readonly
     * modifier ensures that the Filesystem instance cannot be reassigned after
     * construction, promoting immutability and thread safety.
     *
     * @param Filesystem $filesystem The Symfony Filesystem component instance used to
     *                               perform filesystem existence checks. This component
     *                               provides cross-platform filesystem operations and
     *                               should be configured and injected by the Symfony
     *                               dependency injection container.
     */
    public function __construct(private readonly Filesystem $filesystem) {

    }
    /**
     * Returns the unique identifier for this validator implementation.
     *
     * This static method provides a machine-readable name that uniquely identifies
     * this specific validator within the Zyos Install Bundle's validation system.
     * The name 'exists' clearly and concisely describes the validation behavior:
     * checking whether a filesystem path exists.
     *
     * This identifier is used internally by the bundle for validator registration,
     * configuration mapping, logging, and programmatic reference. When configuring
     * validators in the application's configuration files, this name is used to
     * specify that this validator should be executed.
     *
     * The name follows a simple, descriptive convention that makes it easy for
     * developers to understand the validator's purpose without needing to refer
     * to additional documentation. It is short, lowercase, and uses a single word
     * that directly describes the validation action.
     *
     * @return string The unique identifier 'exists' for this validator. This value
     *                is used in configuration files and internal system operations
     *                to reference this specific validator implementation.
     */
    public static function getName(): string {
        return 'exists';
    }

    /**
     * Returns a human-readable title describing this validator's purpose.
     *
     * This static method provides a user-friendly description of what this validator
     * checks and validates. The title 'File - Directory Exists' clearly indicates
     * that the validator verifies the existence of either files or directories on
     * the filesystem.
     *
     * The title is intended for display purposes in user-facing interfaces such
     * as console output, validation reports, installation wizards, or dashboard
     * displays where users need to understand what validation is being performed.
     * It uses clear, plain language that non-technical users can understand.
     *
     * The hyphenated format 'File - Directory Exists' indicates that this validator
     * handles both file and directory existence checks, making it versatile for
     * various validation scenarios. This distinction is important for users to
     * understand that the validator is not limited to just files or just directories.
     *
     * @return string The human-readable title 'File - Directory Exists' describing
     *                the validation performed by this validator. This title is
     *                suitable for display in user interfaces and provides clear
     *                indication of the validator's purpose.
     */
    public static function getTitle(): string {
        return 'File - Directory Exists';
    }
    /**
     * Performs the validation logic to check if the specified filepath exists on the filesystem.
     *
     * This method implements the core validation logic required by the ValidatorsInterface.
     * It orchestrates the validation process by first ensuring that the filepath argument
     * is valid and present, then extracting the filepath value, and finally checking whether
     * the path exists on the filesystem using Symfony's Filesystem component.
     *
     * The validation process follows a structured approach:
     * 1. Calls the parent class's validateFilepathArgument() method to ensure the
     *    'filepath' argument is present and non-empty. This step will throw a
     *    RuntimeException if the argument is missing or empty, providing clear error
     *    messages to help users identify configuration issues.
     * 2. Extracts the filepath value from the arguments array using the extractFilepath()
     *    helper method. This separation of concerns makes the code more maintainable
     *    and allows for potential future enhancements to the extraction logic.
     * 3. Delegates the actual filesystem existence check to the pathExistsOnDisk()
     *    method, which uses the injected Filesystem component to perform the check.
     *    This abstraction allows for easy testing and potential replacement of the
     *    filesystem checking logic.
     *
     * The method returns a boolean indicating whether the validation passed (true)
     * or failed (false). A return value of true indicates that the specified file
     * or directory exists on the filesystem, while false indicates that it does not
     * exist.
     *
     * The $params parameter is included for interface compatibility but is not
     * used by this validator, as the validation logic depends solely on the
     * filepath argument. This allows for future extensibility if additional
     * configuration parameters become necessary.
     *
     * @param array $params Optional associative array of configuration parameters.
     *                      This parameter is not currently used by this validator
     *                      but is included for interface compatibility and future
     *                      extensibility. Default is an empty array.
     * @param array $arguments The arguments array containing the configuration for
     *                         this validator. This array must include a 'filepath'
     *                         key with the filesystem path to validate. The array
     *                         is typically provided by the bundle's validation pipeline
     *                         based on the configuration defined in the application's
     *                         configuration files.
     *
     * @return bool Returns true if the specified filepath exists on the filesystem,
     *              indicating that the validation passed successfully. Returns false
     *              if the filepath does not exist, indicating that the validation
     *              failed.
     *
     * @throws RuntimeException Thrown when the 'filepath' argument is missing from the
     *                           arguments array or when it contains an empty value.
     *                           This exception is thrown by the validateFilepathArgument()
     *                           method inherited from the parent class.
     */
    public function validate(array $params = [], array $arguments = []): bool {

        $this->validateFilepathArgument($arguments);
        $filepath = $this->extractFilepath($arguments);
        return $this->pathExistsOnDisk($filepath);
    }
    /**
     * Extracts the filepath value from the arguments array.
     *
     * This private helper method is responsible for retrieving the filepath value
     * from the arguments array. The method directly accesses the 'filepath' key
     * in the array and returns its value as a string.
     *
     * This method is called after the validateFilepathArgument() method has already
     * verified that the 'filepath' key exists and contains a non-empty value.
     * Therefore, this method can safely assume that the key is present and the
     * value is valid, eliminating the need for additional validation checks.
     *
     * The separation of this extraction logic into a dedicated method promotes
     * code maintainability and follows the Single Responsibility Principle. It
     * allows for potential future enhancements to the extraction logic (such as
     * path normalization, relative-to-absolute path conversion, or environment
     * variable expansion) without modifying the main validate() method.
     *
     * While this method currently performs a simple array access, the abstraction
     * provides a clear semantic meaning to the operation and makes the code more
     * self-documenting. It also facilitates unit testing of the extraction logic
     * in isolation if needed.
     *
     * @param array $arguments The arguments array containing the configuration
     *                         parameters for the validator. This array must include
     *                         a 'filepath' key with a valid filesystem path as its
     *                         value. The array is expected to have been previously
     *                         validated by the validateFilepathArgument() method.
     *
     * @return string The filepath value extracted from the arguments array. This
     *                value is guaranteed to be a non-empty string representing a
     *                filesystem path.
     */
    private function extractFilepath(array $arguments): string {
        return $arguments['filepath'];
    }

    /**
     * Checks whether the specified filepath exists on the filesystem.
     *
     * This private helper method performs the actual filesystem existence check
     * using the injected Symfony Filesystem component. The method delegates the
     * check to the Filesystem component's exists() method, which provides a
     * cross-platform implementation that handles different operating systems
     * and filesystem types correctly.
     *
     * The Filesystem component's exists() method returns true if the path exists,
     * regardless of whether it is a file or a directory. It also handles symbolic
     * links correctly, following them to their target. This makes the validator
     * versatile for various validation scenarios.
     *
     * By delegating the filesystem check to Symfony's Filesystem component, this
     * method ensures that the validation logic is reliable, well-tested, and
     * consistent with Symfony's best practices. The component handles edge cases
     * such as path normalization, permission checks, and cross-platform path
     * separators, reducing the complexity of the validator implementation.
     *
     * This abstraction also promotes testability, as the Filesystem component can
     * be easily mocked in unit tests to simulate different filesystem states
     * without actually touching the filesystem. This allows for fast, isolated
     * testing of the validator logic.
     *
     * @param string $filepath The filesystem path to check for existence. This
     *                         parameter should be a valid string representing an
     *                         absolute or relative path to a file or directory.
     *                         The path is expected to have been extracted from the
     *                         arguments array by the extractFilepath() method.
     *
     * @return bool Returns true if the specified filepath exists on the filesystem,
     *              indicating that either a file or directory is present at that
     *              location. Returns false if the path does not exist.
     */
    private function pathExistsOnDisk(string $filepath): bool {
        return $this->filesystem->exists($filepath);
    }
}
