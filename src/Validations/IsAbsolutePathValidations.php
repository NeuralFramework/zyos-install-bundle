<?php

namespace Zyos\InstallBundle\Validations;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Validation class for verifying whether a given file path is an absolute path.
 *
 * This class extends AbstractFilepathValidation and provides specific functionality
 * to validate that a file path is absolute rather than relative. An absolute path
 * is one that starts from the root directory of the filesystem (e.g., '/var/www'
 * on Unix-like systems or 'C:\Users' on Windows), as opposed to a relative path
 * which is interpreted relative to the current working directory.
 *
 * The validation is performed using Symfony's Filesystem component, which provides
 * cross-platform path detection capabilities, ensuring consistent behavior across
 * different operating systems.
 *
 * Typical use cases for this validation include:
 * - Verifying configuration file paths in installation processes
 * - Ensuring log file paths are absolute for proper file system access
 * - Validating directory paths for deployment scripts
 * - Checking that critical system paths are properly specified
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Validations
 */
class IsAbsolutePathValidations extends AbstractFilepathValidation {

    /**
     * Constructor that initializes the validation with required dependencies.
     *
     * This constructor performs dependency injection of the Symfony Filesystem
     * component, which is essential for performing cross-platform absolute path
     * detection. The Filesystem service is injected via constructor injection,
     * following best practices for dependency management and testability.
     *
     * The Filesystem parameter is marked as readonly, meaning once assigned it
     * cannot be reassigned. This ensures immutability of the dependency and
     * prevents accidental modification during the object's lifetime.
     *
     * @param Filesystem $filesystem The Symfony Filesystem component instance
     *                              used for path validation operations. This
     *                              service provides the isAbsolutePath() method
     *                              that performs the actual path checking.
     *
     * @return void
     */
    public function __construct(private readonly Filesystem $filesystem) {

    }

    /**
     * Returns the unique identifier name for this validation.
     *
     * This method provides a machine-readable string identifier that can be used
     * to reference this specific validation rule programmatically. The name is
     * typically used in configuration files, validation registries, or when
     * dynamically selecting validations to apply.
     *
     * The returned value 'is_absolute_path' is a snake_case string that clearly
     * describes the validation's purpose and follows common naming conventions
     * for configuration keys.
     *
     * @return string The unique identifier 'is_absolute_path' for this validation.
     *                This value should be used when referencing this validation
     *                in configuration or programmatic contexts.
     */
    public static function getName(): string {
        return 'is_absolute_path';
    }

    /**
     * Returns a human-readable title for this validation.
     *
     * This method provides a user-friendly, descriptive title that can be
     * displayed in user interfaces, error messages, or documentation. The title
     * is designed to be easily understood by non-technical users while still
     * accurately describing the validation's purpose.
     *
     * Unlike getName(), which returns a machine-readable identifier, this method
     * returns a title case string suitable for display purposes. This distinction
     * allows for both programmatic access and human-readable presentation of the
     * validation.
     *
     * @return string The human-readable title 'Is Absolute Path' for this validation.
     *                This value is suitable for display in UI elements, error
     *                messages, or documentation.
     */
    public static function getTitle(): string {
        return 'Is Absolute Path';
    }

    /**
     * Validates whether the provided filepath argument represents an absolute path.
     *
     * This is the main validation method that performs the actual check to determine
     * if a given file path is absolute. The method follows a structured validation
     * process:
     *
     * 1. First, it validates that the required 'filepath' argument is present in
     *    the arguments array by calling validateFilepathArgument(). This ensures
     *    the validation fails early with a clear error if the required argument
     *    is missing.
     *
     * 2. Then, it extracts the filepath value from the arguments array using the
     *    extractFilepath() helper method.
     *
     * 3. Finally, it delegates the actual absolute path checking to the
     *    isAbsolutePath() method, which uses the Symfony Filesystem component.
     *
     * The method accepts two arrays: $params for additional validation parameters
     * (currently unused but available for future extensibility) and $arguments
     * which must contain the 'filepath' key to be validated.
     *
     * @param array $params Optional array of additional validation parameters.
     *                      This parameter is reserved for future use and allows
     *                      for extending the validation behavior without breaking
     *                      the existing interface. Currently not used in the
     *                      implementation.
     * @param array $arguments Required array containing the filepath to validate.
     *                         This array must contain a 'filepath' key with a
     *                         string value representing the path to be checked.
     *                         The absence of this key will cause validation to
     *                         fail via the validateFilepathArgument() call.
     *
     * @return bool Returns true if the filepath is an absolute path, false otherwise.
     *              The result is determined by the Symfony Filesystem component's
     *              isAbsolutePath() method, which handles platform-specific path
     *              detection.
     *
     * @throws \InvalidArgumentException May be thrown if the 'filepath' argument
     *                                  is missing from the $arguments array.
     *                                  This exception is thrown by the
     *                                  validateFilepathArgument() method inherited
     *                                  from the parent class.
     */
    public function validate(array $params = [], array $arguments = []): bool {

        $this->validateFilepathArgument($arguments);
        $filepath = $this->extractFilepath($arguments);

        return $this->isAbsolutePath($filepath);
    }

    /**
     * Extracts the filepath value from the arguments array.
     *
     * This is a private helper method that retrieves the 'filepath' value from
     * the provided arguments array. The method encapsulates the array access
     * logic, making the main validate() method cleaner and more readable.
     *
     * The method assumes that the 'filepath' key exists in the array. This
     * assumption is safe because validateFilepathArgument() is called before
     * this method in the validation flow, ensuring the key is present.
     *
     * This method is private as it is an implementation detail of the validation
     * logic and should not be called from outside the class. It provides a single
     * point of change if the argument structure needs to be modified in the future.
     *
     * @param array $arguments The arguments array containing the filepath.
     *                         This array must have a 'filepath' key with a
     *                         string value. The method does not perform validation
     *                         on the array structure as this is handled by the
     *                         calling validate() method.
     *
     * @return string The filepath string extracted from the arguments array.
     *                This value is returned exactly as stored in the array without
     *                any modification or validation.
     */
    private function extractFilepath(array $arguments): string {
        return $arguments['filepath'];
    }

    /**
     * Determines whether the given filepath is an absolute path.
     *
     * This private method performs the actual absolute path detection by delegating
     * to the Symfony Filesystem component's isAbsolutePath() method. The Filesystem
     * component handles the platform-specific logic for detecting absolute paths,
     * ensuring consistent behavior across different operating systems.
     *
     * On Unix-like systems, an absolute path typically starts with a forward slash
     * (/). On Windows systems, absolute paths can start with a drive letter and colon
     * (e.g., C:) or a UNC path (e.g., \\server\share). The Filesystem component
     * abstracts these differences, providing a unified interface.
     *
     * This method is private as it encapsulates the implementation detail of using
     * the Filesystem component. This allows the implementation to be changed
     * (e.g., to use a different path detection library) without affecting the
     * public interface of the class.
     *
     * @param string $filepath The filepath string to be checked for absolute path
     *                         status. This should be a valid string representation
     *                         of a file system path. Empty strings or null values
     *                         are not expected and may produce undefined results.
     *
     * @return bool Returns true if the filepath is an absolute path according to
     *              the current operating system's conventions, false if it is a
     *              relative path. The determination is made by the Symfony
     *              Filesystem component's isAbsolutePath() method.
     */
    private function isAbsolutePath(string $filepath): bool {
        return $this->filesystem->isAbsolutePath($filepath);
    }
}
