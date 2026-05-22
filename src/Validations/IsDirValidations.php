<?php

namespace Zyos\InstallBundle\Validations;

use InvalidArgumentException;

/**
 * Validation class for verifying whether a given file path is a directory.
 *
 * This class extends AbstractFilepathValidation and provides specific functionality
 * to validate that a file path exists and is a directory rather than a regular file.
 * The validation uses PHP's built-in is_dir() function to perform the check, which
 * determines whether the given path is a directory and exists on the filesystem.
 *
 * This validation is essential in installation and configuration processes where
 * it is necessary to ensure that specific paths are directories before attempting
 * operations such as creating files within them, listing their contents, or
 * performing directory-specific operations like permission checks.
 *
 * Typical use cases for this validation include:
 * - Verifying that log directories exist before writing log files
 * - Ensuring cache directories are properly set up
 * - Validating that upload directories exist and are accessible
 * - Checking that configuration directories are present before reading config files
 * - Verifying temporary directories are available for file operations
 *
 * The validation follows a structured process:
 * 1. Validates that the required 'filepath' argument is present
 * 2. Extracts the filepath from the arguments
 * 3. Checks if the path exists and is a directory using is_dir()
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Validations
 */
class IsDirValidations extends AbstractFilepathValidation {

    /**
     * Returns the unique identifier name for this validation.
     *
     * This method provides a machine-readable string identifier that can be used
     * to reference this specific validation rule programmatically. The name is
     * typically used in configuration files, validation registries, or when
     * dynamically selecting validations to apply.
     *
     * The returned value 'is_dir' is a snake_case string that clearly describes
     * the validation's purpose and follows common naming conventions for
     * configuration keys. This name corresponds to the PHP built-in function
     * is_dir() that is used internally to perform the actual validation.
     *
     * @return string The unique identifier 'is_dir' for this validation.
     *                This value should be used when referencing this validation
     *                in configuration or programmatic contexts.
     */
    public static function getName(): string {
        return 'is_dir';
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
     * @return string The human-readable title 'Is Directory' for this validation.
     *                This value is suitable for display in UI elements, error
     *                messages, or documentation.
     */
    public static function getTitle(): string {
        return 'Is Directory';
    }

    /**
     * Validates whether the provided filepath argument represents an existing directory.
     *
     * This is the main validation method that performs the actual check to determine
     * if a given file path exists and is a directory. The method follows a structured
     * validation process:
     *
     * 1. First, it validates that the required 'filepath' argument is present in
     *    the arguments array by calling validateFilepathArgument(). This ensures
     *    the validation fails early with a clear error if the required argument
     *    is missing.
     *
     * 2. Then, it extracts the filepath value from the arguments array using the
     *    extractFilepath() helper method.
     *
     * 3. Finally, it delegates the actual directory checking to the isDirectory()
     *    method, which uses PHP's built-in is_dir() function to determine if the
     *    path exists and is a directory.
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
     * @return bool Returns true if the filepath exists and is a directory, false otherwise.
     *              The result is determined by PHP's is_dir() function, which returns
     *              true only if the path exists and is a directory. It returns false
     *              if the path does not exist, is a file, or is not accessible.
     *
     * @throws InvalidArgumentException May be thrown if the 'filepath' argument
     *                                  is missing from the $arguments array.
     *                                  This exception is thrown by the
     *                                  validateFilepathArgument() method inherited
     *                                  from the parent class.
     */
    public function validate(array $params = [], array $arguments = []): bool {

        $this->validateFilepathArgument($arguments);
        $filepath = $this->extractFilepath($arguments);

        return $this->isDirectory($filepath);
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
     * Determines whether the given filepath is a directory.
     *
     * This private method performs the actual directory check by delegating to
     * PHP's built-in is_dir() function. The is_dir() function returns true if the
     * given path exists and is a directory, and false otherwise.
     *
     * The function checks both the existence of the path and its type. It will
     * return false if:
     * - The path does not exist
     * - The path exists but is a regular file (not a directory)
     * - The path is a symbolic link pointing to a non-directory
     * - The path is not accessible due to permission issues
     *
     * This method is private as it encapsulates the implementation detail of using
     * PHP's is_dir() function. This allows the implementation to be changed
     * (e.g., to add additional checks or use a different approach) without affecting
     * the public interface of the class.
     *
     * @param string $filepath The filepath string to be checked for directory status.
     *                         This should be a valid string representation of a file
     *                         system path. Empty strings or null values will cause
     *                         is_dir() to return false. Relative paths are resolved
     *                         relative to the current working directory.
     *
     * @return bool Returns true if the filepath exists and is a directory, false otherwise.
     *              The determination is made by PHP's is_dir() function, which performs
     *              the actual filesystem check. The function returns true only when the
     *              path exists and is specifically a directory type.
     */
    private function isDirectory(string $filepath): bool {
        return is_dir($filepath);
    }
}
