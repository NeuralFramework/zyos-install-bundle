<?php

namespace Zyos\InstallBundle;

use ArrayIterator;

/**
 * ParameterBag
 *
 * A flexible and powerful parameter container class that provides a robust interface for managing
 * and manipulating array-based data structures. This class implements the IteratorAggregate and
 * Countable interfaces, enabling it to be used in foreach loops and count operations seamlessly.
 *
 * The ParameterBag serves as a wrapper around an associative array, offering a comprehensive set
 * of methods for accessing, modifying, filtering, and transforming the underlying data. It is
 * particularly useful in scenarios where structured parameter management is required, such as
 * configuration handling, request parameter processing, or data collection manipulation within
 * the Zyos InstallBundle ecosystem.
 *
 * Key responsibilities:
 * - Encapsulating array data with a fluent object-oriented interface
 * - Providing safe access to array elements with default value support
 * - Supporting data transformation operations like filtering and sorting
 * - Enabling iteration and counting through standard PHP interfaces
 * - Facilitating column-based sorting for multi-dimensional arrays
 * - Offering immutable-like operations that return new instances
 *
 * Usage patterns:
 * This class is designed for both simple key-value storage and complex multi-dimensional array
 * operations. It promotes functional programming patterns by returning new instances for
 * transformation methods rather than modifying the current instance in place.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle
 */
class ParameterBag implements \IteratorAggregate, \Countable {

    /**
     * Constant representing ascending order for sorting operations.
     *
     * This constant is used as a flag to indicate that sorting operations should be performed
     * in ascending order (from lowest to highest). It is primarily used by the orderByColumn
     * method to determine the sort direction when organizing multi-dimensional array data
     * based on a specific column or key value.
     *
     * Value: 1
     *
     * @see ParameterBag::orderByColumn() Method that uses this constant for sorting direction
     */
    public const int ORDER_ASC  = 1;

    /**
     * Constant representing descending order for sorting operations.
     *
     * This constant is used as a flag to indicate that sorting operations should be performed
     * in descending order (from highest to lowest). It is primarily used by the orderByColumn
     * method to determine the sort direction when organizing multi-dimensional array data
     * based on a specific column or key value.
     *
     * Value: 2
     *
     * @see ParameterBag::orderByColumn() Method that uses this constant for sorting direction
     */
    public const int ORDER_DESC = 2;

    /**
     * Constructor for the ParameterBag class.
     *
     * Initializes a new ParameterBag instance with an optional array of parameters. The constructor
     * uses constructor property promotion to directly assign the provided array to the private
     * $params property. If no array is provided, an empty array is used as the default value,
     * creating an empty ParameterBag instance.
     *
     * The parameters array can contain any type of data, including simple key-value pairs or
     * complex multi-dimensional structures. The class provides methods to handle both scenarios
     * appropriately, with specific methods like orderByColumn designed for multi-dimensional
     * array operations.
     *
     * Method behavior:
     * - Accepts an optional array of parameters (defaults to empty array)
     * - Stores the array in the private $params property using constructor property promotion
     * - Does not perform any validation or transformation on the input data
     * - Creates a new independent instance; modifications to the original array after
     *   construction will not affect this instance (though the array itself is not cloned)
     *
     * @param array $params The initial array of parameters to store in the bag. This can be
     *                      an associative array, indexed array, or multi-dimensional array.
     *                      Defaults to an empty array if not provided.
     *
     * @return void
     */
    public function __construct(private array $params = []) {

    }

    /**
     * Checks whether a specific key exists in the parameter array.
     *
     * This method provides a safe way to determine if a given key is present in the underlying
     * parameter array without triggering a warning or error. It uses the PHP array_key_exists
     * function, which checks for the existence of a key regardless of its associated value,
     * including keys with null values.
     *
     * This is particularly useful for conditional logic where you need to verify the presence
     * of a parameter before attempting to access or manipulate it. Unlike isset(), this method
     * returns true even if the key exists but has a null value.
     *
     * Method behavior:
     * - Accepts a key that can be either a string or an integer
     * - Performs an exact key match check (case-sensitive for strings)
     * - Returns true if the key exists in the array, false otherwise
     * - Does not modify the parameter array in any way
     *
     * @param string|int $key The key to check for existence in the parameter array. Can be
     *                        a string for associative arrays or an integer for indexed arrays.
     *
     * @return bool True if the key exists in the parameter array, false otherwise.
     *
     * @see ParameterBag::get() Method that uses this check for safe value retrieval
     */
    public function has(string|int $key): bool {
        return array_key_exists($key, $this->params);
    }

    /**
     * Retrieves the value associated with a specific key from the parameter array.
     *
     * This method provides safe access to parameter values by checking for the existence of
     * the key before attempting to retrieve its value. If the key does not exist, a default
     * value is returned instead, preventing undefined index warnings or errors.
     *
     * The method uses strict type checking for the key lookup and returns the exact value
     * stored in the array without any type coercion or transformation. This ensures that
     * the retrieved value maintains its original type and structure.
     *
     * Method behavior:
     * - First checks if the key exists using the has() method
     * - If the key exists, returns the associated value from the parameter array
     * - If the key does not exist, returns the provided default value (false by default)
     * - Does not modify the parameter array
     * - Performs no type conversion on the returned value
     *
     * @param string|int $key The key whose value should be retrieved. Can be a string for
     *                        associative arrays or an integer for indexed arrays.
     * @param mixed $default The default value to return if the key does not exist. Defaults
     *                       to false. This can be any type (null, array, object, etc.) depending
     *                       on the expected value type.
     *
     * @return mixed The value associated with the key if it exists, otherwise the default value.
     *                The return type matches the type of the stored value or the default value.
     *
     * @see ParameterBag::has() Method used to check key existence before retrieval
     * @see ParameterBag::set() Method for setting values in the parameter array
     */
    public function get(string|int $key, mixed $default = false): mixed {
        return $this->has($key) ? $this->params[$key] : $default;
    }

    /**
     * Sets or updates a value for a specific key in the parameter array.
     *
     * This method allows adding new key-value pairs to the parameter array or updating the
     * value of an existing key. It provides a clean object-oriented interface for array
     * manipulation, abstracting away the direct array access syntax.
     *
     * The method overwrites any existing value associated with the specified key without
     * warning or confirmation. If the key does not exist, it is created with the provided
     * value. This behavior is consistent with standard array assignment operations in PHP.
     *
     * Method behavior:
     * - Accepts a key (string or integer) and a value (any type)
     * - Assigns the value to the specified key in the parameter array
     * - Overwrites existing values if the key already exists
     * - Creates new entries if the key does not exist
     * - Modifies the parameter array in place (mutates the instance)
     *
     * @param string|int $key The key to set or update in the parameter array. Can be a string
     *                        for associative arrays or an integer for indexed arrays.
     * @param mixed $value The value to associate with the key. Can be any PHP type including
     *                     scalars, arrays, objects, resources, or null.
     *
     * @return void
     *
     * @see ParameterBag::get() Method for retrieving values set by this method
     * @see ParameterBag::has() Method for checking if a key exists before setting
     */
    public function set(string|int $key, mixed $value): void {
        $this->params[$key] = $value;
    }

    /**
     * Returns the complete parameter array.
     *
     * This method provides direct access to the underlying array containing all parameters
     * stored in the ParameterBag instance. It returns the entire array structure without
     * any modification, filtering, or transformation.
     *
     * This method is useful when you need to pass the entire parameter collection to another
     * function or method that expects an array, or when you need to perform operations that
     * are not supported by the ParameterBag's interface.
     *
     * Method behavior:
     * - Returns a reference to the internal parameter array
     * - Does not create a copy of the array (modifications to the returned array will
     *   affect the ParameterBag instance)
     * - Returns the array in its current state with all keys and values intact
     * - Performs no validation or transformation on the data
     *
     * @return array The complete parameter array containing all key-value pairs currently
     *               stored in the ParameterBag instance. The array structure matches exactly
     *               what was provided during construction or modified through set operations.
     *
     * @see ParameterBag::__construct() Constructor that initializes the parameter array
     * @see ParameterBag::set() Method for modifying the parameter array
     */
    public function all(): array {
        return $this->params;
    }

    /**
     * Returns all values from the parameter array, re-indexed numerically.
     *
     * This method extracts all values from the parameter array and returns them as a
     * numerically indexed array, discarding the original keys. The re-indexing starts
     * from 0 and increments sequentially, regardless of the original key types or values.
     *
     * This operation is particularly useful when you need to work with the values as a
     * simple list without regard for their original keys, or when passing the data to
     * functions that expect a sequential array rather than an associative one.
     *
     * Method behavior:
     * - Uses PHP's array_values function to extract values
     * - Creates a new numerically indexed array starting from 0
     * - Preserves the order of values as they appear in the original array
     * - Does not modify the internal parameter array
     * - Returns a new array, not a reference to the internal data
     *
     * @return array A numerically indexed array containing all values from the parameter
     *               array. The keys are sequential integers (0, 1, 2, ...) and the values
     *               maintain their original order from the source array.
     *
     * @see ParameterBag::keys() Method for retrieving all keys from the parameter array
     * @see ParameterBag::all() Method for retrieving the complete parameter array with keys
     */
    public function values(): array {
        return array_values($this->params);
    }

    /**
     * Returns all keys from the parameter array, re-indexed numerically.
     *
     * This method extracts all keys from the parameter array and returns them as a
     * numerically indexed array, discarding the associated values. The re-indexing
     * starts from 0 and increments sequentially, regardless of the original key types.
     *
     * This operation is useful when you need to work with the keys as a simple list,
     * validate key presence, or iterate over the available keys without accessing
     * their corresponding values.
     *
     * Method behavior:
     * - Uses PHP's array_keys function to extract keys
     * - Creates a new numerically indexed array starting from 0
     * - Preserves the order of keys as they appear in the original array
     * - Does not modify the internal parameter array
     * - Returns a new array, not a reference to the internal data
     *
     * @return array A numerically indexed array containing all keys from the parameter
     *               array. The keys are sequential integers (0, 1, 2, ...) and the
     *               original keys (whether strings or integers) become the values.
     *
     * @see ParameterBag::values() Method for retrieving all values from the parameter array
     * @see ParameterBag::all() Method for retrieving the complete parameter array
     */
    public function keys(): array {
        return array_keys($this->params);
    }

    /**
     * Checks whether a specific value exists in the parameter array.
     *
     * This method performs a strict search for a given value within the parameter array,
     * using both value and type comparison (===). This ensures that the search is precise
     * and does not produce false positives due to type juggling.
     *
     * The strict comparison is particularly important when distinguishing between values
     * that might be loosely equal but have different types, such as the integer 0 and the
     * string "0", or boolean false and null.
     *
     * Method behavior:
     * - Uses PHP's in_array function with strict type checking enabled
     * - Performs a linear search through all values in the parameter array
     * - Returns true if the value is found with matching type, false otherwise
     * - Does not modify the internal parameter array
     * - Searches only values, not keys
     *
     * @param mixed $value The value to search for in the parameter array. Can be any PHP
     *                     type. The search uses strict type comparison, so both the value
     *                     and type must match exactly.
     *
     * @return bool True if the value exists in the parameter array with matching type,
     *              false otherwise.
     *
     * @see ParameterBag::inKey() Method for checking if a value exists in a specific key's array
     * @see ParameterBag::has() Method for checking if a key exists (not a value)
     */
    public function in(mixed $value): bool {
        return in_array($value, $this->params, true);
    }

    /**
     * Checks whether a specific value exists within an array stored at a given key.
     *
     * This method is designed for multi-dimensional arrays where a specific key contains
     * an array of values. It retrieves the array at the specified key and performs a
     * strict search for the given value within that sub-array.
     *
     * This method assumes that the value at the specified key is an array. If the key
     * does not exist or contains a non-array value, PHP will generate a warning. It is
     * the caller's responsibility to ensure the key contains an array before calling this
     * method, typically by using the has() method first.
     *
     * Method behavior:
     * - Accesses the array stored at the specified key
     * - Uses PHP's in_array function with strict type checking on the sub-array
     * - Performs a linear search through all values in the sub-array
     * - Returns true if the value is found with matching type, false otherwise
     * - Does not modify the internal parameter array
     *
     * @param string|int $key The key whose associated array should be searched. The value
     *                        at this key must be an array for the method to work correctly.
     * @param mixed $value The value to search for within the array at the specified key.
     *                     Can be any PHP type. The search uses strict type comparison.
     *
     * @return bool True if the value exists in the array at the specified key with matching
     *              type, false otherwise.
     *
     * @throws \Warning If the specified key does not exist or does not contain an array value.
     *                  This is a PHP warning, not a thrown exception.
     *
     * @see ParameterBag::in() Method for checking if a value exists in the main parameter array
     * @see ParameterBag::has() Method for verifying key existence before calling this method
     */
    public function inKey(string|int $key, mixed $value): bool {
        return in_array($value, $this->params[$key], true);
    }

    /**
     * Filters the parameter array using a callback function and returns a new ParameterBag.
     *
     * This method applies a filtering operation to the parameter array using a user-provided
     * callback function. Each element of the array is passed to the callback, and only those
     * elements for which the callback returns true are included in the result.
     *
     * The method follows functional programming principles by returning a new ParameterBag
     * instance rather than modifying the current instance. This allows for chaining multiple
     * operations without side effects.
     *
     * Method behavior:
     * - Uses PHP's array_filter function with the provided callback
     * - Re-indexes the resulting array numerically using array_values
     * - Creates and returns a new ParameterBag instance with the filtered data
     * - Does not modify the original parameter array or the current instance
     * - Preserves the order of elements that pass the filter
     *
     * @param callable $callback A callback function used to determine which elements should
     *                           be included in the result. The callback receives the array
     *                           value as the first argument and the array key as the second
     *                           argument. It should return true to include the element, or
     *                           false to exclude it.
     *
     * @return self A new ParameterBag instance containing only the elements that passed
     *              the filter callback. The result is a numerically indexed array of the
     *              filtered elements.
     *
     * @see ParameterBag::self() Method for creating a new instance from a subset of data
     */
    public function filter(callable $callback): self {
        return new self(array_values(array_filter($this->params, $callback)));
    }

    /**
     * Sorts a multi-dimensional parameter array by a specific column/key value.
     *
     * This method is designed for multi-dimensional arrays where each element is an
     * associative array with common keys. It groups elements by the value of a specified
     * column/key, sorts the groups based on that value, and returns a new ParameterBag
     * with the sorted data.
     *
     * The sorting operation can be performed in either ascending or descending order,
     * controlled by the $order parameter which accepts the ORDER_ASC or ORDER_DESC constants.
     * If the parameter array is empty, the method returns a new empty ParameterBag instance.
     *
     * Method behavior:
     * - Checks if the parameter array is empty using isEmpty()
     * - Groups array elements by the value of the specified column/key
     * - Sorts the groups by their keys in the specified order (ascending or descending)
     * - Creates and returns a new ParameterBag instance with the sorted data
     * - Does not modify the original parameter array or the current instance
     *
     * @param string|int $key The column/key within each array element to sort by. All elements
     *                        in the parameter array must have this key for the method to work
     *                        correctly.
     * @param int $order The sort direction. Must be either self::ORDER_ASC for ascending order
     *                   or self::ORDER_DESC for descending order. Defaults to self::ORDER_ASC.
     *
     * @return self A new ParameterBag instance with the multi-dimensional array sorted by the
     *              specified column. The structure is grouped by the column value, with each
     *              group containing arrays that share that value.
     *
     * @see ParameterBag::ORDER_ASC Constant for ascending sort order
     * @see ParameterBag::ORDER_DESC Constant for descending sort order
     * @see ParameterBag::isEmpty() Helper method used to check for empty array
     * @see ParameterBag::groupByColumnValue() Helper method for grouping elements
     * @see ParameterBag::sortGroups() Helper method for sorting the groups
     */
    public function orderByColumn(string|int $key, int $order = self::ORDER_ASC): self {

        if ($this->isEmpty()) {
            return new self();
        }

        $grouped = $this->groupByColumnValue($key);
        $sorted  = $this->sortGroups($grouped, $order);

        return new self($sorted);
    }

    /**
     * Creates a new ParameterBag instance from the value stored at a specific key.
     *
     * This method retrieves the value at the specified key and uses it to create a new
     * ParameterBag instance. If the key does not exist, an empty array is used as the
     * default value, resulting in an empty ParameterBag.
     *
     * This method is particularly useful for nested data structures where a key contains
     * an array that should be treated as a separate ParameterBag. It allows for hierarchical
     * data organization and enables fluent chaining of ParameterBag operations on nested
     * structures.
     *
     * Method behavior:
     * - Retrieves the value at the specified key using the get() method
     * - Uses an empty array as the default if the key does not exist
     * - Creates and returns a new ParameterBag instance with the retrieved value
     * - Does not modify the original parameter array or the current instance
     * - The retrieved value should typically be an array for meaningful use
     *
     * @param string|int $key The key whose value should be used to create a new ParameterBag
     *                        instance. The value at this key is typically expected to be an
     *                        array, but any type can be passed to the constructor.
     *
     * @return self A new ParameterBag instance initialized with the value from the specified
     *              key. If the key does not exist, returns a new empty ParameterBag instance.
     *
     * @see ParameterBag::get() Method used to retrieve the value at the specified key
     * @see ParameterBag::__construct() Constructor used to create the new instance
     */
    public function self(string|int $key): self {
        return new self($this->get($key, []));
    }

    /**
     * Returns the first value from the parameter array.
     *
     * This method retrieves the first value from the numerically indexed array of values.
     * It uses the values() method to obtain a re-indexed array and then returns the
     * element at index 0. If the parameter array is empty, the method returns false.
     *
     * This operation is useful when you need to access the first element without regard
     * for its original key, or when you want to perform operations on a single representative
     * element from the collection.
     *
     * Method behavior:
     * - Calls values() to obtain a numerically indexed array of all values
     * - Attempts to access the element at index 0
     * - Returns the first value if it exists, or false if the array is empty
     * - Does not modify the internal parameter array
     * - The return type is mixed (could be any type or false)
     *
     * @return mixed The first value from the parameter array, or false if the array is empty.
     *                The return type matches the type of the first value in the array, or
     *                boolean false if no values exist.
     *
     * @see ParameterBag::values() Method used to obtain the numerically indexed array
     * @see ParameterBag::isEmpty() Method for checking if the array is empty
     */
    public function first(): mixed {

        $values = $this->values();
        return $values[0] ?? false;
    }

    /**
     * Returns an external iterator for the parameter array.
     *
     * This method is part of the IteratorAggregate interface implementation, which allows
     * the ParameterBag to be used in foreach loops and other iteration contexts. It creates
     * and returns an ArrayIterator instance that provides sequential access to the parameter
     * array elements.
     *
     * By implementing this interface, the ParameterBag can be used directly in iteration
     * constructs without needing to expose the internal array or implement the full Iterator
     * interface with its multiple methods.
     *
     * Method behavior:
     * - Creates a new ArrayIterator instance with the parameter array
     * - Returns the iterator for external use
     * - The iterator provides standard iteration functionality (current, key, next, valid, rewind)
     * - Does not modify the internal parameter array
     * - Each call creates a new iterator instance
     *
     * @return ArrayIterator An iterator that enables traversal of the parameter array.
     *                        The iterator implements the Iterator interface and provides
     *                        sequential access to all key-value pairs in the array.
     *
     * @see \IteratorAggregate Interface that requires this method implementation
     * @see ArrayIterator PHP class used for array iteration
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->params);
    }

    /**
     * Returns the number of elements in the parameter array.
     *
     * This method is part of the Countable interface implementation, which allows the
     * ParameterBag to be used with PHP's count() function. It returns the total number
     * of key-value pairs currently stored in the parameter array.
     *
     * This interface implementation enables natural PHP syntax for counting elements,
     * such as using count($parameterBag) directly, which internally calls this method.
     *
     * Method behavior:
     * - Uses PHP's count function to determine the number of elements
     * - Returns an integer representing the total count
     * - Counts all key-value pairs regardless of key type or value type
     * - Does not modify the internal parameter array
     *
     * @return int The number of elements in the parameter array. Returns 0 if the array
     *             is empty.
     *
     * @see \Countable Interface that requires this method implementation
     * @see ParameterBag::isEmpty() Helper method that uses this count for emptiness check
     */
    public function count(): int {
        return count($this->params);
    }

    /**
     * Checks whether the parameter array is empty.
     *
     * This private helper method determines if the parameter array contains no elements.
     * It uses the count() method to get the total number of elements and checks if the
     * result equals zero.
     *
     * This method is used internally by other methods that need to handle empty arrays
     * specially, such as orderByColumn, to avoid unnecessary processing or potential
     * errors when operating on empty data structures.
     *
     * Method behavior:
     * - Calls count() to get the number of elements in the parameter array
     * - Returns true if the count is 0, false otherwise
     * - Does not modify the internal parameter array
     * - Provides a semantic check for emptiness rather than exposing the raw count
     *
     * @return bool True if the parameter array contains no elements, false if it contains
     *              one or more elements.
     *
     * @see ParameterBag::count() Method used to determine the element count
     * @see ParameterBag::orderByColumn() Method that uses this check for early return
     */
    private function isEmpty(): bool {
        return $this->count() === 0;
    }

    /**
     * Groups multi-dimensional array elements by the value of a specific column/key.
     *
     * This private helper method is used by orderByColumn to organize array elements
     * based on the value of a specified column/key. It iterates through the parameter
     * array (which is expected to contain associative arrays) and creates a new grouped
     * array where the keys are the column values and the values are arrays of elements
     * that share that column value.
     *
     * This grouping operation is a preprocessing step for sorting, as it allows the
     * sort operation to work with the column values as keys rather than having to extract
     * and compare them repeatedly.
     *
     * Method behavior:
     * - Initializes an empty groups array
     * - Iterates through each element in the parameter array
     * - For each element, uses the value at the specified key as a group key
     * - Appends the element to the array at that group key
     * - Returns the grouped array structure
     * - Does not modify the internal parameter array
     *
     * @param string|int $key The column/key within each array element to group by. All
     *                        elements must have this key for proper grouping.
     *
     * @return array A grouped array where keys are the values from the specified column
     *               and values are arrays of elements that share that column value.
     *
     * @see ParameterBag::orderByColumn() Method that uses this helper for grouping
     * @see ParameterBag::sortGroups() Method that sorts the result of this grouping
     */
    private function groupByColumnValue(string|int $key): array {

        $groups = [];

        foreach ($this->params as $item) {
            $groups[$item[$key]][] = $item;
        }

        return $groups;
    }

    /**
     * Sorts a grouped array by its keys in the specified order.
     *
     * This private helper method is used by orderByColumn to sort the groups created
     * by groupByColumnValue. It performs either ascending (ksort) or descending (krsort)
     * sorting on the array keys, depending on the order parameter.
     *
     * The sorting is performed in-place on the provided array, modifying it directly.
     * This is acceptable because the array is created specifically for this operation
     * within the orderByColumn method and is not used elsewhere.
     *
     * Method behavior:
     * - Checks the order parameter against ORDER_ASC constant
     * - If ORDER_ASC, uses ksort for ascending key sort
     * - If ORDER_DESC, uses krsort for descending key sort
     * - Performs the sort operation in-place on the provided array
     * - Returns the sorted array
     *
     * @param array $groups The grouped array to sort. This should be the output from
     *                      groupByColumnValue, where keys are column values and values
     *                      are arrays of elements.
     * @param int $order The sort direction. Must be either self::ORDER_ASC or
     *                   self::ORDER_DESC. Determines whether ksort or krsort is used.
     *
     * @return array The same array instance with keys sorted in the specified order.
     *               The array is sorted in-place and then returned.
     *
     * @see ParameterBag::orderByColumn() Method that uses this helper for sorting
     * @see ParameterBag::groupByColumnValue() Method that creates the groups to sort
     * @see ParameterBag::ORDER_ASC Constant for ascending sort order
     * @see ParameterBag::ORDER_DESC Constant for descending sort order
     */
    private function sortGroups(array $groups, int $order): array {

        $order === self::ORDER_ASC ? ksort($groups) : krsort($groups);
        return $groups;
    }
}
