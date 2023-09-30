<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 14/04/22
     * Time: 4:41 a. m.
     */
    namespace Zyos\InstallBundle\Command;

    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Exception\LogicException;
    use Symfony\Component\Console\Helper\TableSeparator;
    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;
    use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
    use Symfony\Component\Filesystem\Filesystem;
    use Zyos\InstallBundle\ParameterBag;
    use Zyos\InstallBundle\Replacement;
    use Zyos\InstallBundle\ValidatorsHandler;

    /**
     * Class ValidateCommand
     *
     * @package Zyos\InstallBundle\Command
     */
    class ValidateCommand extends Command {

        /**
         * @var ParameterBagInterface
         */
        private ParameterBagInterface $parameterBag;

        /**
         * @var Filesystem
         */
        private Filesystem $filesystem;

        /**
         * @var ValidatorsHandler
         */
        private ValidatorsHandler $validatorsHandler;

        /**
         * @var string
         */
        private string $environment;

        /**
         * @var bool
         */
        private bool $onlyErrors;

        /**
         * @var array
         */
        private array $errors = [];

        /**
         * @var Replacement
         */
        private Replacement $replacement;

        /**
         * Constructor ValidateCommand
         *
         * @param ParameterBagInterface $parameterBag
         * @param Filesystem $filesystem
         * @param ValidatorsHandler $validatorsHandler
         * @param Replacement $replacement
         */
        public function __construct(ParameterBagInterface $parameterBag, Filesystem $filesystem, ValidatorsHandler $validatorsHandler, Replacement $replacement) {

            parent::__construct(null);
            $this->parameterBag = $parameterBag;
            $this->filesystem = $filesystem;
            $this->validatorsHandler = $validatorsHandler;
            $this->replacement = $replacement;
        }

        /**
         * Configures the current command.
         */
        protected function configure(): void {

            $this->setName('zyos:validate');
            $this->setDescription('Custom validation of files, directories and others');
            $this->addArgument('environment', InputArgument::OPTIONAL, 'runtime environment', 'dev');
            $this->addOption('only-errors', null, InputOption::VALUE_NONE, 'only show errors');
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
            $this->onlyErrors = $input->getOption('only-errors');

            $io = new SymfonyStyle($input, $output);
            $io->title(sprintf('Validate Command <info>[%s] [%s]</info>', $this->getName(), $this->environment));
            $io->text([
                'This process validates the different configured paths',
                'showing the information required to complement the project.'
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

            if (!$this->parameterBag->has('zyos_install.validate')):
                $io->error('Configurations could not be found');
                return Command::FAILURE;
            endif;

            $parameters = new ParameterBag($this->parameterBag->get('zyos_install.validate'));

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

            return $this->iterateEnabled($io, $filter);
        }

        /**
         * Method iterateEnabled
         *
         * @param SymfonyStyle $io
         * @param ParameterBag $parameters
         *
         * @return int
         */
        private function iterateEnabled(SymfonyStyle $io, ParameterBag $parameters): int {

            $io->text('<comment>Number of commands to execute:</comment> '.$parameters->count());
            $io->newLine();

            foreach ($parameters as $parameter):
                $this->iterateCommands($io, $parameter);
            endforeach;

            $error = count($this->errors) > 0;

            if ($error):
                $io->error('Errors found');
            else:
                $io->success('No errors found');
            endif;

            return $error ? Command::FAILURE : Command::SUCCESS;
        }

        /**
         * Method iterateCommands
         *
         * @param SymfonyStyle $io
         * @param array $parameters
         *
         * @return void
         */
        private function iterateCommands(SymfonyStyle $io, array $parameters = []): void {

            $filepath = $this->replacement->replace($parameters['filepath'], $this->environment);
            $array = $this->getParametersValidation($parameters);

            if ($this->onlyErrors):
                if (array_key_exists($filepath, $this->errors)):
                    $io->text(sprintf('<fg=yellow;options=bold>Validation:</> %s', $filepath));
                    call_user_func_array([$io, 'definitionList'], $array);
                    $io->newLine();
                endif;
            else:
                $io->text(sprintf('<fg=yellow;options=bold>Validation:</> %s', $filepath));
                call_user_func_array([$io, 'definitionList'], $array);
                $io->newLine();
            endif;
        }

        /**
         * Method getParametersValidation
         *
         * @param array $parameters
         *
         * @return array
         */
        private function getParametersValidation(array $parameters = []): array {

            $filepath = $this->replacement->replace($parameters['filepath'], $this->environment);
            $permissions = $this->getPermissions($filepath);
            $array = [
                ['FilePath' => $filepath],
                ['type' => '<fg=white;options=bold>'.$parameters['type'].'</>'],
                ['Permissions Integer' => '<fg=white;options=bold>'.$permissions['int'].'</>'],
                ['Permissions String' => '<fg=white;options=bold>'.$permissions['string'].'</>'],
                ['Date Time' => $this->getDateTimeFile($filepath)],
                ['Environments' => '<fg=white;options=bold>'.implode(', ', $parameters['environments']).'</>'],
                new TableSeparator(),
            ];
            $validations = $this->getIterateValidations($parameters);

            return array_merge($array, $validations);
        }

        /**
         * Method getIterateValidations
         *
         * @param array $parameters
         *
         * @return array
         */
        private function getIterateValidations(array $parameters = []): array {

            return array_map(function (array $validation) use ($parameters) {

                $stringValidation = $this->replacement->arrayReplace($validation, $this->environment);
                $stringParameters = $this->replacement->arrayReplace($parameters, $this->environment);

                return $this->getValidation($stringParameters, $stringValidation);
            }, $parameters['validations']);
        }

        /**
         * Method getValidation
         *
         * @param array $arguments
         * @param array $parameters
         *
         * @return string[]
         */
        private function getValidation(array $arguments = [], array $parameters = []): array {

            $name = $parameters['name'];
            $status = '<fg=red;options=bold>Validation does not exist</>';

            if ($this->validatorsHandler->has($parameters['name'])):
                $object = $this->validatorsHandler->get($parameters['name'], $arguments);
                $name = method_exists($object, 'getTitle') ? $object->getTitle() : $parameters['name'];
                $validation = $object->validate($parameters['parameters'], $arguments);
                $status = $validation ? '<fg=green;options=bold>SUCCESS</>' : '<fg=red;options=bold>FAILED</>';

                if (!$validation && !isset($this->errors[$arguments['filepath']])):
                    $this->errors[$arguments['filepath']] = $arguments['filepath'];
                endif;
            endif;

            return [$name => $status];
        }

        /**
         * Method getDateTimeFile
         *
         * @param string $filepath
         *
         * @return string
         */
        private function getDateTimeFile(string $filepath): string {

            if ($this->filesystem->exists($filepath)):
                return '<fg=white;options=bold>'.date('Y-m-d H:i:s', filemtime($filepath)).'</>';
            endif;

            return '<fg=red;options=bold>NOT AVAILABLE</>';
        }

        /**
         * Method getPermissions
         *
         * @param string $filepath
         *
         * @return array|string[]
         */
        private function getPermissions(string $filepath): array {

            if ($this->filesystem->exists($filepath)):
                $permissions = fileperms($filepath);

                $info = match ($permissions & 0xF000) {
                    0xC000 => 's',
                    0xA000 => 'l',
                    0x8000 => 'r',
                    0x6000 => 'b',
                    0x4000 => 'd',
                    0x2000 => 'c',
                    0x1000 => 'p',
                    default => 'u',
                };

                // Owner
                $info .= (($permissions & 0x0100) ? 'r' : '-');
                $info .= (($permissions & 0x0080) ? 'w' : '-');
                $info .= (($permissions & 0x0040) ? (($permissions & 0x0800) ? 's' : 'x' ) : (($permissions & 0x0800) ? 'S' : '-'));

                // Group
                $info .= (($permissions & 0x0020) ? 'r' : '-');
                $info .= (($permissions & 0x0010) ? 'w' : '-');
                $info .= (($permissions & 0x0008) ? (($permissions & 0x0400) ? 's' : 'x' ) : (($permissions & 0x0400) ? 'S' : '-'));

                // World
                $info .= (($permissions & 0x0004) ? 'r' : '-');
                $info .= (($permissions & 0x0002) ? 'w' : '-');
                $info .= (($permissions & 0x0001) ? (($permissions & 0x0200) ? 't' : 'x' ) : (($permissions & 0x0200) ? 'T' : '-'));

                return ['int' => substr(sprintf('%o', $permissions), -4), 'string' => $info];
            endif;

            return ['int' => '<fg=red;options=bold>NOT AVAILABLE</>', 'string' => '<fg=red;options=bold>NOT AVAILABLE</>'];
        }
    }