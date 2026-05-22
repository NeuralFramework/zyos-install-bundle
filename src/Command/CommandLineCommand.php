<?php

namespace Zyos\InstallBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Zyos\InstallBundle\ParameterBag;
use Zyos\InstallBundle\Replacement;
use Zyos\InstallBundle\Service\CommandRunner;
use Zyos\InstallBundle\Service\ConfigurationValidator;
use Zyos\InstallBundle\Service\EnvironmentValidator;
use Zyos\InstallBundle\Service\LockFileValidator;

/**
 * CommandLineCommand
 *
 * Command for executing supplementary CLI commands required for deploying the application.
 *
 * This command is responsible for executing CLI commands defined in the bundle configuration
 * for a specific deployment environment. Commands are executed in priority order and respect
 * the configured error policy, allowing for flexible error handling and continuation logic.
 *
 * The command accepts a single required argument, the target deployment environment
 * (e.g. dev, prod), and an optional flag for streaming process output to the console.
 * The command provides a validation pipeline to ensure prerequisites are met before
 * executing any commands, including environment validation, lock file validation, and
 * configuration validation.
 *
 * Error policies determine how the command behaves when a CLI command fails:
 * - 'none': Ignore the error and continue with subsequent commands
 * - 'stop': Halt execution immediately when a command fails
 * - 'default': Treat success as SUCCESS and any non-zero exit code as FAILURE
 *
 * The command is designed to be highly configurable and can be used to execute
 * arbitrary shell commands as part of a deployment pipeline, such as running migrations,
 * clearing caches, or executing custom scripts.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Command
 */
class CommandLineCommand extends Command {

    /**
     * Constant representing the 'none' error policy.
     *
     * This policy indicates that command failures should be ignored and execution
     * should continue with subsequent commands. When this policy is configured for
     * a command, the command will return SUCCESS regardless of the actual exit code
     * of the executed process. This is useful for optional commands that may fail
     * without affecting the overall deployment success.
     *
     * @var string
     */
    private const string ERROR_POLICY_NONE = 'none';

    /**
     * Constant representing the 'stop' error policy.
     *
     * This policy indicates that command failures should halt execution immediately.
     * When this policy is configured for a command and the command fails (returns
     * a non-zero exit code), the entire command pipeline will stop and no further
     * commands will be executed. The actual exit code of the failed process is
     * returned as the command's exit code. This is useful for critical commands
     * where failure should prevent subsequent operations from running.
     *
     * @var string
     */
    private const string ERROR_POLICY_STOP = 'stop';

    /**
     * Constant representing the 'default' error policy.
     *
     * This policy provides a standard error handling behavior where successful
     * commands (exit code 0) return SUCCESS, and failed commands (non-zero exit
     * code) return FAILURE. This is the most common policy for commands where
     * failure should be treated as an error but may not necessarily halt the
     * entire pipeline depending on the CommandRunner's continuation logic.
     *
     * @var string
     */
    private const string ERROR_POLICY_DEFAULT = 'default';

    /**
     * The target deployment environment for the current command execution.
     *
     * This property stores the environment name (e.g., 'dev', 'prod', 'staging')
     * that was passed as a required argument to the command. The environment is
     * used throughout the command execution to filter which commands should be
     * run based on their configured environment compatibility, and to perform
     * environment-specific placeholder replacements in command arguments using
     * the Replacement service.
     *
     * @var string
     */
    private string $environment;
    /**
     * Flag indicating whether process output should be streamed to the console.
     *
     * When set to true via the --show-output command option, this property causes
     * the command to stream the stdout and stderr of each executed process directly
     * to the console in real-time. This is useful for debugging or when the output
     * of the commands is important for the user to see. When false (default),
     * process output is suppressed and only the final success/failure status is
     * displayed, keeping the console output concise.
     *
     * @var bool
     */
    private bool   $showOutput;

    /**
     * Constructor that injects all required dependencies for the CLI command.
     *
     * This constructor uses constructor property promotion to declare and initialize
     * all required service dependencies in a single step. Each dependency is marked
     * as readonly to ensure immutability after construction. The command is
     * initialized with a null name, which will be set in the configure() method.
     *
     * @param Replacement $replacement Service for replacing placeholders in command
     *        arguments with environment-specific values (e.g., %kernel.project_dir%).
     * @param EnvironmentValidator $environmentValidator Service for validating that
     *        the provided environment name is valid and configured.
     * @param LockFileValidator $lockFileValidator Service for checking that the
     *        installation lock file exists and is valid for the environment.
     * @param ConfigurationValidator $configurationValidator Service for validating
     *        and filtering the CLI command configuration based on environment.
     * @param CommandRunner $commandRunner Service for executing commands grouped
     *        by priority with proper error handling and continuation logic.
     */
    public function __construct(
        private readonly Replacement            $replacement,
        private readonly EnvironmentValidator   $environmentValidator,
        private readonly LockFileValidator      $lockFileValidator,
        private readonly ConfigurationValidator $configurationValidator,
        private readonly CommandRunner          $commandRunner
    ) {
        parent::__construct();
    }

    /**
     * Configures the command definition including name, description, arguments, and options.
     *
     * This method is called automatically by Symfony's Console component during
     * command initialization. It defines the command's interface, including the
     * command name, a human-readable description, the required environment argument,
     * and an optional flag for streaming process output. The configuration allows
     * users to control which environment to target and whether to see real-time
     * output from executed commands.
     *
     * The command accepts:
     * - One required argument: the target environment name
     * - One optional flag for streaming process output to the console
     *
     * @return void
     */
    protected function configure(): void {

        $this
            ->setName('zyos:cli')
            ->setDescription('Executes CLI commands defined in the bundle configuration.')
            ->addArgument('environment', InputArgument::REQUIRED, 'Target deployment environment (e.g. dev, prod).')
            ->addOption('show-output', null, InputOption::VALUE_NONE, 'Stream process stdout/stderr to the console.');
    }

    /**
     * Executes the CLI command after extracting input arguments and options.
     *
     * This method is the main entry point for command execution. It extracts the
     * environment argument and the show-output option from the input, stores them in
     * instance properties for use throughout the command lifecycle, and then
     * delegates to the validation pipeline. A SymfonyStyle instance is created
     * to provide formatted console output with improved readability.
     *
     * The method displays a title showing the command name and environment, along
     * with a brief description of what the command does, before starting the
     * validation and execution process.
     *
     * @param InputInterface $input The input interface containing command arguments and options.
     * @param OutputInterface $output The output interface for writing command output.
     * @return int Command exit code (SUCCESS or FAILURE) based on validation and execution results.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {

        $this->environment = $input->getArgument('environment');
        $this->showOutput  = $input->getOption('show-output');

        $io = new SymfonyStyle($input, $output);

        $io->title(sprintf('CLI Command <info>[%s] [%s]</info>', $this->getName(), $this->environment));
        $io->text([
            'Executes supplementary CLI commands configured for this environment.',
            'Commands run in priority order and respect the configured error policy.',
        ]);
        $io->newLine();

        return $this->runValidationPipeline($io);
    }

    /**
     * Runs the validation pipeline before executing CLI commands.
     *
     * This method orchestrates a sequence of validation checks using the null coalescing
     * operator (??) to short-circuit on the first failure. Each validation method
     * returns an exit code if it fails, or null if it passes. The pipeline ensures
     * that prerequisites are met before attempting any CLI command executions:
     *
     * 1. Environment validation - checks the environment name is valid
     * 2. Lock file validation - ensures the installation lock file exists
     * 3. Configuration validation - verifies CLI command configuration is present
     * 4. If all validations pass, proceeds to execute enabled commands
     *
     * This pattern provides early failure with clear error messages while keeping
     * the code concise and readable.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int Command exit code (SUCCESS if all validations pass and commands complete,
     *         FAILURE if any validation fails or commands encounter errors).
     */
    private function runValidationPipeline(SymfonyStyle $io): int {

        return $this->validateEnvironment($io)
            ?? $this->validateLockFile($io)
            ?? $this->validateConfiguration($io)
            ?? $this->runEnabledCommands($io);
    }

    /**
     * Validates that the provided environment name is valid and configured.
     *
     * This method delegates to the EnvironmentValidator service to check that the
     * environment name provided by the user exists in the application's configuration
     * and is a valid deployment target. The validator handles the actual validation
     * logic and displays appropriate error messages if the environment is invalid.
     *
     * The method returns null on success to allow the validation pipeline to continue
     * to the next check, or returns a failure exit code to halt execution immediately.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int|null Returns null if validation passes, or an exit code (FAILURE/INVALID)
     *         if validation fails, causing the pipeline to short-circuit.
     */
    private function validateEnvironment(SymfonyStyle $io): ?int {

        $result = $this->environmentValidator->validate($this->environment, $io);
        return $result !== Command::SUCCESS ? $result : null;
    }

    /**
     * Validates that the installation lock file exists and is valid for the environment.
     *
     * This method delegates to the LockFileValidator service to check that the
     * installation lock file (typically .install.lock or similar) exists in the
     * project and contains valid configuration for the target environment. The lock
     * file serves as a safety mechanism to ensure installations are performed in
     * a controlled manner with proper configuration.
     *
     * The method returns null on success to allow the validation pipeline to continue
     * to the next check, or returns a failure exit code to halt execution immediately.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int|null Returns null if validation passes, or an exit code (FAILURE/INVALID)
     *         if validation fails, causing the pipeline to short-circuit.
     */
    private function validateLockFile(SymfonyStyle $io): ?int {

        $result = $this->lockFileValidator->validate($this->environment, $io);
        return $result !== Command::SUCCESS ? $result : null;
    }

    /**
     * Validates that the CLI command configuration exists and is properly structured.
     *
     * This method delegates to the ConfigurationValidator service to check that the
     * 'zyos_install.cli' configuration parameter exists and contains valid CLI command
     * definitions for the target environment. The validator handles the actual validation
     * logic and displays appropriate error messages if the configuration is missing or invalid.
     *
     * If the validator does not return a ParameterBag instance (indicating configuration
     * was not found or is invalid), an error message is displayed and the method returns
     * FAILURE to halt execution. This ensures that the command fails fast with a clear
     * error message when the configuration is missing.
     *
     * The method returns null on success to allow the validation pipeline to proceed
     * to command execution.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int|null Returns null if validation passes and configuration is found,
     *         or FAILURE if the configuration is missing or invalid.
     */
    private function validateConfiguration(SymfonyStyle $io): ?int {

        $parameters = $this->configurationValidator->validate('zyos_install.cli', $this->environment, $io);

        if (!($parameters instanceof ParameterBag)) {
            $io->error('Configuration key "zyos_install.cli" is missing or invalid. Check your bundle configuration.');
            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Retrieves enabled CLI commands and dispatches them for execution.
     *
     * This method is the bridge between validation and actual command execution.
     * It first retrieves all CLI command configuration parameters using the validator,
     * then filters them to include only commands that are enabled for the current
     * environment. The filtering is based on the 'environments' configuration in
     * each command definition.
     *
     * If no commands are enabled for the environment, a success message is displayed
     * and the command exits successfully since there is nothing to do. Otherwise,
     * the enabled commands are sorted by priority in ascending order and passed to
     * the CommandRunner service, which groups them by priority and executes each group.
     * The CommandRunner handles error propagation and continuation logic based on
     * the configured error policy for each command.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int Command exit code (SUCCESS if commands complete successfully or
     *         no commands are enabled, FAILURE if any command fails with a 'stop' error policy).
     */
    private function runEnabledCommands(SymfonyStyle $io): int {

        $allParameters = $this->configurationValidator->validate('zyos_install.cli', $this->environment, $io);
        $enabled       = $this->configurationValidator->filterEnabled($allParameters, $this->environment, $io);

        if (!($enabled instanceof ParameterBag)) {
            $io->success(sprintf('No CLI commands enabled for environment [%s].', $this->environment));
            return Command::SUCCESS;
        }

        $ordered = $enabled->orderByColumn('priority');

        return $this->commandRunner->run(
            $ordered,
            fn(array $group, int $previousExitCode) => $this->executeCommandGroup($io, $group, $previousExitCode),
            $io
        );
    }

    /**
     * Executes a group of CLI commands that share the same priority.
     *
     * This method is called by the CommandRunner service for each group of commands
     * that have the same priority value. Commands within a group are executed
     * sequentially in the order they appear in the configuration. The method tracks
     * the exit code of the last command and returns it, allowing the CommandRunner
     * to determine whether to continue executing remaining groups or halt based on
     * the error policy.
     *
     * The previousExitCode parameter is passed to each command execution to allow
     * commands to be skipped if a previous command in the pipeline failed. This ensures
     * that commands are not executed when the pipeline is in a failed state, unless
     * the error policy specifically allows continuation.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param array $group An array of command configuration entries with the same priority.
     * @param int $previousExitCode The exit code from the previous command group execution.
     * @return int The exit code of the last executed command (SUCCESS if all succeeded,
     *         FAILURE if any failed).
     */
    private function executeCommandGroup(SymfonyStyle $io, array $group, int $previousExitCode): int {

        $lastExitCode = Command::SUCCESS;

        foreach ($group as $entry) {
            $lastExitCode = $this->executeCommandEntry($io, $entry, $previousExitCode);
        }

        return $lastExitCode;
    }

    /**
     * Executes a single CLI command with progress display and error handling.
     *
     * This method is the core command executor that handles individual CLI command
     * executions. It extracts the command configuration, replaces placeholders in
     * the command arguments with environment-specific values, displays a progress
     * indicator, and checks if the pipeline is blocked by a previous failure before
     * attempting execution.
     *
     * If the pipeline is blocked (previousExitCode is not SUCCESS), the command is
     * skipped and the blocking exit code is returned. Otherwise, the command is
     * executed using the Process component, the result is reported, and the error
     * policy is applied to determine the final exit code.
     *
     * The method uses carriage return (\x0D) to overwrite the "Running" status line
     * with the final result, creating a clean progress display.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param array $entry The command configuration array containing command array, priority,
     *        and error policy settings.
     * @param int $previousExitCode The exit code from the previous command execution.
     * @return int Command exit code (SUCCESS if command completes successfully or is skipped,
     *         FAILURE/INVALID/other codes based on error policy if command fails).
     */
    private function executeCommandEntry(SymfonyStyle $io, array $entry, int $previousExitCode): int {

        $tokens   = $this->replacement->arrayReplace($entry['command'], $this->environment);
        $command  = implode(' ', $tokens);
        $priority = $entry['priority'];

        $io->write(sprintf(
            '  - Running <info>Execute Command</info> [ <comment>Priority:</comment> %s ] [ %s ]',
            $priority,
            $command
        ));

        if ($this->pipelineIsBlocked($previousExitCode)) {
            return $this->reportSkipped($io, $priority, $command, $previousExitCode);
        }

        $processExitCode = $this->runProcess($tokens);
        $this->reportResult($io, $priority, $command, $processExitCode);
        return $this->applyErrorPolicy($entry['if_error'], $processExitCode);
    }

    /**
     * Runs a CLI command using Symfony's Process component.
     *
     * This method creates a Process instance from the provided command tokens
     * (command arguments after placeholder replacement) and executes it. If the
     * --show-output option is enabled, the process output is streamed to the console
     * in real-time using a callback that prefixes stdout with 'OUT > ' and stderr
     * with 'ERR > ' for easy identification. Otherwise, the process runs silently.
     *
     * After the process completes, the raw exit code is normalized to Symfony
     * Console command exit codes (SUCCESS, FAILURE, INVALID) for consistency
     * with the rest of the command framework.
     *
     * @param array $tokens The command arguments as an array of strings.
     * @return int The normalized exit code (SUCCESS, FAILURE, INVALID, or the raw code).
     */
    private function runProcess(array $tokens): int {
        $process = new Process($tokens);

        if ($this->showOutput) {
            $process->run(function (string $type, string $buffer): void {
                echo ($type === Process::ERR ? 'ERR > ' : 'OUT > ') . $buffer;
            });
        } else {
            $process->run();
        }

        return $this->normalizeExitCode($process->getExitCode());
    }

    /**
     * Applies the configured error policy to determine the final exit code.
     *
     * This method uses a match expression to dispatch to the appropriate error
     * handling logic based on the configured policy for the command:
     *
     * - ERROR_POLICY_NONE: Always returns SUCCESS, ignoring the actual exit code
     * - ERROR_POLICY_STOP: Returns the actual process exit code, allowing failures
     *   to propagate and halt the pipeline
     * - ERROR_POLICY_DEFAULT: Returns SUCCESS for exit code 0, FAILURE for any
     *   non-zero exit code
     * - Any other value: Delegates to handleUnknownErrorPolicy for graceful handling
     *
     * The error policy allows users to configure whether command failures should
     * be treated as fatal errors, ignored, or handled with standard behavior.
     *
     * @param string $policy The error policy configured for the command.
     * @param int $processExitCode The actual exit code returned by the process.
     * @return int The final exit code based on the error policy (SUCCESS, FAILURE, INVALID, or raw code).
     */
    private function applyErrorPolicy(string $policy, int $processExitCode): int {

        return match ($policy) {
            self::ERROR_POLICY_NONE    => Command::SUCCESS,
            self::ERROR_POLICY_STOP    => $processExitCode,
            self::ERROR_POLICY_DEFAULT => $processExitCode === Command::SUCCESS
                ? Command::SUCCESS
                : Command::FAILURE,
            default => $this->handleUnknownErrorPolicy($policy, $processExitCode),
        };
    }

    /**
     * Handles unknown error policy values by triggering a warning and returning INVALID.
     *
     * This method is called when an error policy value that is not recognized
     * is encountered in the configuration. It triggers a user warning with a detailed
     * message explaining the valid policy values and the encountered invalid value,
     * along with the process exit code. This helps developers identify and fix
     * configuration errors.
     *
     * The method returns Command::INVALID to indicate that the configuration is
     * invalid, which will cause the command to fail. This ensures that misconfigured
     * error policies are surfaced immediately rather than being silently ignored.
     *
     * @param string $policy The unknown error policy value encountered.
     * @param int $processExitCode The exit code of the process that failed.
     * @return int Command::INVALID to indicate configuration error.
     */
    private function handleUnknownErrorPolicy(string $policy, int $processExitCode): int {

        trigger_error(
            sprintf(
                '[zyos:cli] Unknown error policy "%s" encountered. Valid values: "%s", "%s", "%s". '
                . 'The command exit code was %d. Defaulting to FAILURE to surface the misconfiguration.',
                $policy,
                self::ERROR_POLICY_NONE,
                self::ERROR_POLICY_STOP,
                self::ERROR_POLICY_DEFAULT,
                $processExitCode
            ),
            E_USER_WARNING
        );

        return Command::INVALID;
    }

    /**
     * Normalizes raw process exit codes to Symfony Console command exit codes.
     *
     * This method converts the raw exit code returned by the Process component
     * to the standardized Symfony Console exit code constants for consistency
     * with the rest of the command framework. The mapping is:
     *
     * - 0: Command::SUCCESS (process completed successfully)
     * - 1: Command::FAILURE (process failed with a general error)
     * - 2: Command::INVALID (process failed with an invalid argument or usage)
     * - Any other value: Returned as-is to preserve specific exit codes
     *
     * This normalization ensures that exit codes are consistent across different
     * commands and can be properly interpreted by the CommandRunner and error
     * handling logic.
     *
     * @param int $rawExitCode The raw exit code from the Process component.
     * @return int The normalized exit code (SUCCESS, FAILURE, INVALID, or the raw code).
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
     * Determines whether the command pipeline is blocked by a previous failure.
     *
     * This method checks if the previous exit code indicates a failure, which
     * would block the execution of subsequent commands. A pipeline is considered
     * blocked when the previous exit code is anything other than SUCCESS.
     *
     * This check is used to prevent commands from running when a previous command
     * in the pipeline has failed, unless the error policy specifically allows
     * continuation. This ensures that dependent commands are not executed in
     * a failed state, which could cause cascading errors or unpredictable behavior.
     *
     * @param int $previousExitCode The exit code from the previous command execution.
     * @return bool True if the pipeline is blocked (previous exit code is not SUCCESS), false otherwise.
     */
    private function pipelineIsBlocked(int $previousExitCode): bool {
        return $previousExitCode !== Command::SUCCESS;
    }

    /**
     * Reports that a command was skipped due to a previous pipeline failure.
     *
     * This method is called when a command is not executed because the pipeline
     * is blocked by a previous failure. It displays a formatted message indicating
     * that the command was skipped, along with the command details and the exit
     * code that caused the blockage. This provides clear feedback to the user
     * about why certain commands were not executed.
     *
     * The method uses carriage return (\x0D) to overwrite the "Running" status line
     * with the "Skipped" status, maintaining a clean console output. The blocking
     * exit code is returned so that the failure propagates through the pipeline.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param mixed $priority The priority value of the skipped command.
     * @param string $command The command string that was skipped.
     * @param int $blockingExitCode The exit code that caused the pipeline to be blocked.
     * @return int The blocking exit code to propagate the failure.
     */
    private function reportSkipped(SymfonyStyle $io, mixed $priority, string $command, int $blockingExitCode): int {

        $io->write("\x0D");
        $io->writeln(sprintf(
            '  - <comment>[ Skipped ]</comment> <info>Execute Command</info>'
            . ' [ <comment>Priority:</comment> %s ] [ %s ]'
            . ' <comment>(blocked by previous exit code: %d)</comment>',
            $priority,
            $command,
            $blockingExitCode
        ));

        return $blockingExitCode;
    }

    /**
     * Reports the result of a command execution (success or failure).
     *
     * This method displays the final status of a command execution after it has
     * completed. If the command succeeded (exit code is SUCCESS), a "Done" message
     * is displayed. If the command failed, an "Error" message is displayed along
     * with the exit code for debugging purposes.
     *
     * The method uses carriage return (\x0D) to overwrite the "Running" status line
     * with the final result, creating a clean progress display. The output is
     * color-coded with green for success and red for failure to make it easy
     * to scan the console output for errors.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param mixed $priority The priority value of the executed command.
     * @param string $command The command string that was executed.
     * @param int $exitCode The exit code returned by the command execution.
     * @return void
     */
    private function reportResult(SymfonyStyle $io, mixed $priority, string $command, int $exitCode): void {

        $io->write("\x0D");

        if ($exitCode === Command::SUCCESS) {
            $io->writeln(sprintf(
                '  - Done    <info>Execute Command</info> [ <comment>Priority:</comment> %s ] [ %s ]',
                $priority,
                $command
            ));
        } else {
            $io->writeln(sprintf(
                '  - <fg=red;options=bold>Error</> <info>Execute Command</info>'
                . ' [ <comment>Priority:</comment> %s ] [ %s ]'
                . ' <comment>Exit Code:</comment> %d',
                $priority,
                $command,
                $exitCode
            ));
        }
    }
}
