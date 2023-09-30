<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 13/04/22
     * Time: 3:04 a. m.
     */
    namespace Zyos\InstallBundle;

    use LogicException;
    use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
    use Symfony\Component\HttpKernel\Bundle\Bundle;
    use Zyos\InstallBundle\DependencyInjection\InstallExtension;

    /**
     * Class InstallBundle
     *
     * @package Zyos\InstallBundle
     */
    class InstallBundle extends Bundle {

        /**
         * Returns the bundle's container extension.
         *
         * @throws LogicException
         */
        public function getContainerExtension(): ?ExtensionInterface {
            return new InstallExtension();
        }
    }