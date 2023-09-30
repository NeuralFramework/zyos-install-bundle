<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 17/04/22
     * Time: 8:53 p. m.
     */
    namespace Zyos\InstallBundle;

    /**
     * interface ValidatorsInterface
     *
     * @package Zyos\InstallBundle
     */
    interface ValidatorsInterface {

        /**
         * Generates the validation process corresponding to
         * the process required in data validation.
         *
         * @param array $params
         * @param array $arguments
         *
         * @return bool
         */
        public function validate(array $params = [], array $arguments = []): bool;

        /**
         * Get name of validation
         *
         * @return string
         */
        public static function getName(): string;

        /**
         * Method getTitle
         *
         * @return string
         */
        public static function getTitle(): string;
    }