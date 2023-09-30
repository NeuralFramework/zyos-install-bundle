<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 18/04/22
     * Time: 5:09 p. m.
     */
    namespace Zyos\InstallBundle\Validations;

    use RuntimeException;
    use Zyos\InstallBundle\ValidatorsInterface;

    /**
     * Class IsDirValidations
     *
     * @package Zyos\InstallBundle\Validations
     */
    class IsDirValidations implements ValidatorsInterface {

        /**
         * Generates the validation process corresponding to
         * the process required in data validation.
         *
         * @param array $params
         * @param array $arguments
         *
         * @return bool
         */
        public function validate(array $params = [], array $arguments = []): bool {

            if (!array_key_exists('filepath', $arguments)):
                throw new RuntimeException(sprintf('Validator %s: field "filepath" not found', IsDirValidations::getName()));
            endif;

            if (empty($arguments['filepath'])):
                throw new RuntimeException(sprintf('Validator %s: field "filepath" empty', IsDirValidations::getName()));
            endif;

            return is_dir($arguments['filepath']);
        }

        /**
         * Get name of validation
         *
         * @return string
         */
        public static function getName(): string {
            return 'is_dir';
        }

        /**
         * Method getTitle
         *
         * @return string
         */
        public static function getTitle(): string {
            return 'Is Directory';
        }

    }