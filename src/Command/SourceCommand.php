<?php

namespace Zyos\InstallBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * SourceCommand
 *
 * Symfony Console command that provides a comprehensive diagnostic report of the Zyos Install Bundle
 * configuration, Symfony application state, PHP runtime environment, and server system metrics.
 * This command serves as a central diagnostic tool for developers and system administrators to
 * quickly assess the health and configuration of their deployment environment.
 *
 * The command aggregates information from multiple sources:
 * - Bundle configuration parameters (paths, environments, validation entries, etc.)
 * - Symfony application metadata (version, environment, kernel directories, debug mode)
 * - PHP runtime configuration (version, extensions, OPcache, memory limits, SAPI)
 * - Server system information (OS, kernel, CPU, RAM, swap, uptime)
 * - Disk usage analysis across all mounted filesystems
 * - Web server detection and version identification
 *
 * The command performs automated health checks and generates diagnostic notices for:
 * - Missing or misconfigured bundle parameters
 * - Debug mode enabled in production environments
 * - Insufficient PHP memory limits for Symfony applications
 * - Disabled OPcache (performance impact)
 * - Xdebug loaded in production (severe performance degradation)
 * - High RAM or swap usage indicating memory pressure
 * - Disk usage exceeding warning or critical thresholds
 *
 * All output is presented in formatted tables with color-coded indicators for quick visual
 * assessment of system health. The command always returns Command::SUCCESS, as diagnostic
 * information is informational rather than a pass/fail validation.
 *
 * Usage:
 *   php bin/console zyos:source
 *
 * Responsibilities:
 * - Retrieve and display all Zyos Install Bundle configuration parameters
 * - Validate bundle path and lockfile existence on disk
 * - Display Symfony application version, environment, and directory structure
 * - Report PHP version, extensions, and runtime configuration
 * - Detect and display server hardware information (CPU, RAM, disk)
 * - Perform automated health checks with actionable recommendations
 * - Generate comprehensive diagnostic summary with critical and warning notices
 *
 * @author Carlos Parra <neural.framework@gmail.com>
 * @package Zyos\InstallBundle\Command
 */
class SourceCommand extends Command {

    /**
     * Disk usage percentage threshold for generating warning-level diagnostic notices.
     *
     * This constant defines the warning threshold for disk usage analysis. When any mounted
     * filesystem reaches or exceeds this percentage of capacity, the command generates
     * a warning-level diagnostic notice recommending that the user free space or expand
     * the volume.
     *
     * The threshold of 75% is chosen as a conservative warning level that provides
     * advance notice before disk space becomes critically low, allowing administrators
     * to take proactive action before the situation becomes urgent.
     *
     * Used in: renderDiskUsage() to determine when to add warning-level diagnostic notices
     * and to color-code disk usage bars and values in yellow.
     *
     * @var int
     */
    private const int DISK_THRESHOLD_WARNING  = 75;

    /**
     * Disk usage percentage threshold for generating critical-level diagnostic notices.
     *
     * This constant defines the critical threshold for disk usage analysis. When any mounted
     * filesystem reaches or exceeds this percentage of capacity, the command generates
     * a critical-level diagnostic notice indicating that immediate action is required.
     *
     * The threshold of 90% represents a critical situation where the filesystem is nearly
     * full and may soon cause application failures, system instability, or inability to
     * write logs, cache files, or other essential data. Critical notices are displayed
     * prominently with red color coding to draw immediate attention.
     *
     * Used in: renderDiskUsage() to determine when to add critical-level diagnostic notices
     * and to color-code disk usage bars and values in red.
     *
     * @var int
     */
    private const int DISK_THRESHOLD_CRITICAL = 90;

    /**
     * Recommended minimum PHP memory limit in megabytes for Symfony applications.
     *
     * This constant defines the recommended minimum memory_limit value in php.ini for
     * running Symfony applications in production. Symfony applications, particularly
     * those with complex business logic, large object graphs, or extensive use of
     * third-party bundles, can require significant memory to execute efficiently.
 *
     * The value of 512MB is based on Symfony's official recommendations and real-world
     * experience with production applications. Memory limits below this threshold may
     * result in:
     *
     * - Fatal errors due to memory exhaustion during request processing
     * - Poor performance due to frequent garbage collection
     * - Inability to process large datasets or complex operations
     * - Cache warm-up failures
     *
     * Used in: renderPhpRuntime() to compare against the actual memory_limit setting
     * and generate warning notices if the limit is below the recommended value.
     *
     * @var int
     */
    private const int PHP_RECOMMENDED_MEMORY_MB = 512;

    /**
     * Console-formatted icon representing a successful or healthy state.
     *
     * This constant provides a green checkmark symbol (✔) with foreground color formatting
     * for use in console output. It is displayed next to diagnostic items, configuration
     * values, or system metrics that are in a healthy or optimal state, providing
     * immediate visual feedback of positive status.
     *
     * Used in: renderDiagnostics() to indicate all checks passed, and throughout various
     * render methods to color-code successful states.
     *
     * @var string
     */
    private const string STYLE_OK = '<fg=green>✔</>';

    /**
     * Console-formatted icon representing a failed or critical state.
     *
     * This constant provides a red cross symbol (✘) with foreground color formatting
     * for use in console output. It is displayed next to diagnostic items, configuration
     * values, or system metrics that are in a failed or critically unhealthy state,
     * providing immediate visual feedback of problems requiring immediate attention.
     *
     * Used in: renderDiagnostics() to indicate critical issues, and throughout various
     * render methods to color-code failure states.
     *
     * @var string
     */
    private const string STYLE_FAIL = '<fg=red>✘</>';

    /**
     * Console-formatted icon representing a warning or suboptimal state.
     *
     * This constant provides a yellow warning symbol (⚠) with foreground color formatting
     * for use in console output. It is displayed next to diagnostic items, configuration
     * values, or system metrics that are in a warning or suboptimal state but not critical,
     * providing immediate visual feedback of issues that should be addressed soon.
     *
     * Used in: renderDiagnostics() to indicate warnings, and throughout various render
     * methods to color-code warning states.
     *
     * @var string
     */
    private const string STYLE_WARN = '<fg=yellow>⚠</>';

    /**
     * Console formatting template for displaying values in a healthy/positive state.
     *
     * This constant provides a printf-style format string with green foreground color
     * for displaying values that represent healthy, optimal, or successful states.
     * The %s placeholder is replaced with the actual value when used with sprintf().
     * This ensures consistent visual formatting for positive indicators throughout the output.
     *
     * Used in: Various render methods to color-code values that indicate healthy states,
     * such as enabled features, sufficient resources, or successful checks.
     *
     * @var string
     */
    private const string VALUE_OK = '<fg=green>%s</>';

    /**
     * Console formatting template for displaying values in a warning/suboptimal state.
     *
     * This constant provides a printf-style format string with yellow foreground color
     * for displaying values that represent warning or suboptimal states. The %s placeholder
     * is replaced with the actual value when used with sprintf(). This ensures consistent
     * visual formatting for warning indicators throughout the output.
     *
     * Used in: Various render methods to color-code values that indicate warnings,
     * such as resources approaching limits, disabled optimizations, or suboptimal configurations.
     *
     * @var string
     */
    private const string VALUE_WARN = '<fg=yellow>%s</>';

    /**
     * Console formatting template for displaying values in an error/critical state.
     *
     * This constant provides a printf-style format string with red foreground color
     * for displaying values that represent error or critical states. The %s placeholder
     * is replaced with the actual value when used with sprintf(). This ensures consistent
     * visual formatting for error indicators throughout the output.
     *
     * Used in: Various render methods to color-code values that indicate errors,
     * such as missing configurations, critical resource exhaustion, or failed checks.
     *
     * @var string
     */
    private const string VALUE_ERROR = '<fg=red>%s</>';

    /**
     * Console formatting template for displaying informational values.
     *
     * This constant provides a printf-style format string with cyan foreground color
     * for displaying informational values such as paths, versions, or identifiers.
     * The %s placeholder is replaced with the actual value when used with sprintf().
     * This ensures consistent visual formatting for informational content throughout the output.
     *
     * Used in: Various render methods to color-code informational values such as
     * file paths, version numbers, hostnames, and other neutral metadata.
     *
     * @var string
     */
    private const string VALUE_INFO = '<fg=cyan>%s</>';

    /**
     * Console formatting template for displaying plain values without emphasis.
     *
     * This constant provides a printf-style format string with white foreground color
     * for displaying values that should be presented plainly without color emphasis.
     * The %s placeholder is replaced with the actual value when used with sprintf().
     * This is used for neutral values that don't require color coding.
     *
     * Used in: Various render methods to display neutral values such as configuration
     * counts, system information, or other data that doesn't indicate health status.
     *
     * @var string
     */
    private const string VALUE_PLAIN = '<fg=white>%s</>';

    /**
     * Console formatting template for displaying muted/de-emphasized values.
     *
     * This constant provides a printf-style format string with gray foreground color
     * for displaying values that should be de-emphasized or shown as secondary information.
     * The %s placeholder is replaced with the actual value when used with sprintf().
     * This is used for auxiliary information that provides context but is not the primary focus.
     *
     * Used in: Various render methods to display secondary information such as kernel
     * versions, mount points, or other supporting metadata.
     *
     * @var string
     */
    private const string VALUE_MUTED = '<fg=gray>%s</>';

    /**
     * Accumulator array storing diagnostic notices collected during command execution.
     *
     * This property stores all diagnostic notices generated by the various health checks
     * performed throughout the command execution. Each notice is an associative array
     * containing:
     *
     * - 'level': Either 'warning' or 'critical', indicating the severity of the issue
     * - 'message': A human-readable description of the issue and recommended action
     *
     * Notices are added by helper methods addDiagnosticWarning() and addDiagnosticCritical()
     * and are displayed in the final diagnostics section. The array is reset at the
     * beginning of each command execution to ensure clean state.
     *
     * The diagnostic system allows the command to:
     * - Collect issues from multiple independent checks without immediately displaying them
     * - Present all issues together in a consolidated summary section
     * - Distinguish between warnings (should address soon) and criticals (immediate action required)
     * - Provide actionable recommendations for each detected issue
     *
     * Initialized in: execute() method (reset on each run).
     * Updated in: addDiagnosticWarning() and addDiagnosticCritical() methods.
     * Used in: renderDiagnostics() to display the consolidated diagnostic summary.
     *
     * @var array<int, array{level: string, message: string}>
     */
    private array $diagnosticNotices = [];

    /**
     * Constructor for SourceCommand.
     *
     * Initializes the command with all required dependencies through constructor property promotion.
     * These dependencies provide access to Symfony's core services needed for gathering
     * diagnostic information from the application and system.
     *
     * The constructor accepts readonly properties to ensure immutability after injection,
     * following modern PHP best practices for dependency injection in Symfony commands.
     *
     * @param ParameterBagInterface $parameterBag Symfony's parameter bag for accessing bundle configuration parameters
     * @param Filesystem $filesystem Symfony's filesystem component for checking file/directory existence
     * @param KernelInterface $kernel Symfony's kernel interface for accessing application metadata and environment
     */
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly Filesystem            $filesystem,
        private readonly KernelInterface       $kernel
    ) {
        parent::__construct();
    }

    /**
     * Configures the command definition, name, and description.
     *
     * This method sets up the command's metadata as required by Symfony's Console component.
     * The configuration defines:
     *
     * - Command name: 'zyos:source' - the CLI command used to invoke this diagnostic tool
     * - Description: Explains the command's purpose of providing a comprehensive diagnostic report
     *
     * The command does not require any arguments or options, as it is designed to gather
     * all available diagnostic information automatically without user input.
     *
     * @return void
     */
    protected function configure(): void {

        $this
            ->setName('zyos:source')
            ->setDescription('Full diagnostic report: bundle configuration, Symfony, PHP runtime, and server environment.');
    }

    /**
     * Executes the diagnostic command and displays the comprehensive report.
     *
     * This is the main entry point for command execution. It orchestrates the entire
     * diagnostic workflow by calling specialized render methods for each category of
     * information, then displays a consolidated summary of all diagnostic notices.
     *
     * Execution flow:
     * 1. Resets the $this->diagnosticNotices accumulator to ensure clean state on each run
     * 2. Creates a SymfonyStyle instance for enhanced console output formatting
     * 3. Displays a formatted title with the command name
     * 4. Displays a brief description of the diagnostic categories
     * 5. Calls renderBundleConfiguration() to display bundle configuration and validate paths
     * 6. Calls renderSymfonyApplication() to display Symfony application metadata
     * 7. Calls renderPhpRuntime() to display PHP version, extensions, and configuration
     * 8. Calls renderServer() to display OS, CPU, RAM, and web server information
     * 9. Calls renderDiskUsage() to display disk usage across all mounted filesystems
     * 10. Calls renderDiagnostics() to display the consolidated diagnostic summary
     *
     * The method always returns Command::SUCCESS because the command is informational
     * rather than a validation. Diagnostic notices are displayed for user awareness
     * but do not constitute a command failure.
     *
     * @param InputInterface $input Symfony's input interface (not used, no arguments required)
     * @param OutputInterface $output Symfony's output interface for writing console output
     * @return int Always returns Command::SUCCESS as this is an informational command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {

        $this->diagnosticNotices = [];

        $io = new SymfonyStyle($input, $output);

        $io->title(sprintf('Source Command <info>[%s]</info>', $this->getName()));
        $io->text('Bundle configuration · Symfony application · PHP runtime · server environment.');
        $io->newLine();

        $this->renderBundleConfiguration($io, $output);
        $this->renderSymfonyApplication($io, $output);
        $this->renderPhpRuntime($io, $output);
        $this->renderServer($io, $output);
        $this->renderDiskUsage($io, $output);
        $this->renderDiagnostics($io);

        return Command::SUCCESS;
    }

    /**
     * Renders the bundle configuration section with all parameter values and filesystem validation.
     *
     * This method retrieves and displays all Zyos Install Bundle configuration parameters
     * from the Symfony parameter bag. It validates the existence of critical paths on disk
     * and generates diagnostic notices for missing or misconfigured parameters.
     *
     * Displayed information:
     * - Path: The base directory for bundle-managed files
     * - Lockfile: Path to the installation lock file
     * - Environments: Array of configured environment names
     * - Lock environments: Array of environments requiring lockfile validation
     * - Install entries: Count of installation configuration entries
     * - Validate entries: Count of validation configuration entries
     * - Filesystem entries: Count of filesystem operation entries
     * - CLI entries: Count of CLI command execution entries
     * - Path exists: Whether the bundle path exists on disk
     * - Lockfile exists: Whether the lockfile exists on disk
     *
     * The method uses helper formatting methods to handle null values, type validation,
     * and color coding based on the state of each parameter. Missing parameters trigger
     * warning-level diagnostic notices. A missing bundle path triggers a specific
     * warning recommending the install command be run.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for section headers and formatted output
     * @param OutputInterface $output Output interface for table rendering
     * @return void
     */
    private function renderBundleConfiguration(SymfonyStyle $io, OutputInterface $output): void {

        $io->section('Bundle configuration');

        $path     = $this->safeGetParameter('zyos_install.path');
        $lockfile = $this->safeGetParameter('zyos_install.lockfile');
        $envs     = $this->safeGetParameter('zyos_install.environments');
        $locks    = $this->safeGetParameter('zyos_install.locks');
        $install  = $this->safeGetParameter('zyos_install.install');
        $validate = $this->safeGetParameter('zyos_install.validate');
        $fs       = $this->safeGetParameter('zyos_install.filesystem');
        $cli      = $this->safeGetParameter('zyos_install.cli');

        $pathExists     = is_string($path)     && $this->filesystem->exists($path);
        $lockfileExists = is_string($lockfile) && $this->filesystem->exists($lockfile);

        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Parameter', 'Value']);
        $table->setRows([
            ['Path',                $this->formatStringValue($path)],
            ['Lockfile',            $this->formatStringValue($lockfile)],
            ['Environments',        $this->formatListValue($envs)],
            ['Lock environments',   $this->formatListValue($locks)],
            ['Install entries',     $this->formatCountValue($install,  'zyos_install.install')],
            ['Validate entries',    $this->formatCountValue($validate, 'zyos_install.validate')],
            ['Filesystem entries',  $this->formatCountValue($fs,       'zyos_install.filesystem')],
            ['CLI entries',         $this->formatCountValue($cli,      'zyos_install.cli')],
            new TableSeparator(),
            [
                'Path exists',
                $pathExists
                    ? sprintf(self::VALUE_OK, '✔ yes')
                    : sprintf(self::VALUE_ERROR, '✘ no'),
            ],
            [
                'Lockfile exists',
                $lockfileExists
                    ? sprintf(self::VALUE_OK, '✔ yes')
                    : sprintf(self::VALUE_MUTED, '✘ no (not yet installed)'),
            ],
        ]);
        $table->render();
        $io->newLine();

        if (!$pathExists) {
            $this->addDiagnosticWarning(sprintf(
                'Bundle path "%s" does not exist on disk. Run the install command to create it.',
                $path ?? 'NOT SET'
            ));
        }
    }

    /**
     * Renders the Symfony application metadata section with version, environment, and directory information.
     *
     * This method displays comprehensive information about the Symfony application instance,
     * including the framework version, current environment, debug mode status, kernel class,
     * and all critical directory paths. It also performs a critical health check for
     * debug mode being enabled in production.
     *
     * Displayed information:
     * - Symfony version: The version of the Symfony framework installed
     * - Environment: The current Symfony environment (dev, prod, test, etc.)
     * - Debug mode: Whether debug mode is enabled (color-coded red if enabled)
     * - Kernel class: The fully qualified class name of the application kernel
     * - Project dir: The root directory of the Symfony project
     * - Cache dir: The directory where cache files are stored
     * - Log dir: The directory where log files are written
     * - Charset: The character encoding used by the application
     *
     * Health check:
     * - If debug mode is enabled and the environment is 'prod', a warning-level diagnostic
     *   notice is generated recommending that debug mode be disabled to avoid performance
     *   degradation and security vulnerabilities.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for section headers and formatted output
     * @param OutputInterface $output Output interface for table rendering
     * @return void
     */
    private function renderSymfonyApplication(SymfonyStyle $io, OutputInterface $output): void {

        $io->section('Symfony application');

        $symfonyVersion = defined('Symfony\Component\HttpKernel\Kernel::VERSION')
            ? Kernel::VERSION
            : 'unknown';

        $environment = $this->kernel->getEnvironment();
        $debugMode   = $this->kernel->isDebug();

        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Property', 'Value']);
        $table->setRows([
            ['Symfony version',  sprintf(self::VALUE_PLAIN, $symfonyVersion)],
            ['Environment',      sprintf(self::VALUE_OK, $environment)],
            ['Debug mode',       $debugMode
                ? sprintf(self::VALUE_ERROR, '✔ enabled')
                : sprintf(self::VALUE_OK,    '✘ disabled')],
            ['Kernel class',     sprintf(self::VALUE_INFO, get_class($this->kernel))],
            new TableSeparator(),
            ['Project dir',      sprintf(self::VALUE_INFO, $this->kernel->getProjectDir())],
            ['Cache dir',        sprintf(self::VALUE_INFO, $this->kernel->getCacheDir())],
            ['Log dir',          sprintf(self::VALUE_INFO, $this->kernel->getLogDir())],
            ['Charset',          sprintf(self::VALUE_PLAIN, $this->kernel->getCharset())],
        ]);
        $table->render();
        $io->newLine();

        if ($debugMode && $environment === 'prod') {
            $this->addDiagnosticWarning('Debug mode is enabled in the "prod" environment. Disable it to avoid performance and security issues.');
        }
    }

    /**
     * Renders the PHP runtime section with version, configuration, extensions, and performance optimizations.
     *
     * This method displays comprehensive information about the PHP runtime environment,
     * including the PHP version, SAPI type, architecture, memory configuration, and all
     * loaded extensions. It performs critical health checks for memory limits, OPcache status,
     * and Xdebug presence in production environments.
     *
     * Displayed information:
     * - PHP version: The current PHP version running
     * - SAPI: The Server API (e.g., cli, fpm-fcgi, apache2handler)
     * - Architecture: 32-bit or 64-bit based on PHP_INT_SIZE
     * - memory_limit: The PHP memory_limit setting (color-coded if below recommended)
     * - max_execution_time: Maximum script execution time in seconds
     * - upload_max_filesize: Maximum file upload size
     * - post_max_size: Maximum POST data size
     * - date.timezone: Configured timezone or 'not set'
     * - OPcache: Whether OPcache is enabled (color-coded)
     * - JIT: Whether JIT compilation is enabled
     * - Xdebug: Whether Xdebug extension is loaded (color-coded if in production)
     * - Loaded extensions: Alphabetically sorted list of all loaded PHP extensions
     *
     * Health checks:
     * - If memory_limit is below PHP_RECOMMENDED_MEMORY_MB (512MB), a warning is generated
     * - If OPcache is disabled, a warning is generated recommending enablement
     * - If Xdebug is loaded in the 'prod' environment, a warning is generated due to severe
     *   performance impact
     *
     * @param SymfonyStyle $io SymfonyStyle instance for section headers and formatted output
     * @param OutputInterface $output Output interface for table rendering
     * @return void
     */
    private function renderPhpRuntime(SymfonyStyle $io, OutputInterface $output): void {

        $io->section('PHP runtime');

        $memoryLimitRaw = ini_get('memory_limit');
        $memoryMb       = $this->parseMemoryToMb($memoryLimitRaw);
        $opcacheEnabled = function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);
        $jitEnabled     = $opcacheEnabled && !empty(opcache_get_status(false)['jit']['enabled'] ?? false);
        $xdebugLoaded   = extension_loaded('xdebug');
        $architecture   = PHP_INT_SIZE === 8 ? '64-bit' : '32-bit';

        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Property', 'Value']);
        $table->setRows([
            ['PHP version',        sprintf(self::VALUE_OK,    PHP_VERSION)],
            ['SAPI',               sprintf(self::VALUE_PLAIN, PHP_SAPI)],
            ['Architecture',       sprintf(self::VALUE_PLAIN, $architecture)],
            new TableSeparator(),
            ['memory_limit',       $memoryMb >= self::PHP_RECOMMENDED_MEMORY_MB
                ? sprintf(self::VALUE_OK,   $memoryLimitRaw)
                : sprintf(self::VALUE_WARN, $memoryLimitRaw . ' (recommended: ' . self::PHP_RECOMMENDED_MEMORY_MB . 'M)')],
            ['max_execution_time', sprintf(self::VALUE_PLAIN, ini_get('max_execution_time') . 's')],
            ['upload_max_filesize',sprintf(self::VALUE_PLAIN, ini_get('upload_max_filesize'))],
            ['post_max_size',      sprintf(self::VALUE_PLAIN, ini_get('post_max_size'))],
            ['date.timezone',      sprintf(self::VALUE_PLAIN, ini_get('date.timezone') ?: 'not set')],
            new TableSeparator(),
            ['OPcache',            $opcacheEnabled
                ? sprintf(self::VALUE_OK,   '✔ enabled')
                : sprintf(self::VALUE_WARN, '✘ disabled')],
            ['JIT',                $jitEnabled
                ? sprintf(self::VALUE_OK,   '✔ enabled')
                : sprintf(self::VALUE_MUTED,'✘ disabled')],
            ['Xdebug',             $xdebugLoaded
                ? sprintf(self::VALUE_WARN, '✔ loaded (' . phpversion('xdebug') . ')')
                : sprintf(self::VALUE_MUTED,'✘ not loaded')],
        ]);
        $table->render();

        // Extensions
        $extensions = get_loaded_extensions();
        sort($extensions);
        $io->newLine();
        $io->text(sprintf('<fg=gray>Loaded extensions (%d):</>', count($extensions)));
        $io->text(implode('  ', array_map(
            fn(string $ext) => sprintf('<fg=green>%s</>', $ext),
            $extensions
        )));
        $io->newLine();

        // Diagnostic notices
        if ($memoryMb < self::PHP_RECOMMENDED_MEMORY_MB && $memoryMb !== -1) {
            $this->addDiagnosticWarning(sprintf(
                'memory_limit (%s) is below the recommended %dM for production Symfony applications.',
                $memoryLimitRaw,
                self::PHP_RECOMMENDED_MEMORY_MB
            ));
        }

        if (!$opcacheEnabled) {
            $this->addDiagnosticWarning('OPcache is disabled. Enable it in php.ini for significant performance gains (opcache.enable=1).');
        }

        if ($xdebugLoaded && $this->kernel->getEnvironment() === 'prod') {
            $this->addDiagnosticWarning('Xdebug is loaded in the "prod" environment. This causes severe performance degradation. Disable it in production.');
        }
    }

    /**
     * Renders the server section with OS, CPU, RAM, swap, and web server information.
     *
     * This method displays comprehensive system information about the server hosting
     * the application, including the operating system, kernel version, hardware specifications,
     * memory usage, and detected web server. It performs health checks for RAM and swap
     * usage that may indicate memory pressure or insufficient resources.
     *
     * Displayed information:
     * - Hostname: The server's hostname
     * - OS: The operating system name and version
     * - Kernel: The kernel version
     * - Uptime: System uptime in days, hours, and minutes
     * - CPU model: The processor model name
     * - CPU cores: Physical and logical core count
     * - RAM total: Total installed RAM
     * - RAM used: Used RAM with percentage (color-coded red if >=90%, yellow otherwise)
     * - RAM free: Available RAM
     * - Swap total: Total swap space
     * - Swap used: Used swap with percentage (color-coded yellow if >=50%)
     * - Web server: Detected web server software and version
     *
     * Health checks:
     * - If RAM usage is >=90%, a warning is generated indicating memory pressure
     * - If swap usage is >=50%, a warning is generated indicating insufficient RAM
     *
     * @param SymfonyStyle $io SymfonyStyle instance for section headers and formatted output
     * @param OutputInterface $output Output interface for table rendering
     * @return void
     */
    private function renderServer(SymfonyStyle $io, OutputInterface $output): void {

        $io->section('Server');

        $hostname   = gethostname() ?: 'N/A';
        $os         = $this->readOsRelease();
        $kernel     = $this->safeShell('uname -r');
        $uptime     = $this->readUptime();
        $cpuModel   = $this->readCpuModel();
        $cpuCores   = $this->readCpuCores();
        $ram        = $this->readMemoryInfo();
        $webServer  = $this->detectWebServer();

        $ramUsedPct  = $ram['total'] > 0 ? round(($ram['used'] / $ram['total']) * 100) : 0;
        $swapUsedPct = $ram['swap_total'] > 0 ? round(($ram['swap_used'] / $ram['swap_total']) * 100) : 0;

        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Property', 'Value']);
        $table->setRows([
            ['Hostname',     sprintf(self::VALUE_INFO,  $hostname)],
            ['OS',           sprintf(self::VALUE_PLAIN, $os)],
            ['Kernel',       sprintf(self::VALUE_MUTED, $kernel)],
            ['Uptime',       sprintf(self::VALUE_PLAIN, $uptime)],
            new TableSeparator(),
            ['CPU model',    sprintf(self::VALUE_PLAIN, $cpuModel)],
            ['CPU cores',    sprintf(self::VALUE_PLAIN, $cpuCores)],
            new TableSeparator(),
            ['RAM total',    sprintf(self::VALUE_PLAIN, $this->formatBytes($ram['total'] * 1024))],
            ['RAM used',     $ramUsedPct >= 90
                ? sprintf(self::VALUE_ERROR, $this->formatBytes($ram['used'] * 1024) . sprintf(' (%d%%)', $ramUsedPct))
                : sprintf(self::VALUE_WARN,  $this->formatBytes($ram['used'] * 1024) . sprintf(' (%d%%)', $ramUsedPct))],
            ['RAM free',     sprintf(self::VALUE_OK,    $this->formatBytes($ram['free'] * 1024))],
            ['Swap total',   sprintf(self::VALUE_PLAIN, $this->formatBytes($ram['swap_total'] * 1024))],
            ['Swap used',    $swapUsedPct >= 50
                ? sprintf(self::VALUE_WARN,  $this->formatBytes($ram['swap_used'] * 1024) . sprintf(' (%d%%)', $swapUsedPct))
                : sprintf(self::VALUE_PLAIN, $this->formatBytes($ram['swap_used'] * 1024))],
            new TableSeparator(),
            ['Web server',   sprintf(self::VALUE_PLAIN, $webServer)],
        ]);
        $table->render();
        $io->newLine();

        if ($ramUsedPct >= 90) {
            $this->addDiagnosticWarning(sprintf(
                'RAM usage is at %d%%. The server may be under memory pressure — consider adding RAM or reviewing running processes.',
                $ramUsedPct
            ));
        }

        if ($swapUsedPct >= 50) {
            $this->addDiagnosticWarning(sprintf(
                'Swap usage is at %d%%. Heavy swap use indicates insufficient RAM for the current workload.',
                $swapUsedPct
            ));
        }
    }

    /**
     * Renders the disk usage section with capacity analysis for all mounted filesystems.
     *
     * This method displays disk usage information for all physical block devices mounted
     * on the system, excluding temporary filesystems (tmpfs, devtmpfs, etc.). Each mount
     * point shows the device name, mount path, total capacity, used space, free space, and
     * a visual usage bar with percentage. Critical and warning thresholds trigger diagnostic
     * notices when disk space is running low.
     *
     * Displayed information per mount:
     * - Device: The block device path (e.g., /dev/sda1)
     * - Mount: The mount point path (e.g., /, /var, /home)
     * - Total: Total disk capacity in human-readable format
     * - Used: Used disk space with percentage (color-coded based on threshold)
     * - Free: Free disk space
     * - Usage: Visual bar showing usage percentage with color coding
     *
     * Color coding:
     * - Green: Usage below DISK_THRESHOLD_WARNING (75%)
     * - Yellow: Usage between DISK_THRESHOLD_WARNING (75%) and DISK_THRESHOLD_CRITICAL (90%)
     * - Red: Usage at or above DISK_THRESHOLD_CRITICAL (90%)
     *
     * Health checks:
     * - If usage >= DISK_THRESHOLD_CRITICAL (90%), a critical diagnostic notice is generated
     * - If usage >= DISK_THRESHOLD_WARNING (75%), a warning diagnostic notice is generated
     *
     * If disk mount information cannot be read from the system, a warning message is
     * displayed instead of the table.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for section headers and formatted output
     * @param OutputInterface $output Output interface for table rendering
     * @return void
     */
    private function renderDiskUsage(SymfonyStyle $io, OutputInterface $output): void {

        $io->section('Disk usage');

        $mounts = $this->readDiskMounts();

        if (empty($mounts)) {
            $io->warning('Could not read disk mount information from the system.');
            return;
        }

        $table = new Table($output);
        $table->setStyle('box');
        $table->setHeaders(['Device', 'Mount', 'Total', 'Used', 'Free', 'Usage']);
        $rows = [];

        foreach ($mounts as $mount) {
            $pct     = $mount['total'] > 0 ? round(($mount['used'] / $mount['total']) * 100) : 0;
            $bar     = $this->buildUsageBar($pct);
            $barLine = sprintf('%s %d%%', $bar, $pct);

            if ($pct >= self::DISK_THRESHOLD_CRITICAL) {
                $usageStyled = sprintf(self::VALUE_ERROR, $barLine);
                $usedStyled  = sprintf(self::VALUE_ERROR, $this->formatBytes($mount['used']));
                $this->addDiagnosticCritical(sprintf(
                    'Disk "%s" (%s) is at %d%% capacity. Immediate action required.',
                    $mount['device'],
                    $mount['mount'],
                    $pct
                ));
            } elseif ($pct >= self::DISK_THRESHOLD_WARNING) {
                $usageStyled = sprintf(self::VALUE_WARN, $barLine);
                $usedStyled  = sprintf(self::VALUE_WARN, $this->formatBytes($mount['used']));
                $this->addDiagnosticWarning(sprintf(
                    'Disk "%s" (%s) is at %d%% capacity. Consider freeing space or expanding the volume.',
                    $mount['device'],
                    $mount['mount'],
                    $pct
                ));
            } else {
                $usageStyled = sprintf(self::VALUE_OK, $barLine);
                $usedStyled  = sprintf(self::VALUE_PLAIN, $this->formatBytes($mount['used']));
            }

            $rows[] = [
                sprintf(self::VALUE_INFO, $mount['device']),
                sprintf(self::VALUE_MUTED, $mount['mount']),
                $this->formatBytes($mount['total']),
                $usedStyled,
                sprintf(self::VALUE_OK, $this->formatBytes($mount['free'])),
                $usageStyled,
            ];
        }

        $table->setRows($rows);
        $table->render();
        $io->newLine();
    }

    /**
     * Renders the diagnostics summary section with all collected critical and warning notices.
     *
     * This method displays the consolidated summary of all diagnostic notices collected
     * during the command execution. Notices are grouped by severity (critical vs warning)
     * and displayed with appropriate icons and color coding. A final summary message
     * indicates the total count of issues requiring attention.
     *
     * Display behavior:
     * - If no diagnostic notices were collected, displays a success message indicating
     *   all checks passed
     * - If notices exist, displays each notice with:
     *   - Critical notices: Red cross icon (✘) with [critical] tag in red
     *   - Warning notices: Yellow warning icon (⚠) with [warning] tag in yellow
     *   - The descriptive message explaining the issue and recommended action
     * - After listing all notices, displays a summary:
     *   - If critical issues exist: Error message with critical and warning counts
     *   - If only warnings exist: Warning message with warning count
     *
     * This section provides a centralized view of all issues detected throughout the
     * diagnostic process, making it easy for administrators to identify and prioritize
     * problems that need attention.
     *
     * @param SymfonyStyle $io SymfonyStyle instance for section headers and formatted output
     * @return void
     */
    private function renderDiagnostics(SymfonyStyle $io): void {

        $io->section('Diagnostics');

        if (empty($this->diagnosticNotices)) {
            $io->writeln(sprintf('  %s  All configuration and environment checks passed.', self::STYLE_OK));
            $io->newLine();
            return;
        }

        foreach ($this->diagnosticNotices as $notice) {
            $icon    = $notice['level'] === 'critical' ? self::STYLE_FAIL : self::STYLE_WARN;
            $levelTag = $notice['level'] === 'critical'
                ? sprintf(self::VALUE_ERROR, '[critical]')
                : sprintf(self::VALUE_WARN,  '[warning]');

            $io->writeln(sprintf('  %s  %s %s', $icon, $levelTag, $notice['message']));
        }

        $io->newLine();

        $criticalCount = count(array_filter($this->diagnosticNotices, fn($n) => $n['level'] === 'critical'));
        $warningCount  = count($this->diagnosticNotices) - $criticalCount;

        if ($criticalCount > 0) {
            $io->error(sprintf(
                '%d critical issue(s) and %d warning(s) require attention.',
                $criticalCount,
                $warningCount
            ));
        } else {
            $io->warning(sprintf('%d warning(s) found. Review the items above.', $warningCount));
        }
    }

    /**
     * Adds a warning-level diagnostic notice to the accumulator.
     *
     * This helper method adds a diagnostic notice with 'warning' severity level to the
     * $this->diagnosticNotices array. Warning notices indicate issues that should be
     * addressed soon but are not immediately critical to system operation.
     *
     * Typical use cases for warnings:
     * - Suboptimal configurations that don't prevent operation but impact performance
     * - Resources approaching limits (e.g., disk usage at 80%)
     * - Missing optional but recommended features (e.g., OPcache disabled)
     * - Debug mode or development tools in production environments
     *
     * The notice is stored as an associative array with 'level' set to 'warning' and
     * the provided message. Warnings are displayed with yellow color coding in the
     * diagnostics summary.
     *
     * @param string $message Human-readable description of the warning and recommended action
     * @return void
     */
    private function addDiagnosticWarning(string $message): void {
        $this->diagnosticNotices[] = ['level' => 'warning', 'message' => $message];
    }

    /**
     * Adds a critical-level diagnostic notice to the accumulator.
     *
     * This helper method adds a diagnostic notice with 'critical' severity level to the
     * $this->diagnosticNotices array. Critical notices indicate urgent issues that
     * require immediate attention to prevent system failure, data loss, or severe
     * operational impact.
     *
     * Typical use cases for critical notices:
     * - Disk usage at or above critical threshold (90%+)
     * - Missing essential configuration or dependencies
     * - Security vulnerabilities in production environments
     * - Resource exhaustion that will cause imminent failures
     *
     * The notice is stored as an associative array with 'level' set to 'critical' and
     * the provided message. Critical notices are displayed with red color coding in the
     * diagnostics summary and cause the final summary to be displayed as an error.
     *
     * @param string $message Human-readable description of the critical issue and required action
     * @return void
     */
    private function addDiagnosticCritical(string $message): void {
        $this->diagnosticNotices[] = ['level' => 'critical', 'message' => $message];
    }

    /**
     * Safely retrieves a parameter from the Symfony parameter bag with error handling.
     *
     * This method attempts to retrieve a configuration parameter by key from the
     * Symfony parameter bag. If the parameter does not exist, it generates a
     * warning-level diagnostic notice and returns null instead of throwing an exception.
     *
     * This safe retrieval pattern allows the diagnostic command to continue displaying
     * available information even when some configuration parameters are missing, while
     * still alerting the user to configuration problems that need to be addressed.
     *
     * Error handling:
     * - If the parameter key does not exist in the parameter bag, a warning diagnostic
     *   notice is generated with the parameter name and a recommendation to check the
     *   bundle configuration
     * - The method returns null in this case, allowing calling code to handle missing
     *   parameters gracefully
     *
     * Used throughout renderBundleConfiguration() to retrieve all bundle configuration
     * parameters without risking exceptions for missing keys.
     *
     * @param string $key The parameter key to retrieve from the parameter bag
     * @return mixed The parameter value if it exists, null otherwise
     */
    private function safeGetParameter(string $key): mixed {

        if (!$this->parameterBag->has($key)) {
            $this->addDiagnosticWarning(sprintf(
                'Bundle configuration key "%s" is missing from the parameter bag. Check your bundle configuration.',
                $key
            ));
            return null;
        }

        return $this->parameterBag->get($key);
    }

    /**
     * Formats a scalar configuration value for display in the bundle configuration table.
     *
     * This method handles the formatting of string, numeric, or other scalar values
     * for display in the bundle configuration section. It provides consistent error
     * handling for null values and applies appropriate color coding.
     *
     * Formatting behavior:
     * - If the value is null, returns 'NOT SET' in red color (VALUE_ERROR)
     * - Otherwise, casts the value to string and returns it in cyan color (VALUE_INFO)
     *
     * This method is used for displaying simple scalar values like paths and filenames
     * in the bundle configuration table.
     *
     * @param mixed $value The configuration value to format (typically string or null)
     * @return string The formatted value with appropriate color coding
     */
    private function formatStringValue(mixed $value): string {

        if ($value === null) {
            return sprintf(self::VALUE_ERROR, 'NOT SET');
        }

        return sprintf(self::VALUE_INFO, (string) $value);
    }

    /**
     * Formats an array configuration value as a comma-separated list for display.
     *
     * This method handles the formatting of array values (typically lists of environment
     * names or configuration keys) for display in the bundle configuration section.
     * It validates the type and provides appropriate error handling.
     *
     * Formatting behavior:
     * - If the value is null, returns 'NOT SET' in red color (VALUE_ERROR)
     * - If the value is not an array, generates a warning diagnostic notice and returns
     *   'INVALID TYPE (type)' in red color
     * - Otherwise, joins the array elements with ', ' and returns in white color (VALUE_PLAIN)
     *
     * Used for displaying arrays like environment lists and lock environment lists.
     *
     * @param mixed $value The configuration value to format (expected to be an array)
     * @return string The formatted comma-separated list or error message
     */
    private function formatListValue(mixed $value): string {

        if ($value === null) {
            return sprintf(self::VALUE_ERROR, 'NOT SET');
        }

        if (!is_array($value)) {
            $this->addDiagnosticWarning(sprintf('Expected array but got %s.', gettype($value)));
            return sprintf(self::VALUE_ERROR, 'INVALID TYPE (' . gettype($value) . ')');
        }

        return sprintf(self::VALUE_PLAIN, implode(', ', $value));
    }

    /**
     * Formats an array configuration value by displaying its element count.
     *
     * This method handles the formatting of array values that represent collections
     * of configuration entries (install, validate, filesystem, CLI entries). Instead
     * of displaying the entire array contents, it displays the count of elements,
     * which is more concise and useful for diagnostic purposes.
     *
     * Formatting behavior:
     * - If the value is null, returns 'NOT SET' in red color (VALUE_ERROR)
     * - If the value is not an array, generates a warning diagnostic notice with the
     *   parameter key and actual type, then returns 'INVALID TYPE (type)' in red color
     * - Otherwise, returns the count of array elements as a string in white color (VALUE_PLAIN)
     *
     * Used for displaying counts of configuration entry arrays in the bundle configuration
     * table, providing a quick overview of how many entries are configured for each category.
     *
     * @param mixed $value The configuration value to format (expected to be an array)
     * @param string $paramKey The parameter key for inclusion in error messages
     * @return string The formatted count or error message
     */
    private function formatCountValue(mixed $value, string $paramKey): string {

        if ($value === null) {
            return sprintf(self::VALUE_ERROR, 'NOT SET');
        }

        if (!is_array($value)) {
            $this->addDiagnosticWarning(sprintf(
                'Parameter "%s" should be an array but is %s.',
                $paramKey,
                gettype($value)
            ));
            return sprintf(self::VALUE_ERROR, 'INVALID TYPE (' . gettype($value) . ')');
        }

        return sprintf(self::VALUE_PLAIN, (string) count($value));
    }

    /**
     * Reads the operating system name and version from the system's release information.
     *
     * This method attempts to determine the operating system name and version by reading
     * the /etc/os-release file, which is the standard location for OS metadata on modern
     * Linux distributions. If the file is not available or cannot be read, it falls back
     * to using the uname command to retrieve kernel information.
     *
     * Primary method (Linux):
     * - Reads /etc/os-release if the file exists and is readable
     * - Parses the file line by line, extracting key-value pairs
     * - Returns the PRETTY_NAME field if available (e.g., "Ubuntu 22.04.3 LTS")
     * - Falls back to the NAME field if PRETTY_NAME is not present
     * - Returns 'unknown' if neither field is found
     *
     * Fallback method (all Unix-like systems):
     * - Executes 'uname -s -r' to get the kernel name and release
     * - Returns the output as a fallback when /etc/os-release is unavailable
     *
     * The method uses the @ operator to suppress file reading errors, and the safeShell()
     * method to safely execute shell commands with error handling.
     *
     * @return string The operating system name and version, or kernel information as fallback
     */
    private function readOsRelease(): string {

        if (is_readable('/etc/os-release')) {
            $lines = @file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $data = [];
                foreach ($lines as $line) {
                    [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
                    $data[$key]  = trim($val, '"');
                }
                return $data['PRETTY_NAME'] ?? ($data['NAME'] ?? 'unknown');
            }
        }

        return $this->safeShell('uname -s -r');
    }

    /**
     * Reads the system uptime and formats it as a human-readable string.
     *
     * This method retrieves the system uptime (time since last boot) and formats it
     * in a user-friendly format showing days, hours, and minutes. It attempts to read
     * from the Linux-specific /proc/uptime file first, then falls back to the uptime
     * command if the proc filesystem is unavailable.
     *
     * Primary method (Linux with /proc):
     * - Reads /proc/uptime if the file exists and is readable
     * - The file contains the uptime in seconds as the first whitespace-separated value
     * - Converts total seconds to days, hours, and minutes
     * - Returns formatted string like "7 days 14:32 h"
     *
     * Fallback method (Unix-like systems):
     * - Executes 'uptime -p' which outputs uptime in a human-readable format
     * - Returns the command output directly
     *
     * The formatted uptime provides administrators with a quick view of how long the
     * system has been running, which can be useful for diagnosing stability issues
     * or identifying when the last reboot occurred.
     *
     * @return string The formatted uptime string (e.g., "7 days 14:32 h") or fallback output
     */
    private function readUptime(): string {

        if (is_readable('/proc/uptime')) {
            $content = @file_get_contents('/proc/uptime');
            if ($content !== false) {
                $seconds = (int) explode(' ', $content)[0];
                $days    = intdiv($seconds, 86400);
                $hours   = intdiv($seconds % 86400, 3600);
                $minutes = intdiv($seconds % 3600, 60);
                return sprintf('%d days %02d:%02d h', $days, $hours, $minutes);
            }
        }

        return $this->safeShell('uptime -p');
    }

    /**
     * Reads the CPU model name from the system's processor information.
     *
     * This method retrieves the CPU model name (e.g., "Intel(R) Core(TM) i7-9700K")
     * from the system's processor information. It attempts to read from the Linux-specific
     * /proc/cpuinfo file first, then falls back to a macOS-specific sysctl command.
     *
     * Primary method (Linux with /proc):
     * - Reads /proc/cpuinfo if the file exists and is readable
     * - Searches for the "model name" field using a regular expression
     * - Returns the model name string with whitespace trimmed
     *
     * Fallback method (macOS):
     * - Executes 'sysctl -n machdep.cpu.brand_string' to get the CPU brand string
     * - This sysctl key is specific to macOS and returns the CPU model name
     *
     * The CPU model information helps administrators identify the hardware capabilities
     * of the server and can be useful for performance analysis or capacity planning.
     *
     * @return string The CPU model name, or 'N/A' if unavailable
     */
    private function readCpuModel(): string {

        if (is_readable('/proc/cpuinfo')) {
            $content = @file_get_contents('/proc/cpuinfo');
            if ($content !== false && preg_match('/^model name\s*:\s*(.+)$/m', $content, $m)) {
                return trim($m[1]);
            }
        }

        return $this->safeShell('sysctl -n machdep.cpu.brand_string'); // macOS fallback
    }

    /**
     * Reads the CPU core count (physical and logical) from the system's processor information.
     *
     * This method retrieves information about the number of CPU cores, distinguishing
     * between physical cores and logical cores (threads). Physical cores represent the
     * actual CPU cores on the processor die, while logical cores include hyper-threading
     * or simultaneous multithreading (SMT) where available.
     *
     * Primary method (Linux with /proc):
     * - Reads /proc/cpuinfo if the file exists and is readable
     * - Counts the number of "processor" entries to determine logical core count
     * - Extracts the "cpu cores" field to determine physical core count
     * - Returns formatted string like "4 physical · 8 logical"
     * - If physical cores cannot be determined, uses logical core count as fallback
     *
     * Fallback method (macOS):
     * - Executes 'sysctl -n hw.logicalcpu' to get the logical CPU count
     * - Returns the count if successful, formatted as "X logical"
     * - Returns 'N/A' if the command fails
     *
     * The core count information is valuable for understanding the server's parallel
     * processing capabilities and can inform configuration decisions for worker processes,
     * thread pools, or concurrent task limits.
     *
     * @return string The formatted core count string, or 'N/A' if unavailable
     */
    private function readCpuCores(): string {

        if (is_readable('/proc/cpuinfo')) {
            $content = @file_get_contents('/proc/cpuinfo');
            if ($content !== false) {
                $logical  = substr_count($content, 'processor');
                preg_match('/^cpu cores\s*:\s*(\d+)/m', $content, $m);
                $physical = isset($m[1]) ? (int) $m[1] : $logical;
                return sprintf('%d physical · %d logical', $physical, $logical);
            }
        }

        // macOS fallback
        $logical = (int) $this->safeShell('sysctl -n hw.logicalcpu');
        return $logical > 0 ? sprintf('%d logical', $logical) : 'N/A';
    }

    /**
     * Reads detailed memory and swap information from the Linux /proc/meminfo file.
     *
     * This method retrieves comprehensive memory statistics including total RAM, used RAM,
     * available RAM, and swap space usage. It parses the /proc/meminfo file which provides
     * real-time memory information on Linux systems. The values are returned in kilobytes
     * as reported by the kernel.
     *
     * Memory calculation:
     * - Total RAM: MemTotal field from /proc/meminfo
     * - Used RAM: Calculated as (Total - Free - Buffers - Cached)
     *   This formula accounts for memory used by the kernel for buffers and cache,
     *   which can be reclaimed if needed for applications
     * - Available RAM: MemAvailable field if present (kernel 3.14+), falls back to Free
     * - Swap total: SwapTotal field
     * - Swap used: Calculated as (SwapTotal - SwapFree)
     *
     * Error handling:
     * - If /proc/meminfo is not readable, returns default array with all zeros
     * - If file content cannot be read, returns default array with all zeros
     * - Missing fields default to 0 to prevent calculation errors
     * - Used values are clamped to minimum 0 to prevent negative values
     *
     * The returned array provides all memory metrics needed for the server section
     * display and for generating diagnostic notices about memory pressure.
     *
     * @return array{total: int, used: int, free: int, available: int, swap_total: int, swap_used: int}
     *         Array with memory and swap statistics in kilobytes
     */
    private function readMemoryInfo(): array {

        $default = ['total' => 0, 'used' => 0, 'free' => 0, 'available' => 0, 'swap_total' => 0, 'swap_used' => 0];

        if (!is_readable('/proc/meminfo')) {
            return $default;
        }

        $content = @file_get_contents('/proc/meminfo');
        if ($content === false) {
            return $default;
        }

        $values = [];
        preg_match_all('/^(\w+):\s+(\d+)/m', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $values[$match[1]] = (int) $match[2];
        }

        $total     = $values['MemTotal']     ?? 0;
        $free      = $values['MemFree']      ?? 0;
        $available = $values['MemAvailable'] ?? $free;
        $buffers   = $values['Buffers']      ?? 0;
        $cached    = $values['Cached']       ?? 0;
        $swapTotal = $values['SwapTotal']    ?? 0;
        $swapFree  = $values['SwapFree']     ?? 0;
        $used      = $total - $free - $buffers - $cached;

        return [
            'total'      => $total,
            'used'       => max(0, $used),
            'free'       => $available,
            'available'  => $available,
            'swap_total' => $swapTotal,
            'swap_used'  => max(0, $swapTotal - $swapFree),
        ];
    }

    /**
     * Detects the web server software and version running on the system.
     *
     * This method attempts to identify the web server (e.g., nginx, Apache, Caddy) using
     * multiple strategies, from fastest to most portable. The detection is important for
     * understanding the server environment and can inform configuration decisions.
     *
     * Strategy 1: Superglobal check (fastest, web context only)
     * - Checks $_SERVER['SERVER_SOFTWARE'] which is set by web servers
     * - Returns immediately if available (only works under a web server, not CLI)
     *
     * Strategy 2: /proc filesystem scan (Linux, no external tools)
     * - Iterates through /proc/<pid>/comm files to find running web server processes
     * - Each comm file contains the short process name (max 15 characters)
     * - Works on any Linux kernel without requiring pgrep, ps, or other external tools
     * - Returns the first match with version information if found
     *
     * Strategy 3: PATH binary check (POSIX-compliant)
     * - Uses 'command -v' (POSIX shell built-in) to check if binaries are on PATH
     * - Does not execute the binaries, only checks their existence
     * - Works across Unix-like systems with sh, bash, dash, etc.
     * - Returns the first match with version information if found
     *
     * If all strategies fail, returns 'unknown (CLI context)' indicating the command
     * is running in CLI mode rather than under a web server.
     *
     * @return string The detected web server name and version, or 'unknown (CLI context)'
     */
    private function detectWebServer(): string {

        if (!empty($_SERVER['SERVER_SOFTWARE'])) {
            return $_SERVER['SERVER_SOFTWARE'];
        }

        $knownServers = ['nginx', 'apache2', 'httpd', 'caddy', 'lighttpd', 'php-fpm'];

        if (is_dir('/proc') && ($procHandle = @opendir('/proc')) !== false) {
            $foundNames = [];
            while (($entry = readdir($procHandle)) !== false) {
                if (!ctype_digit($entry)) {
                    continue;
                }
                $commFile = '/proc/' . $entry . '/comm';
                $comm     = @file_get_contents($commFile);
                if ($comm === false) {
                    continue;
                }
                $comm = trim($comm);
                if (in_array($comm, $knownServers, true)) {
                    $foundNames[$comm] = true;
                }
            }
            closedir($procHandle);

            foreach ($knownServers as $name) {
                if (isset($foundNames[$name])) {
                    return $this->resolveWebServerVersion($name);
                }
            }
        }

        foreach ($knownServers as $name) {
            $binaryPath = $this->safeShell(sprintf('command -v %s 2>/dev/null', escapeshellarg($name)));
            if ($binaryPath !== '' && $binaryPath !== 'N/A' && str_starts_with($binaryPath, '/')) {
                return $this->resolveWebServerVersion($name);
            }
        }

        return 'unknown (CLI context)';
    }

    /**
     * Resolves the version string for a detected web server binary.
     *
     * This method attempts to extract the version number from a web server's version
     * output by executing the binary with the -v flag. The version is extracted using
     * a regular expression that matches common version number patterns (e.g., 1.18.0,
     * 2.4.52, 1.20.2).
     *
     * Process:
     * - Executes the web server binary with the -v flag (e.g., 'nginx -v', 'apache2 -v')
     * - Captures the output, including stderr (redirected via 2>&1)
     * - Uses a regular expression to extract the version number pattern
     * - Returns the server name and version if a version is found
     * - Returns only the server name if version extraction fails
     *
     * The version information helps administrators identify the specific software
     * version running, which can be important for security updates, compatibility
     * checks, or troubleshooting version-specific issues.
     *
     * @param string $name The name of the web server binary (e.g., 'nginx', 'apache2')
     * @return string The server name with version if available, otherwise just the name
     */
    private function resolveWebServerVersion(string $name): string {

        $versionOutput = $this->safeShell(sprintf('%s -v 2>&1', escapeshellarg($name)));
        if (preg_match('/(\d+\.\d+[\.\d]*)/', $versionOutput, $m)) {
            return sprintf('%s %s', $name, $m[1]);
        }
        return $name;
    }

    /**
     * Reads disk mount information for all physical block devices on the system.
     *
     * This method retrieves disk usage information for all mounted filesystems by
     * executing the df (disk free) command. It filters out temporary and virtual
     * filesystems to focus only on physical storage devices that administrators
     * need to monitor for capacity.
     *
     * Primary method (Linux with modern df):
     * - Executes 'df -B1 --output=source,target,size,used,avail,fstype'
     * - -B1: Display sizes in bytes (1-byte blocks)
     * - --output: Specify which columns to display
     * - Returns device, mount point, total size, used space, available space, and filesystem type
     *
     * Fallback method (macOS or older df):
     * - Executes 'df -k' if the primary command fails
     * - -k: Display sizes in kilobyte blocks
     * - Parses the standard df output format
     *
     * Filtering:
     * - Skips temporary filesystems: tmpfs, devtmpfs, overlay, squashfs
     * - Skips virtual filesystems: udev, none, sysfs, proc
     * - Only includes devices starting with /dev/ (physical block devices)
     * - Skips lines with insufficient data (less than 5 parts)
     *
     * The returned array contains only relevant physical storage mounts, providing
     * clean data for the disk usage display and diagnostic checks.
     *
     * @return array<int, array{device: string, mount: string, total: int, used: int, free: int}>
     *         Array of mount information with device, mount point, and capacity data in bytes
     */
    private function readDiskMounts(): array {

        $output = $this->safeShell('df -B1 --output=source,target,size,used,avail,fstype 2>/dev/null');

        if ($output === '' || $output === 'N/A') {
            $output = $this->safeShell('df -k 2>/dev/null');
        }

        if (empty($output)) {
            return [];
        }

        $mounts     = [];
        $skipFs     = ['tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'udev', 'none', 'sysfs', 'proc'];
        $lines      = explode("\n", trim($output));

        array_shift($lines);

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 5) {
                continue;
            }

            [$device, $mount, $total, $used, $free] = $parts;
            $fstype = $parts[5] ?? '';

            if (in_array($fstype, $skipFs, true) || in_array($device, $skipFs, true)) {
                continue;
            }

            if (!str_starts_with($device, '/dev/')) {
                continue;
            }

            $mounts[] = [
                'device' => $device,
                'mount'  => $mount,
                'total'  => (int) $total,
                'used'   => (int) $used,
                'free'   => (int) $free,
            ];
        }

        return $mounts;
    }

    /**
     * Safely executes a shell command and returns its output with error handling.
     *
     * This method is a wrapper around shell_exec() that provides consistent error
     * handling for all shell command executions throughout the command. It suppresses
     * errors using the @ operator and returns a fallback value when execution fails.
     *
     * Error handling:
     * - Uses the @ operator to suppress PHP errors/warnings from shell_exec()
     * - Checks if the result is a string (successful execution)
     * - Trims whitespace from the output for clean display
     * - Returns 'N/A' if execution fails or returns non-string output
     *
     * This safe execution pattern allows the diagnostic command to continue functioning
     * even when certain system commands are unavailable, fail, or return unexpected
     * output types. It prevents the entire command from failing due to a single
     * unsuccessful shell command.
     *
     * Used throughout the command for all shell command executions including:
     * - uname for kernel information
     * - sysctl for macOS-specific system information
     * - df for disk usage information
     * - uptime for system uptime
     * - Web server version detection
     *
     * @param string $command The shell command to execute
     * @return string The command output trimmed of whitespace, or 'N/A' on failure
     */
    private function safeShell(string $command): string {

        $result = @shell_exec($command);
        return is_string($result) ? trim($result) : 'N/A';
    }

    /**
     * Parses a PHP memory_limit ini value and converts it to megabytes.
     *
     * This method converts PHP memory_limit values from their string representation
     * (e.g., '128M', '2G', '256K') to an integer value in megabytes for comparison
     * against the recommended threshold. PHP's memory_limit setting can be specified
     * with various unit suffixes or as -1 for unlimited.
     *
     * Supported formats:
     * - '-1': Unlimited memory, returns -1 (special value indicating no limit)
     * - 'XG': Gigabytes (e.g., '2G' = 2048 MB)
     * - 'XM': Megabytes (e.g., '128M' = 128 MB)
     * - 'XK': Kilobytes (e.g., '262144K' = 256 MB)
     * - No suffix: Assumed to be in bytes, converted to MB
     *
     * Conversion logic:
     * - Extracts the last character as the unit (uppercase)
     * - Extracts the numeric portion as an integer
     * - Uses a match expression to convert based on unit:
     *   - 'G': Multiply by 1024 to convert GB to MB
     *   - 'M': Return as-is (already in MB)
     *   - 'K': Divide by 1024 to convert KB to MB
     *   - Default: Divide by 1024*1024 to convert bytes to MB
     *
     * This conversion enables comparison against PHP_RECOMMENDED_MEMORY_MB (512MB)
     * to generate appropriate diagnostic notices for insufficient memory limits.
     *
     * @param string $iniValue The memory_limit value from php.ini (e.g., '128M', '2G', '-1')
     * @return int The memory limit in megabytes, or -1 for unlimited
     */
    private function parseMemoryToMb(string $iniValue): int {

        $iniValue = trim($iniValue);

        if ($iniValue === '-1') {
            return -1;
        }

        $unit  = strtoupper(substr($iniValue, -1));
        $value = (int) $iniValue;

        return match ($unit) {
            'G'     => $value * 1024,
            'M'     => $value,
            'K'     => intdiv($value, 1024),
            default => intdiv($value, 1024 * 1024),
        };
    }

    /**
     * Formats a byte count as a human-readable string with appropriate units.
     *
     * This method converts raw byte counts into human-readable format by automatically
     * selecting the most appropriate unit (B, KB, MB, GB, or TB) and formatting
     * the value with appropriate precision. This makes large numbers like disk sizes
     * and memory capacities much easier to read and understand.
     *
     * Formatting logic:
     * - Returns '0 B' for zero or negative values
     * - Calculates the appropriate unit using base-1024 logarithm
     * - Clamps the exponent to prevent array overflow (max TB)
     * - Divides the byte count by 1024^exponent to get the value in the target unit
     * - For MB and larger: Formats with 1 decimal place (e.g., '15.5 GB')
     * - For KB and B: Formats as integer (e.g., '512 KB', '1024 B')
     *
     * Unit thresholds:
     * - 0-1023 bytes: Displayed in bytes (B)
     * - 1024-1048575 bytes: Displayed in kilobytes (KB)
     * - 1048576-1073741823 bytes: Displayed in megabytes (MB)
     * - 1073741824-1099511627775 bytes: Displayed in gigabytes (GB)
     * - 1099511627776+ bytes: Displayed in terabytes (TB)
     *
     * Used throughout the command for displaying disk capacities, memory sizes,
     * and other byte-based metrics in a user-friendly format.
     *
     * @param int $bytes The byte count to format
     * @return string The formatted string with appropriate unit (e.g., '15.5 GB', '512 MB')
     */
    private function formatBytes(int $bytes): string {

        if ($bytes <= 0) {
            return '0 B';
        }

        $units     = ['B', 'KB', 'MB', 'GB', 'TB'];
        $exponent  = (int) floor(log($bytes, 1024));
        $exponent  = min($exponent, count($units) - 1);
        $value     = $bytes / (1024 ** $exponent);

        return sprintf('%s %s', $exponent >= 2 ? number_format($value, 1) : (int) $value, $units[$exponent]);
    }

    /**
     * Builds a visual progress bar representing disk usage percentage.
     *
     * This method creates a text-based progress bar using Unicode block characters
     * to visually represent disk usage percentage. The bar provides an immediate
     * visual indicator of how full a disk is, complementing the numerical percentage.
     *
     * Bar construction:
     * - Fixed width of 20 characters for consistency
     * - Calculates filled portion as (percentage / 100 * width), rounded to nearest integer
     * - Clamps filled value to range [0, width] to prevent overflow or underflow
     * - Uses '█' (full block) character for filled portion
     * - Uses '░' (light shade) character for empty portion
     * - Wraps the bar in square brackets for visual framing
     *
     * Visual examples:
     * - 0%:   [░░░░░░░░░░░░░░░░░░░░]
     * - 25%:  [█████░░░░░░░░░░░░░░░]
     * - 50%:  [███████████░░░░░░░░░]
     * - 75%:  [████████████████░░░░░]
     * - 100%: [████████████████████]
     *
     * The visual bar is displayed alongside the numerical percentage in the disk
     * usage table, providing both precise and intuitive representations of disk capacity.
     *
     * @param int $percent The usage percentage (0-100)
     * @return string The visual progress bar string (20 characters wide)
     */
    private function buildUsageBar(int $percent): string {

        $width   = 20;
        $filled  = (int) round($percent / 100 * $width);
        $filled  = max(0, min($width, $filled));
        $empty   = $width - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
