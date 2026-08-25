<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
try {
    ensure_accounting_schema();
    [$attachment, $path] = accounting_attachment_private_path(db(), (int) ($_GET['attachment'] ?? 0));
    $name = preg_replace('/[\r\n"\\\\]/', '_', (string) $attachment['original_name']) ?: 'piece';
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $exception) {
    error_log('L’Horloger: téléchargement de pièce échoué.');
    http_response_code(404);
    exit('Pièce indisponible.');
}
