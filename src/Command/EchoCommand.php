<?php

namespace Zyos\InstallBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * EchoCommand
 *
 * A hidden console command designed for internal testing and verification of the
 * Zyos Install Bundle command pipeline execution. This command serves as a
 * diagnostic tool to validate that commands are properly registered, configured,
 * and executable within the Symfony console application.
 *
 * The command provides configurable behavior through command-line options,
 * allowing it to simulate different execution scenarios including successful
 * completion, failure states, and delayed responses. This makes it particularly
 * useful for testing command chaining, error handling, and timeout scenarios
 * in automated deployment or installation processes.
 *
 * Key features:
 * - Hidden from command list (not shown in `bin/console` output)
 * - Configurable delay before response execution
 * - Ability to simulate failure states for testing error handling
 * - Clean output formatting using SymfonyStyle
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Command
 */
class EchoCommand extends Command {

    /**
     * Configures the command definition, including name, description, visibility,
     * and available command-line options.
     *
     * This method sets up the command's metadata and defines the options that
     * can be passed when executing the command. The command is marked as hidden
     * to prevent it from appearing in the standard command list, as it is intended
     * for internal testing purposes only.
     *
     * Available options:
     * - `--error`: If present, causes the command to exit with a failure status
     *              code and display an error message. Useful for testing error
     *              handling and failure scenarios in command pipelines.
     * - `--wait=N`: Specifies a delay in seconds before the command responds.
     *               Defaults to 0 (no delay). Useful for testing timeout behavior
     *               and asynchronous command execution.
     *
     * @return void
     */
    protected function configure(): void {

        $this
            ->setName('zyos:echo')
            ->setDescription('Hidden test command for verifying command pipeline execution.')
            ->setHidden(true)
            ->addOption('error', null, InputOption::VALUE_NONE,     'Exit with FAILURE and print an error message.')
            ->addOption('wait',  null, InputOption::VALUE_REQUIRED, 'Sleep for N seconds before responding.', 0);
    }

    /**
     * Executes the command logic based on the provided input and output interfaces.
     *
     * This method is the main entry point for command execution. It retrieves the
     * command options, processes them accordingly, and orchestrates the test
     * execution flow. The method handles optional delay simulation and determines
     * whether the command should succeed or fail based on the provided options.
     *
     * The execution flow consists of:
     * 1. Retrieving and parsing command options (error flag and wait duration)
     * 2. Initializing a SymfonyStyle instance for formatted output
     * 3. Displaying command title and informational message
     * 4. Executing the optional delay if requested via the --wait option
     * 5. Returning the appropriate exit code based on the --error flag
     *
     * @param InputInterface $input The input interface containing command arguments
     *                              and options provided by the user.
     * @param OutputInterface $output The output interface for writing command
     *                                output and messages to the console.
     *
     * @return int The command exit status code. Returns Command::SUCCESS (0) if
     *             the command completes successfully, or Command::FAILURE (1) if
     *             the --error flag is present.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {

        $shouldFail  = (bool) $input->getOption('error');
        $waitSeconds = (int)  $input->getOption('wait');

        $io = new SymfonyStyle($input, $output);

        $io->title('Echo Test');
        $io->text('Internal test command for the Zyos Install Bundle command pipeline.');
        $io->newLine();

        $this->waitIfRequested($waitSeconds);

        return $this->respondWithResult($io, $shouldFail);
    }

    /**
     * Pauses command execution for a specified number of seconds if requested.
     *
     * This private helper method implements the delay functionality when the
     * --wait option is provided with a positive value. The method uses the
     * native PHP sleep() function to block execution for the specified duration,
     * which is useful for testing timeout scenarios, asynchronous command
     * execution, and simulating long-running operations.
     *
     * The method includes a guard clause to ensure that sleep() is only called
     * when the seconds parameter is greater than zero, preventing unnecessary
     * delays when the option is not used or set to zero.
     *
     * @param int $seconds The number of seconds to pause execution. Must be a
     *                     positive integer to trigger the delay. Values of zero
     *                     or negative numbers will result in no delay.
     *
     * @return void
     */
    private function waitIfRequested(int $seconds): void {

        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * Determines and outputs the final result of the command execution.
     *
     * This private helper method handles the final response logic based on whether
     * the command should simulate a failure or success state. It uses the provided
     * SymfonyStyle instance to display appropriate messages to the user and returns
     * the corresponding exit status code.
     *
     * When simulating a failure (shouldFail is true), the method displays an error
     * message indicating that the command was executed with the --error flag and
     * returns Command::FAILURE. This is useful for testing error handling logic
     * in command pipelines and deployment scripts.
     *
     * When simulating success (shouldFail is false), the method displays a success
     * message confirming that the command completed successfully and returns
     * Command::SUCCESS. This represents the normal, expected execution path.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance used for formatted console
     *                         output. Provides methods for displaying success and
     *                         error messages with consistent styling.
     * @param bool $shouldFail A flag indicating whether the command should simulate
     *                         a failure state. When true, an error message is
     *                         displayed and Command::FAILURE is returned. When
     *                         false, a success message is displayed and
     *                         Command::SUCCESS is returned.
     *
     * @return int The command exit status code. Returns Command::FAILURE (1) if
     *             shouldFail is true, or Command::SUCCESS (0) if shouldFail is
     *             false.
     */
    private function respondWithResult(SymfonyStyle $io, bool $shouldFail): int {

        if ($shouldFail) {
            $io->error('Echo command exited with --error flag.');
            return Command::FAILURE;
        }

        $io->success('Echo command completed successfully.');
        return Command::SUCCESS;
    }
}
