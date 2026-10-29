<?php

/*
 * Work Schedule Manager
 * Copyright (C) 2026 QvarcY
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Additional terms: see ADDITIONAL_TERMS.md
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\MigrationRunner;
use App\Services\ModuleManager;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Installer can only be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);

require $root . '/app/autoload.php';

$options = getopt('', [
    'check',
    'yes',
    'admin-user:',
    'admin-email:',
    'help',
]);

if (isset($options['help'])) {
    echo <<<TXT
Work Schedule Manager installer

Usage:
  php tools/install.php --check
  php tools/install.php
  php tools/install.php --yes --admin-user=admin
  php tools/install.php --yes --admin-user=admin --admin-email=admin@example.com

Options:
  --check          Validate PHP, extensions, manifests and SQL only.
                   No database connection is made.

  --yes            Skip the final confirmation prompt.

  --admin-user     Initial administrator username.
                   If omitted, the installer asks interactively.

  --admin-email    Optional initial administrator email address.

  --help           Show this help.

The administrator password is generated securely by the installer
and displayed once after a successful installation.

If ADMIN_PASSWORD is set in the environment, that value is used
instead and is not printed. It must be 12-200 characters.

TXT;

    exit(0);
}

function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function prompt(string $message, string $default = ''): string
{
    $suffix = $default !== ''
        ? " [{$default}]"
        : '';

    echo $message . $suffix . ': ';

    $value = fgets(STDIN);

    if ($value === false) {
        return $default;
    }

    $value = trim($value);

    return $value !== ''
        ? $value
        : $default;
}

function confirm(string $message): bool
{
    $answer = strtolower(
        prompt($message . ' [y/N]')
    );

    return in_array(
        $answer,
        ['y', 'yes'],
        true
    );
}

function tableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );

    $statement->execute([$table]);

    return (int) $statement->fetchColumn() > 0;
}

function databaseTables(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT TABLE_NAME
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME'
    );

    return array_map(
        'strval',
        $statement->fetchAll(PDO::FETCH_COLUMN)
    );
}

function migrationApplied(
    PDO $pdo,
    string $migration
): bool {
    if (!tableExists($pdo, 'schema_migrations')) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM schema_migrations
         WHERE migration = ?'
    );

    $statement->execute([$migration]);

    return (int) $statement->fetchColumn() > 0;
}

function markMigration(
    PDO $pdo,
    string $migration
): void {
    if (!tableExists($pdo, 'schema_migrations')) {
        throw new RuntimeException(
            'schema_migrations table does not exist.'
        );
    }

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO schema_migrations
            (migration, applied_at)
         VALUES (?, NOW())'
    );

    $statement->execute([$migration]);
}

function generatedPassword(): string
{
    return rtrim(
        strtr(
            base64_encode(random_bytes(18)),
            '+/',
            '-_'
        ),
        '='
    );
}

function validateUsername(string $username): void
{
    if (
        strlen($username) < 3
        || strlen($username) > 100
        || preg_match(
            '/^[A-Za-z0-9._-]+$/',
            $username
        ) !== 1
    ) {
        fail(
            'Administrator username must be 3-100 characters '
            . 'and may contain letters, numbers, ".", "_" and "-".'
        );
    }
}

function validateEmail(string $email): void
{
    if (
        $email !== ''
        && filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        fail('Administrator email address is not valid.');
    }
}

function manifestFiles(string $root): array
{
    $files = glob(
        $root . '/modules/*/module.json'
    ) ?: [];

    sort($files);

    return $files;
}

function migrationFiles(string $root): array
{
    $files = array_merge(
        glob(
            $root
            . '/database/migrations/*.sql'
        ) ?: [],
        glob(
            $root
            . '/modules/*/migrations/*.sql'
        ) ?: []
    );

    sort($files);

    return $files;
}

function relativePath(
    string $root,
    string $path
): string {
    $root = rtrim(
        str_replace('\\', '/', $root),
        '/'
    ) . '/';

    $path = str_replace(
        '\\',
        '/',
        $path
    );

    if (str_starts_with($path, $root)) {
        return substr(
            $path,
            strlen($root)
        );
    }

    return $path;
}


/*
|--------------------------------------------------------------------------
| Preflight
|--------------------------------------------------------------------------
*/

line();
line('Work Schedule Manager installer');
line('================================');
line();

line('Preflight checks:');

if (
    version_compare(
        PHP_VERSION,
        '8.1.0',
        '<'
    )
) {
    fail(
        'PHP 8.1 or newer is required. '
        . 'Detected: '
        . PHP_VERSION
    );
}

line('  OK PHP ' . PHP_VERSION);

$requiredExtensions = [
    'pdo',
    'pdo_mysql',
    'mbstring',
];

foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        fail(
            'Required PHP extension is missing: '
            . $extension
        );
    }

    line(
        '  OK extension: '
        . $extension
    );
}

if (extension_loaded('zip')) {
    line('  OK extension: zip');
} else {
    line(
        '  WARN extension: zip is not loaded; '
        . 'module ZIP upload will be unavailable.'
    );
}

$requiredFiles = [
    $root . '/app/autoload.php',
    $root . '/database/migrations/001_core_schema.sql',
    $root . '/database/migrations/002_core_seed.sql',
    $root . '/.env.example',
];

foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        fail(
            'Required project file is missing: '
            . relativePath(
                $root,
                $file
            )
        );
    }
}

line('  OK required project files');

$manifests = manifestFiles($root);

if ($manifests === []) {
    fail('No bundled module manifests were found.');
}

foreach ($manifests as $manifestFile) {
    $manifest = json_decode(
        (string) file_get_contents(
            $manifestFile
        ),
        true
    );

    if (
        !is_array($manifest)
        || empty($manifest['name'])
        || empty($manifest['version'])
    ) {
        fail(
            'Invalid module manifest: '
            . relativePath(
                $root,
                $manifestFile
            )
        );
    }
}

line(
    '  OK module manifests: '
    . count($manifests)
);

$migrationFiles = migrationFiles($root);
$totalStatements = 0;

foreach ($migrationFiles as $migrationFile) {
    $sql = file_get_contents(
        $migrationFile
    );

    if ($sql === false) {
        fail(
            'Unable to read migration: '
            . relativePath(
                $root,
                $migrationFile
            )
        );
    }

    try {
        $statements =
            MigrationRunner::statements(
                $sql
            );
    } catch (Throwable $exception) {
        fail(
            'Migration parser failed for '
            . relativePath(
                $root,
                $migrationFile
            )
            . ': '
            . $exception->getMessage()
        );
    }

    $totalStatements +=
        count($statements);
}

line(
    '  OK SQL migrations: '
    . count($migrationFiles)
    . ' file(s), '
    . $totalStatements
    . ' statement(s)'
);

if (isset($options['check'])) {
    line();
    line(
        'CHECK OK - no database connection was made.'
    );
    exit(0);
}


/*
|--------------------------------------------------------------------------
| Environment
|--------------------------------------------------------------------------
*/

$envPath = $root . '/.env';

if (!is_file($envPath)) {
    fail(
        '.env does not exist. '
        . 'Copy .env.example to .env, '
        . 'configure the database connection, '
        . 'then run the installer again.'
    );
}

Env::load($envPath);

$databaseName = trim(
    (string) Env::get(
        'DB_DATABASE',
        ''
    )
);

$databaseUser = trim(
    (string) Env::get(
        'DB_USERNAME',
        ''
    )
);

if ($databaseName === '') {
    fail(
        'DB_DATABASE is missing from .env.'
    );
}

if ($databaseUser === '') {
    fail(
        'DB_USERNAME is missing from .env.'
    );
}

line();
line('Configuration:');
line(
    '  Database: '
    . $databaseName
);
line(
    '  Host: '
    . (string) Env::get(
        'DB_HOST',
        '127.0.0.1'
    )
    . ':'
    . (string) Env::get(
        'DB_PORT',
        '3306'
    )
);


/*
|--------------------------------------------------------------------------
| Database safety check
|--------------------------------------------------------------------------
*/

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fail(
        'Database connection failed: '
        . $exception->getMessage()
    );
}

line('  Database connection: OK');

$tables = databaseTables($pdo);

if (
    $tables !== []
    && !in_array(
        'schema_migrations',
        $tables,
        true
    )
) {
    fail(
        'The selected database is not empty '
        . 'and does not contain the '
        . 'Work Schedule Manager installation marker. '
        . 'Use a new empty database.'
    );
}

if (
    tableExists($pdo, 'users')
) {
    $existingUsers = (int) $pdo
        ->query(
            'SELECT COUNT(*) FROM users'
        )
        ->fetchColumn();

    if ($existingUsers > 0) {
        fail(
            'The selected database already contains '
            . $existingUsers
            . ' user account(s). '
            . 'This installer is for fresh installations only.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Administrator setup
|--------------------------------------------------------------------------
*/

$adminUsername = trim(
    (string) (
        $options['admin-user']
        ?? ''
    )
);

if ($adminUsername === '') {
    $adminUsername = prompt(
        'Initial administrator username',
        'admin'
    );
}

validateUsername(
    $adminUsername
);

$adminEmail = trim(
    (string) (
        $options['admin-email']
        ?? ''
    )
);

if (
    $adminEmail === ''
    && !isset($options['yes'])
) {
    $adminEmail = prompt(
        'Administrator email (optional)'
    );
}

validateEmail(
    $adminEmail
);

line();
line('Installation plan:');
line('  Core database schema');
line('  Default shift types');
line('  Roles and permissions');
line(
    '  Bundled modules: '
    . count($manifests)
);
line(
    '  Initial administrator: '
    . $adminUsername
);

if (!isset($options['yes'])) {
    line();

    if (
        !confirm(
            'Continue with installation?'
        )
    ) {
        line('Installation cancelled.');
        exit(0);
    }
}


/*
|--------------------------------------------------------------------------
| Core migrations
|--------------------------------------------------------------------------
*/

line();
line('Installing core database...');

$coreFiles = glob(
    $root
    . '/database/migrations/*.sql'
) ?: [];

sort($coreFiles);

foreach ($coreFiles as $file) {
    $migrationKey =
        'core/'
        . basename($file);

    if (
        migrationApplied(
            $pdo,
            $migrationKey
        )
    ) {
        line(
            '  SKIP '
            . basename($file)
        );
        continue;
    }

    try {
        $count =
            MigrationRunner::runFile(
                $pdo,
                $file
            );

        markMigration(
            $pdo,
            $migrationKey
        );
    } catch (Throwable $exception) {
        fail(
            'Core migration failed: '
            . basename($file)
            . ' - '
            . $exception->getMessage()
        );
    }

    line(
        '  OK '
        . basename($file)
        . ' ('
        . $count
        . ' statement(s))'
    );
}


/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/

line();
line(
    'Installing roles and permissions...'
);

try {
    (new AccessControl())
        ->ensureSchema();
} catch (Throwable $exception) {
    fail(
        'Access-control setup failed: '
        . $exception->getMessage()
    );
}

line('  OK roles and permissions');


/*
|--------------------------------------------------------------------------
| Bundled modules
|--------------------------------------------------------------------------
*/

line();
line('Installing bundled modules...');

$moduleManager =
    new ModuleManager();

$available =
    $moduleManager->availableModules();

$installed =
    $moduleManager->installedModules();

$preferredOrder = [
    'EmployeeProfiles',
    'Notifications',
    'EmailNotifications',
    'DayOffRequests',
    'ScheduleAcknowledgements',
    'ScheduleChangeLog',
    'SystemStatus',
    'UserInvitations',
];

$moduleOrder = [];

foreach ($preferredOrder as $name) {
    if (isset($available[$name])) {
        $moduleOrder[] = $name;
    }
}

$remaining = array_diff(
    array_keys($available),
    $moduleOrder
);

sort($remaining);

$moduleOrder = array_merge(
    $moduleOrder,
    $remaining
);

foreach ($moduleOrder as $moduleName) {
    if (isset($installed[$moduleName])) {
        line(
            '  SKIP '
            . $moduleName
        );
        continue;
    }

    try {
        $moduleManager->install(
            $moduleName
        );
    } catch (Throwable $exception) {
        fail(
            'Module installation failed: '
            . $moduleName
            . ' - '
            . $exception->getMessage()
        );
    }

    line(
        '  OK '
        . $moduleName
    );

    $installed[$moduleName] = true;
}


/*
|--------------------------------------------------------------------------
| Initial administrator
|--------------------------------------------------------------------------
*/

line();
line(
    'Creating initial administrator...'
);

$passwordFromEnvironment = getenv('ADMIN_PASSWORD');
$password = is_string($passwordFromEnvironment)
    ? trim($passwordFromEnvironment)
    : '';
$passwordWasProvided = $password !== '';

if (!$passwordWasProvided) {
    $password = generatedPassword();
} elseif (
    strlen($password) < 12
    || strlen($password) > 200
    || strpbrk($password, "\r\n") !== false
) {
    fail(
        'ADMIN_PASSWORD must be 12-200 characters '
        . 'and must not contain a line break.'
    );
}

try {
    $userModel = new User();

    $userModel->create(
        $adminUsername,
        $password,
        'admin',
        [
            'email' =>
                $adminEmail !== ''
                    ? $adminEmail
                    : null,
            'can_be_scheduled' => false,
            'schedule_view_mode' => 'full',
            'show_hours_summary' => true,
        ]
    );
} catch (Throwable $exception) {
    fail(
        'Administrator creation failed: '
        . $exception->getMessage()
    );
}

line('  OK administrator created');


/*
|--------------------------------------------------------------------------
| Final verification
|--------------------------------------------------------------------------
*/

line();
line('Verifying installation...');

$userCount = (int) $pdo
    ->query(
        'SELECT COUNT(*) FROM users'
    )
    ->fetchColumn();

$moduleCount = (int) $pdo
    ->query(
        'SELECT COUNT(*) FROM modules'
    )
    ->fetchColumn();

$roleCount = (int) $pdo
    ->query(
        'SELECT COUNT(*) FROM roles'
    )
    ->fetchColumn();

$permissionCount = (int) $pdo
    ->query(
        'SELECT COUNT(*) FROM permissions'
    )
    ->fetchColumn();

if ($userCount !== 1) {
    fail(
        'Unexpected user count after installation: '
        . $userCount
    );
}

if (
    $moduleCount
    !== count($available)
) {
    fail(
        'Unexpected installed module count. '
        . 'Expected '
        . count($available)
        . ', got '
        . $moduleCount
        . '.'
    );
}

if (
    $roleCount < 1
    || $permissionCount < 1
) {
    fail(
        'Roles or permissions were not initialized.'
    );
}

line(
    '  OK users: '
    . $userCount
);
line(
    '  OK modules: '
    . $moduleCount
);
line(
    '  OK roles: '
    . $roleCount
);
line(
    '  OK permissions: '
    . $permissionCount
);


/*
|--------------------------------------------------------------------------
| Complete
|--------------------------------------------------------------------------
*/

line();
line(
    '================================'
);
line(
    'INSTALLATION COMPLETE'
);
line(
    '================================'
);
line();

line(
    'Administrator username: '
    . $adminUsername
);

if ($passwordWasProvided) {
    line(
        'Administrator password source: ADMIN_PASSWORD'
    );
} else {
    line(
        'Administrator password: '
        . $password
    );
}

line();

if ($adminEmail !== '') {
    line(
        'Administrator email: '
        . $adminEmail
    );
    line();
}

if (!$passwordWasProvided) {
    line(
        'Save the generated password now. '
        . 'It will not be stored or shown again.'
    );
}

line();

line(
    'Web server document root must point to:'
);
line(
    '  '
    . $root
    . DIRECTORY_SEPARATOR
    . 'public'
);

line();

line(
    'Before production use:'
);
line(
    '  - set APP_URL to the final HTTPS URL'
);
line(
    '  - keep APP_DEBUG=false'
);
line(
    '  - keep .env outside version control'
);
line(
    '  - verify HTTPS before logging in'
);
line();

exit(0);
