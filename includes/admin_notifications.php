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

