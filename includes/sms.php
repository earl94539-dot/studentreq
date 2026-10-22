<?php
/**
 * SMS / in-app notifications  (DFD store D:9 and process 8.0)
 *
 *   7.0 request processing  ->  queue_sms()      writes the process status to D:9
 *   8.0 document update     ->  dispatch_sms()   reads D:9 and sends it to the student
 *   student                 ->  student/notifications.php  (receive notification)
 *
 * SMS_MODE (config/database.php / .env.local):
 *   'log'       : no real SMS. Rows stay in sms_notifications and are appended to var/sms/sms.log.
 *   'semaphore' : sends through the Semaphore PH API (needs SMS_API_KEY).
 *   'textbee'   : sends through your own Android phone using the free TextBee gateway
 *                 (needs SMS_API_KEY; the phone's SIM load/plan pays for the text).
 *
 * Every function fails soft: if the table or column is missing (database not
 * upgraded yet) the error is logged and the request workflow carries on.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!defined('SMS_MODE'))        define('SMS_MODE', 'log');
if (!defined('SMS_API_KEY'))     define('SMS_API_KEY', '');
if (!defined('SMS_SENDER_NAME')) define('SMS_SENDER_NAME', 'SRVP');
define('SMS_LOG_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'sms');

/**
 * Normalise a Philippine mobile number to 09XXXXXXXXX.
 * Accepts 09171234567, +639171234567, 639171234567 (spaces/dashes ignored).
 * Returns null when the number is not a valid PH mobile number.
 */
function sms_normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';
    if (preg_match('/^(?:\+?63|0)(9\d{9})$/', $digits, $m)) {
        return '0' . $m[1];
    }
    return null;
}

/** The student's saved mobile number, or null. */
function student_phone(int $studentId): ?string
{
    try {
        $stmt = db()->prepare('SELECT phone FROM students WHERE id = ?');
        $stmt->execute([$studentId]);
        $phone = $stmt->fetchColumn();
        return is_string($phone) && $phone !== '' ? $phone : null;
    } catch (Throwable $e) {
        error_log('student_phone failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * 7.0 -> D:9. Store a process-status message for a student.
 * Returns the new row id, or null when it could not be stored.
 */
function queue_sms(int $studentId, ?int $requestId, string $processStatus, string $message): ?int
{
    try {
        $phone  = student_phone($studentId);
        $status = $phone === null ? 'no_number' : 'queued';
        $stmt = db()->prepare(
            'INSERT INTO sms_notifications (student_id, request_id, phone, process_status, message, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$studentId, $requestId, $phone, $processStatus, mb_substr($message, 0, 320), $status]);
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('queue_sms failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * D:9 -> 8.0. Send one queued notification and record the outcome.
 * Only rows with status 'queued' are sent, so calling it twice is harmless.
 */
function dispatch_sms(int $id): bool
{
    try {
        $stmt = db()->prepare('SELECT * FROM sms_notifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'queued' || empty($row['phone'])) {
            return false;
        }

        $send = match (SMS_MODE) {
            'semaphore' => 'sms_send_semaphore',
            'textbee'   => 'sms_send_textbee',
            default     => 'sms_send_log',
        };
        [$ok, $response] = $send((string) $row['phone'], (string) $row['message']);

        $upd = db()->prepare(
            'UPDATE sms_notifications
             SET status = ?, provider_response = ?, sent_at = ' . ($ok ? 'NOW()' : 'NULL') . '
             WHERE id = ?'
        );
        $upd->execute([$ok ? 'sent' : 'failed', mb_substr($response, 0, 255), $id]);
        return $ok;
    } catch (Throwable $e) {
        error_log('dispatch_sms failed: ' . $e->getMessage());
        return false;
    }
}

/** Log mode: append the message to var/sms/sms.log instead of sending. */
function sms_send_log(string $phone, string $message): array
{
    if (!is_dir(SMS_LOG_DIR)) {
        @mkdir(SMS_LOG_DIR, 0775, true);
        @file_put_contents(SMS_LOG_DIR . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
        @file_put_contents(SMS_LOG_DIR . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    $line = date('Y-m-d H:i:s') . ' | to ' . $phone . ' | ' . $message . "\n";
    @file_put_contents(SMS_LOG_DIR . DIRECTORY_SEPARATOR . 'sms.log', $line, FILE_APPEND | LOCK_EX);
    return [true, 'logged (SMS_MODE=log)'];
}

/** Semaphore PH API (https://semaphore.co/docs). Returns [bool ok, string response]. */
function sms_send_semaphore(string $phone, string $message): array
{
    if (SMS_API_KEY === '') {
        return [false, 'SMS_API_KEY is not set'];
    }
    if (!function_exists('curl_init')) {
        return [false, 'PHP curl extension is not available'];
    }
    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'apikey'     => SMS_API_KEY,
            'number'     => $phone,
            'message'    => $message,
            'sendername' => SMS_SENDER_NAME,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [false, 'curl error: ' . $err];
    }
    return [$code >= 200 && $code < 300, 'HTTP ' . $code . ' ' . (string) $body];
}

/** TextBee (https://textbee.dev): your own Android phone sends the SMS. Returns [bool ok, string response]. */
function sms_send_textbee(string $phone, string $message): array
{
    if (SMS_API_KEY === '') {
        return [false, 'SMS_API_KEY is not set'];
    }
    if (!function_exists('curl_init')) {
        return [false, 'PHP curl extension is not available'];
    }
    $international = preg_match('/^09\d{9}$/', $phone) ? '+63' . substr($phone, 1) : $phone;
    $ch = curl_init('https://api.textbee.dev/api/v1/gateway/send-sms');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['recipients' => [$international], 'message' => $message]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . SMS_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [false, 'curl error: ' . $err];
    }
    return [$code >= 200 && $code < 300, 'HTTP ' . $code . ' ' . (string) $body];
}

/** Message text for each process status. $req needs ref_no and service_name. */
function sms_message_for(string $event, array $req): string
{
    $ref = (string) ($req['ref_no'] ?? '');
    $svc = (string) ($req['service_name'] ?? 'document');
    $reason = (string) ($req['admin_note'] ?? '');
    return match ($event) {
        'approved'         => "SRVP: Your request $ref ($svc) was verified by the Registrar. Please pay and upload your receipt in the portal.",
        'rejected'         => "SRVP: Hello {$req['full_name']}, your request for $svc ($ref) has been rejected due to: $reason",
        'payment_declined' => "SRVP: The payment receipt for $ref could not be verified. Please upload a valid receipt in the portal.",
        'processing'       => "SRVP: Payment for $ref is verified. The Registrar is now processing your document.",
        'ready'            => "SRVP: Hello {$req['full_name']}, your $svc ($ref) is ready for claiming at the Registrar's Office. Bring your school ID.",
        'released'         => "SRVP: Request $ref has been marked as released. Thank you!",
        default            => "SRVP: There is an update on your request $ref. Open the portal for details.",
    };
}

/**
 * One call for a status change: write the process status to D:9 (7.0)
 * and send it (8.0). $req must contain id, student_id, ref_no, service_name.
 */
function notify_request_status(array $req, string $event): void
{
    $id = queue_sms(
        (int) $req['student_id'],
        isset($req['id']) ? (int) $req['id'] : null,
        $event,
        sms_message_for($event, $req)
    );
    if ($id !== null) {
        dispatch_sms($id);
    }
}

/** Unread in-app notifications for the student (nav badge). */
function unread_notification_count(int $studentId): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM sms_notifications WHERE student_id = ? AND read_at IS NULL');
        $stmt->execute([$studentId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}
