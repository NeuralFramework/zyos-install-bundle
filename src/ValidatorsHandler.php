<?php

namespace Zyos\InstallBundle;

/**
 * ValidatorsHandler
 *
 * A specialized handler class for managing and storing validator instances.
 * This class extends ParameterBag to provide a structured way to organize
 * and access validator objects by their names. It serves as a central registry
 * for validators used throughout the Zyos Install Bundle, allowing for easy
 * retrieval and management of validation logic.
 *
 * The handler maintains a collection of validator objects where each validator
 * is stored with its name as the key, enabling quick lookup and access by name.
 * This design pattern is particularly useful in scenarios where multiple
 * validators need to be registered and accessed dynamically during the
 * installation or validation processes.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle
 */
class ValidatorsHandler extends ParameterBag {

    /**
     * ValidatorsHandler constructor.
     *
     * Initializes the validator handler with an optional array of parameters.
     * The constructor accepts an associative array of initial parameters that
     * will be stored in the underlying ParameterBag. This allows for pre-populating
     * the handler with validators or other configuration data during instantiation.
     *
     * The parameters array should follow the structure where keys represent
     * validator names and values represent the corresponding validator objects
     * or configuration data. If no parameters are provided, the handler is
     * initialized with an empty collection.
     *
     * @param array $params An optional associative array of initial parameters
     *                      to populate the handler. Default is an empty array.
     *                      The array keys should be string identifiers for
     *                      validators, and values can be validator objects or
     *                      related configuration data.
     *
     * @return void
     */
    public function __construct(array $params = []) {
        parent::__construct($params);
    }

    /**
     * Sets validators from a traversable collection.
     *
     * This method accepts any traversable collection (such as an Iterator,
     * IteratorAggregate, or array) containing validator objects and adds them
     * to the handler. Each validator in the collection is stored using its
     * name as the key, which is obtained by calling the getName() method on
     * the validator object.
     *
     * The method converts the traversable collection to an array using
     * iterator_to_array() to ensure efficient iteration. For each validator
     * in the collection, it extracts the validator's name and stores the
     * validator instance in the parameter bag using that name as the key.
     *
     * This approach allows for bulk registration of validators from various
     * sources such as configuration files, service containers, or other
     * collections, providing flexibility in how validators are loaded and
     * managed within the application.
     *
     * @param \Traversable $handlers A traversable collection of validator objects.
     *                               Each object in the collection must implement
     *                               a getName() method that returns a string
     *                               identifier for the validator. The collection
     *                               can be any PHP Traversable including iterators,
     *                               generator functions, or objects implementing
     *                               IteratorAggregate.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If any handler object in the collection
     *                                  does not have a getName() method or if
     *                                  the method returns a non-string value.
     * @throws \RuntimeException If there is an error converting the traversable
     *                           to an array or during the iteration process.
     */
    public function setIterable(\Traversable $handlers): void {

        $array = iterator_to_array($handlers);
        foreach ($array AS $item):
            $this->set($item->getName(), $item);
        endforeach;
    }
}
