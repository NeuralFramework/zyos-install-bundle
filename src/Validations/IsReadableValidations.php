<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 25/04/22
     * Time: 7:41 a. m.
     */
    namespace Zyos\InstallBundle\Validations;

    use RuntimeException;
    use Zyos\InstallBundle\ValidatorsInterface;

    /**
     * Class IsReadableValidations
     *
     * @package Zyos\InstallBundle\Validations
     */
    class IsReadableValidations implements ValidatorsInterface {

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
                throw new RuntimeException(sprintf('Validator %s: field "filepath" not found', $this->getName()));
            endif;

            if (empty($arguments['filepath'])):
                throw new RuntimeException(sprintf('Validator %s: field "filepath" empty', $this->getName()));
            endif;

            return is_readable($arguments['filepath']);
        }

        /**
         * Get name of validation
         *
         * @return string
         */
        public static function getName(): string {
            return 'is_readable';
        }

        /**
         * Method getTitle
         *
         * @return string
         */
        public static function getTitle(): string {
            return 'Is Readable';
        }
    }