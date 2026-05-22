<?php

namespace Zyos\InstallBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Zyos\InstallBundle\Replacement;
use Zyos\InstallBundle\Service\ConfigurationValidator;
use Zyos\InstallBundle\Service\EnvironmentValidator;
use Zyos\InstallBundle\ValidatorsHandler;

/**
 * ValidateCommand
 *
 * Symfony Console command responsible for validating configured files, directories, and paths
 * for a specific deployment environment. This command executes a comprehensive validation pipeline
 * that checks environment configuration, bundle configuration, and runs configured validators
 * against declared filesystem paths.
 *
 * The command provides detailed visual feedback through formatted tables showing validation results,
 * file permissions, modification timestamps, and individual validator outcomes. It supports
 * filtering to display only failed validations when needed, making it suitable for both
 * comprehensive audits and focused troubleshooting.
 *
 * Responsibilities:
 * - Validate that the specified environment exists and is properly configured
 * - Validate that the bundle configuration key 'zyos_install.validate' is present and valid
 * - Filter validation entries based on environment-specific enablement
 * - Execute configured validators against each declared path
 * - Resolve and display file metadata (permissions, modification time)
 * - Generate comprehensive validation reports with color-coded status indicators
 * - Provide summary statistics and exit with appropriate status codes
 *
 * Usage:
 *   php bin/console zyos:validate dev
 *   php bin/console zyos:validate prod --only-errors
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Command
 */
class ValidateCommand extends Command {

    /**
     * Mapping of file type bitmasks to their symbolic representation characters.
     *
     * This constant defines the correspondence between the high-order bits of file permissions
     * (returned by fileperms()) and the single-character symbols used in Unix-style permission
     * strings (e.g., 'd' for directory, '-' for regular file, 'l' for symbolic link).
     *
     * The bitmask values correspond to the file type portion of the st_mode field in the
     * stat structure, where the high 4 bits (0xF000) indicate the file type.
     *
     * Values:
     * - 0xC000 (socket): 's' - Unix domain socket
     * - 0xA000 (symbolic link): 'l' - Symbolic link to another file
     * - 0x8000 (regular file): '-' - Regular file (most common type)
     * - 0x6000 (block device): 'b' - Block special device (e.g., /dev/sda)
     * - 0x4000 (directory): 'd' - Directory
     * - 0x2000 (character device): 'c' - Character special device (e.g., /dev/tty)
     * - 0x1000 (named pipe): 'p' - Named pipe (FIFO)
     *
     * Used in: buildPermissionString() to determine the first character of permission strings.
     *
     * @var array<int, string>
     */
    private const array PERMISSION_TYPE_MAP = [
        0xC000 => 's',
        0xA000 => 'l',
        0x8000 => '-',
        0x6000 => 'b',
        0x4000 => 'd',
        0x2000 => 'c',
        0x1000 => 'p'
    ];

    /**
     * Character used to represent unknown or unrecognized file types in permission strings.
     *
     * When a file's type bitmask does not match any known type in PERMISSION_TYPE_MAP,
     * this character is used as the first character of the permission string to indicate
     * an unknown file type. This provides a clear visual indicator that the file type
     * could not be determined from the permissions bitmask.
     *
     * Used in: buildPermissionString() as fallback when type lookup fails.
     *
     * @var string
     */
    private const string PERMISSION_TYPE_UNKNOWN = 'u';

    /**
     * Result identifier for entries that passed all validations successfully.
     *
     * This constant is used to mark validation entries where all configured validators
     * executed without errors and the file/directory exists and is accessible.
     * Entries with this result are displayed with a green "PASSED" badge and success icon.
     *
     * Used in: entryResults array to track validation outcomes, renderEntryTable() for badge selection.
     *
     * @var string
     */
    private const string RESULT_PASSED = 'passed';

    /**
     * Result identifier for entries that failed one or more validations.
     *
     * This constant marks validation entries where at least one configured validator
     * returned a failure result, or where a required validator could not be found.
     * Entries with this result are displayed with a red "FAILED" badge and failure icon,
     * and are included in the final summary of paths requiring attention.
     *
     * Used in: entryResults array, registerResult(), renderEntryTable(), reportFinalSummary().
     *
     * @var string
     */
    private const string RESULT_FAILED = 'failed';

    /**
     * Result identifier for entries where the target file or directory does not exist.
     *
     * This constant marks validation entries where the resolved filepath does not exist
     * in the filesystem. This is distinct from validation failures - the file itself
     * is not available for validation. Entries with this result are displayed with a
     * yellow "NOT AVAILABLE" badge and warning icon.
     *
     * Used in: processValidationEntry(), renderEntryTable(), reportFinalSummary().
     *
     * @var string
     */
    private const string RESULT_NOT_AVAILABLE = 'not_available';

    /**
     * Console-formatted icon representing a successful validation outcome.
     *
     * This constant provides a green checkmark symbol (✔) with foreground color formatting
     * for use in console output. It is displayed next to validator names that passed their
     * validation checks, providing immediate visual feedback of successful results.
     *
     * Used in: runSingleValidator() to indicate successful validator execution.
     *
     * @var string
     */
    private const string ICON_SUCCESS = '<fg=green>✔</>';

    /**
     * Console-formatted icon representing a failed validation outcome.
     *
     * This constant provides a red cross symbol (✘) with foreground color formatting
     * for use in console output. It is displayed next to validator names that failed their
     * validation checks, providing immediate visual feedback of failure results.
     *
     * Used in: runSingleValidator() to indicate failed validator execution.
     *
     * @var string
     */
    private const string ICON_FAILURE = '<fg=red>✘</>';

    /**
     * Console-formatted icon representing a warning or unknown state.
     *
     * This constant provides a yellow warning symbol (⚠) with foreground color formatting
     * for use in console output. It is displayed when a validator name is not registered
     * in the ValidatorsHandler, indicating that the requested validator could not be found.
     *
     * Used in: runSingleValidator() to indicate unknown validator.
     *
     * @var string
     */
    private const string ICON_WARNING = '<fg=yellow>⚠</>';

    /**
     * Console-formatted status label for successful validator execution.
     *
     * This constant provides a bold green "SUCCESS" label with foreground color formatting
     * for use in validation result tables. It is displayed in the status column when a
     * validator passes its validation checks, providing clear visual confirmation of success.
     *
     * Used in: runSingleValidator() to display validator success status.
     *
     * @var string
     */
    private const string STATUS_SUCCESS = '<fg=green;options=bold>SUCCESS</>';

    /**
     * Console-formatted status label for failed validator execution.
     *
     * This constant provides a bold red "FAILED" label with foreground color formatting
     * for use in validation result tables. It is displayed in the status column when a
     * validator fails its validation checks, providing clear visual indication of failure.
     *
     * Used in: runSingleValidator() to display validator failure status.
     *
     * @var string
     */
    private const string STATUS_FAILED = '<fg=red;options=bold>FAILED</>';

    /**
     * Console-formatted status label for unknown validators.
     *
     * This constant provides a yellow "validator not found" label with foreground color
     * formatting for use in validation result tables. It is displayed when a validator
     * name specified in the configuration is not registered in the ValidatorsHandler,
     * indicating a configuration error or missing validator implementation.
     *
     * Used in: runSingleValidator() to display unknown validator status.
     *
     * @var string
     */
    private const string STATUS_UNKNOWN_VALIDATOR = '<fg=yellow>validator not found</>';

    /**
     * Console-formatted badge for passed validation entries.
     *
     * This constant provides a green background badge with black text displaying "PASSED",
     * used in the header of validation entry tables to indicate that all validations for
     * that entry succeeded. The badge provides a prominent visual indicator of the overall
     * entry status.
     *
     * Used in: renderEntryTable() to display entry-level success badge.
     *
     * @var string
     */
    private const string BADGE_PASSED = '<fg=black;bg=green> PASSED </>';

    /**
     * Console-formatted badge for failed validation entries.
     *
     * This constant provides a red background badge with white text displaying "FAILED",
     * used in the header of validation entry tables to indicate that one or more validations
     * for that entry failed. The badge provides a prominent visual indicator of the overall
     * entry status.
     *
     * Used in: renderEntryTable() to display entry-level failure badge.
     *
     * @var string
     */
    private const string BADGE_FAILED = '<fg=white;bg=red> FAILED </>';

    /**
     * Console-formatted badge for unavailable validation entries.
     *
     * This constant provides a yellow background badge with black text displaying "NOT AVAILABLE",
     * used in the header of validation entry tables to indicate that the target file or directory
     * does not exist. The badge provides a prominent visual indicator that the entry could not
     * be validated due to absence.
     *
     * Used in: renderEntryTable() to display entry-level unavailability badge.
     *
     * @var string
     */
    private const string BADGE_UNAVAILABLE = '<fg=black;bg=yellow> NOT AVAILABLE </>';

    /**
     * Console formatting template for displaying metadata values.
     *
     * This constant provides a printf-style format string with white foreground color
     * for displaying metadata values in validation tables. The %s placeholder is replaced
     * with the actual value when used with sprintf(). This ensures consistent visual
     * formatting for all metadata fields.
     *
     * Used in: renderEntryTable() for type, permissions, and other metadata fields.
     *
     * @var string
     */
    private const string META_VALUE = '<fg=white>%s</>';

    /**
     * Console-formatted label for unavailable metadata values.
     *
     * This constant provides a red "NOT AVAILABLE" label for use when metadata cannot
     * be retrieved, typically because the target file does not exist or is inaccessible.
     * This provides a clear visual indication that the requested information is not
     * available for display.
     *
     * Used in: resolveLastModified(), unavailablePermissions() for missing metadata.
     *
     * @var string
     */
    private const string META_NA = '<fg=red>NOT AVAILABLE</>';

    /**
     * The target deployment environment for validation.
     *
     * This property stores the environment name (e.g., 'dev', 'prod', 'staging') specified
     * as a command argument. It is used throughout the validation pipeline to filter
     * configuration entries, resolve environment-specific parameters, and determine which
     * validators should be executed. The environment is validated at the start of execution
     * to ensure it exists in the application configuration.
     *
     * Set in: execute() method from the input argument.
     * Used in: All methods requiring environment context for filtering and parameter resolution.
     *
     * @var string
     */
    private string $environment;

    /**
     * Flag indicating whether to display only failed validation entries.
     *
     * When set to true, this property causes the command to suppress display of entries
     * that passed all validations, showing only entries with failures or unavailability.
     * This is useful for focused troubleshooting and reducing output noise when only
     * problems need attention. The flag is controlled by the --only-errors command option.
     *
     * Set in: execute() method from the --only-errors input option.
     * Used in: processValidationEntry() to determine whether to skip passed entries.
     *
     * @var bool
     */
    private bool   $onlyErrors;

    /**
     * Accumulator array storing validation results for each processed entry.
     *
     * This property maps resolved filepaths to their validation result status (RESULT_PASSED,
     * RESULT_FAILED, or RESULT_NOT_AVAILABLE). The array is populated as each validation entry
     * is processed and is used to generate the final summary report. Results are registered
     * using the registerResult() method, which ensures that failed results override passed
     * results for the same path.
     *
     * Initialized in: execute() method (reset on each run).
     * Updated in: registerResult() method.
     * Used in: processValidationEntry() to check existing results, reportFinalSummary() for statistics.
     *
     * @var array<string, string>
     */
    private array $entryResults = [];

    /**
     * Constructor for ValidateCommand.
     *
     * Initializes the command with all required dependencies through constructor property promotion.
     * These dependencies provide access to Symfony's parameter bag, filesystem operations,
     * validator management, parameter replacement, and validation services for environment
     * and configuration validation.
     *
     * The constructor accepts readonly properties to ensure immutability after injection,
     * following modern PHP best practices for dependency injection in Symfony commands.
     *
     * @param ParameterBagInterface $parameterBag Symfony's parameter bag for accessing application parameters
     * @param Filesystem $filesystem Symfony's filesystem component for file operations
     * @param ValidatorsHandler $validatorsHandler Handler for managing and executing configured validators
     * @param Replacement $replacement Service for replacing environment-specific placeholders in paths and parameters
     * @param EnvironmentValidator $environmentValidator Service for validating the specified environment exists
     * @param ConfigurationValidator $configurationValidator Service for validating bundle configuration entries
     */
    public function __construct(
        private readonly ParameterBagInterface  $parameterBag,
        private readonly Filesystem             $filesystem,
        private readonly ValidatorsHandler      $validatorsHandler,
        private readonly Replacement            $replacement,
        private readonly EnvironmentValidator   $environmentValidator,
        private readonly ConfigurationValidator $configurationValidator,
    ) {
        parent::__construct();
    }

    /**
     * Configures the command definition, arguments, and options.
     *
     * This method sets up the command's name, description, and input parameters as required
     * by Symfony's Console component. The configuration defines:
     *
     * - Command name: 'zyos:validate' - the CLI command used to invoke this validation
     * - Description: Explains the command's purpose of validating files, directories, and paths
     * - Required argument 'environment': Specifies the target deployment environment (e.g., 'dev', 'prod')
     * - Optional flag 'only-errors': When set, suppresses display of passed entries, showing only failures
     *
     * The environment argument is required because validation rules and enabled entries are
     * environment-specific. The --only-errors option is useful for focused troubleshooting
     * in production environments where only failures need attention.
     *
     * @return void
     */
    protected function configure(): void {

        $this
            ->setName('zyos:validate')
            ->setDescription('Validates configured files, directories, and paths for a deployment environment.')
            ->addArgument('environment', InputArgument::REQUIRED, 'Target deployment environment (e.g. dev, prod).')
            ->addOption('only-errors', null, InputOption::VALUE_NONE, 'Only display entries with failed validations.');
    }

    /**
     * Executes the validation command with the provided input and output interfaces.
     *
     * This is the main entry point for command execution. It performs the following operations:
     *
     * 1. Extracts the required 'environment' argument from input and stores it in $this->environment
     * 2. Extracts the optional 'only-errors' flag from input and stores it in $this->onlyErrors
     * 3. Resets the $this->entryResults accumulator to ensure clean state on each execution
     * 4. Creates a SymfonyStyle instance for enhanced console output formatting
     * 5. Displays a formatted title showing the command name and target environment
     * 6. Displays usage information about the command's purpose and options
     * 7. Delegates to runValidationPipeline() to execute the actual validation logic
     *
     * The method returns Command::SUCCESS or Command::FAILURE based on the validation pipeline's
     * outcome, which determines the exit code of the CLI command.
     *
     * @param InputInterface $input Symfony's input interface providing access to command arguments and options
     * @param OutputInterface $output Symfony's output interface for writing console output
     * @return int Command::SUCCESS if all validations pass, Command::FAILURE if any validation fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {

        $this->environment  = $input->getArgument('environment');
        $this->onlyErrors   = $input->getOption('only-errors');
        $this->entryResults = []; // reset accumulator on each run

        $io = new SymfonyStyle($input, $output);

        $io->title(sprintf('Validate Command <info>[%s] [%s]</info>', $this->getName(), $this->environment));
        $io->text([
            'Runs configured validators against declared paths and reports results.',
            'Use <comment>--only-errors</comment> to suppress passing entries and focus on failures.',
        ]);
        $io->newLine();

        return $this->runValidationPipeline($io, $output);
    }

    /**
     * Orchestrates the sequential execution of the validation pipeline stages.
     *
     * This method implements a short-circuit pipeline pattern where each stage can return
     * an exit code to halt execution, or null to continue to the next stage. The pipeline
     * executes in the following order:
     *
     * 1. validateEnvironment(): Verifies the specified environment exists in configuration
     *    - Returns Command::FAILURE if environment is invalid, null otherwise
     * 2. validateConfiguration(): Verifies the bundle configuration key exists and is valid
     *    - Returns Command::FAILURE if configuration is missing/invalid, null otherwise
     * 3. runEnabledEntries(): Executes validators against all enabled configuration entries
     *    - Always returns an exit code (SUCCESS or FAILURE)
     *
     * The null coalescing operator (??) ensures that if any stage returns a non-null value
     * (indicating an error), that value is immediately returned without executing subsequent
     * stages. This prevents unnecessary processing when earlier validations fail.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for formatted console output
     * @param OutputInterface $output Output interface for table rendering
     * @return int Command::SUCCESS if all validations pass, Command::FAILURE if any stage fails
     */
    private function runValidationPipeline(SymfonyStyle $io, OutputInterface $output): int {

        return $this->validateEnvironment($io)
            ?? $this->validateConfiguration($io)
            ?? $this->runEnabledEntries($io, $output);
    }

    /**
     * Validates that the specified environment exists in the application configuration.
     *
     * This method delegates to the EnvironmentValidator service to check whether the
     * environment name provided by the user is a valid, configured environment in the
     * Symfony application. This is a critical first step to prevent processing invalid
     * or non-existent environments.
     *
     * The EnvironmentValidator handles the actual validation logic and displays appropriate
     * error messages through the SymfonyStyle instance if the environment is invalid.
     *
     * Return behavior:
     * - Returns the validation result if it's not SUCCESS (i.e., FAILURE)
     * - Returns null if validation succeeds, allowing the pipeline to continue
     *
     * This null-return pattern enables the short-circuit behavior in runValidationPipeline().
     *
     * @param SymfonyStyle $io SymfonyStyle instance for displaying validation messages
     * @return int|null Command::FAILURE if environment is invalid, null if valid
     */
    private function validateEnvironment(SymfonyStyle $io): ?int {

        $result = $this->environmentValidator->validate($this->environment, $io);
        return $result !== Command::SUCCESS ? $result : null;
    }

    /**
     * Validates that the bundle configuration key exists and contains valid validation entries.
     *
     * This method checks for the presence and validity of the 'zyos_install.validate' configuration
     * key, which defines the files, directories, and paths to be validated along with their
     * associated validators. The validation is performed by the ConfigurationValidator service.
     *
     * The method handles two scenarios:
     *
     * 1. Configuration key exists but returns null from validator:
     *    - This indicates the key exists but may be empty or have no enabled entries
     *    - Returns Command::SUCCESS to allow the pipeline to continue (empty config is valid)
     *
     * 2. Configuration key does not exist at all:
     *    - This indicates a missing or misconfigured bundle
     *    - Displays an error message directing the user to check bundle configuration
     *    - Returns Command::FAILURE to halt the pipeline
     *
     * The distinction between these cases is important: an empty configuration is valid
     * (nothing to validate), but a missing configuration key indicates a setup error.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for displaying validation messages
     * @return int|null Command::FAILURE if configuration key is missing, null if valid
     */
    private function validateConfiguration(SymfonyStyle $io): ?int {

        $parameters = $this->configurationValidator->validate('zyos_install.validate', $this->environment, $io);

        if ($parameters === null) {
            if ($this->parameterBag->has('zyos_install.validate')) {
                return Command::SUCCESS;
            }

            $io->error('Configuration key "zyos_install.validate" is missing. Check your bundle configuration.');
            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Executes validators against all configuration entries enabled for the target environment.
     *
     * This method is the core validation execution stage. It retrieves all validation entries
     * from the bundle configuration, filters them to include only those enabled for the current
     * environment, and processes each entry through the validation pipeline.
     *
     * Processing flow:
     * 1. Retrieves all validation parameters from 'zyos_install.validate' configuration
     * 2. Filters parameters to include only entries enabled for the target environment
     * 3. If no entries are enabled, displays a success message and returns SUCCESS
     * 4. Displays the count of entries to process and the target environment
     * 5. Iterates through each enabled entry, calling processValidationEntry() for each
     * 6. Generates and displays a final summary report with statistics
     *
     * The method uses an index counter to display progress (e.g., "entry 3/10") for each
     * processed entry, providing visual feedback on validation progress.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for formatted console output
     * @param OutputInterface $output Output interface for table rendering
     * @return int Command::SUCCESS if all validations pass, Command::FAILURE if any fail
     */
    private function runEnabledEntries(SymfonyStyle $io, OutputInterface $output): int {

        $allParameters = $this->configurationValidator->validate('zyos_install.validate', $this->environment, $io);
        $enabled       = $this->configurationValidator->filterEnabled($allParameters, $this->environment, $io);

        if ($enabled === null) {
            $io->success(sprintf('No validation entries enabled for environment [%s].', $this->environment));
            return Command::SUCCESS;
        }

        $total = $enabled->count();

        $io->text(sprintf(
            '<comment>Entries to process:</comment> %d  <comment>|</comment>  <comment>Environment:</comment> %s',
            $total,
            $this->environment
        ));
        $io->newLine();

        $index = 1;
        foreach ($enabled as $entry) {
            $this->processValidationEntry($output, $entry, $index, $total);
            $index++;
        }

        return $this->reportFinalSummary($io);
    }

    /**
     * Processes a single validation entry by resolving paths, running validators, and rendering results.
     *
     * This method handles the complete validation workflow for a single configuration entry:
     *
     * 1. Resolves the filepath by replacing environment-specific placeholders (e.g., %kernel.project_dir%)
     * 2. Resolves all entry parameters by replacing environment-specific placeholders in the entry array
     * 3. Executes all configured validators against the resolved path, populating $this->entryResults
     * 4. Determines the overall result state for the entry (passed, failed, or not available)
     * 5. Checks if the file/directory exists; if not, marks as RESULT_NOT_AVAILABLE
     * 6. If --only-errors flag is set and the entry passed, skips rendering (suppresses clean output)
     * 7. Renders a detailed table showing the entry's metadata and validation results
     *
     * The method respects the --only-errors flag to reduce output noise in production scenarios,
     * displaying only entries that require attention.
     *
     * @param OutputInterface $output Output interface for rendering the validation table
     * @param array $entry The configuration entry array containing filepath, type, environments, and validations
     * @param int $index The 1-based index of this entry in the processing sequence (for progress display)
     * @param int $total The total number of entries being processed (for progress display)
     * @return void
     */
    private function processValidationEntry(OutputInterface $output, array $entry, int $index, int $total): void {

        $resolvedPath  = $this->resolvePath($entry['filepath']);
        $resolvedEntry = $this->replacement->arrayReplace($entry, $this->environment);

        $validatorRows = $this->runValidators($resolvedPath, $resolvedEntry);
        $result = $this->entryResults[$resolvedPath] ?? self::RESULT_PASSED;

        if (!$this->filesystem->exists($resolvedPath)) {
            $result = self::RESULT_NOT_AVAILABLE;
        }

        if ($this->onlyErrors && $result === self::RESULT_PASSED) {
            return;
        }

        $this->renderEntryTable($output, $resolvedPath, $resolvedEntry, $validatorRows, $result, $index, $total);
    }

    /**
     * Renders a formatted table displaying validation results and metadata for a single entry.
     *
     * This method creates a visually rich console table using Symfony's Table component to display
     * comprehensive information about a validation entry. The table includes:
     *
     * - Header row showing entry index (e.g., "entry 3/10") and status badge (PASSED/FAILED/NOT AVAILABLE)
     * - The resolved filepath prominently displayed
     * - File metadata: type, last modified timestamp, permissions (octal and string format)
     * - Configured environments for this entry
     * - Individual validator results with icons and status labels
     *
     * Visual styling:
     * - Badge color: Green for passed, red for failed, yellow for unavailable
     * - Header color: Cyan for passed, red for failed, yellow for unavailable
     * - Box style border for clear visual separation
     * - Centered alignment for status badges and values
     *
     * The table provides immediate visual feedback through color coding, making it easy to
     * identify problems at a glance during validation audits.
     *
     * @param OutputInterface $output Output interface for rendering the table
     * @param string $resolvedPath The fully resolved filepath after placeholder replacement
     * @param array $resolvedEntry The entry array with all placeholders replaced
     * @param array $validatorRows Array of validator result rows with icon, name, and status
     * @param string $result The overall result state (RESULT_PASSED, RESULT_FAILED, or RESULT_NOT_AVAILABLE)
     * @param int $index The 1-based index of this entry in the processing sequence
     * @param int $total The total number of entries being processed
     * @return void
     */
    private function renderEntryTable(
        OutputInterface $output,
        string          $resolvedPath,
        array           $resolvedEntry,
        array           $validatorRows,
        string          $result,
        int             $index,
        int             $total
    ): void {

        $permissions  = $this->resolvePermissions($resolvedPath);
        $lastModified = $this->resolveLastModified($resolvedPath);

        $badge = match ($result) {
            self::RESULT_FAILED        => self::BADGE_FAILED,
            self::RESULT_NOT_AVAILABLE => self::BADGE_UNAVAILABLE,
            default                    => self::BADGE_PASSED,
        };

        $headerColor = match ($result) {
            self::RESULT_FAILED        => 'fg=red',
            self::RESULT_NOT_AVAILABLE => 'fg=yellow',
            default                    => 'fg=cyan',
        };

        $table = new Table($output);
        $table->setStyle('box');

        $table->addRow([
            new TableCell(
                sprintf('<%s;options=bold>entry %d/%d</>', $headerColor, $index, $total)
            ),
            new TableCell($badge, [
                'style' => new TableCellStyle(['align' => 'center']),
            ]),
        ]);

        $table->addRow(new TableSeparator());

        $table->addRow([
            new TableCell(
                sprintf('<fg=yellow;options=bold>%s</>', $resolvedPath),
                ['colspan' => 2, 'style' => new TableCellStyle(['align' => 'center'])]
            )
        ]);

        $table->addRow(new TableSeparator());

        $table->addRow([
            '<fg=gray>Type:</>',
            new TableCell(sprintf(self::META_VALUE, $resolvedEntry['type']), [
                'style' => new TableCellStyle(['align' => 'center']),
            ])
        ]);

        $table->addRow(['<fg=gray>Last modified:</>', $lastModified]);
        $table->addRow(['<fg=gray>Permissions (octal):</>', sprintf(self::META_VALUE, $permissions['int'])]);
        $table->addRow(['<fg=gray>Permissions (string):</>', sprintf(self::META_VALUE, $permissions['string'])]);
        $table->addRow(['<fg=gray>Environments:</>', sprintf('<fg=cyan>%s</>', implode(', ', $resolvedEntry['environments']))]);
        $table->addRow(new TableSeparator());
        $table->addRow([
            new TableCell('<fg=green;options=bold>Validations</>', ['colspan' => 2])
        ]);
        $table->addRow(new TableSeparator());

        foreach ($validatorRows as $row) {
            $table->addRow([
                sprintf('%s %s', $row['icon'], $row['name']),
                new TableCell($row['status'], [
                    'style' => new TableCellStyle(['align' => 'center']),
                ]),
            ]);
        }
        $table->addRow(new TableSeparator());
        $table->render();

        $output->writeln('');
        $output->writeln('');
    }

    /**
     * Executes all configured validators for a single validation entry.
     *
     * This method iterates through the 'validations' array in the entry configuration and
     * executes each validator by calling runSingleValidator(). The results are collected
     * into an array of rows suitable for display in the validation table.
     *
     * The method uses array_map() to transform each validator definition into a result row,
     * maintaining the order of validators as configured. Each result row contains:
     * - 'icon': The visual icon indicating success, failure, or warning
     * - 'name': The validator's display name (from getTitle() method or validator name)
     * - 'status': The formatted status label (SUCCESS, FAILED, or validator not found)
     *
     * Side effects:
     * - Populates $this->entryResults with validation outcomes via registerResult()
     * - Failed results override previous passed results for the same path
     *
     * @param string $resolvedPath The fully resolved filepath to validate
     * @param array $entry The complete entry configuration array
     * @return array<int, array{icon: string, name: string, status: string}> Array of validator result rows
     */
    private function runValidators(string $resolvedPath, array $entry): array {

        return array_map(
            fn(array $validatorDef) => $this->runSingleValidator($resolvedPath, $entry, $validatorDef),
            $entry['validations']
        );
    }

    /**
     * Executes a single validator against a resolved path and returns the result row.
     *
     * This method handles the execution of an individual validator, including parameter
     * resolution, validator instantiation, validation execution, and result formatting.
     *
     * Execution flow:
     * 1. Resolves environment-specific placeholders in the validator definition
     * 2. Extracts the validator name from the resolved definition
     * 3. Checks if the validator is registered in the ValidatorsHandler
     *    - If not found: Registers a failed result and returns a warning row
     * 4. Retrieves the validator instance from the handler, passing the entry context
     * 5. Determines the display title (uses getTitle() method if available, falls back to name)
     * 6. Executes the validator's validate() method with resolved parameters and entry context
     * 7. Registers the result (failed if validation failed)
     * 8. Returns a result row with appropriate icon, name, and status
     *
     * The method handles both successful and failed validations, as well as the edge case
     * of unknown validators (configuration errors where a validator name doesn't exist).
     *
     * @param string $resolvedPath The fully resolved filepath to validate
     * @param array $entry The complete entry configuration array for context
     * @param array $validatorDef The validator definition array containing name and parameters
     * @return array{icon: string, name: string, status: string} Result row with icon, name, and status
     */
    private function runSingleValidator(string $resolvedPath, array $entry, array $validatorDef): array {

        $resolvedDef   = $this->replacement->arrayReplace($validatorDef, $this->environment);
        $validatorName = $resolvedDef['name'];

        if (!$this->validatorsHandler->has($validatorName)) {
            $this->registerResult($resolvedPath);
            return [
                'icon'   => self::ICON_WARNING,
                'name'   => $validatorName,
                'status' => self::STATUS_UNKNOWN_VALIDATOR,
            ];
        }

        $validatorInstance = $this->validatorsHandler->get($validatorName, $entry);
        $displayTitle      = method_exists($validatorInstance, 'getTitle')
            ? $validatorInstance->getTitle()
            : $validatorName;

        $passed = $validatorInstance->validate($resolvedDef['parameters'], $entry);

        if (!$passed) {
            $this->registerResult($resolvedPath);
        }

        return [
            'icon'   => $passed ? self::ICON_SUCCESS : self::ICON_FAILURE,
            'name'   => $displayTitle,
            'status' => $passed ? self::STATUS_SUCCESS : self::STATUS_FAILED,
        ];
    }

    /**
     * Registers a validation result for a specific filepath in the results accumulator.
     *
     * This method updates the $this->entryResults array with the validation outcome for a
     * given resolved path. The registration follows a "failure takes precedence" policy:
     *
     * - If no result exists for the path, the new result is registered
     * - If a result exists and the new result is RESULT_FAILED, it overrides the existing result
     * - If a result exists and the new result is not RESULT_FAILED, the existing result is preserved
     *
     * This policy ensures that a single failed validator marks the entire entry as failed,
     * even if other validators for the same entry pass. This is the correct behavior for
     * validation systems where all validators must pass for the entry to be considered valid.
     *
     * The method is called by runSingleValidator() whenever a validator completes execution.
     *
     * @param string $resolvedPath The fully resolved filepath to register the result for
     * @return void
     */
    private function registerResult(string $resolvedPath): void {

        if (!isset($this->entryResults[$resolvedPath])) {
            $this->entryResults[$resolvedPath] = self::RESULT_FAILED;
        }
    }

    /**
     * Generates and displays a final summary report of all validation results.
     *
     * This method is called after all validation entries have been processed to provide
     * a comprehensive overview of the validation outcomes. The summary includes:
     *
     * 1. Statistics showing counts of passed, failed, and unavailable entries
     * 2. A detailed list of all paths that require attention (failed or unavailable)
     * 3. Color-coded icons for visual identification of problem paths
     * 4. A final success or error message with the overall exit code
     *
     * The summary uses a divider line for visual separation and displays paths requiring
     * attention only when there are failures or unavailable entries. This keeps the output
     * concise when all validations pass.
     *
     * Exit code logic:
     * - Returns Command::FAILURE if any entries failed or are unavailable
     * - Returns Command::SUCCESS if all entries passed validation
     *
     * This exit code determines the command's overall success status, which can be used
     * in CI/CD pipelines or deployment scripts to halt deployments when validations fail.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for formatted console output
     * @return int Command::SUCCESS if all validations pass, Command::FAILURE if any fail
     */
    private function reportFinalSummary(SymfonyStyle $io): int {

        $passed       = array_keys(array_filter($this->entryResults, fn($r) => $r === self::RESULT_PASSED));
        $failed       = array_keys(array_filter($this->entryResults, fn($r) => $r === self::RESULT_FAILED));
        $notAvailable = array_keys(array_filter($this->entryResults, fn($r) => $r === self::RESULT_NOT_AVAILABLE));

        $hasFailures = count($failed) > 0 || count($notAvailable) > 0;

        $divider = str_repeat('─', 52);

        $io->writeln('<options=bold>Validation summary</>');
        $io->writeln($divider);

        $io->writeln(sprintf('  %s  Passed            <fg=green;options=bold>%d</>', self::ICON_SUCCESS, count($passed)));
        $io->writeln(sprintf('  %s  Failed            <fg=red;options=bold>%d</>',   self::ICON_FAILURE, count($failed)));
        $io->writeln(sprintf('  %s  Not available     <fg=yellow;options=bold>%d</>', self::ICON_WARNING, count($notAvailable)));

        if ($hasFailures) {
            $io->writeln($divider);
            $io->writeln('  <fg=red>Paths requiring attention:</>');

            foreach ($failed as $path) {
                $io->writeln(sprintf('    %s  %s', self::ICON_FAILURE, $path));
            }

            foreach ($notAvailable as $path) {
                $io->writeln(sprintf('    %s  %s', self::ICON_WARNING, $path));
            }
        }

        $io->writeln($divider);
        $io->newLine();

        if ($hasFailures) {
            $io->error(sprintf('%d path(s) require attention.', count($failed) + count($notAvailable)));
            return Command::FAILURE;
        }

        $io->success('All validations passed.');
        return Command::SUCCESS;
    }

    /**
     * Resolves and formats the last modification timestamp for a given filepath.
     *
     * This method retrieves the last modification time of a file or directory and formats
     * it as a human-readable date string. The method handles error cases gracefully:
     *
     * - If the file does not exist, returns META_NA ("NOT AVAILABLE")
     * - If filemtime() returns false (e.g., permission error), returns META_NA
     * - Otherwise, formats the timestamp as "Y-m-d H:i:s" with white color formatting
     *
     * The formatted timestamp is displayed in the validation table to provide context
     * about when the file was last modified, which can be useful for identifying stale
     * or recently changed files during validation audits.
     *
     * @param string $filepath The absolute or relative path to the file or directory
     * @return string The formatted last modified timestamp, or META_NA if unavailable
     */
    private function resolveLastModified(string $filepath): string {

        if (!$this->filesystem->exists($filepath)) {
            return self::META_NA;
        }

        $timestamp = filemtime($filepath);

        if ($timestamp === false) {
            return self::META_NA;
        }

        return sprintf(self::META_VALUE, date('Y-m-d H:i:s', $timestamp));
    }

    /**
     * Resolves the file permissions for a given filepath in both octal and string formats.
     *
     * This method retrieves the Unix-style file permissions for a file or directory and
     * returns them in two formats:
     *
     * - 'int': The last 4 octal digits (e.g., "0644" or "0755")
     * - 'string': The symbolic representation (e.g., "-rw-r--r--" or "drwxr-xr-x")
     *
     * Error handling:
     * - If the file does not exist, returns unavailablePermissions() (META_NA for both formats)
     * - If fileperms() returns false (e.g., broken symlink, permission denied), triggers a
     *   warning with the filepath and returns unavailablePermissions()
     *
     * The octal format is useful for programmatic comparison, while the string format
     * provides human-readable permission information including file type, owner permissions,
     * group permissions, and world permissions.
     *
     * @param string $filepath The absolute or relative path to the file or directory
     * @return array{int: string, string: string} Array with 'int' (octal) and 'string' (symbolic) permission formats
     */
    private function resolvePermissions(string $filepath): array {

        if (!$this->filesystem->exists($filepath)) {
            return $this->unavailablePermissions();
        }

        $rawPermissions = fileperms($filepath);

        if ($rawPermissions === false) {
            trigger_error(
                sprintf(
                    '[zyos:validate] fileperms() returned false for "%s". '
                    . 'The path may be a broken symlink or inaccessible.',
                    $filepath
                ),
                E_USER_WARNING
            );
            return $this->unavailablePermissions();
        }

        return [
            'int'    => substr(sprintf('%o', $rawPermissions), -4),
            'string' => $this->buildPermissionString($rawPermissions),
        ];
    }

    /**
     * Builds a Unix-style permission string from a raw permission bitmask.
     *
     * This method converts the integer permission bitmask returned by fileperms() into
     * the familiar 10-character Unix permission string format (e.g., "-rw-r--r--" or
     * "drwxr-xr-x"). The string consists of:
     *
     * 1. File type character (1 char): d, -, l, s, b, c, p, or u (unknown)
     * 2. Owner permissions (3 chars): r/w/x with setuid indicator (s/S)
     * 3. Group permissions (3 chars): r/w/x with setgid indicator (s/S)
     * 4. World permissions (3 chars): r/w/x with sticky bit indicator (t/T)
     *
     * Permission bit logic:
     * - 'r' (read): Present if the respective bit is set
     * - 'w' (write): Present if the respective bit is set
     * - 'x' (execute): Present if the execute bit is set
     * - 's' (setuid/setgid): Execute bit set AND setuid/setgid bit set
     * - 'S' (setuid/setgid without execute): Execute bit not set BUT setuid/setgid bit set
     * - 't' (sticky bit): Execute bit set AND sticky bit set
     * - 'T' (sticky bit without execute): Execute bit not set BUT sticky bit set
     *
     * The method uses bitwise operations to extract specific permission bits and map them
     * to their character representations, handling special bits (setuid, setgid, sticky)
     * with appropriate uppercase/lowercase logic.
     *
     * @param int $permissions The raw permission bitmask from fileperms()
     * @return string The 10-character Unix-style permission string
     */
    private function buildPermissionString(int $permissions): string {

        $type = self::PERMISSION_TYPE_MAP[$permissions & 0xF000] ?? self::PERMISSION_TYPE_UNKNOWN;

        $owner = ($permissions & 0x0100 ? 'r' : '-')
            . ($permissions & 0x0080 ? 'w' : '-')
            . ($permissions & 0x0040
                ? ($permissions & 0x0800 ? 's' : 'x')
                : ($permissions & 0x0800 ? 'S' : '-'));

        $group = ($permissions & 0x0020 ? 'r' : '-')
            . ($permissions & 0x0010 ? 'w' : '-')
            . ($permissions & 0x0008
                ? ($permissions & 0x0400 ? 's' : 'x')
                : ($permissions & 0x0400 ? 'S' : '-'));

        $world = ($permissions & 0x0004 ? 'r' : '-')
            . ($permissions & 0x0002 ? 'w' : '-')
            . ($permissions & 0x0001
                ? ($permissions & 0x0200 ? 't' : 'x')
                : ($permissions & 0x0200 ? 'T' : '-'));

        return $type . $owner . $group . $world;
    }

    /**
     * Returns a permissions array indicating that permission information is unavailable.
     *
     * This method is a helper that returns a standardized array structure with both
     * permission formats set to META_NA ("NOT AVAILABLE"). It is used when the file
     * does not exist or when permission information cannot be retrieved due to errors.
     *
     * The returned array maintains the same structure as the successful return value
     * from resolvePermissions(), ensuring consistent handling in calling code.
     *
     * Used in:
     * - resolvePermissions() when the file does not exist
     * - resolvePermissions() when fileperms() returns false
     *
     * @return array{int: string, string: string} Array with both 'int' and 'string' set to META_NA
     */
    private function unavailablePermissions(): array {

        return [
            'int'    => self::META_NA,
            'string' => self::META_NA,
        ];
    }

    /**
     * Resolves a path by replacing environment-specific placeholders with their actual values.
     *
     * This method takes a path string that may contain Symfony parameter placeholders
     * (e.g., "%kernel.project_dir%", "%env(SOME_VAR)%") and replaces them with their
     * resolved values for the current environment.
     *
     * The resolution is performed by the Replacement service, which handles:
     * - Symfony kernel parameters (project_dir, cache_dir, log_dir, etc.)
     * - Environment variables accessed through %env()% syntax
     * - Custom parameters defined in the application configuration
     *
     * This ensures that paths defined in configuration files (which may use placeholders
     * for portability across environments) are resolved to absolute paths before
     * validation operations are performed.
     *
     * Example transformations:
     * - "%kernel.project_dir%/config" → "/var/www/myapp/config"
     * - "%env(HOME)%/.ssh" → "/home/user/.ssh"
     *
     * @param string $path The path string that may contain placeholders
     * @return string The resolved path with all placeholders replaced
     */
    private function resolvePath(string $path): string {
        return $this->replacement->replace($path, $this->environment);
    }
}
