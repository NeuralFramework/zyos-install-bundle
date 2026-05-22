<?php

namespace Zyos\InstallBundle\Service;

use Exception;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Zyos\InstallBundle\Replacement;
/**
 * CommandExecutor
 *
 * Executes a Symfony console command programmatically within the current
 * Application context, resolving environment placeholders in both the
 * command name and its arguments before dispatch.
 *
 * Typical call flow:
 *   1. InstallCommand resolves the command name and arguments from config.
 *   2. InstallCommand calls setShowOutput() to forward the --show-output flag.
 *   3. InstallCommand calls execute() per entry, receiving a raw exit code.
 *   4. InstallCommand normalises the exit code and applies the error policy.
 *
 * Output routing:
 *   showOutput = true  → ConsoleOutput  (streams to the terminal in real time).
 *   showOutput = false → NullOutput     (output is silently discarded).
 *
 * Corrective paths:
 *   - Command not found (CommandNotFoundException) → re-thrown as Exception
 *     with a descriptive message that includes the resolved command name so
 *     the operator knows exactly which entry in the configuration is wrong.
 *   - setShowOutput() not called before execute() → defaults to false (silent)
 *     rather than throwing, because silent is always the safer default.
 *
 * Executes Symfony console commands programmatically within the current Application
 * context, resolving environment placeholders in both the command name and its arguments
 * before dispatch.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Service
 */
class CommandExecutor {

    /**
     * Flag indicating whether command output should be displayed to the terminal.
     *
     * This property controls the output routing behavior when executing Symfony console
     * commands programmatically. When set to true, the command's stdout and stderr streams
     * are routed to ConsoleOutput, which displays the output in real-time to the terminal.
     * When set to false, the output is routed to NullOutput, which silently discards all
     * command output.
     *
     * This flag is particularly useful in deployment and installation scenarios where:
     * - During development or debugging, operators may want to see command execution details
     * - During production deployments, silent execution may be preferred to reduce log noise
     * - The --show-output flag from the parent InstallCommand can be forwarded to child commands
     *
     * The default value is false, ensuring that commands execute silently unless explicitly
     * configured otherwise. This default is chosen as the safer option since silent execution
     * prevents unintended output pollution while still allowing the caller to handle errors
     * through the exit code.
     *
     * @var bool
     * @default false
     */
    private bool $showOutput = false;

    /**
     * Constructs a new CommandExecutor instance with the required replacement service.
     *
     * The constructor requires a Replacement service instance which is responsible for
     * resolving environment placeholder tokens in command names and arguments. This service
     * enables dynamic command execution based on the current environment context, allowing
     * configuration entries to use tokens like %env% that are replaced with actual environment
     * values (e.g., 'dev', 'prod', 'staging') before command execution.
     *
     * The Replacement service is injected as a readonly property, ensuring that once set,
     * the dependency cannot be modified during the lifecycle of the CommandExecutor instance.
     * This promotes immutability and thread-safety, which is particularly important in
     * long-running processes or when multiple commands are executed sequentially.
     *
     * Typical usage pattern:
     * - The CommandExecutor is instantiated by the Symfony dependency injection container
     * - The Replacement service is automatically injected based on service configuration
     * - The executor is then used to run one or more console commands with environment-aware
     *   placeholder resolution
     *
     * @param Replacement $replacement The replacement service responsible for resolving
     *                                 environment placeholder tokens in command names and
     *                                 arguments. This service must implement the replace()
     *                                 method for string replacement and arrayReplace() for
     *                                 array-based argument resolution.
     */
    public function __construct(private readonly Replacement $replacement) {

    }

    /**
     * Configures whether command output should be streamed to the terminal during execution.
     *
     * This method allows the caller to control the visibility of command execution output
     * by setting the showOutput flag. When enabled, all stdout and stderr streams from the
     * executed command are displayed in real-time to the terminal, which is useful for
     * debugging, monitoring, or providing feedback during interactive deployments. When
     * disabled, all output is silently discarded, which is the preferred behavior for
     * automated production deployments where log noise should be minimized.
     *
     * This method must be called before execute() if the caller wishes to forward the
     * --show-output flag from a parent command (typically InstallCommand). If this method
     * is not called before execution, the default value of false is used, ensuring silent
     * execution as the safer default behavior.
     *
     * The output routing behavior is implemented by the buildOutput() method, which returns
     * either a ConsoleOutput instance (when showOutput is true) or a NullOutput instance
     * (when showOutput is false). This design allows for flexible output handling without
     * modifying the core command execution logic.
     *
     * Usage example:
     * <code>
     * $executor = new CommandExecutor($replacement);
     * $executor->setShowOutput(true); // Enable real-time output display
     * $executor->execute($application, 'cache:clear', [], 'prod');
     * </code>
     *
     * @param bool $showOutput When true, command output is streamed to the terminal via
     *                         ConsoleOutput. When false, output is discarded via NullOutput.
     *                         Defaults to false if not explicitly set.
     *
     * @return void This method does not return a value; it modifies the internal state
     *              of the CommandExecutor instance.
     */
    public function setShowOutput(bool $showOutput): void {
        $this->showOutput = $showOutput;
    }

    /**
     * Executes a Symfony console command programmatically with environment-aware placeholder resolution.
     *
     * This is the primary method of the CommandExecutor class, responsible for orchestrating the
     * complete command execution lifecycle. The method performs the following operations in sequence:
     *
     * 1. **Placeholder Resolution**: Resolves environment placeholder tokens (e.g., %env%) in both
     *    the command name and arguments using the Replacement service. This enables dynamic command
     *    execution based on the current deployment environment (dev, prod, staging, etc.).
     *
     * 2. **Command Discovery**: Locates the command instance within the Symfony Application by its
     *    resolved name. If the command is not found, a descriptive exception is thrown to help
     *    operators identify configuration errors.
     *
     * 3. **Input/Output Construction**: Builds the appropriate input and output objects for command
     *    execution. The input is configured as non-interactive to prevent blocking prompts during
     *    automated deployments. The output is either ConsoleOutput or NullOutput based on the
     *    showOutput flag.
     *
     * 4. **Command Execution**: Invokes the command's run() method with the constructed input and
     *    output objects, returning the raw integer exit code.
     *
     * The method returns the raw exit code from the executed command without normalization. The
     * caller (typically InstallCommand) is responsible for interpreting this code according to
     * the configured error policy. This separation of concerns allows for flexible error handling
     * strategies without modifying the core execution logic.
     *
     * Error handling strategy:
     * - CommandNotFoundException is caught and re-thrown as a generic Exception with an enriched
     *   message that includes the resolved command name, making configuration errors easier to debug.
     * - Other exceptions from the command itself are propagated to the caller for handling.
     *
     * Usage example:
     * <code>
     * $executor = new CommandExecutor($replacement);
     * $executor->setShowOutput(true);
     * $exitCode = $executor->execute(
     *     $application,
     *     'cache:clear:%env%',
     *     ['--no-warmup' => true],
     *     'prod'
     * );
     * if ($exitCode !== 0) {
     *     // Handle error according to policy
     * }
     * </code>
     *
     * @param Application $application The Symfony Console Application instance containing all
     *                                registered commands. This instance is typically obtained from
     *                                the Symfony kernel or dependency injection container.
     *
     * @param string $command The command name to execute, potentially containing environment
     *                        placeholder tokens (e.g., 'app:deploy:%env%'). The placeholders will
     *                        be resolved before command lookup.
     *
     * @param array $arguments An associative array of command arguments and options. Keys represent
     *                        argument names or option flags (e.g., '--env', '--no-interaction'),
     *                        and values represent their corresponding values. Placeholders in values
     *                        will be resolved before execution.
     *
     * @param string $environment The current environment identifier (e.g., 'dev', 'prod', 'staging').
     *                           This value is used to replace placeholder tokens in the command name
     *                           and arguments.
     *
     * @return int The raw integer exit code returned by the executed command. A value of 0 typically
     *             indicates success, while non-zero values indicate various error conditions. The
     *             specific meaning of non-zero codes depends on the individual command implementation.
     *
     * @throws Exception When the resolved command name is not registered in the Application. The
     *                   exception message includes the resolved command name to aid in debugging
     *                   configuration errors.
     *
     * @throws ExceptionInterface When the command execution itself throws an exception. This includes
     *                            various Symfony Console exceptions that may occur during command
     *                            execution, such as invalid input, runtime errors, or command-specific
     *                            exceptions.
     */
    public function execute(Application $application, string $command, array $arguments, string $environment): int {

        $resolvedName      = $this->resolveCommandName($command, $environment);
        $resolvedArguments = $this->resolveArguments($arguments, $environment);

        $commandInstance = $this->findCommand($application, $resolvedName);
        $input           = $this->buildInput($resolvedArguments);
        $output          = $this->buildOutput();

        return $commandInstance->run($input, $output);
    }

    /**
     * Resolves environment placeholder tokens in the command name string.
     *
     * This method processes the command name to replace any environment placeholder tokens
     * with their actual values based on the current deployment environment. This enables
     * dynamic command selection based on the environment context, allowing a single
     * configuration entry to execute different commands in different environments.
     *
     * The resolution is performed by delegating to the Replacement service's replace()
     * method, which handles the actual token substitution logic. Common placeholder patterns
     * include %env% for the environment name, but the service may support additional
     * custom placeholders depending on configuration.
     *
     * This method is called early in the execution pipeline, before command discovery,
     * ensuring that the resolved command name is used for all subsequent operations
     * including command lookup and execution.
     *
     * Example transformations:
     * - Input: "cache:clear:%env%" with environment "prod" → Output: "cache:clear:prod"
     * - Input: "app:deploy:%env%" with environment "staging" → Output: "app:deploy:staging"
     * - Input: "doctrine:migrations:migrate" with any environment → Output: "doctrine:migrations:migrate" (no placeholders)
     *
     * @param string $command The command name string potentially containing environment
     *                        placeholder tokens. This is the raw command name from the
     *                        configuration before resolution.
     *
     * @param string $environment The current environment identifier (e.g., 'dev', 'prod',
     *                           'staging'). This value is used as the replacement for
     *                           placeholder tokens in the command name.
     *
     * @return string The resolved command name with all placeholder tokens replaced by their
     *                actual environment-specific values. If no placeholders are present,
     *                the original command name is returned unchanged.
     */
    private function resolveCommandName(string $command, string $environment): string {
        return $this->replacement->replace($command, $environment);
    }

    /**
     * Resolves environment placeholder tokens in all command argument values.
     *
     * This method processes the associative array of command arguments and options to replace
     * any environment placeholder tokens with their actual values based on the current deployment
     * environment. This enables dynamic argument configuration based on the environment context,
     * allowing a single configuration entry to pass environment-specific values to commands.
     *
     * The resolution is performed by delegating to the Replacement service's arrayReplace()
     * method, which handles the actual token substitution logic for array values. This method
     * recursively processes all string values in the array, ensuring that placeholders in nested
     * structures are also resolved.
     *
     * This method is called after command name resolution but before input construction, ensuring
     * that all resolved argument values are used when building the ArrayInput object for command
     * execution.
     *
     * Example transformations:
     * - Input: ['--env' => '%env%'] with environment "prod" → Output: ['--env' => 'prod']
     * - Input: ['--connection' => 'doctrine:%env%'] with environment "dev" → Output: ['--connection' => 'doctrine:dev']
     * - Input: ['--no-interaction' => true] with any environment → Output: ['--no-interaction' => true] (no placeholders in boolean)
     *
     * @param array $arguments An associative array of command arguments and options where keys
     *                        represent argument names or option flags (e.g., '--env', '--no-warmup')
     *                        and values represent their corresponding values. Values may contain
     *                        environment placeholder tokens that need resolution.
     *
     * @param string $environment The current environment identifier (e.g., 'dev', 'prod',
     *                           'staging'). This value is used as the replacement for
     *                           placeholder tokens in the argument values.
     *
     * @return array The resolved arguments array with all placeholder tokens in string values
     *               replaced by their actual environment-specific values. Non-string values
     *               (booleans, integers, etc.) are preserved unchanged.
     */
    private function resolveArguments(array $arguments, string $environment): array {
        return $this->replacement->arrayReplace($arguments, $environment);
    }

    /**
     * Locates and retrieves a command instance from the Application by its resolved name.
     *
     * This method is responsible for command discovery within the Symfony Console Application.
     * It uses the Application's find() method to locate the command by its resolved name,
     * which has already been processed to replace any environment placeholder tokens.
     *
     * The method implements a corrective error handling strategy to improve the developer
     * experience when configuration errors occur. The Application::find() method throws a
     * CommandNotFoundException when the command name is not registered in the application.
     * This exception is caught and re-thrown as a generic Exception with an enriched,
     * user-friendly message that includes:
     *
     * - The resolved command name (so operators know exactly which entry is problematic)
     * - A reference to the zyos_install configuration file where the command is defined
     * - The original error message for technical debugging purposes
     *
     * This enrichment eliminates the need for operators to trace through the stack trace
     * to identify which configuration entry is incorrect, significantly reducing debugging
     * time in deployment scenarios.
     *
     * The exception is re-thrown as a generic Exception rather than preserving the
     * CommandNotFoundException type to simplify exception handling in the calling code.
     * The original exception is chained as the previous exception to preserve the full
     * stack trace for debugging.
     *
     * @param Application $application The Symfony Console Application instance containing
     *                                all registered commands. This instance maintains the
     *                                command registry and provides the find() method for
     *                                command lookup.
     *
     * @param string $resolvedName The fully resolved command name with all environment
     *                            placeholder tokens replaced. This is the name used to
     *                            locate the command within the Application's registry.
     *
     * @return Command The located Command instance ready for execution. The returned
     *                 object is a fully configured Symfony Console Command that can
     *                 be invoked with the run() method.
     *
     * @throws Exception When the resolved command name is not registered in the Application.
     *                   The exception message includes the resolved command name and a
     *                   reference to the zyos_install configuration to aid in debugging.
     *                   The original CommandNotFoundException is chained as the previous
     *                   exception for complete stack trace information.
     */
    private function findCommand(Application $application, string $resolvedName): Command {

        try {
            return $application->find($resolvedName);
        } catch (CommandNotFoundException $exception) {
            throw new Exception(sprintf(
                'Command "%s" was not found in the application. '
                . 'Check the "command" value in your zyos_install configuration entry. '
                . 'Original error: %s',
                $resolvedName,
                $exception->getMessage()
            ), $exception->getCode(), $exception);
        }
    }

    /**
     * Constructs an ArrayInput object from the resolved command arguments.
     *
     * This method is responsible for building the input object that will be passed to the
     * command during execution. The ArrayInput class accepts command arguments and options
     * in the form of an associative array, which aligns perfectly with the configuration format
     * used by the zyos_install bundle.
     *
     * The ArrayInput format supports both:
     * - Positional arguments (e.g., ['argument' => 'value'])
     * - Named options with flags (e.g., ['--env' => 'prod', '--no-interaction' => true])
     * - Boolean flags (e.g., ['--no-warmup' => true])
     *
     * A critical configuration applied to the input is the disabling of interactive mode.
     * By calling setInteractive(false), the method ensures that the command cannot prompt
     * for user input during execution. This is essential for automated deployment scenarios
     * where blocking prompts would cause the deployment process to hang indefinitely, waiting
     * for user input that will never arrive.
     *
     * The non-interactive mode is particularly important for:
     * - Automated CI/CD pipelines
     * - Unattended production deployments
     * - Batch command execution where human intervention is not possible
     * - Commands that might otherwise ask for confirmation (e.g., database migrations)
     *
     * @param array $resolvedArguments The associative array of command arguments and options
     *                                with all environment placeholder tokens already resolved.
     *                                This array is used directly to construct the ArrayInput
     *                                instance without further modification.
     *
     * @return ArrayInput A configured ArrayInput instance ready for command execution. The
     *                    instance is configured with the resolved arguments and has interactive
     *                    mode disabled to prevent blocking prompts during automated execution.
     */
    private function buildInput(array $resolvedArguments): ArrayInput {

        $input = new ArrayInput($resolvedArguments);
        $input->setInteractive(false); // prevent prompts from blocking deployment
        return $input;
    }

    /**
     * Constructs the appropriate output channel based on the showOutput configuration flag.
     *
     * This method determines the output routing strategy for command execution by examining
     * the showOutput property and returning the corresponding OutputInterface implementation.
     * The output channel controls whether command execution results are visible to the user
     * or silently discarded.
     *
     * The method implements a conditional routing strategy:
     *
     * **When showOutput is true:**
     * - Returns a ConsoleOutput instance
     * - ConsoleOutput streams both stdout and stderr to the terminal in real-time
     * - Operators can see command execution progress, errors, and warnings as they occur
     * - This mode is useful for debugging, monitoring, or interactive deployments
     * - Output is written directly to the PHP output streams (STDOUT and STDERR)
     *
     * **When showOutput is false (default):**
     * - Returns a NullOutput instance
     * - NullOutput silently discards all output without writing to any stream
     * - Command execution is completely silent from the user's perspective
     * - This mode is preferred for automated production deployments to reduce log noise
     * - Errors are still communicated through the exit code, not through output
     *
     * This design allows for flexible output handling without modifying the core command
     * execution logic. The same command can be executed with either visible or silent output
     * depending on the deployment context and user preferences.
     *
     * The default behavior (showOutput = false) is chosen as the safer option because:
     * - Silent execution prevents unintended output pollution in logs
     * - Errors are still detectable through the exit code returned by execute()
     * - Production deployments typically prefer minimal output
     * - The caller can explicitly enable output when needed via setShowOutput()
     *
     * @return OutputInterface The output channel instance to be used for command execution.
     *                         Returns ConsoleOutput if showOutput is true, or NullOutput
     *                         if showOutput is false. Both implementations conform to the
     *                         OutputInterface contract, ensuring compatibility with the
     *                         Command::run() method.
     */
    private function buildOutput(): OutputInterface {
        return $this->showOutput ? new ConsoleOutput() : new NullOutput();
    }
}
