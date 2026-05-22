<?php

namespace Zyos\InstallBundle\Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zyos\InstallBundle\ParameterBag;

/**
 * CommandRunner
 *
 * Service responsible for executing a collection of commands or command groups during the
 * ZyosInstallBundle installation process.
 *
 * This service provides a structured approach to executing multiple commands by iterating
 * through a ParameterBag of command groups and delegating the actual execution to a provided
 * callable executor. The service handles the orchestration of command execution, including
 * displaying informational headers, tracking exit codes, and providing a summary of the
 * execution results.
 *
 * The service follows a sequential execution pattern where commands are executed one after
 * another in the order they appear in the ParameterBag. The exit code from each command
 * is passed to the next command's executor, allowing for conditional execution based on
 * previous results. This enables scenarios where subsequent commands may be skipped or
 * modified based on the success or failure of earlier commands.
 *
 * The service provides clear user feedback through console output:
 * - A header displaying the number of commands to execute
 * - A summary indicating whether all commands executed successfully or if failures occurred
 * - Detailed error messages when commands fail, including the final exit code for debugging
 *
 * The service also normalizes exit codes to standard Symfony Console constants (SUCCESS,
 * FAILURE, INVALID) for common exit code values (0, 1, 2), while preserving custom exit
 * codes for other values. This ensures consistency with Symfony's command exit code conventions
 * while maintaining flexibility for custom exit codes.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Service
 */
class CommandRunner {

    /**
     * Executes all command groups from the provided ParameterBag.
     *
     * This method is the main entry point of the service and coordinates the entire
     * command execution process. It orchestrates the execution flow by calling helper
     * methods for each stage of the process: displaying the header, executing all
     * command groups, printing the summary, and normalizing the final exit code.
     *
     * The method follows a structured execution flow:
     * 1. Calls printHeader() to display the number of commands to execute
     * 2. Calls executeAllGroups() to iterate through the ParameterBag and execute each
     *    command group using the provided executor callable
     * 3. Calls printSummary() to display the execution results to the user
     * 4. Calls normalizeExitCode() to convert the raw exit code to a standard Symfony
     *    Console constant if applicable
     *
     * The executor callable is invoked for each command group in the ParameterBag, receiving
     * the current command group and the exit code from the previous command execution. This
     * allows the executor to make decisions based on previous results, such as skipping
     * subsequent commands if a critical failure occurred.
     *
     * The method returns a normalized exit code that can be used by the calling command to
     * determine the overall success or failure of the command execution process.
     *
     * @param ParameterBag $parameters A ParameterBag containing command groups to execute.
     *                                 Each element in the ParameterBag represents a group of
     *                                 commands or a single command configuration that will be
     *                                 passed to the executor callable.
     * @param callable $executor A callable that executes individual command groups. The callable
     *                          receives two parameters: the current command group (mixed type)
     *                          and the exit code from the previous command execution (int).
     *                          The callable should return the exit code for the current command
     *                          execution. This design allows for conditional execution based on
     *                          previous results.
     * @param SymfonyStyle $io Symfony Console component that provides an enhanced interface
     *                          for console input/output. It is used to display informational
     *                          messages, headers, and execution summaries to the user.
     * @return int Returns the normalized exit code from the command execution process.
     *             The exit code is normalized to standard Symfony Console constants
     *             (Command::SUCCESS, Command::FAILURE, Command::INVALID) for common values
     *             (0, 1, 2), while custom exit codes are preserved as-is.
     */
    public function run(ParameterBag $parameters, callable $executor, SymfonyStyle $io): int {

        $this->printHeader($io, $parameters->count());
        $finalExitCode = $this->executeAllGroups($parameters, $executor);
        $this->printSummary($io, $finalExitCode);

        return $this->normalizeExitCode($finalExitCode);
    }

    /**
     * Executes all command groups from the ParameterBag using the provided executor.
     *
     * This method iterates through each command group in the ParameterBag and invokes the
     * executor callable for each one. The method maintains a running exit code that is
     * updated after each command execution and passed to the next executor invocation.
     *
     * The execution follows a sequential pattern where command groups are executed in the
     * order they appear in the ParameterBag. The exit code from each execution is stored
     * and passed to the next executor, enabling conditional execution logic. For example,
     * the executor can check the previous exit code and skip subsequent commands if a
     * critical failure occurred.
     *
     * The method initializes the previous exit code to Command::SUCCESS (0) to ensure that
     * the first command execution starts with a clean slate. As each command executes, its
     * exit code overwrites the previous value, so the final return value represents the
     * exit code of the last command executed.
     *
     * This design allows for flexible execution patterns where the executor callable can
     * implement custom logic such as:
     * - Stopping execution on first failure
     * - Continuing execution despite failures
     * - Modifying commands based on previous results
     * - Accumulating multiple exit codes into a composite result
     *
     * @param ParameterBag $parameters A ParameterBag containing command groups to execute.
     *                                 The method iterates over this ParameterBag, passing each
     *                                 element to the executor callable.
     * @param callable $executor A callable that executes individual command groups. The callable
     *                          receives two parameters: the current command group (mixed type)
     *                          and the exit code from the previous command execution (int).
     *                          The callable should return the exit code for the current command
     *                          execution.
     * @return int Returns the exit code from the last command execution. This represents the
     *             final state of the command execution process and will be used to determine
     *             whether the overall execution was successful.
     */
    private function executeAllGroups(ParameterBag $parameters, callable $executor): int {

        $previousExitCode = Command::SUCCESS;

        foreach ($parameters as $group) {
            $previousExitCode = $executor($group, $previousExitCode);
        }

        return $previousExitCode;
    }

    /**
     * Normalizes a raw exit code to standard Symfony Console constants.
     *
     * This method converts common exit code values to their corresponding Symfony Console
     * command constants, ensuring consistency with Symfony's exit code conventions. This
     * normalization makes the code more readable and maintainable by using named constants
     * instead of magic numbers.
     *
     * The method uses PHP 8.0+ match expression for clean and readable pattern matching:
     * - Exit code 0 is normalized to Command::SUCCESS
     * - Exit code 1 is normalized to Command::FAILURE
     * - Exit code 2 is normalized to Command::INVALID
     * - Any other exit code is returned unchanged, preserving custom exit codes
     *
     * This approach allows the service to handle both standard Symfony exit codes and custom
     * exit codes that may be returned by custom commands or external processes. Custom exit
     * codes are preserved as-is, allowing for specialized error handling in the calling code.
     *
     * The normalization is performed after all command executions are complete, ensuring that
     * the final return value from the run() method is consistent with Symfony's conventions
     * while maintaining flexibility for custom scenarios.
     *
     * @param int $rawExitCode The raw exit code from command execution. This value is typically
     *                         returned by the executor callable or from the last command
     *                         execution. The value can be any integer, including standard
     *                         Symfony exit codes (0, 1, 2) or custom exit codes.
     * @return int Returns the normalized exit code. For common values (0, 1, 2), returns the
     *             corresponding Symfony Console constant (Command::SUCCESS, Command::FAILURE,
     *             Command::INVALID). For any other value, returns the original exit code unchanged.
     */
    private function normalizeExitCode(int $rawExitCode): int {

        return match ($rawExitCode) {
            0       => Command::SUCCESS,
            1       => Command::FAILURE,
            2       => Command::INVALID,
            default => $rawExitCode,
        };
    }

    /**
     * Prints a header displaying the number of commands to execute.
     *
     * This method displays an informational header at the beginning of the command execution
     * process, providing the user with context about how many commands will be executed.
     * This helps users understand the scope of the operation and set appropriate expectations.
     *
     * The header is displayed using the SymfonyStyle component's text() method with a comment
     * tag (<comment>) to highlight the label "Commands to execute:" in a different color
     * (typically yellow in most terminal configurations). The count is displayed as a plain
     * number following the label.
     *
     * After displaying the header line, the method calls newLine() to add a blank line,
     * improving readability and separating the header from the subsequent command output.
     *
     * This method is called at the beginning of the run() method, before any command
     * execution begins, ensuring that users see the header before any command output appears.
     *
     * @param SymfonyStyle $io Symfony Console component used to display the header. The use of
     *                          SymfonyStyle allows formatting the message with appropriate colors
     *                          and styles, improving the user experience.
     * @param int $count The number of commands to execute. This value is typically obtained
     *                   from the ParameterBag's count() method and represents the total number
     *                   of command groups that will be processed.
     * @return void This method does not return a value. It performs a side effect of displaying
     *              information to the console.
     */
    private function printHeader(SymfonyStyle $io, int $count): void {

        $io->text(sprintf('<comment>Commands to execute:</comment> %d', $count));
        $io->newLine();
    }

    /**
     * Prints a summary of the command execution results.
     *
     * This method displays a summary message at the end of the command execution process,
     * informing the user whether all commands executed successfully or if failures occurred.
     * The summary provides clear feedback about the overall outcome of the operation.
     *
     * The method first adds a blank line using newLine() to separate the summary from the
     * preceding command output, improving readability.
     *
     * If the final exit code is Command::SUCCESS (0), the method displays a success message
     * using the success() method, which typically renders the message in green. The message
     * confirms that all commands executed successfully.
     *
     * If the final exit code is any other value, the method displays an error message using
     * the error() method, which typically renders the message in red. The error message
     * indicates that one or more commands did not complete successfully and includes the
     * final exit code for debugging purposes. This detailed information helps users
     * identify and troubleshoot issues that occurred during command execution.
     *
     * This method is called at the end of the run() method, after all command executions
     * are complete, ensuring that users see the summary as the final piece of feedback
     * from the operation.
     *
     * @param SymfonyStyle $io Symfony Console component used to display the summary. The use of
     *                          SymfonyStyle allows formatting the message with appropriate colors
     *                          and styles (green for success, red for error), improving the user
     *                          experience and making the outcome immediately apparent.
     * @param int $finalExitCode The final exit code from the command execution process. This value
     *                           is typically the raw exit code before normalization, representing
     *                           the exit code returned by the last command execution. The value is
     *                           used to determine whether to display a success or error message.
     * @return void This method does not return a value. It performs a side effect of displaying
     *              information to the console.
     */
    private function printSummary(SymfonyStyle $io, int $finalExitCode): void {

        $io->newLine();

        if ($finalExitCode === Command::SUCCESS) {
            $io->success('All commands executed successfully.');
        } else {
            $io->error(sprintf(
                'One or more commands did not complete successfully. Final exit code: %d.',
                $finalExitCode
            ));
        }
    }
}
