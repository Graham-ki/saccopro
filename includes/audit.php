<?php
declare(strict_types=1);

/**
 * Log an action to activity_log.
 * Never throws — audit failures must not break business logic.
 */
function audit_log(
    PDO $pdo,
    ?int $userId,
    string $action,
    ?string $entity = null,
    ?int $entityId = null,
    ?string $details = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, action, entity, entity_id, details, ip)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $entity,
            $entityId,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Never let auditing crash the app
        error_log('[audit] ' . $e->getMessage());
    }
}