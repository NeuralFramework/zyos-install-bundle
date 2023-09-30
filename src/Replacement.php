<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 27/09/23
     * Time: 12:48 a. m.
     */
    namespace Zyos\InstallBundle;

    /**
     * Class Replacement
     *
     * @package Zyos\InstallBundle
     */
    final class Replacement {

        /**
         * Method replace
         *
         * @param string $template
         * @param string $environment
         *
         * @return string
         */
        public function replace(string $template, string $environment): string {

            $pattern = "/\{\{\s*env\s*\}\}/";
            return preg_replace($pattern, $environment, $template);
        }

        /**
         * Method arrayReplace
         *
         * @param array $array
         * @param string $environment
         *
         * @return array
         */
        public function arrayReplace(array $array, string $environment): array {

            $parameters = [];

            foreach ($array as $key => $value):
                if (is_array($value)):
                    $parameters[$key] = $this->arrayReplace($value, $environment);
                else:
                    $parameters[$key] = is_string($value) ? $this->replace($value, $environment) : $value;
                endif;
            endforeach;

            return $parameters;
        }
    }