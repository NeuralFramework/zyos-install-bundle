<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 18/04/22
     * Time: 5:30 p. m.
     */
    namespace Zyos\InstallBundle\Validations;

    use RuntimeException;
    use Zyos\InstallBundle\ValidatorsInterface;

    /**
     * Class IsFileValidations
     *
     * @package Zyos\InstallBundle\Validations
     */
    class IsFileValidations implements ValidatorsInterface {

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
                throw new RuntimeException(sprintf('Validator %s: field "filepath" not found', IsFileValidations::getName()));
            endif;

            if (empty($arguments['filepath'])):
                throw new RuntimeException(sprintf('Validator %s: field "filepath" empty', IsFileValidations::getName()));
            endif;

            return is_file($arguments['filepath']);
        }

        /**
         * Get name of validation
         *
         * @return string
         */
        public static function getName(): string {
            return 'is_file';
        }

        /**
         * Method getTitle
         *
         * @return string
         */
        public static function getTitle(): string {
            return 'Is File';
        }
    }