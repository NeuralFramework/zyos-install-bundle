<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 18/04/22
     * Time: 11:01 p. m.
     */
    namespace Zyos\InstallBundle;

    /**
     * Class ValidatorsHandler
     *
     * @package Zyos\InstallBundle
     */
    class ValidatorsHandler extends ParameterBag {

        /**
         * Constructor ValidatorsHandler
         *
         * @param array $params
         */
        public function __construct(array $params = []) {
            parent::__construct($params);
        }

        /**
         * Method setIterable
         *
         * @param iterable $handlers
         *
         * @return void
         */
        public function setIterable(\Traversable $handlers): void {

            $array = iterator_to_array($handlers);

            /** @var ValidatorsInterface $item */
            foreach ($array AS $item):
                $this->set($item->getName(), $item);
            endforeach;
        }
    }