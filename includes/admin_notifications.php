<?php

function init_admin_notifications(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_type VARCHAR(60) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message VARCHAR(500) NOT NULL,
        related_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_admin_notifications_unread (read_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function create_admin_notification(
    mysqli $conn,
    string $type,
    string $title,
    string $message,
    ?int $relatedUserId = null
): bool {
    init_admin_notifications($conn);
    $stmt = $conn->prepare(
        'INSERT INTO admin_notifications (notification_type, title, message, related_user_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('sssi', $type, $title, $message, $relatedUserId);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function fetch_admin_notifications(mysqli $conn, int $limit = 8): array {
    init_admin_notifications($conn);
    $limit = max(1, min(50, $limit));
    $result = $conn->query(
        "SELECT id, notification_type, title, message, related_user_id, created_at, read_at
         FROM admin_notifications
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function count_unread_admin_notifications(mysqli $conn): int {
    init_admin_notifications($conn);
    $result = $conn->query('SELECT COUNT(*) AS total FROM admin_notifications WHERE read_at IS NULL');
    if ($result && ($row = $result->fetch_assoc())) {
        return (int) ($row['total'] ?? 0);
    }
    return 0;
}

function mark_admin_notifications_read(mysqli $conn): bool {
    init_admin_notifications($conn);
    return (bool) $conn->query('UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL');
}
