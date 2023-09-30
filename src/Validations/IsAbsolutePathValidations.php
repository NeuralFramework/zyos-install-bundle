<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 25/04/22
     * Time: 7:47 a. m.
     */
    namespace Zyos\InstallBundle\Validations;

    use RuntimeException;
    use Symfony\Component\Filesystem\Filesystem;
    use Zyos\InstallBundle\ValidatorsInterface;

    /**
     * Class IsAbsolutePathValidations
     *
     * @package Zyos\InstallBundle\Validations
     */
    class IsAbsolutePathValidations implements ValidatorsInterface {

        /**
         * @var Filesystem
         */
        private Filesystem $filesystem;

        /**
         * Constructor IsAbsolutePathValidations
         *
         * @param Filesystem $filesystem
         */
        public function __construct(Filesystem $filesystem) {
            $this->filesystem = $filesystem;
        }

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

            return $this->filesystem->isAbsolutePath($arguments['filepath']);
        }

        /**
         * Get name of validation
         *
         * @return string
         */
        public static function getName(): string {
            return 'is_absolute_path';
        }

        /**
         * Method getTitle
         *
         * @return string
         */
        public static function getTitle(): string {
            return 'Is Absolute Path';
        }
    }