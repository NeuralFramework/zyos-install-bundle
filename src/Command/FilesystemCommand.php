<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 7/07/22
     * Time: 11:44 a. m.
     */
    namespace Zyos\InstallBundle\Command;

    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Exception\LogicException;
    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
    use Symfony\Component\Filesystem\Exception\IOException;
    use Symfony\Component\Filesystem\Filesystem;
    use Zyos\InstallBundle\ParameterBag;
    use Zyos\InstallBundle\Replacement;

    /**
     * Class FilesystemCommand
     *
     * @package Zyos\InstallBundle\Command
     */
    class FilesystemCommand extends Command {

        /**
         * @var ParameterBagInterface
         */
        private ParameterBagInterface $parameterBag;

        /**
         * @var Filesystem
         */
        private Filesystem $filesystem;

        /**
         * @var string
         */
        private string $environment;

        /**
         * @var bool
         */
        private bool $mirror;

        /**
         * @var bool
         */
        private bool $symlink;

        /**
         * @var mixed
         */
        private bool $directory;

        /**
         * @var bool
         */
        private bool $showOutput;

        /**
         * @var Replacement
         */
        private Replacement $replacement;

        /**
         * Constructor FilesystemCommand
         *
         * @param ParameterBagInterface $parameterBag
         * @param Filesystem $filesystem
         * @param Replacement $replacement
         */
        public function __construct(ParameterBagInterface $parameterBag, Filesystem $filesystem, Replacement $replacement) {

            parent::__construct(null);
            $this->parameterBag = $parameterBag;
            $this->filesystem = $filesystem;
            $this->replacement = $replacement;
        }

        /**
         * Configures the current command.
         */
        protected function configure(): void {

            $this->setName('zyos:filesystem');
            $this->setDescription('Run Directory creation, Symlink and Directory Mirroring');
            $this->addArgument('environment', InputArgument::OPTIONAL, 'Runtime environment', 'dev');
            $this->addOption('mirror', null, InputOption::VALUE_NONE, 'Run only directory mirroring');
            $this->addOption('symlink', null, InputOption::VALUE_NONE, 'Run only create symlink');
            $this->addOption('directory', null, InputOption::VALUE_NONE, 'Run directory creation only');
            $this->addOption('show-output', null, InputOption::VALUE_NONE, 'Show command output');
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

            $this->environment = $input->getArgument('environment');
            $this->mirror = $input->getOption('mirror');
            $this->symlink = $input->getOption('symlink');
            $this->directory = $input->getOption('directory');
            $this->showOutput = $input->getOption('show-output');

            $io = new SymfonyStyle($input, $output);
            $io->title(sprintf('Filesystem Command <info>[%s] [%s]</info>', $this->getName(), $this->environment));
            $io->text([
                'This process generates the creation of symlink and copies',
                'of files and/or directories which are necessary for the',
                'deployment of the application.'
            ]);
            $io->newLine(1);

            return $this->validateEnvironment($io);
        }

        /**
         * Method validateEnvironment
         *
         * @param SymfonyStyle $io
         *
         * @return int
         */
        private function validateEnvironment(SymfonyStyle $io): int {

            if (!$this->parameterBag->has('zyos_install.environments')):
                $io->error('Environments configuration cannot be found');
                return Command::FAILURE;
            endif;

            if (!in_array($this->environment, $this->parameterBag->get('zyos_install.environments'))):
                $io->error(sprintf('the environment [%s] is not found in the configuration', $this->environment));
                return Command::INVALID;
            endif;

            return $this->validateLockFile($io);
        }

        /**
         * Method validateLockFile
         *
         * @param SymfonyStyle $io
         *
         * @return int
         */
        private function validateLockFile(SymfonyStyle $io): int {

            if (!$this->parameterBag->has('zyos_install.locks')):
                $io->error('Environments configuration cannot be found');
                return Command::FAILURE;
            endif;

            if (!$this->parameterBag->has('zyos_install.lockfile')):
                $io->error('Lock file configuration cannot be found');
                return Command::FAILURE;
            endif;

            if (in_array($this->environment, $this->parameterBag->get('zyos_install.locks'))):
                if ($this->filesystem->exists($this->parameterBag->get('zyos_install.lockfile'))):
                    $io->error(sprintf('The file [%s] already exists', $this->parameterBag->get('zyos_install.lockfile')));
                    return Command::FAILURE;
                endif;
            endif;

            return $this->validateConfiguration($io);
        }

        /**
         * Method validateConfiguration
         *
         * @param SymfonyStyle $io
         *
         * @return int
         */
        private function validateConfiguration(SymfonyStyle $io): int {

            if (!$this->parameterBag->has('zyos_install.filesystem')):
                $io->error('Configurations could not be found');
                return Command::FAILURE;
            endif;

            $parameters = new ParameterBag($this->parameterBag->get('zyos_install.filesystem'));

            if ($parameters->count() === 0):
                $io->success('No settings to run');
                return Command::SUCCESS;
            endif;

            $filter = $parameters->filter(function (array $parameter = []) {
                return in_array($this->environment, $parameter['environments']);
            });

            if ($filter->count() === 0):
                $io->success(sprintf('No settings to run in [%s]', $this->environment));
                return Command::FAILURE;
            endif;

            return $this->validateEnabled($io, $filter);
        }

        /**
         * Method validateEnabled
         *
         * @param SymfonyStyle $io
         * @param ParameterBag $parameters
         *
         * @return int
         */
        private function validateEnabled(SymfonyStyle $io, ParameterBag $parameters): int {

            $filter = $parameters->filter(function (array $parameter) {
                return $parameter['enable'];
            });

            if ($filter->count() === 0):
                $io->success(sprintf('No active settings to run in [%s]', $this->environment));
                return Command::SUCCESS;
            endif;

            return $this->validateOptions($io, $filter);
        }

        /**
         * Method validateOptions
         *
         * @param SymfonyStyle $io
         * @param ParameterBag $parameters
         *
         * @return int
         */
        private function validateOptions(SymfonyStyle $io, ParameterBag $parameters): int {

            $options = $this->getOptionsData();
            $filter = $parameters->filter(function (array $parameter) use ($options) {
                return in_array($parameter['type'], $options);
            });

            if ($filter->count() === 0):
                $io->success(sprintf('No settings to run in [%s] [types: %s]', $this->environment, implode(', ', $options)));
                return Command::SUCCESS;
            endif;

            return $this->iterateCommands($io, $filter->orderByColumn('priority', ParameterBag::ORDER_ASC), $filter->count());
        }

        /**
         * Method iterateCommands
         *
         * @param SymfonyStyle $io
         * @param ParameterBag $parameters
         * @param int $total
         *
         * @return int
         */
        private function iterateCommands(SymfonyStyle $io, ParameterBag $parameters, int $total): int {

            $io->text('<comment>Number of commands to execute:</comment> '.$parameters->count());
            $io->newLine();

            $exitCode = 0;
            foreach ($parameters AS $parameter):
                $exitCode = $this->executeCommand($io, $parameter);
            endforeach;

            $io->newLine();

            if (Command::SUCCESS === $exitCode):
                $io->success('All commands executed successfully');
            else:
                $io->error('Not all commands executed successfully');
            endif;

            return match ($exitCode) {
                0 => Command::SUCCESS,
                1 => Command::FAILURE,
                2 => Command::INVALID,
                default => $exitCode
            };
        }

        /**
         * Method executeCommand
         *
         * @param SymfonyStyle $io
         * @param array $parameters
         *
         * @return int
         */
        private function executeCommand(SymfonyStyle $io, array $parameters = []): int {

            $exitCode = 0;
            foreach ($parameters AS $parameter):
                $exitCode = $this->createProcess($io, $exitCode, $parameter);
            endforeach;

            return $exitCode;
        }

        /**
         * Method createProcess
         *
         * @param SymfonyStyle $io
         * @param int $exitCode
         * @param array $parameters
         *
         * @return int
         */
        private function createProcess(SymfonyStyle $io, int $exitCode = 0, array $parameters = []): int {

            $type = ucfirst(mb_strtolower($parameters['type']));
            $source = $this->replacement->replace($parameters['source'], $this->environment);
            $destination = $this->replacement->replace($parameters['destination'], $this->environment);

            $io->write('  - Running <info>Create '.$type.'</info> ['.$destination.']');

            if ($exitCode > 0):
                $io->write("\x0D");
                $io->writeln('  - <comment>[ Not Executed ]</comment> <info>Create '.$type.'</info> ['.$destination.']');
                return $exitCode;
            endif;

            try {
                if ($type === 'Directory'):
                    $this->filesystem->mkdir($destination);
                elseif ($type === 'Symlink'):
                    $this->filesystem->symlink($source, $destination);
                elseif ($type === 'Mirror'):
                    $this->filesystem->mirror($source, $destination);
                endif;

                $io->write("\x0D");
                $io->writeln('  - Created <info>Create '.$type.'</info> ['.$destination.']');

                if ($this->showOutput):
                    $this->getDefinitionList($io, $parameters);
                endif;

                return Command::SUCCESS;
            }
            catch (IOException $e) {

                $io->write("\x0D");
                $io->writeln('  - <fg=red;options=bold> Error </> <info>Created '.$type.'</info> ['.$destination.']' .$e->getMessage());

                return match ($parameters['if_error']) {
                    'none' => Command::SUCCESS,
                    'stop' => Command::FAILURE,
                    default => Command::INVALID
                };
            }
        }

        /**
         * Method getDefinitionList
         *
         * @param SymfonyStyle $io
         * @param array $parameters
         *
         * @return void
         */
        private function getDefinitionList(SymfonyStyle $io, array $parameters = []): void {

            $definition = [];
            $definition[] = ['Type of Creation' => sprintf('<fg=white;options=bold>%s</>', ucfirst(mb_strtolower($parameters['type'])) )];
            $definition[] = ['Priority' => sprintf('<fg=white;options=bold>%s</>', $parameters['priority'])];
            $definition[] = ['Environments' => sprintf('<comment>[</comment><fg=green;options=bold>%s</><comment>]</comment>', implode(', ', $parameters['environments']))];

            if ($parameters['type'] === 'directory'):
                $definition[] = ['Path' => sprintf('<fg=white;options=bold>%s</>', $this->replacement->replace($parameters['source'], $this->environment))];
            endif;

            if (in_array($parameters['type'], ['mirror', 'symlink'])):
                $definition[] = ['Source' => sprintf('<fg=white;options=bold>%s</>', $this->replacement->replace($parameters['source'], $this->environment))];
                $definition[] = ['Destination' => sprintf('<fg=white;options=bold>%s</>', $this->replacement->replace($parameters['destination'], $this->environment))];
            endif;

            call_user_func_array([$io, 'definitionList'], $definition);
        }


        /**
         * Method getOptionsData
         *
         * @return array|string[]
         */
        private function getOptionsData(): array {

            $array = [];

            if (!$this->mirror AND !$this->symlink AND !$this->directory):
                $array = ['mirror', 'symlink', 'directory'];
            endif;

            if ($this->mirror):
                $array[] = 'mirror';
            endif;

            if($this->symlink):
                $array[] = 'symlink';
            endif;

            if($this->directory):
                $array[] = 'directory';
            endif;

            return $array;
        }
    }