<?php
// includes/audit.php - shared audit-log helpers (writing + display labels).

/**
 * Write one audit record. Logging failures never break the page.
 */
function audit_log(PDO $pdo, int $user_id, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address)
            VALUES (:user_id, :action, :details, :ip_address)
        ");
        $stmt->execute([
            ':user_id'    => $user_id,
            ':action'     => substr($action, 0, 100),
            ':details'    => substr($details, 0, 500),
            ':ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('audit_log: ' . $e->getMessage());
    }
}

/**
 * Category of an action, used only for the badge colour on the admin page:
 * security | admin | danger | info
 */
function audit_action_category(string $action): string
{
    switch ($action) {
        case 'ACCESS_DENIED':
        case 'ACCOUNT_LOCKED':
        case 'MATERIAL_DELETED':
            return 'danger';
        case 'LOGIN_SUCCESS':
        case 'LOGOUT':
        case 'MFA_RESET':
            return 'security';
        case 'ACCOUNT_CREATED':
            return 'admin';
        default:
            return 'info';
    }
}

/** "MATERIAL_UPLOADED" -> "Material Uploaded" */
function audit_action_label(string $action): string
{
    $label = ucwords(strtolower(str_replace('_', ' ', $action)));
    return str_replace('Mfa', 'MFA', $label);
}
