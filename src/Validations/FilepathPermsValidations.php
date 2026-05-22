<?php

namespace Zyos\InstallBundle\Validations;

use RuntimeException;

/**
 * FilepathPermsValidations
 *
 * Concrete validator implementation that checks whether a specified file or directory
 * has the expected filesystem permissions. This validator extends the
 * AbstractFilepathValidation base class to provide specific validation logic for
 * verifying permission settings as part of the Zyos Install Bundle's validation pipeline.
 *
 * This validator is critical for ensuring that files and directories have the correct
 * access permissions before proceeding with installation or deployment processes.
 * Proper permission settings are essential for security, functionality, and
 * compliance with system requirements. This validator can be used to verify that
 * configuration files are readable, that log directories are writable, that executable
 * files have the correct execute permissions, or that sensitive files have restricted
 * access.
 *
 * The validator uses PHP's native fileperms() function to retrieve the actual
 * permissions of the specified filesystem path and compares them against the expected
 * permissions. The expected permissions can be configured via the 'perms' parameter
 * in the validator configuration. If no specific permissions are provided, the
 * validator defaults to '0777' (read, write, and execute for owner, group, and others).
 *
 * The validation process follows these steps:
 * 1. Validates that the 'filepath' argument is present and non-empty (inherited from parent)
 * 2. Extracts the filepath value from the arguments array
 * 3. Resolves the expected permissions from the parameters or uses the default
 * 4. Reads the current permissions from the filesystem using fileperms()
 * 5. Compares the current permissions with the expected permissions
 * 6. Returns true if they match, false otherwise
 *
 * The permission comparison is performed on the last four octal digits of the
 * permission mode, which represent the standard Unix permission bits (user, group,
 * and others). This approach ensures that the comparison focuses on the meaningful
 * permission bits while ignoring higher-order bits that may vary across different
 * filesystem types or operating systems.
 *
 * This validator is typically used in configuration to ensure that critical files
 * or directories have the appropriate permissions for the application to function
 * correctly and securely. Common use cases include validating that cache directories
 * are writable, that configuration files are not world-writable, or that executable
 * scripts have the correct execute permissions.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Validations
 */
class FilepathPermsValidations extends AbstractFilepathValidation {

    /**
     * The default permission mode used when no specific permissions are provided.
     *
     * This constant defines the fallback permission mode that the validator will
     * use when the 'perms' parameter is not specified in the validator configuration.
     * The value '0777' represents the most permissive permission mode in Unix-like
     * systems: read, write, and execute permissions for the owner, group, and others.
     *
     * The octal notation '0777' breaks down as follows:
     * - First digit (0): Special bits (setuid, setgid, sticky bit) - none set
     * - Second digit (7): Owner permissions - read (4) + write (2) + execute (1) = 7
     * - Third digit (7): Group permissions - read (4) + write (2) + execute (1) = 7
     * - Fourth digit (7): Others permissions - read (4) + write (2) + execute (1) = 7
     *
     * This default was chosen as a safe fallback that ensures the validator will
     * not fail due to missing configuration, while still allowing the comparison to
     * be performed. However, in production environments, it is recommended to
     * explicitly configure the expected permissions to match the actual security
     * requirements of the application.
     *
     * The constant is defined as private since it is only used internally by this
     * validator class and should not be accessed or modified from outside the class.
     * Using a constant instead of a hardcoded string ensures type safety, prevents
     * typos, and makes the code more maintainable by providing a single source of
     * truth for this default value.
     *
     * @var string
     */
    private const string DEFAULT_PERMISSIONS = '0777';

    /**
     * Returns the unique identifier for this validator implementation.
     *
     * This static method provides a machine-readable name that uniquely identifies
     * this specific validator within the Zyos Install Bundle's validation system.
     * The name 'filepath_perms' clearly and concisely describes the validation
     * behavior: checking the permissions of a filesystem path.
     *
     * This identifier is used internally by the bundle for validator registration,
     * configuration mapping, logging, and programmatic reference. When configuring
     * validators in the application's configuration files, this name is used to
     * specify that this validator should be executed.
     *
     * The name follows a descriptive convention using snake_case format, which
     * clearly indicates that the validator deals with filepath permissions. The
     * underscore separator improves readability and follows common PHP naming
     * conventions for identifiers that consist of multiple words.
     *
     * @return string The unique identifier 'filepath_perms' for this validator.
     *                This value is used in configuration files and internal
     *                system operations to reference this specific validator
     *                implementation.
     */
    public static function getName(): string {
        return 'filepath_perms';
    }

    /**
     * Returns a human-readable title describing this validator's purpose.
     *
     * This static method provides a user-friendly description of what this validator
     * checks and validates. The title 'File - Directory Permissions' clearly indicates
     * that the validator verifies the permission settings of files or directories on
     * the filesystem.
     *
     * The title is intended for display purposes in user-facing interfaces such
     * as console output, validation reports, installation wizards, or dashboard
     * displays where users need to understand what validation is being performed.
     * It uses clear, plain language that non-technical users can understand.
     *
     * The hyphenated format 'File - Directory Permissions' indicates that this
     * validator handles permission checks for both files and directories, making it
     * versatile for various validation scenarios. This distinction is important for
     * users to understand that the validator is not limited to just files or just
     * directories.
     *
     * @return string The human-readable title 'File - Directory Permissions'
     *                describing the validation performed by this validator.
     *                This title is suitable for display in user interfaces and
     *                provides clear indication of the validator's purpose.
     */
    public static function getTitle(): string {
        return 'File - Directory Permissions';
    }

    /**
     * Performs the validation logic to check if the specified filepath has the
     * expected filesystem permissions.
     *
     * This method implements the core validation logic required by the ValidatorsInterface.
     * It orchestrates the validation process by first ensuring that the filepath argument
     * is valid and present, then extracting the filepath value, resolving the expected
     * permissions, reading the current permissions from the filesystem, and finally
     * comparing them to determine if they match.
     *
     * The validation process follows a structured approach:
     * 1. Calls the parent class's validateFilepathArgument() method to ensure the
     *    'filepath' argument is present and non-empty. This step will throw a
     *    RuntimeException if the argument is missing or empty, providing clear error
     *    messages to help users identify configuration issues.
     * 2. Extracts the filepath value from the arguments array using the extractFilepath()
     *    helper method. This separation of concerns makes the code more maintainable.
     * 3. Resolves the expected permissions from the $params array using the
     *    resolveExpectedPermissions() method. If the 'perms' parameter is not provided,
     *    the method returns the default '0777' permission mode.
     * 4. Reads the current permissions from the filesystem using the readCurrentPermissions()
     *    method, which uses PHP's fileperms() function and formats the result as a
     *    four-digit octal string.
     * 5. Compares the current permissions with the expected permissions using the
     *    permissionsMatch() method, which performs a strict string comparison.
     *
     * The method returns a boolean indicating whether the validation passed (true)
     * or failed (false). A return value of true indicates that the actual permissions
     * match the expected permissions, while false indicates a mismatch.
     *
     * The $params parameter is used to provide the expected permissions for the
     * validation. The 'perms' key should contain a four-digit octal string representing
     * the desired permission mode (e.g., '0755', '0644', '0777'). If this parameter
     * is not provided, the validator uses the default '0777' permission mode.
     *
     * @param array $params Optional associative array of configuration parameters.
     *                      The 'perms' key can be used to specify the expected
     *                      permission mode as a four-digit octal string (e.g., '0755').
     *                      If not provided, the validator uses the default '0777'.
     *                      Default is an empty array.
     * @param array $arguments The arguments array containing the configuration for
     *                         this validator. This array must include a 'filepath'
     *                         key with the filesystem path to validate. The array
     *                         is typically provided by the bundle's validation pipeline
     *                         based on the configuration defined in the application's
     *                         configuration files.
     *
     * @return bool Returns true if the current permissions match the expected permissions,
     *              indicating that the validation passed successfully. Returns false
     *              if the permissions do not match, indicating that the validation
     *              failed.
     *
     * @throws RuntimeException Thrown when the 'filepath' argument is missing from the
     *                           arguments array or when it contains an empty value.
     *                           This exception is thrown by the validateFilepathArgument()
     *                           method inherited from the parent class.
     */
    public function validate(array $params = [], array $arguments = []): bool {

        $this->validateFilepathArgument($arguments);

        $filepath            = $this->extractFilepath($arguments);
        $expectedPermissions = $this->resolveExpectedPermissions($params);
        $currentPermissions  = $this->readCurrentPermissions($filepath);

        return $this->permissionsMatch($currentPermissions, $expectedPermissions);
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
     * Resolves the expected permission mode from the parameters or returns the default.
     *
     * This private helper method determines the expected permission mode that should
     * be used for the validation. The method checks if the 'perms' key exists in the
     * $params array and returns its value if present. If the key is not found, the
     * method returns the default permission mode defined by the DEFAULT_PERMISSIONS
     * constant.
     *
     * The method uses array_key_exists() to check for the presence of the 'perms' key,
     * which distinguishes between a missing key and a key with a null value. This
     * ensures that the validator behaves correctly in both scenarios. When the key
     * is present, its value is explicitly cast to a string to ensure type consistency,
     * regardless of the original type of the parameter value.
     *
     * This separation of concerns allows the validate() method to remain focused on
     * the validation orchestration while delegating the permission resolution logic
     * to this dedicated method. It also makes the code more testable, as the
     * resolution logic can be tested independently of the full validation process.
     *
     * The default permission mode '0777' is used when no specific permissions are
     * configured, providing a safe fallback that ensures the validator can still
     * perform the comparison. However, in production environments, it is recommended
     * to explicitly configure the expected permissions to match the actual security
     * requirements of the application.
     *
     * @param array $params The parameters array that may contain the 'perms' key
     *                      with the expected permission mode. This array is typically
     *                      provided by the bundle's validation pipeline based on the
     *                      configuration defined in the application's configuration
     *                      files.
     *
     * @return string The expected permission mode as a four-digit octal string.
     *                Returns the value from the 'perms' key if present, otherwise
     *                returns the default '0777' permission mode.
     */
    private function resolveExpectedPermissions(array $params): string {

        return array_key_exists('perms', $params)
            ? (string) $params['perms']
            : self::DEFAULT_PERMISSIONS;
    }

    /**
     * Reads the current permission mode from the filesystem for the specified filepath.
     *
     * This private helper method retrieves the actual filesystem permissions of the
     * specified file or directory using PHP's native fileperms() function. The method
     * formats the permission mode as a four-digit octal string for comparison with
     * the expected permissions.
     *
     * The fileperms() function returns the permission mode as an integer, which
     * represents the full permission bits including special bits (setuid, setgid,
     * sticky bit) and the standard permission bits (user, group, others). To focus
     * the comparison on the meaningful permission bits, the method uses sprintf()
     * with the '%o' format specifier to convert the integer to an octal string
     * representation, then uses substr() to extract the last four characters.
     *
     * The last four octal digits represent the standard Unix permission bits:
     * - First of the four: Special bits (setuid, setgid, sticky bit)
     * - Second of the four: Owner permissions (read, write, execute)
     * - Third of the four: Group permissions (read, write, execute)
     * - Fourth of the four: Others permissions (read, write, execute)
     *
     * This approach ensures that the comparison focuses on the permission bits that
     * are typically configured and validated, while ignoring higher-order bits that
     * may vary across different filesystem types or operating systems. It provides
     * a consistent representation that matches the format commonly used in
     * configuration files and documentation.
     *
     * The method assumes that the filepath exists and is accessible. If the file
     * does not exist or cannot be accessed, fileperms() will return false, which
     * will cause sprintf() to produce unexpected results. However, this scenario
     * is prevented by the validateFilepathArgument() method, which ensures that
     * the filepath argument is valid before this method is called.
     *
     * @param string $filepath The filesystem path for which to read the current
     *                         permissions. This parameter should be a valid string
     *                         representing an absolute or relative path to a file or
     *                         directory. The path is expected to have been extracted
     *                         from the arguments array by the extractFilepath()
     *                         method.
     *
     * @return string The current permission mode as a four-digit octal string
     *                (e.g., '0755', '0644', '0777'). This string represents the
     *                actual permissions of the file or directory on the filesystem.
     */
    private function readCurrentPermissions(string $filepath): string {
        return substr(sprintf('%o', fileperms($filepath)), -4);
    }

    /**
     * Compares the current permissions with the expected permissions to determine
     * if they match.
     *
     * This private helper method performs a strict string comparison between the
     * current permissions read from the filesystem and the expected permissions
     * resolved from the configuration. The method returns true if the two permission
     * strings are identical, and false otherwise.
     *
     * The comparison is performed as a strict string equality check (===), which
     * ensures that both the value and the type must match. Since both parameters
     * are guaranteed to be strings (the current permissions are formatted as a
     * string by readCurrentPermissions(), and the expected permissions are cast
     * to a string by resolveExpectedPermissions()), this comparison effectively
     * checks for exact string equality.
     *
     * This strict comparison is appropriate for permission validation because
     * permission modes are typically represented as exact octal strings (e.g.,
     * '0755', '0644'). Any deviation, even a single digit difference, represents
     * a meaningful difference in the permission settings that should cause the
     * validation to fail.
     *
     * The separation of this comparison logic into a dedicated method promotes
     * code maintainability and testability. It allows for potential future
     * enhancements to the comparison logic (such as supporting wildcard patterns,
     * minimum permission checks, or permission subset validation) without modifying
     * the main validate() method.
     *
     * @param string $current The current permission mode as a four-digit octal
     *                        string read from the filesystem. This value is expected
     *                        to have been returned by the readCurrentPermissions()
     *                        method.
     * @param string $expected The expected permission mode as a four-digit octal
     *                        string resolved from the configuration. This value is
     *                        expected to have been returned by the
     *                        resolveExpectedPermissions() method.
     *
     * @return bool Returns true if the current permissions exactly match the expected
     *              permissions, indicating that the validation passed. Returns false
     *              if the permissions do not match, indicating that the validation
     *              failed.
     */
    private function permissionsMatch(string $current, string $expected): bool {
        return $current === $expected;
    }
}
