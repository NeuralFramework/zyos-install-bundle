<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 24/09/23
     * Time: 4:04 p. m.
     */
    namespace Zyos\InstallBundle\Command;

    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Helper\Table;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
    use Symfony\Component\Filesystem\Filesystem;

    /**
     * Class SourceCommand
     *
     * @package Zyos\InstallBundle\Command
     */
    class SourceCommand extends Command {

        /**
         * @var ParameterBagInterface
         */
        private ParameterBagInterface $parameterBag;

        /**
         * @var Filesystem
         */
        private Filesystem $filesystem;

        /**
         * Constructor SourceCommand
         *
         * @param ParameterBagInterface $parameterBag
         * @param Filesystem $filesystem
         */
        public function __construct(ParameterBagInterface $parameterBag, Filesystem $filesystem) {

            parent::__construct(null);
            $this->parameterBag = $parameterBag;
            $this->filesystem = $filesystem;
        }

        /**
         * Configures the current command.
         */
        protected function configure(): void {

            $this->setName('zyos:source');
            $this->setDescription('Information about Bundle configuration');
        }

        /**
         * Executes the current command.
         *
         * This method is not abstract because you can use this class
         * as a concrete class. In this case, instead of defining the
         * execute() method, you set the code to execute by passing
         * a Closure to the setCode() method.
         *
         * @return int 0 if everything went fine, or an exit code
         *
         * @throws LogicException When this abstract method is not implemented
         *
         * @see setCode()
         */
        protected function execute(InputInterface $input, OutputInterface $output): int {

            $io = new SymfonyStyle($input, $output);
            $io->title(sprintf('Zyos Install Bundle <info>[%s] [source]</info>', $this->getName()));
            $io->text([
                'In this command you will find the general information of',
                'the different configurations of the bundle, in this case',
                'you will observe the flat information and you will be',
                'able to generate some additional processes for the creation',
                'of the structure.'
            ]);
            $io->newLine();

            $table = new Table($output);
            $table
                ->setHeaders(['Configuration', 'Value'])
                ->setRows([
                    ['Path', $this->parameterBag->get('zyos_install.path')],
                    ['Environments', implode(', ', $this->parameterBag->get('zyos_install.environments'))],
                    ['Lock Environments', implode(', ', $this->parameterBag->get('zyos_install.locks'))],
                    ['Lockfile', $this->parameterBag->get('zyos_install.lockfile')],
                    ['Install Count', count($this->parameterBag->get('zyos_install.install'))],
                    ['Validate Count', count($this->parameterBag->get('zyos_install.validate'))],
                    ['Filesystem Count', count($this->parameterBag->get('zyos_install.filesystem'))]

                ]);
            $table->render();

            $existsPath = $this->filesystem->exists($this->parameterBag->get('zyos_install.path'));
            $existLockfile = $this->filesystem->exists($this->parameterBag->get('zyos_install.lockfile'));

            $io->definitionList(
                ['Exists Path' => $existsPath ? '<fg=green;options=bold>True</>' : '<fg=red;options=bold>False</>'],
                ['Exists Lockfile' => $existLockfile ? '<fg=green;options=bold>True</>' : '<fg=red;options=bold>False</>'],
            );

            return Command::SUCCESS;
        }
    }