<?php

/*
 * Work Schedule Manager — demonstration data command.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Modified version. Original project:
 * https://github.com/QvarcY/work-schedule-manager
 * Additional terms: see ADDITIONAL_TERMS.md
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Models\User;
use App\Services\AccessControl;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Demonstration data can only be loaded from the CLI.\n");
    exit(1);
}

const DEMO_PASSWORD = 'demo-password';
const DEMO_USER_PREFIX = 'demo.';
const DEMO_LABEL_PREFIX = 'Demo · ';
const DEMO_EMAIL_DOMAIN = '@demo.local';

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

$options = getopt('', ['yes', 'force', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
Load demonstration data into an installed Work Schedule Manager database.

Usage:
  php tools/seed-demo.php --yes
  php tools/seed-demo.php --yes --force

Options:
  --yes     Skip the confirmation prompt.
  --force   Replace demonstration data that was loaded before.
  --help    Show this help.

The command adds sample people, schedules, day-off requests, notifications,
acknowledgements, a change-log entry and invitations. It does not change
the administrator account.

Every demonstration account uses the same password: demo-password
Run this only on a database used to explore the product.

TXT;
    exit(0);
}

function demoLine(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function demoFail(string $message, int $code = 1): never
{
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function demoTableExists(PDO $pdo, string $table): bool
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

function demoColumnExists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @return list<int>
 */
function demoUserIds(PDO $pdo): array
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE username LIKE ? ORDER BY id ASC');
    $statement->execute([DEMO_USER_PREFIX . '%']);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return list<int>
 */
function demoScheduleIds(PDO $pdo): array
{
    $statement = $pdo->prepare('SELECT id FROM schedules WHERE schedule_name LIKE ? ORDER BY id ASC');
    $statement->execute([DEMO_LABEL_PREFIX . '%']);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param list<int> $ids
 */
function demoDeleteByIds(PDO $pdo, string $sql, array $ids): void
{
    if ($ids === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(sprintf($sql, $placeholders));
    $statement->execute($ids);
}

function demoPurge(PDO $pdo): void
{
    $userIds = demoUserIds($pdo);
    $scheduleIds = demoScheduleIds($pdo);

    if (
        demoTableExists($pdo, 'schedule_change_reads')
        && demoTableExists($pdo, 'schedule_change_batches')
        && $scheduleIds !== []
    ) {
        $batchStatement = $pdo->prepare(
            'SELECT id FROM schedule_change_batches WHERE schedule_id IN ('
            . implode(', ', array_fill(0, count($scheduleIds), '?'))
            . ')'
        );
        $batchStatement->execute($scheduleIds);
        $batchIds = array_map('intval', $batchStatement->fetchAll(PDO::FETCH_COLUMN));
        demoDeleteByIds($pdo, 'DELETE FROM schedule_change_reads WHERE batch_id IN (%s)', $batchIds);
    }

    foreach ([
        'schedule_change_items' => 'DELETE FROM schedule_change_items WHERE schedule_id IN (%s)',
        'schedule_change_batches' => 'DELETE FROM schedule_change_batches WHERE schedule_id IN (%s)',
        'schedule_acknowledgements' => 'DELETE FROM schedule_acknowledgements WHERE schedule_id IN (%s)',
    ] as $table => $sql) {
        if (demoTableExists($pdo, $table)) {
            demoDeleteByIds($pdo, $sql, $scheduleIds);
        }
    }

    if (demoTableExists($pdo, 'notifications')) {
        $statement = $pdo->prepare('SELECT id FROM notifications WHERE title LIKE ?');
        $statement->execute([DEMO_LABEL_PREFIX . '%']);
        $notificationIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        foreach ([
            'notification_deliveries' => 'DELETE FROM notification_deliveries WHERE notification_id IN (%s)',
            'notification_recipients' => 'DELETE FROM notification_recipients WHERE notification_id IN (%s)',
        ] as $table => $sql) {
            if (demoTableExists($pdo, $table)) {
                demoDeleteByIds($pdo, $sql, $notificationIds);
            }
        }

        $deleteNotifications = $pdo->prepare('DELETE FROM notifications WHERE title LIKE ?');
        $deleteNotifications->execute([DEMO_LABEL_PREFIX . '%']);
    }

    if (demoTableExists($pdo, 'day_off_requests')) {
        demoDeleteByIds($pdo, 'DELETE FROM day_off_requests WHERE user_id IN (%s)', $userIds);
    }

    if (demoTableExists($pdo, 'user_invitations')) {
        $deleteInvitations = $pdo->prepare(
            'DELETE FROM user_invitations
             WHERE email LIKE ?
                OR invited_contact LIKE ?'
        );
        $like = '%' . DEMO_EMAIL_DOMAIN;
        $deleteInvitations->execute([$like, $like]);
    }

    if (demoTableExists($pdo, 'activity_logs')) {
        $deleteActivity = $pdo->prepare('DELETE FROM activity_logs WHERE action = ?');
        $deleteActivity->execute(['demo_seed']);
    }

    demoDeleteByIds($pdo, 'DELETE FROM schedules WHERE id IN (%s)', $scheduleIds);
    demoDeleteByIds($pdo, 'DELETE FROM users WHERE id IN (%s)', $userIds);
}

/**
 * @return array{year: int, month: int, days: int, label: string}
 */
function demoMonth(int $offset): array
{
    $date = new DateTimeImmutable('first day of this month');
    if ($offset !== 0) {
        $date = $date->modify(($offset > 0 ? '+' : '') . $offset . ' month');
    }

    $names = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'março',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];
    $month = (int) $date->format('n');
    $year = (int) $date->format('Y');

    return [
        'year' => $year,
        'month' => $month,
        'days' => (int) $date->format('t'),
        'label' => $names[$month] . ' ' . $year,
    ];
}

function demoWeekday(int $year, int $month, int $day): int
{
    return (int) DateTimeImmutable::createFromFormat(
        '!Y-n-j',
        $year . '-' . $month . '-' . $day
    )->format('N');
}

/**
 * @param array{year: int, month: int, days: int, label: string} $month
 * @return list<array{day: int, type: string, label: ?string, background_color: ?string, text_color: ?string}>
 */
function demoDaySettings(array $month): array
{
    $settings = [];
    $holiday = null;

    for ($day = 10; $day <= $month['days']; $day++) {
        if (demoWeekday($month['year'], $month['month'], $day) < 6) {
            $holiday = $day;
            break;
        }
    }

    for ($day = 1; $day <= $month['days']; $day++) {
        $weekday = demoWeekday($month['year'], $month['month'], $day);

        if ($day === $holiday) {
            $settings[] = [
                'day' => $day,
                'type' => 'holiday',
                'label' => 'Feriado',
                'background_color' => '#fecaca',
                'text_color' => '#7f1d1d',
            ];
            continue;
        }

        if ($weekday === 6 || $weekday === 7) {
            $settings[] = [
                'day' => $day,
                'type' => $weekday === 6 ? 'saturday' : 'sunday',
                'label' => null,
                'background_color' => '#d7e0ea',
                'text_color' => null,
            ];
        }
    }

    return $settings;
}

function demoRotationCode(int $day, int $phase): ?string
{
    $slot = ($day + $phase) % 8;

    return match ($slot) {
        0, 1 => 'D',
        2, 3 => 'N',
        default => null,
    };
}

/**
 * @return array<int, array{code: string, note: ?string}>
 */
function demoShiftsFor(string $username, int $days): array
{
    $shifts = [];

    for ($day = 1; $day <= $days; $day++) {
        $code = null;
        $note = null;

        if ($username === 'demo.elena' && $day <= 5) {
            $code = 'A';
            $note = 'Férias';
        } elseif ($username === 'demo.diego' && $day === 12) {
            $code = 'S';
            $note = 'Atestado';
        } elseif ($username === 'demo.diego') {
            $code = demoRotationCode($day, 1) === 'D' ? 'DT' : null;
        } elseif ($username === 'demo.carla') {
            $code = demoRotationCode($day, 0) === 'D' ? 'D' : null;
        } elseif ($username === 'demo.bruno') {
            $code = demoRotationCode($day, 4);
            if ($code === 'D') {
                $code = null;
            }
        } elseif ($username === 'demo.ana') {
            $code = demoRotationCode($day, 0);
            if ($code === 'D' && $day % 2 === 1) {
                $code = 'D*';
            }
            if ($code === 'N') {
                $code = null;
            }
        }

        if ($code !== null) {
            $shifts[$day] = ['code' => $code, 'note' => $note];
        }
    }

    return $shifts;
}

/**
 * @param array<int, array{code: string, note: ?string}> $shifts
 * @param array<string, array{hours: float, counts_as_shift: int}> $types
 * @return array{count: int, hours: float}
 */
function demoSummary(array $shifts, array $types): array
{
    $count = 0;
    $hours = 0.0;

    foreach ($shifts as $shift) {
        $type = $types[$shift['code']] ?? null;
        if ($type === null) {
            continue;
        }

        if ($type['counts_as_shift'] === 1) {
            $count++;
        }

        $hours += $type['hours'];
    }

    return ['count' => $count, 'hours' => $hours];
}

$envPath = $root . '/.env';

if (!is_file($envPath)) {
    demoFail('.env does not exist. Install the application before loading demonstration data.');
}

Env::load($envPath);

try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    demoFail('Database connection failed: ' . $exception->getMessage());
}

if (!demoTableExists($pdo, 'users') || !demoTableExists($pdo, 'schedules') || !demoTableExists($pdo, 'shift_types')) {
    demoFail('The application schema is missing. Run php tools/install.php first.');
}

$admin = $pdo->query("SELECT id, username FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetch();

if ($admin === false) {
    demoFail('No administrator account was found. Run the installer first.');
}

$adminId = (int) $admin['id'];
$existingDemoUsers = demoUserIds($pdo);

if ($existingDemoUsers !== [] && !isset($options['force'])) {
    demoFail(
        'Demonstration data is already loaded. '
        . 'Run php tools/seed-demo.php --yes --force to replace it.'
    );
}

demoLine('Demonstration data');
demoLine('===================');
demoLine();
demoLine('Shared password for every demo account: ' . DEMO_PASSWORD);
demoLine('The administrator account is left unchanged.');
demoLine();

if (!isset($options['yes'])) {
    echo 'Load demonstration data? [y/N]: ';
    $answer = strtolower(trim((string) fgets(STDIN)));

    if (!in_array($answer, ['y', 'yes'], true)) {
        demoLine('Cancelled.');
        exit(0);
    }
}

$people = [
    ['username' => 'demo.moderador', 'first_name' => 'Marina', 'last_name' => 'Alves', 'role' => 'moderator', 'scheduled' => false, 'view' => 'full', 'hours' => true],
    ['username' => 'demo.controle', 'first_name' => 'Paulo', 'last_name' => 'Nogueira', 'role' => 'control', 'scheduled' => false, 'view' => 'full', 'hours' => true],
    ['username' => 'demo.ana', 'first_name' => 'Ana', 'last_name' => 'Costa', 'role' => 'employee', 'scheduled' => true, 'view' => 'own', 'hours' => true],
    ['username' => 'demo.bruno', 'first_name' => 'Bruno', 'last_name' => 'Lima', 'role' => 'employee', 'scheduled' => true, 'view' => 'own', 'hours' => true],
    ['username' => 'demo.carla', 'first_name' => 'Carla', 'last_name' => 'Mendes', 'role' => 'employee', 'scheduled' => true, 'view' => 'day', 'hours' => false],
    ['username' => 'demo.diego', 'first_name' => 'Diego', 'last_name' => 'Rocha', 'role' => 'employee', 'scheduled' => true, 'view' => 'night', 'hours' => true],
    ['username' => 'demo.elena', 'first_name' => 'Elena', 'last_name' => 'Souza', 'role' => 'employee', 'scheduled' => true, 'view' => 'own', 'hours' => true],
    ['username' => 'demo.leitor', 'first_name' => 'Rita', 'last_name' => 'Campos', 'role' => 'viewer', 'scheduled' => false, 'view' => 'full', 'hours' => false],
];

(new AccessControl())->ensureSchema();

$stage = 'purge';

try {
    demoPurge($pdo);
    $stage = 'load';

    $users = new User();
    $ids = [];

    foreach ($people as $person) {
        $users->create(
            $person['username'],
            DEMO_PASSWORD,
            $person['role'],
            [
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'email' => $person['username'] . DEMO_EMAIL_DOMAIN,
                'can_be_scheduled' => $person['scheduled'],
                'schedule_view_mode' => $person['view'],
                'show_hours_summary' => $person['hours'],
            ]
        );

        $created = $users->findByUsername($person['username']);
        if ($created === null) {
            throw new RuntimeException('Could not reload ' . $person['username'] . '.');
        }

        $ids[$person['username']] = (int) $created['id'];
    }

    if (demoColumnExists($pdo, 'users', 'locale')) {
        $locale = $pdo->prepare('UPDATE users SET locale = ? WHERE id = ?');
        foreach ($ids as $id) {
            $locale->execute(['en', $id]);
        }
    }

    $pdo->beginTransaction();

    $typeRows = $pdo->query('SELECT code, hours, counts_as_shift FROM shift_types')->fetchAll();
    $types = [];
    foreach ($typeRows as $row) {
        $types[(string) $row['code']] = [
            'hours' => (float) $row['hours'],
            'counts_as_shift' => (int) $row['counts_as_shift'],
        ];
    }

    $insertSchedule = $pdo->prepare(
        'INSERT INTO schedules (schedule_name, month, status, created_by) VALUES (?, ?, ?, ?)'
    );
    $insertDay = $pdo->prepare(
        'INSERT INTO schedule_day_settings (schedule_id, day_number, day_type, label, background_color, text_color)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertEmployee = $pdo->prepare(
        'INSERT INTO employees (schedule_id, user_id, name, shifts_count, hours, order_index)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertShift = $pdo->prepare(
        'INSERT INTO shifts (employee_id, day_number, shift_code, is_leader, note) VALUES (?, ?, ?, ?, ?)'
    );

    $saveSchedule = static function (
        string $name,
        array $month,
        string $status,
        array $assignedUsernames,
        bool $partial
    ) use (
        $pdo,
        $ids,
        $people,
        $types,
        $insertSchedule,
        $insertDay,
        $insertEmployee,
        $insertShift,
        $adminId
    ): int {
        $insertSchedule->execute([
            DEMO_LABEL_PREFIX . $name,
            $month['label'],
            $status,
            $adminId,
        ]);
        $scheduleId = (int) $pdo->lastInsertId();

        foreach (demoDaySettings($month) as $setting) {
            $insertDay->execute([
                $scheduleId,
                $setting['day'],
                $setting['type'],
                $setting['label'],
                $setting['background_color'],
                $setting['text_color'],
            ]);
        }

        $order = 1;
        foreach ($people as $person) {
            if (!in_array($person['username'], $assignedUsernames, true)) {
                continue;
            }

            $days = $partial ? min(10, $month['days']) : $month['days'];
            $shifts = demoShiftsFor($person['username'], $days);
            $summary = demoSummary($shifts, $types);
            $insertEmployee->execute([
                $scheduleId,
                $ids[$person['username']],
                $person['first_name'] . ' ' . $person['last_name'],
                $summary['count'],
                $summary['hours'],
                $order,
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $order++;

            foreach ($shifts as $day => $shift) {
                if (!isset($types[$shift['code']])) {
                    throw new RuntimeException('Unknown shift code ' . $shift['code'] . '.');
                }

                $insertShift->execute([
                    $employeeId,
                    $day,
                    $shift['code'],
                    str_contains($shift['code'], '*') ? 1 : 0,
                    $shift['note'],
                ]);
            }
        }

        return $scheduleId;
    };

    $current = demoMonth(0);
    $next = demoMonth(1);
    $previous = demoMonth(-1);
    $scheduled = ['demo.ana', 'demo.bruno', 'demo.carla', 'demo.diego', 'demo.elena'];

    $archivedId = $saveSchedule('Escala arquivada', $previous, 'archived', $scheduled, false);
    $draftId = $saveSchedule('Escala em rascunho', $next, 'draft', ['demo.ana', 'demo.bruno'], true);
    $publishedId = $saveSchedule('Escala publicada', $current, 'published', $scheduled, false);

    if (demoTableExists($pdo, 'day_off_requests')) {
        $insertRequest = $pdo->prepare(
            'INSERT INTO day_off_requests
                (user_id, request_date, importance, comment, status, admin_comment, employee_seen_at, decided_by, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $pendingDate = $current['year'] . '-' . str_pad((string) $current['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) min(20, $current['days']), 2, '0', STR_PAD_LEFT);
        $approvedDate = $next['year'] . '-' . str_pad((string) $next['month'], 2, '0', STR_PAD_LEFT) . '-03';
        $rejectedDate = $next['year'] . '-' . str_pad((string) $next['month'], 2, '0', STR_PAD_LEFT) . '-04';
        $urgentDate = $current['year'] . '-' . str_pad((string) $current['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) min(18, $current['days']), 2, '0', STR_PAD_LEFT);

        $insertRequest->execute([$ids['demo.ana'], $pendingDate, 'svarigs', 'Consulta médica de manhã.', 'pending', null, null, null, null]);
        $insertRequest->execute([$ids['demo.diego'], $urgentDate, 'velams', 'Troca combinada com a Carla.', 'pending', null, null, null, null]);
        $insertRequest->execute([$ids['demo.bruno'], $approvedDate, 'velams', 'Aniversário da filha.', 'approved', 'Coberto pelo turno da noite.', date('Y-m-d H:i:s'), $adminId, date('Y-m-d H:i:s')]);
        $insertRequest->execute([$ids['demo.carla'], $rejectedDate, 'neatliekams', 'Viagem já comprada.', 'rejected', 'A escala desse dia já está fechada.', date('Y-m-d H:i:s'), $adminId, date('Y-m-d H:i:s')]);
    }

    if (demoTableExists($pdo, 'notifications') && demoTableExists($pdo, 'notification_recipients')) {
        $insertNotification = $pdo->prepare(
            'INSERT INTO notifications (title, body, type, created_by) VALUES (?, ?, ?, ?)'
        );
        $insertRecipient = $pdo->prepare(
            'INSERT INTO notification_recipients (notification_id, user_id, read_at) VALUES (?, ?, ?)'
        );
        $insertDelivery = demoTableExists($pdo, 'notification_deliveries')
            ? $pdo->prepare(
                "INSERT INTO notification_deliveries (notification_id, user_id, channel_code, status, sent_at)
                 VALUES (?, ?, 'system', 'sent', NOW())"
            )
            : null;

        $insertNotification->execute([
            DEMO_LABEL_PREFIX . 'Escala publicada',
            'A escala de ' . $current['label'] . ' foi publicada. Confira seus turnos e registre o recebimento.',
            'schedule',
            $adminId,
        ]);
        $publishedNotice = (int) $pdo->lastInsertId();

        foreach ($scheduled as $username) {
            $insertRecipient->execute([$publishedNotice, $ids[$username], null]);
            $insertDelivery?->execute([$publishedNotice, $ids[$username]]);
        }

        $insertNotification->execute([
            DEMO_LABEL_PREFIX . 'Bem-vindo',
            'Esta conta faz parte dos dados de demonstração. A senha compartilhada está no resultado do comando.',
            'info',
            $adminId,
        ]);
        $welcome = (int) $pdo->lastInsertId();
        $insertRecipient->execute([$welcome, $ids['demo.ana'], date('Y-m-d H:i:s')]);
        $insertDelivery?->execute([$welcome, $ids['demo.ana']]);
    }

    if (demoTableExists($pdo, 'schedule_acknowledgements')) {
        $insertAck = $pdo->prepare(
            'INSERT INTO schedule_acknowledgements (schedule_id, user_id, comment) VALUES (?, ?, ?)'
        );
        $insertAck->execute([$publishedId, $ids['demo.bruno'], 'Escala conferida.']);
        $insertAck->execute([$publishedId, $ids['demo.elena'], 'Vi a semana de férias.']);
    }

    if (demoTableExists($pdo, 'schedule_change_batches') && demoTableExists($pdo, 'schedule_change_items')) {
        $insertBatch = $pdo->prepare(
            'INSERT INTO schedule_change_batches (schedule_id, schedule_name, month, changed_by, summary)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertBatch->execute([
            $publishedId,
            DEMO_LABEL_PREFIX . 'Escala publicada',
            $current['label'],
            $adminId,
            DEMO_LABEL_PREFIX . 'Ajuste de turno',
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $insertItem = $pdo->prepare(
            'INSERT INTO schedule_change_items
                (batch_id, schedule_id, user_id, employee_name, day_number, old_code, new_code, change_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItem->execute([
            $batchId,
            $publishedId,
            $ids['demo.bruno'],
            'Bruno Lima',
            5,
            'D',
            'N',
            'shift_changed',
        ]);
    }

    if (demoTableExists($pdo, 'user_invitations')) {
        $insertInvitation = $pdo->prepare(
            'INSERT INTO user_invitations
                (token_hash, invited_contact, intended_role, can_be_scheduled, schedule_view_mode, status,
                 first_name, last_name, email, phone, username, password_hash, created_by, submitted_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertInvitation->execute([
            hash('sha256', 'demo-open-invitation'),
            'rafael.nunes' . DEMO_EMAIL_DOMAIN,
            'employee',
            1,
            'own',
            'open',
            null,
            null,
            null,
            null,
            null,
            null,
            $adminId,
            null,
            date('Y-m-d H:i:s', strtotime('+14 days')),
        ]);
        $insertInvitation->execute([
            hash('sha256', 'demo-submitted-invitation'),
            null,
            'employee',
            1,
            'own',
            'submitted',
            'Fernanda',
            'Alves',
            'fernanda.alves' . DEMO_EMAIL_DOMAIN,
            null,
            'fernanda.alves',
            password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT),
            $adminId,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+14 days')),
        ]);
    }

    $insertActivity = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, actor_name, action, entity_type, entity_id, message)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertActivity->execute([
        $adminId,
        (string) $admin['username'],
        'demo_seed',
        'schedule',
        $publishedId,
        DEMO_LABEL_PREFIX . 'Dados de demonstração carregados.',
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($stage === 'load') {
        try {
            demoPurge($pdo);
        } catch (Throwable) {
            // Keep the original failure. A second purge error would hide it.
        }
    }

    demoFail('Demonstration data was not loaded: ' . $exception->getMessage());
}

demoLine('Loaded:');
demoLine('  8 demonstration accounts');
demoLine('  published schedule #' . $publishedId . ' (' . $current['label'] . ')');
demoLine('  draft schedule #' . $draftId . ' (' . $next['label'] . ')');
demoLine('  archived schedule #' . $archivedId . ' (' . $previous['label'] . ')');
demoLine();
demoLine('Sign in with any of these usernames and the password ' . DEMO_PASSWORD . ':');

foreach ($people as $person) {
    demoLine(
        '  ' . str_pad($person['username'], 16, ' ')
        . $person['first_name'] . ' ' . $person['last_name']
        . ' (' . $person['role'] . ')'
    );
}

demoLine();
demoLine('Replace this data later with: php tools/seed-demo.php --yes --force');
exit(0);
