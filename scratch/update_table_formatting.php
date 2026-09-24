<?php
$adminFile = __DIR__ . '/../admin.php';
$content = file_get_contents($adminFile);
$contentNorm = str_replace("\r\n", "\n", $content);

$target = <<<'EOD'
                                    <?php foreach ($showingList as $emp):
                                        $nameStr = $emp['owner_name'] ?: $emp['name'];
                                        $emailStr = $emp['email'];
                                        $phoneStr = $emp['phone'] ?: ($emp['whatsapp'] ?: '-');
                                        $addressStr = $emp['address'] ?: ($emp['address_detail'] ?: '-');

                                        $locArr = [];
                                        if (!empty($emp['city'])) $locArr[] = $emp['city'];
                                        if (!empty($emp['province'])) $locArr[] = $emp['province'];
                                        $locationStr = !empty($locArr) ? implode(', ', $locArr) : '-';

                                        $vStatus = $emp['verification_status'] ?? '';
                                        if ($vStatus === 'APPROVED') {
                                            $badgeHtml = '<span class="pill-badge verified">● Terverifikasi</span>';
                                        } elseif ($vStatus === 'NEEDS_REVISION') {
                                            $revNum = (int)($emp['revision_count'] ?? ($emp['rejection_count'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $badgeHtml = '<span class="pill-badge revision" style="background:#fef3c7; color:#d97706; border:1px solid #fde68a;">● Revisi Diminta (ke-' . $revNum . ')</span>';
                                        } elseif ($vStatus === 'REJECTED') {
                                            $badgeHtml = '<span class="pill-badge rejected">● Ditolak</span>';
                                        } else {
                                            $badgeHtml = '<span class="pill-badge pending">● Dalam Proses</span>';
                                        }

                                        $dateStr = date('d M Y, H:i', strtotime($emp['created_at']));
                                    ?>
EOD;

$replacement = <<<'EOD'
                                    <?php foreach ($showingList as $emp):
                                        $nameStr = $emp['owner_name'] ?: $emp['name'];
                                        $emailStr = $emp['email'];
                                        $phoneStr = $emp['phone'] ?: ($emp['whatsapp'] ?: '-');
                                        $addressStr = $emp['address'] ?: ($emp['address_detail'] ?: '-');

                                        $locArr = array_filter([$emp['village'] ?? null, $emp['district'] ?? null, $emp['city'] ?? null, $emp['province'] ?? null]);
                                        $locationStr = !empty($locArr) ? implode(', ', $locArr) : ($emp['domicile_city_id'] ?: ($emp['city'] ?: '-'));

                                        $vStatus = $emp['verification_status'] ?? '';
                                        if ($vStatus === 'APPROVED' || !empty($emp['verified'])) {
                                            $badgeHtml = '<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Terverifikasi</span>';
                                        } elseif ($vStatus === 'NEEDS_REVISION') {
                                            $revNum = (int)($emp['revision_count'] ?? ($emp['rejection_count'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $badgeHtml = '<span class="pill-badge revision" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Revisi Diminta (ke-' . $revNum . ')</span>';
                                        } elseif ($vStatus === 'REJECTED' || $vStatus === 'FULL_DISABLED') {
                                            $badgeHtml = '<span class="pill-badge rejected" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Ditolak</span>';
                                        } else {
                                            $badgeHtml = '<span class="pill-badge pending" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Dikirim</span>';
                                        }

                                        $dateStr = date('d M Y, H:i', strtotime($emp['created_at']));
                                    ?>
EOD;

$targetNorm = str_replace("\r\n", "\n", $target);
$replacementNorm = str_replace("\r\n", "\n", $replacement);

if (strpos($contentNorm, $targetNorm) !== false) {
    $contentNorm = str_replace($targetNorm, $replacementNorm, $contentNorm);
    file_put_contents($adminFile, $contentNorm);
    echo "Table formatting updated successfully.\n";
} else {
    echo "Target string not found.\n";
}
