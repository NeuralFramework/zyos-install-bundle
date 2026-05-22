<?php
namespace Zyos\InstallBundle\Validations;

use RuntimeException;
use Zyos\InstallBundle\ValidatorInterface;

/**
 * AbstractFilepathValidation
 *
 * Abstract base class that provides common functionality for validators that
 * require filesystem path validation. This class serves as a foundation for
 * concrete validator implementations that need to validate file or directory
 * paths as part of the Zyos Install Bundle's validation pipeline.
 *
 * The class implements the ValidatorsInterface, requiring concrete implementations
 * to provide the validate(), getName(), and getTitle() methods. In addition, this
 * abstract class provides a protected helper method for validating that a
 * filepath argument is present and non-empty in the configuration arguments.
 *
 * This abstraction promotes code reuse and ensures consistent validation logic
 * across all filepath-based validators. Concrete implementations can extend this
 * class and focus on their specific validation logic (e.g., checking if a file
 * exists, verifying directory permissions, validating file formats) while relying
 * on this base class to handle the common task of argument validation.
 *
 * Typical use cases for extending this class include:
 * - File existence validators (checking if required files are present)
 * - Directory permission validators (verifying read/write access)
 * - File format validators (validating file extensions or content types)
 * - Path structure validators (ensuring paths follow expected patterns)
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Validations
 */
abstract class AbstractFilepathValidation implements ValidatorInterface {

    /**
     * The argument key used to identify the filepath value in the arguments array.
     *
     * This constant defines the standardized key name that must be used in the
     * configuration arguments array to provide the filesystem path for validation.
     * All validators extending this class expect the filepath to be provided under
     * this key in the arguments array.
     *
     * The value 'filepath' was chosen as it clearly describes the purpose of the
     * argument and follows a consistent naming convention across the bundle's
     * validation system. Using a constant instead of a hardcoded string ensures
     * type safety, prevents typos, and makes the code more maintainable by
     * providing a single source of truth for this argument name.
     *
     * When configuring validators that extend this class, the configuration entry
     * must include a 'filepath' key with the corresponding filesystem path as its
     * value. For example:
     * <code>
     * validators:
     *   - name: file_exists
     *     filepath: /path/to/file.txt
     * </code>
     *
     * @var string
     */
    private const string ARGUMENT_FILEPATH = 'filepath';

    /**
     * Validates that the filepath argument is present in the arguments array and
     * contains a non-empty value.
     *
     * This protected helper method performs essential validation on the filepath
     * argument before concrete validator implementations perform their specific
     * validation logic. The method ensures that the required 'filepath' argument
     * exists in the provided arguments array and that it contains a valid, non-empty
     * value that can be used for filesystem operations.
     *
     * The validation logic distinguishes between two error conditions:
     * 1. The 'filepath' key is completely missing from the arguments array
     * 2. The 'filepath' key exists but contains an empty value (null, empty string,
     *    or any value that evaluates to empty in a boolean context)
     *
     * For the first condition, the method checks both for the presence of the key
     * using array_key_exists() and ensures the value is not null. This distinction
     * is important because a null value could legitimately occur if the key exists
     * but was explicitly set to null, versus the key not being present at all.
     *
     * When validation fails, the method throws a RuntimeException with a detailed
     * error message that includes the validator name (obtained via static::getName())
     * and the missing argument key. This provides clear feedback to users about
     * configuration errors and helps them identify and fix the issue quickly.
     *
     * This method is intended to be called by concrete implementations in their
     * validate() method before performing their specific validation logic. This
     * ensures that the filepath argument is valid before attempting filesystem
     * operations, preventing null pointer exceptions or undefined index errors.
     *
     * @param array $arguments The arguments array containing configuration parameters
     *                         for the validator. This array must include a 'filepath'
     *                         key with a valid filesystem path as its value. The array
     *                         is typically provided by the bundle's validation pipeline
     *                         based on the configuration defined in the application's
     *                         configuration files.
     *
     * @return void This method does not return a value. It either completes successfully
     *              (indicating the filepath argument is valid) or throws an exception
     *              if validation fails.
     *
     * @throws RuntimeException Thrown when the 'filepath' argument is missing from the
     *                           arguments array or when it contains an empty value. The
     *                           exception message includes the validator name and the
     *                           specific validation error to aid in troubleshooting.
     */
    protected function validateFilepathArgument(array $arguments): void {

        $filepathValue = $arguments[self::ARGUMENT_FILEPATH] ?? null;

        if ($filepathValue === null && !array_key_exists(self::ARGUMENT_FILEPATH, $arguments)) {

            throw new RuntimeException(sprintf(
                'Validator "%s": required argument "%s" was not found in the arguments array. '
                . 'Ensure the configuration entry declares a "%s" value.',
                static::getName(),
                self::ARGUMENT_FILEPATH,
                self::ARGUMENT_FILEPATH
            ));
        }

        if (empty($filepathValue)) {

            throw new RuntimeException(sprintf(
                'Validator "%s": argument "%s" is present but contains an empty value. '
                . 'Provide a valid filesystem path in the configuration entry.',
                static::getName(),
                self::ARGUMENT_FILEPATH
            ));
        }
    }
}
