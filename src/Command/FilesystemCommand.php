<?php

namespace Zyos\InstallBundle\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
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
use Zyos\InstallBundle\Service\CommandRunner;
use Zyos\InstallBundle\Service\ConfigurationValidator;
use Zyos\InstallBundle\Service\EnvironmentValidator;
use Zyos\InstallBundle\Service\LockFileValidator;

/**
 * FilesystemCommand
 *
 * Command for handling filesystem operations required for deploying the application.
 *
 * This command is responsible for creating directories, creating symbolic links,
 * and mirroring directories required for deploying the application. The command
 * is designed to be run in a controlled environment, typically as part of a CI/CD
 * pipeline or manually on a development machine.
 *
 * The command accepts a single required argument, the target deployment environment
 * (e.g. dev, prod), and several optional flags for filtering operation types and
 * output verbosity. The command also provides a pipeline for executing operations
 * grouped by priority, with proper error handling and continuation logic.
 *
 * The command is designed to be highly configurable and extensible, allowing
 * developers to customize the filesystem operations and their order of execution.
 * The command is also designed to be runnable in any environment, without requiring
 * additional dependencies or setup beyond what is provided by Symfony.
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Command
 */
class FilesystemCommand extends Command {

    /**
     * Constant representing the directory creation operation type.
     *
     * This constant is used to identify filesystem operations that involve creating
     * a single directory. When this type is specified, the command will use the
     * Filesystem component's mkdir() method to create the directory specified in
     * the source path. This operation does not require a destination path since
     * it only creates a single directory location.
     *
     * @var string
     */
    private const string TYPE_DIRECTORY = 'directory';
    /**
     * Constant representing the symbolic link creation operation type.
     *
     * This constant is used to identify filesystem operations that involve creating
     * a symbolic link (symlink) from a source path to a destination path. When this
     * type is specified, the command will use the Filesystem component's symlink()
     * method to create the link. This operation requires both a source path (the
     * target of the link) and a destination path (where the link will be created).
     *
     * @var string
     */
    private const string TYPE_SYMLINK   = 'symlink';
    /**
     * Constant representing the directory mirroring operation type.
     *
     * This constant is used to identify filesystem operations that involve mirroring
     * a source directory to a destination directory. When this type is specified,
     * the command will use the Filesystem component's mirror() method to recursively
     * copy all files and directories from the source to the destination. This
     * operation requires both a source path (the directory to mirror) and a destination
     * path (where the mirrored content will be placed).
     *
     * @var string
     */
    private const string TYPE_MIRROR    = 'mirror';

    /**
     * Array containing all supported filesystem operation types.
     *
     * This constant serves as a master list of all valid operation types that can
     * be processed by this command. It is used when no type filter is specified
     * by the user, allowing the command to process all configured operations
     * regardless of their type. The order in this array does not affect execution
     * order, as operations are sorted by priority before execution.
     *
     * @var array<string>
     */
    private const array ALL_TYPES = [
        self::TYPE_MIRROR,
        self::TYPE_SYMLINK,
        self::TYPE_DIRECTORY,
    ];

    /**
     * Array of filesystem operation types that require a destination path.
     *
     * This constant identifies which operation types need both a source and a
     * destination path to be valid. Directory creation only requires a source
     * path (the directory to create), but symbolic links and mirroring operations
     * require both source (target) and destination (link/location) paths. This
     * constant is used for validation to ensure that required configuration
     * parameters are present before attempting to execute an operation.
     *
     * @var array<string>
     */
    private const array TYPES_REQUIRING_DESTINATION = [
        self::TYPE_SYMLINK,
        self::TYPE_MIRROR,
    ];

    /**
     * The target deployment environment for the current command execution.
     *
     * This property stores the environment name (e.g., 'dev', 'prod', 'staging')
     * that was passed as a required argument to the command. The environment is
     * used throughout the command execution to filter which operations should
     * be run based on their configured environment compatibility, and to
     * perform environment-specific path replacements using the Replacement service.
     *
     * @var string
     */
    private string $environment;
    /**
     * Flag indicating whether only mirror operations should be executed.
     *
     * When set to true via the --mirror command option, this property causes
     * the command to filter out all operation types except TYPE_MIRROR.
     * This allows users to run only directory mirroring operations without
     * executing directory creation or symlink operations. When false (default),
     * mirror operations are included in the execution unless another filter
     * is active.
     *
     * @var bool
     */
    private bool $filterMirror;
    /**
     * Flag indicating whether only symlink operations should be executed.
     *
     * When set to true via the --symlink command option, this property causes
     * the command to filter out all operation types except TYPE_SYMLINK.
     * This allows users to run only symbolic link creation operations without
     * executing directory creation or mirroring operations. When false (default),
     * symlink operations are included in the execution unless another filter
     * is active.
     *
     * @var bool
     */
    private bool $filterSymlink;
    /**
     * Flag indicating whether only directory operations should be executed.
     *
     * When set to true via the --directory command option, this property causes
     * the command to filter out all operation types except TYPE_DIRECTORY.
     * This allows users to run only directory creation operations without
     * executing symlink or mirroring operations. When false (default),
     * directory operations are included in the execution unless another filter
     * is active.
     *
     * @var bool
     */
    private bool $filterDirectory;
    /**
     * Flag indicating whether detailed operation output should be displayed.
     *
     * When set to true via the --show-output command option, this property causes
     * the command to print detailed information about each operation after it
     * completes successfully. The detailed output includes the operation type,
     * priority, enabled environments, and relevant paths. When false (default),
     * only a brief status line is shown for each operation, keeping the output
     * concise.
     *
     * @var bool
     */
    private bool $showOutput;

    /**
     * Constructor that injects all required dependencies for the filesystem command.
     *
     * This constructor uses constructor property promotion to declare and initialize
     * all required service dependencies in a single step. Each dependency is marked
     * as readonly to ensure immutability after construction. The command is
     * initialized with a null name, which will be set in the configure() method.
     *
     * @param ParameterBagInterface $parameterBag Symfony's parameter bag for accessing
     *        container parameters, used to check if filesystem configuration exists.
     * @param Filesystem $filesystem Symfony's Filesystem component for performing
     *        actual filesystem operations (mkdir, symlink, mirror).
     * @param Replacement $replacement Service for replacing placeholders in paths
     *        with environment-specific values (e.g., %kernel.project_dir%).
     * @param EnvironmentValidator $environmentValidator Service for validating that
     *        the provided environment name is valid and configured.
     * @param LockFileValidator $lockFileValidator Service for checking that the
     *        installation lock file exists and is valid for the environment.
     * @param ConfigurationValidator $configurationValidator Service for validating
     *        and filtering the filesystem configuration based on environment.
     * @param CommandRunner $commandRunner Service for executing operations grouped
     *        by priority with proper error handling and continuation logic.
     */
    public function __construct(
        private readonly ParameterBagInterface  $parameterBag,
        private readonly Filesystem             $filesystem,
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
     * and optional filtering flags. The configuration allows users to control
     * which operations are executed and how much output is displayed.
     *
     * The command accepts:
     * - One required argument: the target environment name
     * - Four optional flags for filtering operation types and output verbosity
     *
     * @return void
     */
    protected function configure(): void {

        $this
            ->setName('zyos:filesystem')
            ->setDescription('Run directory creation, symlink creation, and directory mirroring.')
            ->addArgument('environment', InputArgument::REQUIRED, 'Target deployment environment (e.g. dev, prod).')
            ->addOption('mirror',      null, InputOption::VALUE_NONE, 'Run only directory mirroring operations.')
            ->addOption('symlink',     null, InputOption::VALUE_NONE, 'Run only symlink creation operations.')
            ->addOption('directory',   null, InputOption::VALUE_NONE, 'Run only directory creation operations.')
            ->addOption('show-output', null, InputOption::VALUE_NONE, 'Print detailed output for each operation.');
    }

    /**
     * Executes the filesystem command after extracting input arguments and options.
     *
     * This method is the main entry point for command execution. It extracts the
     * environment argument and all filter options from the input, stores them in
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

        $this->environment     = $input->getArgument('environment');
        $this->filterMirror    = $input->getOption('mirror');
        $this->filterSymlink   = $input->getOption('symlink');
        $this->filterDirectory = $input->getOption('directory');
        $this->showOutput      = $input->getOption('show-output');

        $io = new SymfonyStyle($input, $output);

        $io->title(sprintf('Filesystem Command <info>[%s] [%s]</info>', $this->getName(), $this->environment));
        $io->text([
            'This process handles the creation of directories, symbolic links,',
            'and directory mirrors required for deploying the application.',
        ]);
        $io->newLine();

        return $this->runValidationPipeline($io);
    }

    /**
     * Runs the validation pipeline before executing filesystem operations.
     *
     * This method orchestrates a sequence of validation checks using the null coalescing
     * operator (??) to short-circuit on the first failure. Each validation method
     * returns an exit code if it fails, or null if it passes. The pipeline ensures
     * that prerequisites are met before attempting any filesystem operations:
     *
     * 1. Environment validation - checks the environment name is valid
     * 2. Lock file validation - ensures the installation lock file exists
     * 3. Configuration validation - verifies filesystem configuration is present
     * 4. If all validations pass, proceeds to execute enabled operations
     *
     * This pattern provides early failure with clear error messages while keeping
     * the code concise and readable.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int Command exit code (SUCCESS if all validations pass and operations complete,
     *         FAILURE if any validation fails or operations encounter errors).
     */
    private function runValidationPipeline(SymfonyStyle $io): int {

        return $this->validateEnvironment($io)
            ?? $this->validateLockFile($io)
            ?? $this->validateConfiguration($io)
            ?? $this->runEnabledOperations($io);
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
     * Validates that the filesystem configuration exists and is properly structured.
     *
     * This method delegates to the ConfigurationValidator service to check that the
     * 'zyos_install.filesystem' configuration parameter exists and contains valid
     * filesystem operation definitions for the target environment. The validator
     * handles the actual validation logic and displays appropriate error messages
     * if the configuration is missing or invalid.
     *
     * If the validator returns null (indicating no configuration was found), the method
     * performs a fallback check using the ParameterBag to determine if the configuration
     * parameter exists at all. This handles edge cases where the validator might not
     * find configuration but it technically exists.
     *
     * The method returns null on success to allow the validation pipeline to proceed
     * to operation execution, or returns an exit code to halt execution.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int|null Returns null if validation passes and configuration is found,
     *         SUCCESS if the parameter exists but validator returned null, or FAILURE
     *         if the configuration is missing entirely.
     */
    private function validateConfiguration(SymfonyStyle $io): ?int {

        $parameters = $this->configurationValidator->validate('zyos_install.filesystem', $this->environment, $io);

        if ($parameters === null) {
            return $this->parameterBag->has('zyos_install.filesystem')
                ? Command::SUCCESS
                : Command::FAILURE;
        }

        return null;
    }

    /**
     * Retrieves enabled filesystem operations and dispatches them for execution.
     *
     * This method is the bridge between validation and actual operation execution.
     * It first retrieves all filesystem configuration parameters using the validator,
     * then filters them to include only operations that are enabled for the current
     * environment. The filtering is based on the 'environments' configuration in
     * each operation definition.
     *
     * If no operations are enabled for the environment, the method returns SUCCESS
     * immediately since there is nothing to do. Otherwise, it delegates to the
     * dispatch method which will apply any user-specified type filters and execute
     * the operations in priority order.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @return int Command exit code (SUCCESS if operations complete successfully or
     *         no operations are enabled, FAILURE if any operation fails).
     */
    private function runEnabledOperations(SymfonyStyle $io): int {

        $allParameters = $this->configurationValidator->validate('zyos_install.filesystem', $this->environment, $io);
        $enabled       = $this->configurationValidator->filterEnabled($allParameters, $this->environment, $io);

        if ($enabled === null) {
            return Command::SUCCESS;
        }

        return $this->dispatchByRequestedTypes($io, $enabled);
    }

    /**
     * Filters operations by user-requested types and executes them in priority order.
     *
     * This method applies the type filters specified by the user via command options
     * (--mirror, --symlink, --directory). If no filters are specified, all operation
     * types are included. The filtered operations are then sorted by their priority
     * value in ascending order to ensure dependencies are executed before dependents.
     *
     * If no operations match the requested type filters, a success message is displayed
     * and the command exits successfully. Otherwise, the operations are passed to the
     * CommandRunner service which groups them by priority and executes each group.
     * The CommandRunner handles error propagation and continuation logic based on
     * the configured error policy for each operation.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param ParameterBag $parameters The ParameterBag containing enabled filesystem operations.
     * @return int Command exit code (SUCCESS if all operations complete successfully,
     *         FAILURE if any operation fails with a 'stop' error policy).
     */
    private function dispatchByRequestedTypes(SymfonyStyle $io, ParameterBag $parameters): int {

        $requestedTypes = $this->resolveRequestedTypes();

        $filtered = $parameters->filter(
            fn(array $entry) => in_array($entry['type'], $requestedTypes, true)
        );

        if ($filtered->count() === 0) {
            $io->success(sprintf(
                'No operations to run for environment [%s] with type filter [%s].',
                $this->environment,
                implode(', ', $requestedTypes)
            ));
            return Command::SUCCESS;
        }

        $ordered = $filtered->orderByColumn('priority');

        return $this->commandRunner->run(
            $ordered,
            fn(array $group) => $this->executeOperationGroup($io, $group),
            $io
        );
    }

    /**
     * Executes a group of filesystem operations that share the same priority.
     *
     * This method is called by the CommandRunner service for each group of operations
     * that have the same priority value. Operations within a group are executed
     * sequentially in the order they appear in the configuration. The method tracks
     * the exit code of the last operation and returns it, allowing the CommandRunner
     * to determine whether to continue executing remaining groups or halt based on
     * the error policy.
     *
     * Grouping by priority allows operations to be executed in dependency order while
     * still allowing operations at the same priority level to be processed together.
     * The last exit code is returned so that if any operation in the group fails,
     * the failure propagates to the caller.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param array $group An array of operation configuration entries with the same priority.
     * @return int The exit code of the last executed operation (SUCCESS if all succeeded,
     *         FAILURE if any failed).
     */
    private function executeOperationGroup(SymfonyStyle $io, array $group): int {

        $lastExitCode = Command::SUCCESS;

        foreach ($group as $entry) {
            $lastExitCode = $this->executeFilesystemOperation($io, $entry);
        }

        return $lastExitCode;
    }

    /**
     * Executes a single filesystem operation with progress display and error handling.
     *
     * This method is the core operation executor that handles individual filesystem
     * operations. It extracts the operation configuration, validates that required
     * paths are present, displays a progress indicator, attempts to execute the
     * operation, and handles both success and failure cases appropriately.
     *
     * The method uses carriage return (\x0D) to overwrite the "Running" status line
     * with "Done" on success, creating a clean progress display. If the --show-output
     * option is enabled, detailed operation information is printed after successful
     * completion. On failure, the error is delegated to the failure handler which
     * determines the exit code based on the operation's error policy.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param array $entry The operation configuration array containing type, paths, priority,
     *        and error policy settings.
     * @return int Command exit code (SUCCESS if operation completes successfully,
     *         FAILURE/INVALID/other codes based on error policy if operation fails).
     * @throws InvalidArgumentException If required paths are missing for the operation type.
     */
    private function executeFilesystemOperation(SymfonyStyle $io, array $entry): int {

        $type        = mb_strtolower(trim($entry['type']));
        $source      = $this->resolvePath($entry['source']);
        $destination = $this->resolvePath($entry['destination']);
        $priority    = $entry['priority'];

        $this->assertRequiredPathsPresent($type, $source, $destination);

        $displayPath = $destination ?? $source;

        $io->write(sprintf(
            '  - Running <info>%s</info> [ <comment>Priority:</comment> %s ] [ %s ]',
            $this->formatTypeLabel($type),
            $priority,
            $displayPath
        ));

        try {
            $this->applyFilesystemOperation($type, $source, $destination);

            $io->write("\x0D");
            $io->writeln(sprintf(
                '  - Done    <info>%s</info> [ <comment>Priority:</comment> %s ] [ %s ]',
                $this->formatTypeLabel($type),
                $priority,
                $displayPath
            ));

            if ($this->showOutput) {
                $this->printOperationDetails($io, $entry);
            }

            return Command::SUCCESS;

        } catch (IOException $exception) {
            return $this->handleOperationFailure($io, $type, $priority, $displayPath, $entry['if_error'], $exception);
        }
    }

    /**
     * Applies the actual filesystem operation based on the operation type.
     *
     * This method uses PHP 8's match expression to dispatch to the appropriate
     * Filesystem component method based on the operation type constant. Each
     * operation type maps to a specific filesystem operation:
     *
     * - TYPE_DIRECTORY: Creates a directory using mkdir()
     * - TYPE_SYMLINK: Creates a symbolic link using symlink()
     * - TYPE_MIRROR: Mirrors a directory using mirror()
     *
     * If an unsupported operation type is provided (which should not happen after
     * validation), an InvalidArgumentException is thrown. This method is kept
     * separate from executeFilesystemOperation to maintain single responsibility
     * and make the code easier to test.
     *
     * @param string $type The operation type constant (TYPE_DIRECTORY, TYPE_SYMLINK, or TYPE_MIRROR).
     * @param string|null $source The source path (required for all types).
     * @param string|null $destination The destination path (required for symlink and mirror types).
     * @return void
     * @throws InvalidArgumentException If the operation type is not supported.
     * @throws IOException If the filesystem operation fails (propagated from Filesystem component).
     */
    private function applyFilesystemOperation(string $type, ?string $source, ?string $destination): void {

        match ($type) {
            self::TYPE_DIRECTORY => $this->filesystem->mkdir($source),
            self::TYPE_SYMLINK   => $this->filesystem->symlink($source, $destination),
            self::TYPE_MIRROR    => $this->filesystem->mirror($source, $destination),
            default              => throw new InvalidArgumentException(
                sprintf('Unsupported filesystem operation type: "%s".', $type)
            ),
        };
    }

    /**
     * Handles filesystem operation failures based on the configured error policy.
     *
     * This method is called when a filesystem operation throws an IOException.
     * It displays an error message to the user with the operation details and
     * the exception message, then determines the appropriate exit code based
     * on the operation's 'if_error' configuration policy:
     *
     * - 'none': Ignore the error and continue (return SUCCESS)
     * - 'stop': Halt execution immediately (return FAILURE)
     * - Any other value: Return INVALID to indicate an invalid configuration
     *
     * The error policy allows users to configure whether individual operation
     * failures should be treated as fatal errors or should be ignored, which
     * is useful for optional operations like creating cache directories that
     * may already exist.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param string $type The operation type that failed.
     * @param mixed $priority The priority value of the failed operation.
     * @param string $displayPath The path being displayed for the failed operation.
     * @param string $errorPolicy The error handling policy ('none', 'stop', or other).
     * @param IOException $exception The exception that was thrown during operation execution.
     * @return int Command exit code based on the error policy (SUCCESS, FAILURE, or INVALID).
     */
    private function handleOperationFailure(SymfonyStyle $io, string $type, mixed $priority, string $displayPath, string $errorPolicy, IOException $exception): int {

        $io->write("\x0D");
        $io->writeln(sprintf(
            '  - <fg=red;options=bold>Error</> <info>%s</info> [ <comment>Priority:</comment> %s ] [ %s ] %s',
            $this->formatTypeLabel($type),
            $priority,
            $displayPath,
            $exception->getMessage()
        ));

        return match ($errorPolicy) {
            'none'  => Command::SUCCESS,
            'stop'  => Command::FAILURE,
            default => Command::INVALID,
        };
    }

    /**
     * Prints detailed information about a successfully executed operation.
     *
     * This method is called after an operation completes successfully when the
     * --show-output option is enabled. It displays a formatted definition list
     * containing key information about the operation, including the operation type,
     * priority, enabled environments, and relevant paths.
     *
     * The output is conditionally formatted based on the operation type:
     * - Directory operations show only the path being created
     * - Symlink and mirror operations show both source and destination paths
     *
     * The method uses SymfonyStyle's definitionList() method for clean, aligned
     * output with color coding to improve readability. The paths are resolved
     * through the replacement service to show the actual paths after placeholder
     * substitution.
     *
     * @param SymfonyStyle $io The SymfonyStyle instance for formatted console output.
     * @param array $entry The operation configuration array containing all operation details.
     * @return void
     */
    private function printOperationDetails(SymfonyStyle $io, array $entry): void {

        $type         = mb_strtolower($entry['type']);
        $environments = implode(', ', $entry['environments']);

        $rows = [
            ['Operation type' => sprintf('<fg=white;options=bold>%s</>', ucfirst($type))],
            ['Priority'       => sprintf('<fg=white;options=bold>%s</>', $entry['priority'])],
            ['Environments'   => sprintf('<comment>[</comment><fg=green;options=bold>%s</><comment>]</comment>', $environments)],
        ];

        if ($type === self::TYPE_DIRECTORY) {
            $rows[] = ['Path' => sprintf('<fg=white;options=bold>%s</>', $this->resolvePath($entry['source']))];
        }

        if (in_array($type, self::TYPES_REQUIRING_DESTINATION, true)) {
            $rows[] = ['Source'      => sprintf('<fg=white;options=bold>%s</>', $this->resolvePath($entry['source']))];
            $rows[] = ['Destination' => sprintf('<fg=white;options=bold>%s</>', $this->resolvePath($entry['destination']))];
        }

        call_user_func_array([$io, 'definitionList'], $rows);
    }

    /**
     * Resolves the list of operation types to execute based on user filters.
     *
     * This method determines which filesystem operation types should be executed
     * based on the command-line filter options (--mirror, --symlink, --directory).
     * If no filters are specified (all filter flags are false), the method returns
     * all operation types, causing all configured operations to be executed.
     *
     * When one or more filters are active, the method builds an array containing
     * only the types corresponding to the active filters. The array_filter
     * function removes null values, resulting in a clean array of only the
     * requested types. This allows users to selectively execute only certain
     * types of operations without modifying the configuration.
     *
     * @return array<string> An array of operation type constants to execute.
     */
    private function resolveRequestedTypes(): array {

        if (!$this->filterMirror && !$this->filterSymlink && !$this->filterDirectory) {
            return self::ALL_TYPES;
        }

        return array_filter([
            $this->filterMirror    ? self::TYPE_MIRROR    : null,
            $this->filterSymlink   ? self::TYPE_SYMLINK   : null,
            $this->filterDirectory ? self::TYPE_DIRECTORY : null,
        ]);
    }

    /**
     * Resolves a path by replacing placeholders with environment-specific values.
     *
     * This method delegates to the Replacement service to substitute placeholders
     * in path strings with their actual values for the current environment. Common
     * placeholders include Symfony kernel parameters like %kernel.project_dir%,
     * %kernel.cache_dir%, and custom parameters defined in the application's
     * configuration.
     *
     * The method handles null values gracefully, returning null if the input path
     * is null. This is important because some operation types (like directory
     * creation) only require a source path, while others require both source
     * and destination paths.
     *
     * @param string|null $path The path string that may contain placeholders, or null.
     * @return string|null The resolved path with placeholders replaced, or null if input was null.
     */
    private function resolvePath(?string $path): ?string {
        return $path !== null ? $this->replacement->replace($path, $this->environment) : null;
    }

    /**
     * Formats an operation type for display in console output.
     *
     * This method creates a human-readable label for the operation type that
     * is displayed in the progress output. The label is prefixed with "Create "
     * and the type name is capitalized and padded to 9 characters to ensure
     * consistent alignment in the console output.
     *
     * The padding ensures that labels like "Create Directory", "Create Symlink",
     * and "Create Mirror" all have the same width, which keeps the priority
     * and path columns aligned when displayed in the console. This improves
     * readability and makes it easier to scan the output.
     *
     * @param string $type The operation type constant (TYPE_DIRECTORY, TYPE_SYMLINK, or TYPE_MIRROR).
     * @return string A formatted label string padded to 9 characters (e.g., "Create Directory").
     */
    private function formatTypeLabel(string $type): string {
        return sprintf('Create %s', str_pad(ucfirst($type), 9));
    }

    /**
     * Asserts that required paths are present for the given operation type.
     *
     * This method validates that the configuration contains all required paths
     * for the specified operation type. Directory creation only requires a source
     * path (the directory to create), but symbolic link and mirror operations
     * require both a source path (the target) and a destination path (where the
     * link or mirrored content will be placed).
     *
     * If a required path is missing for an operation type that requires it,
     * an InvalidArgumentException is thrown with a clear error message. This
     * validation happens before any filesystem operations are attempted,
     * preventing confusing errors from the Filesystem component and providing
     * immediate feedback about configuration issues.
     *
     * @param string $type The operation type constant being validated.
     * @param string|null $source The source path from the configuration.
     * @param string|null $destination The destination path from the configuration.
     * @return void
     * @throws InvalidArgumentException If the operation type requires both source and destination
     *         but one or both are null.
     */
    private function assertRequiredPathsPresent(string $type, ?string $source, ?string $destination): void {

        if (in_array($type, self::TYPES_REQUIRING_DESTINATION, true) && ($source === null || $destination === null)) {
            throw new InvalidArgumentException(sprintf(
                'Both "source" and "destination" are required for operation type "%s".',
                $type
            ));
        }
    }
}
