<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/platform.php';

$user = current_user();
if (!$user) {
    http_response_code(403);
    die('Akses ditolak: Silakan login terlebih dahulu.');
}

$jobId = (int)($_GET['job_id'] ?? 0);
$docId = (int)($_GET['doc_id'] ?? 0);

$doc = null;
if ($docId > 0) {
    $stmt = db()->prepare('SELECT * FROM job_additional_documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if ($doc && empty($jobId)) {
        $jobId = (int)$doc['job_id'];
    }
} elseif ($jobId > 0) {
    $stmt = db()->prepare('SELECT * FROM job_additional_documents WHERE job_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$jobId]);
    $doc = $stmt->fetch();
}

$job = null;
if ($jobId > 0) {
    $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ?');
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();
}

// Authorization check: admin or owner of the job
$isAdmin = in_array($user['role'] ?? '', ['admin', 'admin_pusat', 'admin_dinas'], true);
$isOwner = false;
if ($doc && (int)$doc['user_id'] === (int)$user['id']) {
    $isOwner = true;
}
if ($job && (int)$job['user_id'] === (int)$user['id']) {
    $isOwner = true;
}

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    die('Akses ditolak.');
}

$blobData = $doc['document_blob'] ?? ($job['additional_doc_blob'] ?? null);
$mimeType = $doc['document_mime'] ?? ($job['additional_doc_mime'] ?? '');
$fileName = $doc['document_filename'] ?? ($job['additional_doc_filename'] ?? '');
$filePath = $doc['document_file'] ?? ($job['additional_doc_file'] ?? '');

if (!empty($blobData)) {
    if (empty($mimeType)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($blobData) ?: 'application/octet-stream';
    }
    if (empty($fileName)) {
        $ext = 'bin';
        if (strpos($mimeType, 'pdf') !== false) $ext = 'pdf';
        elseif (strpos($mimeType, 'png') !== false) $ext = 'png';
        elseif (strpos($mimeType, 'jpeg') !== false || strpos($mimeType, 'jpg') !== false) $ext = 'jpg';
        elseif (strpos($mimeType, 'webp') !== false) $ext = 'webp';
        $fileName = 'dokumen_pendukung_' . ($jobId ?: $docId) . '.' . $ext;
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    header('Content-Length: ' . strlen($blobData));
    header('Cache-Control: private, max-age=86400');
    echo $blobData;
    exit;
}

if (!empty($filePath) && file_exists(__DIR__ . '/' . ltrim($filePath, '/'))) {
    $fullPath = __DIR__ . '/' . ltrim($filePath, '/');
    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

// Fallback to PDF in public/images
$fallbackPdf = __DIR__ . '/public/images/Surat_Pernyataan_Usaha_Andi_Pratama_Dummy.pdf';
if (!file_exists($fallbackPdf)) {
    $fallbackPdf = __DIR__ . '/public/images/surat-pernyataan-usaha.pdf';
}
if (file_exists($fallbackPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($fallbackPdf) . '"');
    header('Content-Length: ' . filesize($fallbackPdf));
    readfile($fallbackPdf);
    exit;
}

http_response_code(404);
die('Dokumen tidak ditemukan.');
