<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 2/07/23
     * Time: 11:33 p. m.
     */
    namespace Zyos\InstallBundle\Command;

    use LogicException;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Style\SymfonyStyle;

    /**
     * Class EchoCommand
     *
     * @package Zyos\InstallBundle\Command
     */
    class EchoCommand extends Command {

        /**
         * Configures the current command.
         */
        protected function configure(): void {

            $this->setName('zyos:echo');
            $this->setDescription('Echo command for testing');
            $this->addOption('error', null, InputOption::VALUE_NONE, 'Return Echo error');
            $this->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Wait Time', 0);
            $this->setHidden(true);
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
        protected function execute(InputInterface $input, OutputInterface $output): int{

            $option = $input->getOption('error');
            $wait = $input->getOption('wait');

            $io = new SymfonyStyle($input, $output);

            $io->title('  Echo Test  ');
            $io->text(['This is for test execution of commands inside Zyos Install Bundle']);

            if ($wait > 0):
                sleep($wait);
            endif;

            if ($option):
                $io->error('Error');
            else:
                $io->success('Success');
            endif;

            return $option ? Command::FAILURE : Command::SUCCESS;
        }
    }