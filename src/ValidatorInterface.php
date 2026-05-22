<?php

namespace Zyos\InstallBundle;

/**
 * ValidatorsInterface
 *
 * Defines the contract that all validator classes must implement within the
 * Zyos Install Bundle. This interface establishes a standardized structure for
 * validation logic, ensuring that all validators follow a consistent pattern
 * for validating configuration parameters, file paths, system requirements,
 * and other installation prerequisites.
 *
 * Validators implementing this interface are responsible for performing
 * specific validation checks during the installation or setup process. Each
 * validator focuses on a particular aspect of validation, such as checking
 * file existence, directory permissions, PHP extensions, environment variables,
 * or custom business rules required for the application to function correctly.
 *
 * The interface provides three essential methods:
 * - validate(): Performs the actual validation logic and returns the result
 * - getName(): Returns a unique identifier for the validator
 * - getTitle(): Returns a human-readable description of what the validator checks
 *
 * Implementations of this interface are typically discovered and executed
 * automatically by the bundle's validation pipeline, allowing for a flexible
 * and extensible validation system that can be easily extended with custom
 * validators for project-specific requirements.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle
 */
interface ValidatorInterface {

    /**
     * Performs the validation logic for this specific validator implementation.
     *
     * This method contains the core validation logic that determines whether
     * a particular condition or set of conditions is met. The method receives
     * optional parameters and arguments that can be used to customize the
     * validation behavior or provide context-specific data needed for the
     * validation check.
     *
     * The validation logic can include checks such as:
     * - Verifying file or directory existence and permissions
     * - Validating configuration values or formats
     * - Checking system requirements (PHP version, extensions, etc.)
     * - Testing database connectivity or schema integrity
     * - Validating environment variables or settings
     * - Performing custom business rule validations
     *
     * The method should return true if the validation passes, indicating that
     * the checked condition is satisfied. If the validation fails, the method
     * should return false, typically accompanied by appropriate error messaging
     * or logging to inform the user of the validation failure.
     *
     * @param array $params Optional associative array of configuration parameters
     *                      that may influence the validation behavior. These
     *                      parameters are typically defined in the bundle's
     *                      configuration and can include thresholds, paths,
     *                      expected values, or other validation criteria.
     *                      Default is an empty array.
     * @param array $arguments Optional array of additional arguments that may be
     *                         passed to the validator. These can include dynamic
     *                         values such as user input, command-line options,
     *                         or runtime context information. Default is an
     *                         empty array.
     *
     * @return bool Returns true if the validation passes successfully, indicating
     *              that all checked conditions are satisfied. Returns false if the
     *              validation fails, indicating that one or more conditions were
     *              not met.
     */
    public function validate(array $params = [], array $arguments = []): bool;

    /**
     * Returns a unique identifier for this validator implementation.
     *
     * This static method provides a machine-readable name that uniquely identifies
     * the validator within the Zyos Install Bundle's validation system. The name
     * is used for internal purposes such as validator registration, configuration
     * mapping, logging, and programmatic reference to specific validators.
     *
     * The name should be a short, descriptive string that follows a consistent
     * naming convention across all validators. Typically, this would be in
     * snake_case or camelCase format and should clearly indicate the type of
     * validation being performed (e.g., 'file_exists', 'php_version', 'db_connection').
     *
     * This identifier must be unique across all validator implementations to
     * prevent conflicts in the validation pipeline. The bundle may use this name
     * to look up validators from configuration files or to reference them in
     * validation reports and error messages.
     *
     * @return string A unique string identifier for this validator. The format
     *                should be consistent with the project's naming conventions
     *                and should not contain spaces or special characters that
     *                could cause issues with configuration parsing or logging.
     */
    public static function getName(): string;

    /**
     * Returns a human-readable title or description for this validator implementation.
     *
     * This static method provides a user-friendly description of what this validator
     * checks and validates. The title is intended for display purposes in user-facing
     * interfaces such as console output, validation reports, installation wizards,
     * or dashboard displays where users need to understand what validation is being
     * performed.
     *
     * The title should be clear, concise, and descriptive enough for non-technical
     * users to understand the purpose of the validation. It should explain what
     * condition is being checked in plain language, avoiding overly technical
     * jargon when possible. For example, instead of "filesystem_permission_check",
     * a good title might be "File System Permissions Check" or "Verify Directory
     * Write Access".
     *
     * This method complements the getName() method by providing a human-readable
     * counterpart to the machine-readable identifier. While getName() is used for
     * internal system operations, getTitle() is used for user communication and
     * interface display purposes.
     *
     * @return string A human-readable title describing the validation performed by
     *                this validator. The title should be clear, descriptive, and
     *                suitable for display in user interfaces. It may include spaces,
     *                punctuation, and should follow proper capitalization rules for
     *                readability.
     */
    public static function getTitle(): string;
}
