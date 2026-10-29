<?php

/*
 * Work Schedule Manager — Coolify deployment helper.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Modified version. Original project:
 * https://github.com/QvarcY/work-schedule-manager
 * Additional terms: see ADDITIONAL_TERMS.md
 */

declare(strict_types=1);

$root = dirname(__DIR__);

function requiredEnv(string $key): string
{
    $value = getenv($key);

    if (!is_string($value) || trim($value) === '') {
        fwrite(STDERR, "Missing required environment variable {$key}.\n");
        exit(1);
    }

    return trim($value);
}

$host = requiredEnv('DB_HOST');
$port = getenv('DB_PORT');
$port = is_string($port) && trim($port) !== '' ? trim($port) : '3306';
$database = requiredEnv('DB_DATABASE');
$username = requiredEnv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

if (!is_string($password) || $password === '') {
    fwrite(STDERR, "Missing required environment variable DB_PASSWORD.\n");
    exit(1);
}

$charset = getenv('DB_CHARSET');
$charset = is_string($charset) && trim($charset) !== '' ? trim($charset) : 'utf8mb4';
$dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

if (defined('Pdo\Mysql::ATTR_CONNECT_TIMEOUT')) {
    $options[\Pdo\Mysql::ATTR_CONNECT_TIMEOUT] = 3;
} elseif (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
    $options[constant('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')] = 3;
}

$pdo = null;

for ($attempt = 1; $attempt <= 60; $attempt++) {
    try {
        $pdo = new PDO($dsn, $username, $password, $options);
        $pdo->query('SELECT 1');
        break;
    } catch (Throwable $exception) {
        $pdo = null;
        $detail = $attempt === 1 || $attempt % 10 === 0
            ? ': ' . $exception->getMessage()
            : '';
        fwrite(STDERR, "Waiting for database ({$attempt}/60){$detail}.\n");
        sleep(2);
    }
}

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database did not become ready.\n");
    exit(1);
}

$exists = $pdo->prepare(
    'SELECT COUNT(*)
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?'
);
$exists->execute(['users']);

if ((int) $exists->fetchColumn() > 0) {
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    if ($userCount > 0) {
        fwrite(STDOUT, "Installation already present ({$userCount} user account(s)). Skipping installer.\n");
        exit(0);
    }
}

$adminUsername = getenv('ADMIN_USERNAME');
$adminUsername = is_string($adminUsername) && trim($adminUsername) !== ''
    ? trim($adminUsername)
    : 'admin';
$adminEmail = getenv('ADMIN_EMAIL');
$adminEmail = is_string($adminEmail) ? trim($adminEmail) : '';

$command = [
    PHP_BINARY,
    $root . '/tools/install.php',
    '--yes',
    '--admin-user=' . $adminUsername,
];

if ($adminEmail !== '') {
    $command[] = '--admin-email=' . $adminEmail;
}

$process = proc_open(
    $command,
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $root
);

if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start the installer.\n");
    exit(1);
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$installerOutput = '';

while (true) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    if (is_string($stdout) && $stdout !== '') {
        echo $stdout;
        $installerOutput .= $stdout;
    }

    if (is_string($stderr) && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    $status = proc_get_status($process);

    if (!$status['running']) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        if (is_string($stdout) && $stdout !== '') {
            echo $stdout;
            $installerOutput .= $stdout;
        }

        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $stderr);
        }

        break;
    }

    usleep(100000);
}

fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    exit($exitCode === -1 ? 1 : $exitCode);
}

if (preg_match('/^Administrator password: (.+)$/m', $installerOutput, $matches) === 1) {
    $generatedPassword = trim($matches[1]);
    $credentialsPath = $root . '/storage/initial-admin-credentials.txt';
    $credentials = "username: {$adminUsername}\npassword: {$generatedPassword}\n";

    if (file_put_contents($credentialsPath, $credentials, LOCK_EX) === false) {
        fwrite(STDERR, "Installation finished, but the credentials file could not be written.\n");
        exit(1);
    }

    chmod($credentialsPath, 0600);
    fwrite(STDOUT, "Initial administrator credentials were saved to storage/initial-admin-credentials.txt. Remove that file after storing the password.\n");
}

exit(0);
