<?php

namespace Zyos\InstallBundle\Command;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Zyos\InstallBundle\ParameterBag;
use Zyos\InstallBundle\Service\CommandExecutor;
use Zyos\InstallBundle\Service\EnvironmentValidator;
use Zyos\InstallBundle\Service\LockFileCreator;
use Zyos\InstallBundle\Service\LockFileValidator;

/**
 * InstallCommand
 *
 * Symfony Console command that executes configured deployment commands for a specific
 * environment in priority order. This command is part of the Zyos Install Bundle and serves
 * as the primary deployment automation tool, allowing administrators to define and execute
 * sequences of Symfony console commands (cache:clear, doctrine:migrations:migrate, etc.)
 * in a controlled, validated, and tracked manner.
 *
 * The command implements a validation pipeline that ensures:
 * - The target environment is valid and configured in the bundle
 * - No lock file exists for the environment (preventing accidental re-installation)
 * - Install configuration entries exist for the target environment
 * - Only enabled entries are executed
 * - Commands are executed in priority order (ascending)
 * - Error handling policies are respected (none, stop, or default)
 * - A lock file is created only on complete success
 *
 * Error policy behavior:
 * - 'none': Command failures are ignored, pipeline continues
 * - 'stop': Command failures stop the pipeline, subsequent commands are skipped
 * - 'default': Command failures result in FAILURE exit code but may continue based on configuration
 *
 * The command uses a pipeline pattern with early returns for validation failures,
 * ensuring that preconditions are met before any destructive operations are performed.
 * On successful completion of all commands, a lock file is created to prevent
 * accidental re-execution. The lock file must be manually removed to re-run the install.
 *
 * Usage:
 *   php bin/console zyos:install prod
 *   php bin/console zyos:install staging --show-output
 *
 * Responsibilities:
 * - Validate the target environment against bundle configuration
 * - Check for existing lock files to prevent duplicate installations
 * - Filter and sort install entries by environment and priority
 * - Execute Symfony console commands with proper error handling
 * - Apply configured error policies to determine pipeline continuation
 * - Create lock file on successful completion
 * - Provide detailed console output for debugging and audit trails
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Command
 */
class InstallCommand extends Command {

    /**
     * Error policy that ignores command failures and continues the pipeline.
     *
     * This constant defines the 'none' error policy, which indicates that when a
     * command fails during execution, the failure should be ignored and the pipeline
     * should continue executing subsequent commands. This policy is useful for
     * non-critical commands where failure is acceptable or expected, such as
     * optional cache warming or non-essential cleanup operations.
     *
     * When this policy is applied:
     * - The command's exit code is discarded
     * - Command::SUCCESS is returned to the pipeline
     * - Subsequent commands continue to execute normally
     * - The overall pipeline may still succeed even with individual command failures
     *
     * Used in: applyErrorPolicy() to determine behavior when a command fails.
     *
     * @var string
     */
    private const string ERROR_POLICY_NONE = 'none';

    /**
     * Error policy that stops the pipeline on command failure.
     *
     * This constant defines the 'stop' error policy, which indicates that when a
     * command fails during execution, the pipeline should immediately stop and
     * subsequent commands should be skipped. This policy is useful for critical
     * commands where failure indicates a serious problem that should prevent
     * further execution, such as database migrations or schema updates.
     *
     * When this policy is applied:
     * - The command's exit code is preserved
     * - The pipeline is blocked for all subsequent commands
     * - Skipped commands are reported with the blocking exit code
     * - The overall pipeline returns the failure exit code
     * - No lock file is created
     *
     * Used in: applyErrorPolicy() to determine behavior when a command fails.
     *
     * @var string
     */
    private const string ERROR_POLICY_STOP = 'stop';

    /**
     * Default error policy that converts failures to FAILURE exit code.
     *
     * This constant defines the 'default' error policy, which indicates that when
     * a command fails during execution, the failure should be converted to a
     * Command::FAILURE exit code. This is the standard behavior for most commands,
     * treating any non-zero exit code as a failure that should be reported but
     * allowing the pipeline to continue based on other configuration.
     *
     * When this policy is applied:
     * - Command::SUCCESS exit codes return Command::SUCCESS
     * - Any non-zero exit code returns Command::FAILURE
     * - The pipeline may continue or stop based on other factors
     * - Exit codes are normalized to Symfony Console constants
     *
     * Used in: applyErrorPolicy() to determine behavior when a command fails.
     *
     * @var string
     */
    private const string ERROR_POLICY_DEFAULT = 'default';

    /**
     * The target deployment environment for the current command execution.
     *
     * This property stores the environment name (e.g., 'dev', 'prod', 'staging')
     * provided as a command argument. The environment is used throughout the
     * validation and execution pipeline to:
     *
     * - Validate that the environment is configured in the bundle
     * - Check for environment-specific lock files
     * - Filter install entries to those configured for this environment
     * - Pass the environment context to executed commands
     * - Create environment-specific lock files on success
     *
     * The property is set at the beginning of execute() from the input argument
     * and remains constant throughout the command execution. It is used by
     * multiple methods to ensure environment-specific behavior.
     *
     * Initialized in: execute() method from InputInterface argument.
     * Used in: validateEnvironment(), validateLockFile(), validateConfiguration(),
     *          runEnabledCommands(), runSymfonyCommand(), reportAndFinalize().
     *
     * @var string
     */
    private string $environment;

    /**
     * Constructor for InstallCommand.
     *
     * Initializes the command with all required dependencies through constructor property promotion.
     * These dependencies provide access to Symfony's core services and bundle-specific services
     * needed for environment validation, lock file management, and command execution.
     *
     * The constructor accepts readonly properties to ensure immutability after injection,
     * following modern PHP best practices for dependency injection in Symfony commands.
     *
     * @param ParameterBagInterface $parameterBag Symfony's parameter bag for accessing bundle configuration
     * @param EnvironmentValidator $environmentValidator Service for validating environment configuration
     * @param LockFileValidator $lockFileValidator Service for checking existing lock files
     * @param LockFileCreator $lockFileCreator Service for creating lock files on successful completion
     * @param CommandExecutor $commandExecutor Service for executing Symfony console commands
     */
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly EnvironmentValidator  $environmentValidator,
        private readonly LockFileValidator     $lockFileValidator,
        private readonly LockFileCreator       $lockFileCreator,
        private readonly CommandExecutor       $commandExecutor
    ) {
        parent::__construct();
    }

    /**
     * Configures the command definition, name, description, arguments, and options.
     *
     * This method sets up the command's metadata as required by Symfony's Console component.
     * The configuration defines:
     *
     * - Command name: 'zyos:install' - the CLI command used to invoke this deployment tool
     * - Description: Explains the command's purpose of executing configured deployment commands
     * - Environment argument: REQUIRED argument specifying the target deployment environment
     * - show-output option: OPTIONAL flag to stream command output to the console
     *
     * The environment argument is required because the command must know which environment
     * to target for validation, filtering, and execution. The show-output option allows
     * administrators to see real-time output from executed commands for debugging purposes.
     *
     * @return void
     */
    protected function configure(): void {

        $this
            ->setName('zyos:install')
            ->setDescription('Executes configured Symfony commands for a deployment environment.')
            ->addArgument('environment', InputArgument::REQUIRED, 'Target deployment environment (e.g. dev, prod).')
            ->addOption('show-output', null, InputOption::VALUE_NONE, 'Stream command output to the console.');
    }

    /**
     * Executes the install command with validation pipeline and command execution.
     *
     * This is the main entry point for command execution. It initializes the command state,
     * displays the command header with usage information, and invokes the validation pipeline
     * that orchestrates all pre-flight checks and command execution.
     *
     * Execution flow:
     * 1. Extracts the environment argument from input and stores it in $this->environment
     * 2. Configures the command executor's show-output flag based on the option
     * 3. Creates a SymfonyStyle instance for enhanced console output formatting
     * 4. Displays a formatted title with command name and target environment
     * 5. Displays usage information describing the command's behavior and options
     * 6. Invokes runValidationPipeline() to execute the full validation and execution pipeline
     *
     * The method acts as a coordinator, delegating the actual validation and execution
     * logic to the pipeline methods. This separation of concerns keeps the execute()
     * method focused on initialization and orchestration.
     *
     * @param InputInterface $input Symfony's input interface providing arguments and options
     * @param OutputInterface $output Symfony's output interface for writing console output
     * @return int Command::SUCCESS on success, Command::FAILURE on failure, or other exit codes
     * @throws Exception
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {

        $this->environment = $input->getArgument('environment');
        $this->commandExecutor->setShowOutput($input->getOption('show-output'));

        $io = new SymfonyStyle($input, $output);

        $io->title(sprintf('Install Command <info>[%s] [%s]</info>', $this->getName(), $this->environment));
        $io->text([
            'Executes deployment commands configured for this environment in priority order.',
            'On full success a lock file is created to prevent accidental re-installation.',
            'Use <comment>--show-output</comment> to stream each command\'s stdout/stderr to the console.',
        ]);
        $io->newLine();

        return $this->runValidationPipeline($io);
    }

    /**
     * Orchestrates the validation and execution pipeline using the null coalescing operator.
     *
     * This method implements a pipeline pattern where each validation step returns either
     * an exit code (indicating failure) or null (indicating success and continuation). The
     * null coalescing operator (??) chains the steps, short-circuiting on the first non-null
     * return value. This provides a clean, readable way to implement early-return validation.
     *
     * Pipeline steps (executed in order):
     * 1. validateEnvironment(): Validates the target environment is configured
     * 2. validateLockFile(): Checks for existing lock files (prevents re-installation)
     * 3. validateConfiguration(): Validates install configuration exists for environment
     * 4. runEnabledCommands(): Executes the actual deployment commands
     *
     * If any validation step returns a non-null exit code (failure), the pipeline
     * short-circuits and returns that exit code immediately. Only if all steps return
     * null (success) does the pipeline proceed to the next step.
     *
     * This pattern ensures that preconditions are checked before any destructive
     * operations are performed, and that failures are reported immediately without
     * executing unnecessary subsequent steps.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output
     * @return int Exit code from the first failing step, or the final command execution result
     * @throws Exception|ExceptionInterface
     */
    private function runValidationPipeline(SymfonyStyle $io): int {

        return $this->validateEnvironment($io)
            ?? $this->validateLockFile($io)
            ?? $this->validateConfiguration($io)
            ?? $this->runEnabledCommands($io);
    }

    /**
     * Validates that the target environment is properly configured in the bundle.
     *
     * This method delegates to the EnvironmentValidator service to check that the
     * specified environment exists in the bundle's configuration and is valid for
     * installation operations. This is the first validation step in the pipeline,
     * ensuring that we're working with a known environment before proceeding.
     *
     * Validation logic:
     * - Calls EnvironmentValidator->validate() with the environment and SymfonyStyle instance
     * - If validation fails (non-SUCCESS return), returns the exit code to short-circuit the pipeline
     * - If validation succeeds (SUCCESS return), returns null to allow pipeline continuation
     *
     * The EnvironmentValidator service performs checks such as:
     * - Environment exists in the configured environments list
     * - Environment name is valid and not empty
     * - Environment-specific configuration is present
     *
     * @param SymfonyStyle $io SymfonyStyle instance for validation output and error messages
     * @return int|null Exit code if validation fails, null if validation succeeds
     */
    private function validateEnvironment(SymfonyStyle $io): ?int {

        $result = $this->environmentValidator->validate($this->environment, $io);
        return $result !== Command::SUCCESS ? $result : null;
    }

    /**
     * Validates that no lock file exists for the target environment.
     *
     * This method delegates to the LockFileValidator service to check for the existence
     * of a lock file for the specified environment. Lock files are created after successful
     * installation to prevent accidental re-execution of deployment commands. This check
     * is critical for production environments where re-running installation commands
     * could cause data corruption or unintended side effects.
     *
     * Validation logic:
     * - Calls LockFileValidator->validate() with the environment and SymfonyStyle instance
     * - If a lock file exists (validation fails), returns the exit code to short-circuit the pipeline
     * - If no lock file exists (validation succeeds), returns null to allow pipeline continuation
     *
     * The LockFileValidator service checks:
     * - Lock file path is configured
     * - Lock file does not exist for the environment
     * - Lock file is readable if it exists (for validation of contents)
     *
     * If a lock file exists, the user is informed that installation has already been
     * completed and must manually remove the lock file to re-run the installation.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for validation output and error messages
     * @return int|null Exit code if lock file exists, null if validation succeeds
     */
    private function validateLockFile(SymfonyStyle $io): ?int {

        $result = $this->lockFileValidator->validate($this->environment, $io);
        return $result !== Command::SUCCESS ? $result : null;
    }

    /**
     * Validates that install configuration entries exist for the target environment.
     *
     * This method checks the Symfony parameter bag for the 'zyos_install.install'
     * configuration key and validates that install entries are configured for the
     * target environment. This ensures that there are actually commands to execute
     * before proceeding to the execution phase.
     *
     * Validation steps:
     * 1. Checks if 'zyos_install.install' parameter exists in the parameter bag
     *    - If missing, displays error and returns Command::FAILURE
     * 2. Loads all install entries into a ParameterBag instance
     * 3. Checks if any install entries exist at all
     *    - If none exist, displays success message and returns Command::SUCCESS
     * 4. Filters entries to those configured for the target environment
     * 5. Checks if any entries exist for the target environment
     *    - If none exist, displays success message and returns Command::SUCCESS
     *    - This is a bug fix from the original which incorrectly returned FAILURE
     * 6. If entries exist for the environment, returns null to proceed to execution
     *
     * The method uses the ParameterBag utility class for filtering and counting
     * configuration entries, providing a clean interface for working with
     * structured configuration data.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for validation output and messages
     * @return int|null Exit code if configuration is missing, Command::SUCCESS if no entries to run,
     *                null if entries exist and execution should proceed
     */
    private function validateConfiguration(SymfonyStyle $io): ?int {

        if (!$this->parameterBag->has('zyos_install.install')) {
            $io->error('Configuration key "zyos_install.install" is missing. Check your bundle configuration.');
            return Command::FAILURE;
        }

        $all = new ParameterBag($this->parameterBag->get('zyos_install.install'));

        if ($all->count() === 0) {
            $io->success('No install entries configured.');
            return Command::SUCCESS;
        }

        $forEnvironment = $all->filter(
            fn(array $entry) => in_array($this->environment, $entry['environments'], true)
        );

        if ($forEnvironment->count() === 0) {
            $io->success(sprintf('No install entries configured for environment [%s].', $this->environment));
            return Command::SUCCESS;
        }

        return null;
    }

    /**
     * Filters, sorts, and executes enabled install commands for the target environment.
     *
     * This method is the main execution coordinator after all validations pass. It filters
     * the install configuration to find entries that are both configured for the target
     * environment and enabled, sorts them by priority, and executes them in order. After
     * execution, it reports the results and creates a lock file if all commands succeeded.
     *
     * Execution flow:
     * 1. Loads all install entries from the parameter bag into a ParameterBag
     * 2. Filters entries to those configured for the target environment
     * 3. Filters entries to those with 'enable' set to true
     * 4. If no enabled entries exist, displays success and returns Command::SUCCESS
     * 5. Sorts the enabled entries by priority in ascending order
     * 6. Displays the count of commands to execute
     * 7. Executes the command pipeline via executeCommandPipeline()
     * 8. Reports results and finalizes via reportAndFinalize()
     *
     * Priority sorting ensures that commands with lower priority values execute
     * first, allowing administrators to define dependencies between commands
     * (e.g., database migrations must run before cache warming).
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output and progress reporting
     * @return int Command::SUCCESS if all commands succeed, otherwise the appropriate exit code
     * @throws Exception|ExceptionInterface
     */
    private function runEnabledCommands(SymfonyStyle $io): int {

        $all = new ParameterBag($this->parameterBag->get('zyos_install.install'));

        $enabled = $all
            ->filter(fn(array $entry) => in_array($this->environment, $entry['environments'], true))
            ->filter(fn(array $entry) => $entry['enable'] === true);

        if ($enabled->count() === 0) {
            $io->success(sprintf('No active install entries for environment [%s].', $this->environment));
            return Command::SUCCESS;
        }

        $ordered = $enabled->orderByColumn('priority');

        $io->text(sprintf('<comment>Commands to execute:</comment> %d', $ordered->count()));
        $io->newLine();

        $finalExitCode = $this->executeCommandPipeline($io, $ordered);

        return $this->reportAndFinalize($io, $finalExitCode);
    }

    /**
     * Iterates through the sorted command entries and executes each in sequence.
     *
     * This method implements the core execution loop that processes each command entry
     * in the sorted ParameterBag. It maintains a running exit code that represents
     * the state of the pipeline, passing it to each command execution to enable
     * blocking behavior when previous commands fail.
     *
     * Execution logic:
     * - Initializes previousExitCode to Command::SUCCESS (pipeline starts clean)
     * - Iterates through the parameters (which may be grouped by priority)
     * - For each entry in each group, calls executeCommandEntry() with the current exit code
     * - Updates previousExitCode with the result of each command execution
     * - Returns the final exit code after all commands have been processed
     *
     * The nested foreach structure handles the case where ParameterBag may group
     * entries by priority or other criteria. Each entry is processed in order,
     * with the exit code propagating through the pipeline to enable error policy
     * enforcement and blocking behavior.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output and progress reporting
     * @param ParameterBag $parameters Sorted ParameterBag of command entries to execute
     * @return int The final exit code after processing all commands
     * @throws Exception|ExceptionInterface
     */
    private function executeCommandPipeline(SymfonyStyle $io, ParameterBag $parameters): int {

        $previousExitCode = Command::SUCCESS;

        foreach ($parameters as $group) {
            foreach ($group as $entry) {
                $previousExitCode = $this->executeCommandEntry($io, $entry, $previousExitCode);
            }
        }

        return $previousExitCode;
    }

    /**
     * Executes a single command entry with error handling and policy application.
     *
     * This method handles the execution of an individual command entry from the install
     * configuration. It checks if the pipeline is blocked by a previous failure, executes
     * the command if not blocked, applies the configured error policy, and reports the
     * result to the console.
     *
     * Execution flow:
     * 1. Extracts command name, arguments, and priority from the entry array
     * 2. Displays a "Running" message with priority and command name
     * 3. Checks if the pipeline is blocked by a previous command failure
     *    - If blocked, reports the command as skipped and returns the blocking exit code
     * 4. If not blocked, executes the Symfony command via runSymfonyCommand()
     * 5. Reports the execution result (success or error) via reportResult()
     * 6. Applies the configured error policy via applyErrorPolicy()
     * 7. Returns the exit code after policy application
     *
     * The method uses carriage return (\x0D) to overwrite the "Running" message with
     * the final result, creating a dynamic progress indicator that updates in place.
     * This provides clean console output without excessive scrolling.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output
     * @param array $entry The command entry configuration array
     * @param int $previousExitCode The exit code from the previous command in the pipeline
     * @return int The exit code after execution and error policy application
     * @throws Exception|ExceptionInterface
     */
    private function executeCommandEntry(SymfonyStyle $io, array $entry, int $previousExitCode): int {

        $commandName = $entry['command'];
        $arguments   = $entry['arguments'] ?? [];
        $priority    = $entry['priority'];

        $io->write(sprintf(
            '  - Running <info>Execute Command</info> [ <comment>Priority:</comment> %s ] [ %s ]',
            $priority,
            $commandName
        ));

        if ($this->pipelineIsBlocked($previousExitCode)) {
            return $this->reportSkipped($io, $priority, $commandName, $previousExitCode);
        }

        $processExitCode = $this->runSymfonyCommand($commandName, $arguments);
        $this->reportResult($io, $priority, $commandName, $processExitCode);

        return $this->applyErrorPolicy($entry['if_error'], $processExitCode);
    }

    /**
     * Executes a Symfony console command and normalizes the exit code.
     *
     * This method delegates to the CommandExecutor service to actually execute the
     * Symfony console command. It passes the current application instance, command name,
     * arguments, and environment context to the executor. After execution, it normalizes
     * the raw exit code to Symfony Console constants for consistent handling.
     *
     * Execution process:
     * - Calls CommandExecutor->execute() with the application, command name, arguments, and environment
     * - The CommandExecutor service handles the actual process execution and output capture
     * - The raw exit code from the process is passed to normalizeExitCode()
     * - The normalized exit code is returned for use in error policy application
     *
     * The CommandExecutor service provides:
     * - Process execution with proper environment setup
     * - Output streaming or capture based on show-output configuration
     * - Timeout handling and process management
     * - Integration with Symfony's application container
     *
     * Normalization ensures that raw process exit codes (0, 1, 2, etc.) are
     * mapped to Symfony Console constants (SUCCESS, FAILURE, INVALID) for
     * consistent error handling throughout the pipeline.
     *
     * @param string $commandName The Symfony console command to execute (e.g., 'cache:clear')
     * @param array $arguments Array of arguments and options to pass to the command
     * @return int Normalized exit code (Command::SUCCESS, Command::FAILURE, or Command::INVALID)
     * @throws Exception
     * @throws ExceptionInterface
     */
    private function runSymfonyCommand(string $commandName, array $arguments): int {

        return $this->normalizeExitCode(
            $this->commandExecutor->execute(
                $this->getApplication(),
                $commandName,
                $arguments,
                $this->environment
            )
        );
    }

    /**
     * Applies the configured error policy to determine the pipeline exit code.
     *
     * This method implements the error policy logic that determines how the pipeline
     * should respond to a command's exit code. Different policies allow administrators
     * to control whether failures stop the pipeline, are ignored, or are converted
     * to standard Symfony exit codes.
     *
     * Policy behavior:
     * - ERROR_POLICY_NONE: Always returns Command::SUCCESS, ignoring the exit code
     * - ERROR_POLICY_STOP: Returns the actual process exit code, preserving failure state
     * - ERROR_POLICY_DEFAULT: Returns Command::SUCCESS if exit code is SUCCESS,
     *   otherwise returns Command::FAILURE
     * - Unknown policies: Delegates to handleUnknownErrorPolicy() for graceful handling
     *
     * The match expression provides a clean, readable way to implement the policy
     * logic without nested if-else statements. This makes the code easier to
     * maintain and extend with new policies in the future.
     *
     * Error policies are configured per-command in the install configuration,
     * allowing fine-grained control over how different types of commands should
     * behave when they fail (e.g., critical migrations vs. optional cache warming).
     *
     * @param string $policy The error policy string from the command configuration
     * @param int $processExitCode The exit code returned by the executed command
     * @return int The exit code after applying the error policy
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
     * Handles unknown error policies with a warning and fallback to INVALID.
     *
     * This method is called when an error policy string does not match any of the
     * known policy constants. This can occur due to configuration errors, typos,
     * or future policy additions that haven't been implemented yet. The method
     * triggers a PHP warning to alert administrators to the configuration issue
     * and returns Command::INVALID as a safe fallback.
     *
     * Error handling:
     * - Triggers an E_USER_WARNING with details about the unknown policy
     * - Includes the unknown policy value, valid policy values, and the command exit code
     * - The warning is prefixed with '[zyos:install]' for easy identification in logs
     * - Returns Command::INVALID to indicate a configuration error
     *
     * Returning Command::INVALID ensures that the pipeline stops and the issue
     * is brought to the administrator's attention, preventing silent failures
     * or unexpected behavior from misconfigured error policies.
     *
     * This defensive programming approach ensures that configuration errors are
     * caught and reported rather than silently causing incorrect behavior.
     *
     * @param string $policy The unknown error policy string from configuration
     * @param int $processExitCode The exit code from the executed command
     * @return int Always returns Command::INVALID to indicate configuration error
     */
    private function handleUnknownErrorPolicy(string $policy, int $processExitCode): int {

        trigger_error(
            sprintf(
                '[zyos:install] Unknown error policy "%s" (valid: "%s", "%s", "%s"). '
                . 'Command exit code was %d. Defaulting to INVALID.',
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
     * Normalizes raw process exit codes to Symfony Console command constants.
     *
     * This method converts raw integer exit codes returned by processes into
     * Symfony Console command constants for consistent handling throughout the
     * pipeline. This normalization ensures that exit codes are compared against
     * the expected constants rather than magic numbers.
     *
     * Normalization mapping:
     * - 0: Command::SUCCESS (successful execution)
     * - 1: Command::FAILURE (general failure)
     * - 2: Command::INVALID (invalid arguments or configuration)
     * - Other values: Returned as-is (preserves custom exit codes)
     *
     * The match expression provides a clean mapping that can be easily extended
     * with additional exit codes if needed. Preserving unknown exit codes allows
     * for custom exit codes from third-party commands to pass through unchanged.
     *
     * Standard Unix convention:
     * - Exit code 0 indicates success
     * - Non-zero exit codes indicate various types of failure
     * - Exit codes 1-127 are typically application-specific
     * - Exit codes 128+ are typically signal terminations
     *
     * @param int $rawExitCode The raw integer exit code from the executed process
     * @return int The normalized exit code (Symfony Console constant or original value)
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
     * Determines whether the pipeline is blocked by a previous command failure.
     *
     * This method checks if the previous command in the pipeline returned a non-success
     * exit code, which indicates that the pipeline should be blocked and subsequent
     * commands should be skipped. This blocking behavior is controlled by the error
     * policy applied to the previous command.
     *
     * Blocking logic:
     * - Returns true if previousExitCode is not Command::SUCCESS
     * - Returns false if previousExitCode is Command::SUCCESS
     *
     * When the pipeline is blocked:
     * - Subsequent commands are skipped and reported as such
     * - The blocking exit code is preserved and propagated
     * - No lock file is created at the end of the pipeline
     * - The final exit code reflects the original failure
     *
     * When the pipeline is not blocked:
     * - Subsequent commands execute normally
     * - Each command's exit code is evaluated independently
     * - Error policies determine if the pipeline becomes blocked
     *
     * This simple boolean check provides a clear semantic for determining
     * whether execution should continue, making the pipeline logic easier
     * to understand and maintain.
     *
     * @param int $previousExitCode The exit code from the previous command execution
     * @return bool True if the pipeline is blocked, false otherwise
     */
    private function pipelineIsBlocked(int $previousExitCode): bool {
        return $previousExitCode !== Command::SUCCESS;
    }

    /**
     * Reports the final pipeline result and creates a lock file on success.
     *
     * This method is called after all commands have been executed to report the
     * overall result and perform finalization actions. If all commands succeeded,
     * it creates a lock file to prevent accidental re-installation. If any command
     * failed, it reports the failure and ensures no lock file is created.
     *
     * Finalization logic:
     * - Adds a blank line for visual separation in console output
     * - If finalExitCode is Command::SUCCESS:
     *   - Calls LockFileCreator->create() to create the environment lock file
     *   - Displays success message indicating lock file creation
     *   - Returns Command::SUCCESS
     * - If finalExitCode is not Command::SUCCESS:
     *   - Displays error message indicating one or more commands failed
     *   - Explicitly states that no lock file was created
     *   - Advises the user to fix issues and re-run
     *   - Returns the normalized exit code for proper CLI exit behavior
     *
     * The lock file serves as a guard against accidental re-execution of
     * deployment commands, which could cause data corruption or unintended
     * side effects. The lock file must be manually removed to re-run the
     * installation, providing a deliberate safety mechanism.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output
     * @param int $finalExitCode The final exit code from the command pipeline
     * @return int Command::SUCCESS on success, or the normalized exit code on failure
     */
    private function reportAndFinalize(SymfonyStyle $io, int $finalExitCode): int {

        $io->newLine();

        if ($finalExitCode === Command::SUCCESS) {
            $this->lockFileCreator->create($this->environment, $io);
            $io->success('All commands executed successfully. Lock file created.');
            return Command::SUCCESS;
        }

        $io->error('One or more commands failed. Lock file was NOT created. Fix the issues above and re-run.');
        return $this->normalizeExitCode($finalExitCode);
    }

    /**
     * Reports a command as skipped due to pipeline blocking.
     *
     * This method is called when a command cannot be executed because the pipeline
     * is blocked by a previous command failure. It overwrites the "Running" message
     * with a "Skipped" message that includes the blocking exit code for debugging.
     *
     * Reporting behavior:
     * - Uses carriage return (\x0D) to overwrite the "Running" message
     * - Displays "[ Skipped ]" label in comment color
     * - Includes the command priority and name for identification
     * - Shows the blocking exit code that caused the skip
     * - Returns the blocking exit code to preserve the failure state
     *
     * The skipped message provides clear visibility into why a command was not
     * executed, helping administrators understand the chain of failures and
     * identify the root cause. The blocking exit code helps trace back to
     * which specific command caused the pipeline to stop.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output
     * @param mixed $priority The priority value of the skipped command
     * @param string $commandName The name of the skipped Symfony command
     * @param int $blockingExitCode The exit code that is blocking the pipeline
     * @return int The blocking exit code (preserved for pipeline state)
     */
    private function reportSkipped(SymfonyStyle $io, mixed $priority, string $commandName, int $blockingExitCode): int {

        $io->write("\x0D");
        $io->writeln(sprintf(
            '  - <comment>[ Skipped ]</comment> <info>Execute Command</info>'
            . ' [ <comment>Priority:</comment> %s ] [ %s ]'
            . ' <comment>(blocked by previous exit code: %d)</comment>',
            $priority,
            $commandName,
            $blockingExitCode
        ));

        return $blockingExitCode;
    }

    /**
     * Reports the execution result of a command (success or failure).
     *
     * This method overwrites the "Running" message with the final execution result,
     * displaying either a success or error message depending on the exit code. The
     * message includes the command priority, name, and exit code for debugging.
     *
     * Reporting behavior:
     * - Uses carriage return (\x0D) to overwrite the "Running" message
     * - If exitCode is Command::SUCCESS:
     *   - Displays "Success" label in info color
     *   - Shows priority and command name
     * - If exitCode is not Command::SUCCESS:
     *   - Displays "Error" label in red with bold formatting
     *   - Shows priority and command name
     *   - Includes the exit code for debugging
     *
     * The color-coded output provides immediate visual feedback on command success
     * or failure, making it easy to scan the console output for issues. The exit
     * code is included in error messages to help diagnose the specific type of
     * failure that occurred.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for console output
     * @param mixed $priority The priority value of the executed command
     * @param string $commandName The name of the executed Symfony command
     * @param int $exitCode The exit code returned by the command execution
     * @return void
     */
    private function reportResult(SymfonyStyle $io, mixed $priority, string $commandName, int $exitCode): void {
        $io->write("\x0D");

        if ($exitCode === Command::SUCCESS) {
            $io->writeln(sprintf(
                '  - <info>Success</info> Execute Command [ <comment>Priority:</comment> %s ] [ %s ]',
                $priority,
                $commandName
            ));
        } else {
            $io->writeln(sprintf(
                '  - <fg=red;options=bold>Error</> <info>Execute Command</info>'
                . ' [ <comment>Priority:</comment> %s ] [ %s ]'
                . ' <comment>Exit Code:</comment> %d',
                $priority,
                $commandName,
                $exitCode
            ));
        }
    }
}
