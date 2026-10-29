<?php

/*
 * Work Schedule Manager — Coolify deployment helper.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Modified version. Original project:
 * https://github.com/QvarcY/work-schedule-manager
 * Additional terms: see ADDITIONAL_TERMS.md
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

$root = dirname(__DIR__);

require $root . '/app/autoload.php';

Env::load($root . '/.env');

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

try {
    Database::connection()->query('SELECT 1');
    http_response_code(200);
    echo "ok\n";
} catch (Throwable $exception) {
    http_response_code(503);
    echo "unavailable\n";
}
