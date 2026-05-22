# User Manual — `ZyosInstallBundle` Commands

**Bundle:** `ZyosInstallBundle`  
**Framework:** Symfony LTS >=  
**Manual version:** 1.0  
**Language:** English

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Requirements and global configuration](#2-requirements-and-global-configuration)
3. [Command `zyos:install`](#3-command-zyosinstall)
4. [Command `zyos:cli`](#4-command-zyoscli)
5. [Command `zyos:filesystem`](#5-command-zyosfilesystem)
6. [Command `zyos:source`](#6-command-zyossource)
7. [Command `zyos:validate`](#7-command-zyosvalidate)
8. [Error policy reference (`if_error`)](#8-error-policy-reference-if_error)
9. [Available validators reference](#9-available-validators-reference)
10. [Recommended deployment flow](#10-recommended-deployment-flow)
11. [Troubleshooting common issues](#11-troubleshooting-common-issues)

---

## 1. Introduction

### Installation

```sh
composer require zyos/install-bundle
```

If you don't use flex (you should), you need to enable the package manually:

```php
// config/bundles.php
return [
	/** ... **/
	Zyos\InstallBundle\InstallBundle::class => ['all' => true],
];
```

It is necessary to create the configuration file in the path:
```text
config/packages/zyos_install.yaml
```

##

The **ZyosInstallBundle** provides a set of Symfony Console commands (`zyos:*`) that automate deployment and verification tasks for a Symfony application. Each command is executed from the command line using `bin/console` and covers a specific phase of the deployment lifecycle:

| Command           | Purpose                                                    |
|-------------------|------------------------------------------------------------|
| `zyos:install`    | Executes configured Symfony commands in priority order     |
| `zyos:cli`        | Executes configured operating system (shell) commands      |
| `zyos:filesystem` | Creates directories, symbolic links, and directory mirrors |
| `zyos:source`     | Generates a full diagnostic report of the environment      |
| `zyos:validate`   | Validates the existence and permissions of critical paths  |

> **Note:** The `zyos:echo` command is internal and hidden; it is used exclusively for testing the execution pipeline. It does not appear in the public command listing.

---

## 2. Requirements and global configuration

### 2.1 Listing available commands

To verify the bundle is correctly installed, run:

```bash
php bin/console list zyos
```

**Expected output:**

```
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  Available commands for the "zyos" namespace:                           │
│                                                                         │
│    zyos:cli         Executes CLI commands defined in bundle config.     │
│    zyos:filesystem  Run directory creation, symlink and mirroring.      │
│    zyos:install     Executes configured Symfony commands for env.       │
│    zyos:source      Full diagnostic report: bundle, PHP, server.        │
│    zyos:validate    Validates configured files, dirs and paths.         │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Main configuration file

All commands read their configuration from `config/packages/zyos_install.yaml`. The general structure is:

```yaml
zyos_install:
    # Path where the bundle stores its internal resources
    path: '%kernel.project_dir%/src/Resources/zyos-install-bundle'

    # Environments in which the bundle is active (default: dev, prod)
    environments: ['dev', 'prod']

    # Environments that get locked after a successful install
    locks: ['prod']

    # Symfony commands to run with zyos:install
    install:
        - command: 'assets:install'
          arguments: { --symlink: true, --relative: true }
          environments: ['dev', 'prod']

    # Operating system commands to run with zyos:cli
    cli:
        - command: ['mkdir', '-p', '/path/to/directory']
          environments: ['dev', 'prod']
          enable: true
          if_error: 'stop'
          priority: 1

    # Filesystem operations for zyos:filesystem
    filesystem:
        - source: '%kernel.project_dir%/private'
          type: directory
          environments: ['dev', 'prod']

    # Paths to validate with zyos:validate
    validate:
        - filepath: '%kernel.project_dir%/config/jwt'
          type: directory
          environments: ['dev', 'prod']
          validations:
              - exists
              - is_dir
              - is_readable
              - is_writable
```

### 2.3 The `{{ env }}` placeholder

Inside command arguments, you can use the `{{ env }}` placeholder to have the bundle automatically insert the current environment name. Standard Symfony parameters such as `%kernel.project_dir%` are also supported.

---

## 3. Command `zyos:install`

### 3.1 Description

Executes in priority order the Symfony commands configured under the `install` key of `zyos_install.yaml`. This is the central command of a deployment process: once all commands finish successfully, it creates a **lock file** that prevents accidental re-execution.

### 3.2 Syntax

```bash
php bin/console zyos:install <environment> [options]
```

| Element         | Type     | Required | Description                                                     |
|-----------------|----------|----------|-----------------------------------------------------------------|
| `<environment>` | Argument | Yes      | Name of the target environment (`dev`, `prod`, `staging`, etc.) |
| `--show-output` | Option   | No       | Streams each executed command's output to the console           |

### 3.3 Usage examples

```bash
# Run installation in production
php bin/console zyos:install prod

# Run in development showing the full output of each sub-command
php bin/console zyos:install dev --show-output
```

### 3.4 Terminal preview

```
 Install Command [zyos:install] [prod]
========================================

 Executes deployment commands configured for this environment in priority order.
 On full success a lock file is created to prevent accidental re-installation.
 Use --show-output to stream each command's stdout/stderr to the console.

 Commands to execute: 3

  - Success Execute Command [ Priority: 1 ] [ assets:install ]
  - Success Execute Command [ Priority: 2 ] [ zyos:filesystem ]
  - Success Execute Command [ Priority: 3 ] [ zyos:cli ]


 [OK] All commands executed successfully. Lock file created.
```

**On error:**

```
  - Success Execute Command [ Priority: 1 ] [ assets:install ]
  - Error   Execute Command [ Priority: 2 ] [ doctrine:migrations:migrate ] Exit Code: 1
  - [ Skipped ] Execute Command [ Priority: 3 ] [ zyos:cli ] (blocked by previous exit code: 1)

 [ERROR] One or more commands failed. Lock file was NOT created.
         Fix the issues above and re-run.
```

### 3.5 Configuring `install` entries

```yaml
zyos_install:
    install:
        -   command: 'assets:install'
            arguments:
                --symlink: true
                --relative: true
            environments:
                - 'dev'
                - 'prod'
            enable: true          # If false, the command is skipped
            if_error: 'stop'      # Error policy: none | stop | default
            priority: 1           # Lower number = higher priority
```

| Key            | Description                                                                 |
|----------------|-----------------------------------------------------------------------------|
| `command`      | Name of the Symfony command to execute                                      |
| `arguments`    | Arguments and options to pass to the command                                |
| `environments` | Environments where this entry applies                                       |
| `enable`       | `true` to execute, `false` to skip                                          |
| `if_error`     | Error handling policy (see [section 8](#8-error-policy-reference-if_error)) |
| `priority`     | Execution order; lower values run first                                     |

### 3.6 Lock file mechanism

- After a **successful** `zyos:install`, a lock file specific to the environment is created.
- If the lock file already exists, the command refuses to run to protect the environment.
- To run the install again, you must **manually remove** the lock file.

> **Warning:** Never delete the production lock file without first verifying the application state. The lock file is a safeguard against accidental duplicate installations.

---

## 4. Command `zyos:cli`

### 4.1 Description

Executes operating system (shell) commands configured under the `cli` key of `zyos_install.yaml`. Unlike `zyos:install`, this command runs system binaries (such as `mkdir`, `chmod`, `composer`, bash scripts, etc.) instead of Symfony commands.

> **Important:** This command **requires the lock file** for the corresponding environment to exist. You must run `zyos:install` before `zyos:cli`.

### 4.2 Syntax

```bash
php bin/console zyos:cli <environment> [options]
```

| Element         | Type     | Required | Description                                                  |
|-----------------|----------|----------|--------------------------------------------------------------|
| `<environment>` | Argument | Yes      | Name of the target environment                               |
| `--show-output` | Option   | No       | Streams real-time `stdout`/`stderr` output from each process |

### 4.3 Usage examples

```bash
# Run CLI commands for production
php bin/console zyos:cli prod

# See each process output in real time
php bin/console zyos:cli dev --show-output
```

### 4.4 Terminal preview

```
 CLI Command [zyos:cli] [prod]
================================

 Executes supplementary CLI commands configured for this environment.
 Commands run in priority order and respect the configured error policy.

  - Done    Execute Command [ Priority: 1  ] [ mkdir -p /var/www/app/public/test1 ]
  - Done    Execute Command [ Priority: 2  ] [ mkdir -p /var/www/app/public/test2 ]
  - Done    Execute Command [ Priority: 100] [ ls -al ]
```

**With `--show-output`:**

```
  - Running Execute Command [ Priority: 1 ] [ mkdir -p /var/www/app/public/test1 ]
OUT > (no output)
  - Done    Execute Command [ Priority: 1 ] [ mkdir -p /var/www/app/public/test1 ]

  - Running Execute Command [ Priority: 100 ] [ ls -al ]
OUT > total 48
OUT > drwxr-xr-x 2 www-data www-data 4096 May 22 12:30 .
OUT > drwxr-xr-x 8 www-data www-data 4096 May 22 12:30 ..
  - Done    Execute Command [ Priority: 100 ] [ ls -al ]
```

### 4.5 Configuring `cli` entries

```yaml
zyos_install:
    cli:
        -   command: ['mkdir', '-p', '/var/www/app/storage']
            environments: ['dev', 'prod']
            enable: true
            if_error: 'stop'
            priority: 1

        -   command: ['chmod', '775', '/var/www/app/storage']
            environments: ['prod']
            enable: true
            if_error: 'none'       # Ignore error if chmod fails
            priority: 2

        -   command: ['/usr/local/bin/composer', 'dump-autoload', '--optimize']
            environments: ['prod']
            enable: true
            if_error: 'stop'
            priority: 10
```

| Key            | Description                                                        |
|----------------|--------------------------------------------------------------------|
| `command`      | Array of command tokens. The first element is the binary           |
| `environments` | Environments where this entry applies                              |
| `enable`       | `true` activates the entry; `false` skips it                       |
| `if_error`     | Error policy (see [section 8](#8-error-policy-reference-if_error)) |
| `priority`     | Execution order; lower number = higher priority                    |

> **Note:** The command is defined as a **token array** (not a string). This is equivalent to calling `Process(['mkdir', '-p', '/path'])` from the Symfony Process component, preventing shell injection vulnerabilities.

---

## 5. Command `zyos:filesystem`

### 5.1 Description

Performs filesystem operations: creates directories, creates symbolic links, and mirrors (recursive copy) directories. All operations are configured under the `filesystem` key of `zyos_install.yaml`.

> **Important:** Like `zyos:cli`, this command **requires the lock file** for the environment to exist.

### 5.2 Syntax

```bash
php bin/console zyos:filesystem <environment> [options]
```

| Element         | Type     | Required | Description                                                       |
|-----------------|----------|----------|-------------------------------------------------------------------|
| `<environment>` | Argument | Yes      | Name of the target environment                                    |
| `--mirror`      | Option   | No       | Runs **only** `mirror` type operations                            |
| `--symlink`     | Option   | No       | Runs **only** `symlink` type operations                           |
| `--directory`   | Option   | No       | Runs **only** `directory` type operations                         |
| `--show-output` | Option   | No       | Prints details for each operation (path, type, permissions, etc.) |

### 5.3 Usage examples

```bash
# Run all filesystem operations for production
php bin/console zyos:filesystem prod

# Create only directories (skip symlinks and mirrors)
php bin/console zyos:filesystem prod --directory

# Create only symbolic links with detailed output
php bin/console zyos:filesystem prod --symlink --show-output

# Run only directory mirroring
php bin/console zyos:filesystem prod --mirror
```

### 5.4 Terminal preview

```
 Filesystem Command [zyos:filesystem] [prod]
=============================================

 This process handles the creation of directories, symbolic links,
 and directory mirrors required for deploying the application.

  - Done    Create Directory  [ Priority: 1 ] [ /var/www/app/private ]
  - Done    Create Directory  [ Priority: 1 ] [ /var/www/app/private/checks ]
  - Done    Create Directory  [ Priority: 1 ] [ /var/www/app/private/ticket ]
  - Done    Create Symlink    [ Priority: 3 ] [ /var/www/app/public/reports ]
```

**With `--show-output`:**

```
  - Done    Create Directory  [ Priority: 1 ] [ /var/www/app/private ]

  ┌─────────────────┬───────────────────────┐
  │ Operation type  │ Directory             │
  │ Priority        │ 1                     │
  │ Environments    │ [dev, prod]           │
  │ Path            │ /var/www/app/private  │
  └─────────────────┴───────────────────────┘
```

**On error:**

```
  - Error   Create Symlink    [ Priority: 3 ] [ /var/www/app/public/reports ]
            The link "/var/www/app/public/reports" already exists and is not a symlink.
```

### 5.5 Operation types

| Type        | `source`         | `destination`         | Description                                           |
|-------------|------------------|-----------------------|-------------------------------------------------------|
| `directory` | Path to create   | Not applicable        | Creates the directory specified in `source`           |
| `symlink`   | Link target path | Link path             | Creates a symbolic link from `destination` → `source` |
| `mirror`    | Source directory | Destination directory | Recursively copies `source` to `destination`          |

### 5.6 Configuring `filesystem` entries

```yaml
zyos_install:
    filesystem:
        # Create a directory
        -   source: '%kernel.project_dir%/private'
            type: directory
            environments: ['dev', 'prod']
            enable: true
            if_error: 'stop'
            priority: 1

        # Create a symbolic link: /public/reports → /private/reports
        -   source: '%kernel.project_dir%/private/reports'
            destination: '%kernel.project_dir%/public/reports'
            type: symlink
            environments: ['dev', 'prod']
            enable: true
            if_error: 'none'
            priority: 3

        # Mirror a directory
        -   source: '%kernel.project_dir%/templates/default'
            destination: '%kernel.project_dir%/public/assets/default'
            type: mirror
            environments: ['prod']
            enable: true
            if_error: 'stop'
            priority: 5
```

> **Warning:** For `symlink` and `mirror` operations, both `source` and `destination` are **required**. If either is missing, the command will throw a configuration error before executing any operation.

---

## 6. Command `zyos:source`

### 6.1 Description

Generates a **full diagnostic report** of the runtime environment. It requires no arguments or application changes: it simply gathers information about the bundle, Symfony, PHP, and the server, and presents it in structured tables with visual status indicators.

This command **always returns exit code 0** (success), as it is purely informational.

### 6.2 Syntax

```bash
php bin/console zyos:source
```

No additional arguments or options are accepted.

### 6.3 Terminal preview

```
 Source Command [zyos:source]
==============================
 Bundle configuration · Symfony application · PHP runtime · server environment.


 Bundle configuration
 ┌──────────────────────┬──────────────────────────────────────────────────┐
 │ Parameter            │ Value                                            │
 ├──────────────────────┼──────────────────────────────────────────────────┤
 │ Path                 │ /var/www/app/src/Resources/zyos-install-bundle   │
 │ Lockfile             │ /var/www/app/var/zyos-install.lock               │
 │ Environments         │ dev, prod                                        │
 │ Lock environments    │ prod                                             │
 │ Install entries      │ 3                                                │
 │ Validate entries     │ 12                                               │
 │ Filesystem entries   │ 9                                                │
 │ CLI entries          │ 3                                                │
 ├──────────────────────┼──────────────────────────────────────────────────┤
 │ Path exists          │ ✔ yes                                            │
 │ Lockfile exists      │ ✘ no (not yet installed)                         │
 └──────────────────────┴──────────────────────────────────────────────────┘

 Symfony application
 ┌──────────────────┬──────────────────────────────────────────┐
 │ Property         │ Value                                    │
 ├──────────────────┼──────────────────────────────────────────┤
 │ Symfony version  │ 8.0.11                                   │
 │ Environment      │ prod                                     │
 │ Debug mode       │ ✘ disabled                               │
 │ Kernel class     │ App\Kernel                               │
 ├──────────────────┼──────────────────────────────────────────┤
 │ Project dir      │ /var/www/app                             │
 │ Cache dir        │ /var/www/app/var/cache/prod              │
 │ Log dir          │ /var/www/app/var/log                     │
 │ Charset          │ UTF-8                                    │
 └──────────────────┴──────────────────────────────────────────┘

 PHP runtime
 ┌──────────────────────┬─────────────────────┐
 │ Property             │ Value               │
 ├──────────────────────┼─────────────────────┤
 │ PHP version          │ 8.3.6               │
 │ SAPI                 │ cli                 │
 │ Architecture         │ 64-bit              │
 ├──────────────────────┼─────────────────────┤
 │ memory_limit         │ 512M                │
 │ max_execution_time   │ 0s                  │
 │ upload_max_filesize  │ 8M                  │
 │ post_max_size        │ 8M                  │
 │ date.timezone        │ America/New_York    │
 ├──────────────────────┼─────────────────────┤
 │ OPcache              │ ✔ enabled           │
 │ JIT                  │ ✔ enabled           │
 │ Xdebug               │ ✘ not loaded        │
 └──────────────────────┴─────────────────────┘

 Server
 ┌─────────────┬──────────────────────────────────────┐
 │ Property    │ Value                                │
 ├─────────────┼──────────────────────────────────────┤
 │ Hostname    │ web-server-01                        │
 │ OS          │ Ubuntu 22.04.4 LTS                   │
 │ Kernel      │ 6.8.0-111-generic                    │
 │ Uptime      │ 12 days 08:45 h                      │
 ├─────────────┼──────────────────────────────────────┤
 │ CPU model   │ Intel(R) Xeon(R) CPU E5-2680 v4      │
 │ CPU cores   │ 4 physical · 8 logical               │
 ├─────────────┼──────────────────────────────────────┤
 │ RAM total   │ 16.0 GB                              │
 │ RAM used    │ 6.2 GB (39%)                         │
 │ RAM free    │ 9.8 GB                               │
 │ Swap total  │ 2.0 GB                               │
 │ Swap used   │ 0 B                                  │
 ├─────────────┼──────────────────────────────────────┤
 │ Web server  │ nginx 1.24.0                         │
 └─────────────┴──────────────────────────────────────┘

 Disk usage
 ┌────────────┬───────┬────────┬────────┬────────┬──────────────────────────┐
 │ Device     │ Mount │ Total  │ Used   │ Free   │ Usage                    │
 ├────────────┼───────┼────────┼────────┼────────┼──────────────────────────┤
 │ /dev/sda1  │ /     │ 99.9 G │ 41.2 G │ 58.7 G │ [████████░░░░░░░░░░░░] 41% │
 └────────────┴───────┴────────┴────────┴────────┴──────────────────────────┘

 Diagnostics
  ✔  All configuration and environment checks passed.
```

### 6.4 Status indicators

| Symbol | Color  | Meaning                  |
|--------|--------|--------------------------|
| `✔`    | Green  | Correct or optimal state |
| `⚠`    | Yellow | Warning — review soon    |
| `✘`    | Red    | Error or critical state  |

### 6.5 Automatic diagnostic thresholds

The command automatically evaluates these conditions and reports them in the **Diagnostics** section:

| Condition                               | Level          |
|-----------------------------------------|----------------|
| Bundle path does not exist on disk      | `warning`      |
| PHP `memory_limit` < 512 MB             | `warning`      |
| OPcache disabled                        | `warning`      |
| Xdebug loaded in `prod` environment     | `warning`      |
| Debug mode active in `prod` environment | `warning`      |
| RAM usage ≥ 90%                         | `warning`      |
| Swap usage ≥ 50%                        | `warning`      |
| Disk usage ≥ 75%                        | `warning`      |
| Disk usage ≥ 90%                        | **`critical`** |

---

## 7. Command `zyos:validate`

### 7.1 Description

Verifies that critical files, directories, and paths exist and meet the configured permission requirements. It runs individual validators against each path and presents a detailed report with the status of each check.

The command returns **exit code 0** if all validations pass, or **exit code 1** if any validation fails.

### 7.2 Syntax

```bash
php bin/console zyos:validate <environment> [options]
```

| Element         | Type     | Required | Description                         |
|-----------------|----------|----------|-------------------------------------|
| `<environment>` | Argument | Yes      | Name of the environment to validate |
| `--only-errors` | Option   | No       | Shows only entries with failures    |

### 7.3 Usage examples

```bash
# Validate all configured paths for production
php bin/console zyos:validate prod

# Validate showing only failures (useful in CI/CD)
php bin/console zyos:validate prod --only-errors

# Validate the development environment
php bin/console zyos:validate dev
```

### 7.4 Terminal preview

```
 Validate Command [zyos:validate] [prod]
=========================================

 Runs configured validators against declared paths and reports results.
 Use --only-errors to suppress passing entries and focus on failures.

 Entries to process: 3  |  Environment: prod

 ┌──────────┬──────────┐
 │ entry 1/3│  PASSED  │
 ├──────────┴──────────┤
 │    /var/www/app/config/jwt    │
 ├─────────────────────┬─────────┤
 │ Type:               │ directory │
 │ Last modified:      │ 2026-05-20 14:32:11 │
 │ Permissions (octal):│ 0755 │
 │ Permissions (string)│ drwxr-xr-x │
 │ Environments:       │ dev, prod │
 ├─────────────────────┴──────────┤
 │ Validations                    │
 ├────────────────────┬───────────┤
 │ ✔ exists           │  SUCCESS  │
 │ ✔ is_dir           │  SUCCESS  │
 │ ✔ is_readable      │  SUCCESS  │
 │ ✔ is_writable      │  SUCCESS  │
 └────────────────────┴───────────┘


 ┌──────────┬──────────────┐
 │ entry 2/3│   FAILED     │
 ├──────────┴──────────────┤
 │  /var/www/app/config/jwt/private.pem  │
 ├───────────────────────┬───────────────┤
 │ Type:                 │ file          │
 │ Last modified:        │ NOT AVAILABLE │
 │ Permissions (octal):  │ NOT AVAILABLE │
 │ Permissions (string): │ NOT AVAILABLE │
 │ Environments:         │ dev, prod     │
 ├───────────────────────┴───────────────┤
 │ Validations                           │
 ├───────────────────────┬───────────────┤
 │ ✘ exists              │    FAILED     │
 │ ✘ is_file             │    FAILED     │
 │ ✘ is_readable         │    FAILED     │
 └───────────────────────┴───────────────┘


Validation summary
────────────────────────────────────────────────────
  ✔  Passed            1
  ✘  Failed            1
  ⚠  Not available     1
────────────────────────────────────────────────────
  Paths requiring attention:
    ✘  /var/www/app/config/jwt/private.pem
    ⚠  /var/www/app/config/jwt/public.pem
────────────────────────────────────────────────────

 [ERROR] 2 path(s) require attention.
```

### 7.5 Result meanings

| Badge                    | Icon | Description                                       |
|--------------------------|------|---------------------------------------------------|
| `PASSED` (green)         | `✔`  | All validators for that entry passed successfully |
| `FAILED` (red)           | `✘`  | One or more validators failed                     |
| `NOT AVAILABLE` (yellow) | `⚠`  | The file or directory does not exist on disk      |

### 7.6 Configuring `validate` entries

```yaml
zyos_install:
    validate:
        -   filepath: '%kernel.project_dir%/config/jwt'
            type: directory
            environments:
                - 'dev'
                - 'prod'
            enable: true
            validations:
                - exists
                - is_dir
                - is_readable
                - is_writable

        -   filepath: '%kernel.project_dir%/config/jwt/private.pem'
            type: file
            environments:
                - 'prod'
            enable: true
            validations:
                - exists
                - is_file
                - is_readable
```

| Key            | Description                                               |
|----------------|-----------------------------------------------------------|
| `filepath`     | Path to validate; supports Symfony parameter placeholders |
| `type`         | Expected type: `file` or `directory`                      |
| `environments` | Environments where this validation applies                |
| `enable`       | `true` activates the entry; `false` skips it              |
| `validations`  | List of validators to run against the path                |

---

## 8. Error policy reference (`if_error`)

The `if_error` key controls what happens when a command or operation fails. It applies to the `zyos:install`, `zyos:cli`, and `zyos:filesystem` commands.

| Value     | Behavior                                                                                                                                                      |
|-----------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `none`    | **Ignores the error.** The command is marked as successful and the pipeline continues executing subsequent commands normally. Useful for optional operations. |
| `stop`    | **Stops the pipeline.** When the command fails, all subsequent commands are marked as **Skipped** and are not executed. The final result is `FAILURE`.        |
| `default` | **Standard behavior.** Exit code 0 → `SUCCESS`; any other code → `FAILURE`. The pipeline may or may not continue depending on the configuration.              |

**Visual example with `stop`:**

```
  - Success Execute Command [ Priority: 1 ] [ assets:install ]
  - Error   Execute Command [ Priority: 2 ] [ doctrine:migrations:migrate ] Exit Code: 1
  - [ Skipped ] Execute Command [ Priority: 3 ] [ cache:clear ] (blocked by previous exit code: 1)
  - [ Skipped ] Execute Command [ Priority: 4 ] [ zyos:cli ] (blocked by previous exit code: 1)
```

> **Warning:** Configuring `if_error: none` on critical commands (such as database migrations) can hide serious errors. Use it only for genuinely optional operations.

---

## 9. Available validators reference

The following validators can be used in the `validations` key of `zyos:validate` entries:

| Validator          | Description                                       |
|--------------------|---------------------------------------------------|
| `exists`           | Verifies that the path exists in the filesystem   |
| `is_dir`           | Verifies that the path is a directory             |
| `is_file`          | Verifies that the path is a regular file          |
| `is_readable`      | Verifies that the path has read permissions       |
| `is_writable`      | Verifies that the path has write permissions      |
| `is_executable`    | Verifies that the path has execute permissions    |
| `is_link`          | Verifies that the path is a symbolic link         |
| `is_absolute_path` | Verifies that the path is absolute (not relative) |
| `filepath_perms`   | Verifies specific permissions on the path         |

> **Note:** If a validator name is not registered in the bundle, the command will display the `⚠` icon and the message `validator not found` next to that validator, indicating a configuration error.

---

## 10. Recommended deployment flow

The recommended order for running the commands in a production deployment is as follows:

```
┌──────────────────────────────────────────────────────┐
│              ZYOS DEPLOYMENT FLOW                    │
└──────────────────────────────────────────────────────┘

  STEP 1 — Pre-deployment diagnostics (optional but recommended)
  ┌─────────────────────────────────────────────────┐
  │  php bin/console zyos:source                    │
  │  → Verify PHP, OPcache and disk are healthy     │
  └─────────────────────────────────────────────────┘

  STEP 2 — Main installation
  ┌─────────────────────────────────────────────────┐
  │  php bin/console zyos:install prod              │
  │  → Runs Symfony commands (assets, migrations)   │
  │  → Internally calls zyos:filesystem & zyos:cli  │
  │  → Creates the lock file if everything succeeds │
  └─────────────────────────────────────────────────┘

  STEP 3 — Post-installation validation
  ┌─────────────────────────────────────────────────┐
  │  php bin/console zyos:validate prod             │
  │  → Confirms directories and files exist         │
  │  → Verifies correct permissions                 │
  │  → Returns exit code 1 on failure (CI/CD)       │
  └─────────────────────────────────────────────────┘
```

**Full bash deployment script:**

```bash
#!/bin/bash
set -e   # Stop the script if any command fails

echo "=== Starting deployment ==="

# Step 1: Check environment
php bin/console zyos:source

# Step 2: Run installation
php bin/console zyos:install prod

# Step 3: Validate result
php bin/console zyos:validate prod --only-errors

echo "=== Deployment completed successfully ==="
```

---

## 11. Troubleshooting common issues

### Error: "Environment [prod] is not configured"

**Cause:** The specified environment is not in the `environments` list of `zyos_install.yaml`.  
**Solution:** Add the environment to the configuration:

```yaml
zyos_install:
    environments: ['dev', 'prod', 'staging']  # Add 'staging'
```

---

### Error: "Lock file already exists for environment [prod]"

**Cause:** The `zyos:install prod` command was already executed successfully and created a lock file.  
**Solution:** Delete the lock file manually and run again:

```bash
# Locate the lock file (check the 'lockfile' key in zyos:source output)
rm /var/www/app/var/zyos-install.prod.lock

# Run again
php bin/console zyos:install prod
```

> **Warning:** In production, make sure you truly want to re-run the install before deleting the lock file.

---

### Error: "Lock file does not exist for environment [prod]" (in `zyos:cli` or `zyos:filesystem`)

**Cause:** The `zyos:cli` and `zyos:filesystem` commands require `zyos:install` to have been run first.  
**Solution:** Run `zyos:install prod` before using the other commands directly.

---

### Error: "Configuration key zyos_install.install is missing"

**Cause:** The `config/packages/zyos_install.yaml` file does not exist or has a syntax error.  
**Solution:**

```bash
# Check the YAML syntax
php bin/console debug:config zyos_install

# If the file does not exist, create it with the minimum structure:
```

```yaml
zyos_install:
    environments: ['dev', 'prod']
    locks: ['prod']
    install: []
    cli: []
    filesystem: []
    validate: []
```

---

### Commands marked as `Skipped`

**Cause:** A previous command in the pipeline failed with `if_error: stop` policy.  
**Solution:**

1. Identify the command that showed `Error` in the output.
2. Run that command in isolation with `--show-output` to see the full error:

```bash
php bin/console zyos:install prod --show-output
```

3. Fix the problem and run again.

---

### Validation shows `NOT AVAILABLE` for all paths

**Cause:** The `zyos:filesystem prod` command has not been run yet, so the directories do not exist.  
**Solution:** Run the full flow in order:

```bash
php bin/console zyos:install prod
php bin/console zyos:validate prod
```

---
