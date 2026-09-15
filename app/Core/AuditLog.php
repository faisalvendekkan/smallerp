<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Append-only record of who changed what.
 *
 * An audit trail is not optional in a system that produces statutory books:
 * when an auditor asks who voided invoice INV-2026-0041, the answer has to be
 * in the database, not in someone's memory.
 */
final class AuditLog
{
    public static function record(
        string $action,
        string $entityType = '',
        ?int $entityId = null,
        array $details = []
    ): void {
        // Never let logging break the operation it is logging.
        try {
            if (!Database::tableExists('audit_log')) {
                return;
            }
            Database::insert('audit_log', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('SmallERP audit log failed: ' . $e->getMessage());
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 100, array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['entity_type'])) {
            $where[] = 'a.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'a.entity_id = ?';
            $params[] = (int) $filters['entity_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        $params[] = max(1, min($limit, 500));

        return Database::all(
            'SELECT a.*, u.name AS user_name FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.id DESC LIMIT ?',
            $params
        );
    }
}
