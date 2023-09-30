<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 24/09/23
     * Time: 6:43 p. m.
     */
    namespace Zyos\InstallBundle\Command;

    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
    use Symfony\Component\Filesystem\Filesystem;
    use Symfony\Component\Process\Process;
    use Zyos\InstallBundle\ParameterBag;
    use Zyos\InstallBundle\Replacement;

    /**
     * Class CommandLineCommand
     *
     * @package Zyos\InstallBundle\Command
     */
    class CommandLineCommand extends Command {

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
        private bool $showOutput;

        /**
         * @var Replacement
         */
        private Replacement $replacement;

        /**
         * Constructor CommandLineCommand
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

            $this->setName('zyos:cli');
            $this->setDescription('Executing CLI commands');
            $this->addArgument('environment', InputArgument::OPTIONAL, 'Runtime environment', 'dev');
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
            $this->showOutput = $input->getOption('show-output');

            $io = new SymfonyStyle($input, $output);
            $io->title(sprintf('Zyos Install Bundle <info>[%s] [%s]</info>', $this->getName(), $this->environment));
            $io->text([
                'Complementary command line execution for specific processes',
                'for the project requirements.'
            ]);
            $io->newLine();

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

            if (!$this->parameterBag->has('zyos_install.cli')):
                $io->error('Configurations could not be found');
                return Command::FAILURE;
            endif;

            $parameters = new ParameterBag($this->parameterBag->get('zyos_install.cli'));

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

            return $this->iterateEnabled($io, $filter->orderByColumn('priority', ParameterBag::ORDER_ASC), $filter->count());
        }

        /**
         * Method iterateEnabled
         *
         * @param SymfonyStyle $io
         * @param ParameterBag $parameters
         * @param int $total
         *
         * @return int
         */
        private function iterateEnabled(SymfonyStyle $io, ParameterBag $parameters, int $total): int {

            $io->text('<comment>Number of commands to execute:</comment> '.$parameters->count());
            $io->newLine();

            $exitCode = 0;

            foreach ($parameters AS $parameter):
                $exitCode = $this->iterateCommands($io, $exitCode, $parameter);
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
         * Method iterateCommands
         *
         * @param SymfonyStyle $io
         * @param int $exitCode
         * @param array $parameters
         *
         * @return int
         */
        private function iterateCommands(SymfonyStyle $io, int $exitCode, array $parameters): int {

            foreach ($parameters AS $parameter):
                $exitCode = $this->executeCommand($io, $exitCode, $parameter);
            endforeach;

            return $exitCode;
        }

        /**
         * Method executeCommand
         *
         * @param SymfonyStyle $io
         * @param int $exitCode
         * @param array $parameters
         *
         * @return int
         */
        private function executeCommand(SymfonyStyle $io, int $exitCode, array $parameters): int {

            $commandParams = $this->replacement->arrayReplace($parameters['command'], $this->environment);

            $command = implode(' ', $commandParams);
            $io->write('  - Running <info>Execute Command</info> ['.$command.']');

            if ($exitCode > 0):
                $io->write("\x0D");
                $io->writeln('  - <comment>[ Not Executed ]</comment> <info>Execute Command</info> ['.implode(' ', $parameters['command']).']');
                return $exitCode;
            endif;

            $exitCode = $this->symfonyCommand($commandParams);

            $io->write("\x0D");
            $io->writeln(match ($exitCode) {
                0 => '  - Success <info>Execute Command</info> ['.$command.']',
                default => '  - <fg=red;options=bold> Error </> <info>Execute Command</info> ['.$command.'] <comment>Exit Code:</comment> '.$exitCode
            });

            return match ($parameters['if_error']) {
                'none' => Command::SUCCESS,
                'stop' => $exitCode,
                default => match ($exitCode) {
                    0 => Command::SUCCESS,
                    1 => Command::FAILURE,
                    2 => Command::INVALID,
                    default => $exitCode
                }
            };
        }

        /**
         * Method symfonyCommand
         *
         * @param array $command
         *
         * @return int
         */
        private function symfonyCommand(array $command = []): int {

            $process = new Process($command);
            match ($this->showOutput) {
                true => $process->run(function ($type, $buffer): void {
                    echo ($type === Process::ERR ? 'ERR > ' : 'OUT > ') . $buffer;
                }),
                default => $process->run()
            };

            $exitCode = $process->getExitCode();

            return match ($exitCode) {
                0 => Command::SUCCESS,
                1 => Command::FAILURE,
                2 => Command::INVALID,
                default => $exitCode
            };
        }
    }