<?php

namespace Zyos\InstallBundle;

/**
 * Replacement
 *
 * A specialized utility class designed to handle environment placeholder replacement within
 * string templates and array structures. This class provides functionality to substitute
 * environment-specific placeholders with actual environment names or values, facilitating
 * dynamic configuration management across different deployment environments.
 *
 * The class is marked as final to prevent inheritance and ensure that its behavior remains
 * consistent and predictable throughout the application. It operates as a stateless service,
 * meaning it does not maintain any internal state between method calls, making it inherently
 * thread-safe and suitable for use in singleton or shared service contexts.
 *
 * The primary use case for this class is within the Zyos InstallBundle ecosystem, where
 * configuration files and templates may contain environment placeholders that need to be
 * resolved at runtime based on the current execution environment (e.g., development, staging,
 * production). This enables a single set of configuration templates to be used across multiple
 * environments while maintaining environment-specific values.
 *
 * Key responsibilities:
 * - Identifying and replacing environment placeholders in string templates
 * - Recursively processing multi-dimensional arrays to replace placeholders at any depth
 * - Maintaining non-string and non-array values unchanged during replacement operations
 * - Providing a consistent and predictable replacement mechanism using regular expressions
 *
 * Usage patterns:
 * This class is typically used as a service within the dependency injection container, injected
 * wherever environment placeholder resolution is required. It can process both individual
 * string templates and complex nested array structures containing configuration data.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle
 */
final class Replacement {

    /**
     * Regular expression pattern for identifying environment placeholders in strings.
     *
     * This constant defines the regex pattern used to locate environment placeholders within
     * string templates. The pattern matches the placeholder syntax "{{ env }}" with optional
     * whitespace around the "env" keyword, allowing for flexible formatting while maintaining
     * consistency.
     *
     * Pattern breakdown:
     * - \{ \{ : Matches the opening double curly braces literally
     * - \s* : Matches zero or more whitespace characters (allows for "{{env}}", "{{ env }}", etc.)
     * - env : Matches the literal string "env" (the placeholder identifier)
     * - \s* : Matches zero or more whitespace characters after the identifier
     * - \} \} : Matches the closing double curly braces literally
     *
     * This pattern is designed to be specific enough to avoid false positives while being
     * flexible enough to accommodate common formatting variations. The pattern is used with
     * PHP's preg_replace function to perform the actual substitution.
     *
     * @see Replacement::replace() Method that uses this pattern for string replacement
     * @see Replacement::arrayReplace() Method that uses this pattern for array processing
     */
    private const string ENV_PLACEHOLDER_PATTERN = '/\{\{\s*env\s*\}\}/';

    /**
     * Replaces environment placeholders in a string template with the specified environment value.
     *
     * This method performs a single-pass replacement operation on a string template, identifying
     * all occurrences of the environment placeholder pattern and substituting them with the
     * provided environment name or value. The replacement is performed using PHP's preg_replace
     * function, which allows for efficient pattern matching and substitution.
     *
     * The method is designed to be idempotent, meaning that calling it multiple times on the
     * same template with the same environment value will yield the same result after the first
     * call (since all placeholders are replaced in a single operation).
     *
     * Method behavior:
     * - Uses the ENV_PLACEHOLDER_PATTERN constant to identify placeholders
     * - Replaces all matching occurrences with the provided environment value
     * - Performs a global replacement (all occurrences in the string)
     * - Returns the modified string with placeholders substituted
     * - Does not modify the original template string (strings are immutable in PHP)
     * - If no placeholders are found, returns the original string unchanged
     *
     * @param string $template The string template containing potential environment placeholders.
     *                         This can be any string value, including configuration strings,
     *                         file paths, or other template content. The template may contain
     *                         zero, one, or multiple placeholder occurrences.
     * @param string $environment The environment value to substitute in place of the placeholders.
     *                           This is typically the name of the current environment (e.g., "dev",
     *                           "staging", "production") but can be any string value as required
     *                           by the application context.
     *
     * @return string The template string with all environment placeholders replaced by the
     *                specified environment value. If no placeholders are present in the template,
     *                the original string is returned unchanged.
     *
     * @see Replacement::ENV_PLACEHOLDER_PATTERN Constant defining the placeholder pattern
     * @see Replacement::arrayReplace() Method for processing arrays with placeholder replacement
     */
    public function replace(string $template, string $environment): string {
        return preg_replace(self::ENV_PLACEHOLDER_PATTERN, $environment, $template);
    }

    /**
     * Recursively replaces environment placeholders in all string values within an array structure.
     *
     * This method processes an array (potentially multi-dimensional) and applies the environment
     * placeholder replacement to all string values found at any depth within the array. Non-string
     * values (integers, booleans, objects, resources, etc.) are preserved unchanged, ensuring
     * data type integrity is maintained throughout the transformation process.
     *
     * The method uses PHP's array_map function with a callback to apply the replacement logic
     * to each array element. The callback delegates to the private replaceValue method, which
     * handles the recursive processing of nested arrays and the actual string replacement.
     *
     * This method is particularly useful for processing configuration arrays that may contain
     * environment-specific values at various nesting levels, such as database connection strings,
     * API endpoints, file paths, or other environment-dependent configuration parameters.
     *
     * Method behavior:
     * - Iterates through all elements in the provided array using array_map
     * - For each element, delegates to replaceValue for type-specific processing
     * - Recursively processes nested arrays to any depth
     * - Applies string replacement only to string values
     * - Preserves non-string values without modification
     * - Returns a new array with the same structure but with placeholders replaced
     * - Does not modify the original input array
     *
     * @param array $array The input array to process. This can be a simple associative or indexed
     *                     array, or a complex multi-dimensional array with nested structures. The
     *                     array may contain mixed data types (strings, integers, booleans, arrays,
     *                     objects, etc.).
     * @param string $environment The environment value to substitute in place of the placeholders.
     *                           This value is passed through to all recursive calls and string
     *                           replacement operations, ensuring consistent environment resolution
     *                           throughout the entire array structure.
     *
     * @return array A new array with the same structure and keys as the input array, but with all
     *               string values having their environment placeholders replaced by the specified
     *               environment value. Non-string values are preserved unchanged.
     *
     * @see Replacement::replace() Method used for actual string replacement
     * @see Replacement::replaceValue() Private helper method for value type handling
     */
    public function arrayReplace(array $array, string $environment): array {

        return array_map(
            fn(mixed $value) => $this->replaceValue($value, $environment),
            $array
        );
    }

    /**
     * Handles the replacement logic for individual values based on their data type.
     *
     * This private helper method serves as the core logic for the recursive array processing
     * performed by arrayReplace. It examines the type of each value and determines the
     * appropriate action: recursive processing for arrays, string replacement for strings,
     * or pass-through for all other types.
     *
     * The method implements a type-dispatch pattern, where the behavior is determined by
     * the runtime type of the value. This allows for flexible handling of mixed-type arrays
     * while maintaining type safety and avoiding unintended type coercion.
     *
     * Method behavior:
     * - Checks if the value is an array using is_array()
     * - If array: recursively calls arrayReplace to process nested structure
     * - Checks if the value is a string using is_string()
     * - If string: calls replace() to perform placeholder substitution
     * - For all other types (int, float, bool, object, resource, null): returns value unchanged
     * - This ensures type integrity and prevents modification of non-replaceable values
     *
     * @param mixed $value The value to process. Can be any PHP type including arrays, strings,
     *                     integers, floats, booleans, objects, resources, or null. The method
     *                     determines the appropriate processing based on the value's type.
     * @param string $environment The environment value to use for placeholder replacement.
     *                           This parameter is passed through to the replace() method when
     *                           processing string values, and to recursive arrayReplace() calls
     *                           when processing nested arrays.
     *
     * @return mixed The processed value. The return type matches the input type: arrays are
     *               returned with placeholders replaced recursively, strings are returned with
     *               placeholders replaced, and all other types are returned unchanged.
     *
     * @see Replacement::arrayReplace() Public method that calls this helper for each array element
     * @see Replacement::replace() Method used for string value replacement
     */
    private function replaceValue(mixed $value, string $environment): mixed {

        if (is_array($value)) {
            return $this->arrayReplace($value, $environment);
        }

        if (is_string($value)) {
            return $this->replace($value, $environment);
        }

        return $value;
    }
}
