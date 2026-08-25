<?php
declare(strict_types=1);

function accounting_attachment_allowed_mimes(): array {
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

/**
 * A deployment can replace public_html entirely. Keep evidence one level above
 * the deployed application by default, or use the explicit private path in
 * config.php on hosts with a dedicated data directory.
 */
function accounting_attachment_storage_directory(): string {
    global $config;
    $configured = is_array($config ?? null) ? ($config['accounting_storage_path'] ?? null) : null;
    $applicationRoot = defined('APP_ROOT') ? APP_ROOT : __DIR__;
    $base = is_string($configured) && trim($configured) !== ''
        ? rtrim($configured, "\\/")
        : dirname($applicationRoot, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'accounting';
    return $base;
}

function accounting_store_attachment(PDO $pdo, array $upload, ?int $operationId = null, ?int $reconciliationId = null, ?int $userId = null): array {
    if (($operationId === null) === ($reconciliationId === null)) throw new RuntimeException('Une pièce doit être liée à une opération ou à un rapprochement.');
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('La pièce n’a pas pu être téléversée.');
    $temporary = (string) ($upload['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) throw new RuntimeException('Le fichier transmis est invalide.');
    $size = accounting_integer($upload['size'] ?? null, 'La taille de la pièce', 1);
    if ($size > 10 * 1024 * 1024) throw new RuntimeException('La pièce ne doit pas dépasser 10 Mo.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporary);
    $allowed = accounting_attachment_allowed_mimes();
    if (!isset($allowed[$mime])) throw new RuntimeException('Seuls les PDF, JPEG, PNG et WebP sont acceptés.');
    if (str_starts_with($mime, 'image/') && @getimagesize($temporary) === false) throw new RuntimeException('L’image fournie est invalide.');
    if ($mime === 'application/pdf' && file_get_contents($temporary, false, null, 0, 5) !== '%PDF-') throw new RuntimeException('Le PDF fourni est invalide.');
    $originalName = accounting_non_empty_text(basename((string) ($upload['name'] ?? 'piece.' . $allowed[$mime])), 'Le nom de la pièce', 255);
    $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    $directory = accounting_attachment_storage_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Le stockage privé des pièces n’est pas disponible.');
    $path = $directory . DIRECTORY_SEPARATOR . $storedName;

    return accounting_with_transaction($pdo, function () use ($pdo, $operationId, $reconciliationId, $userId, $temporary, $path, $originalName, $storedName, $mime, $size): array {
        if ($operationId !== null) accounting_find_operation($pdo, $operationId, true);
        if ($reconciliationId !== null) {
            $check = $pdo->prepare('SELECT id FROM accounting_reconciliations WHERE id = ? FOR UPDATE');
            $check->execute([$reconciliationId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Rapprochement introuvable.');
        }
        if (!move_uploaded_file($temporary, $path)) throw new RuntimeException('La pièce n’a pas pu être enregistrée.');
        try {
            $insert = $pdo->prepare(
                'INSERT INTO accounting_attachments (operation_id, reconciliation_id, original_name, stored_name, mime_type, size_bytes, storage_path, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$operationId, $reconciliationId, $originalName, $storedName, $mime, $size, $path, $userId ?? accounting_current_user_id()]);
            $id = (int) $pdo->lastInsertId();
            $item = $pdo->prepare('SELECT * FROM accounting_attachments WHERE id = ?');
            $item->execute([$id]);
            $attachment = $item->fetch();
            accounting_audit($pdo, 'store_attachment', 'attachment', $id, null, $attachment ?: null, $userId);
            return $attachment ?: throw new RuntimeException('La pièce n’a pas pu être enregistrée.');
        } catch (Throwable $exception) {
            if (is_file($path)) @unlink($path);
            throw $exception;
        }
    });
}

function accounting_attachment_private_path(PDO $pdo, int $attachmentId): array {
    if ($attachmentId < 1) throw new RuntimeException('Pièce invalide.');
    $statement = $pdo->prepare('SELECT * FROM accounting_attachments WHERE id = ?');
    $statement->execute([$attachmentId]);
    $attachment = $statement->fetch();
    if (!$attachment) throw new RuntimeException('Pièce introuvable.');
    $root = realpath(accounting_attachment_storage_directory());
    $path = realpath((string) $attachment['storage_path']);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('La pièce privée est indisponible.');
    return [$attachment, $path];
}
