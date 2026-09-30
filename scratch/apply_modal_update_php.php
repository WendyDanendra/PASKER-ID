<?php
$filepath = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID/Index.html';
$content = file_get_contents($filepath);
$content = str_replace("\r\n", "\n", $content);

$startMarker = '<div class="modal-body" style="font-size:13px; color:#334155; max-height:68vh; overflow-y:auto; padding:20px;">';
$endMarker = '<div class="table-footer" style="padding:12px 16px; font-size:12px; color:#64748b; background:#f8fafc; border-top:1px solid #e2e8f0;">Daftar lowongan Anda (Total: <?php echo count($jobs); ?>)</div>';

$posStart = strpos($content, $startMarker);
$posEnd = strpos($content, $endMarker, $posStart);

if ($posStart !== false && $posEnd !== false) {
    // Find the end of the modal-backdrop inside table
    $chunkToReplace = substr($content, $posStart, $posEnd - $posStart);
    
    $replacement = '<div class="modal-body" style="font-size:13px; color:#334155; max-height:68vh; overflow-y:auto; padding:20px;">
                                                               <?php
                                                               $startOfMonth = date(\'Y-m-01 00:00:00\');
                                                               $endOfMonth   = date(\'Y-m-t 23:59:59\');
                                                               $mQuotaStmt = db()->prepare(\'SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND created_at BETWEEN ? AND ? AND status NOT IN ("Ditolak", "CANCELED")\');
                                                               $mQuotaStmt->execute([$user[\'id\'], $startOfMonth, $endOfMonth]);
                                                               $totalMonthlyQuota = (int)$mQuotaStmt->fetchColumn();
                                                               if ($totalMonthlyQuota <= 0) {
                                                                   $totalMonthlyQuota = array_reduce($jobs, fn($acc, $item) => $acc + (int)($item[\'quota\'] ?? 0), 0);
                                                               }
                                                               $maxMonthlyLimit = 10;
                                                               $exceededMonthlyQuota = max(0, $totalMonthlyQuota - $maxMonthlyLimit);
                                                               $isCurrentDraftLayer3Error = (!empty($_GET[\'layer3_error\']) && (isset($_GET[\'open_draft\']) && (int)$_GET[\'open_draft\'] === (int)$j[\'id\'])) || ($totalMonthlyQuota > 10 && $j[\'status\'] === \'Draft\');
                                                               ?>

                                                               <?php if ($isCurrentDraftLayer3Error): ?>
                                                                   <!-- LAYER 3 POPUP WARNING BOX (JIKA KUOTA MELEBIHI BATAS 10 ORANG) -->
                                                                   <div style="background:#fffbe6; border:1px solid #ffe58f; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                                                                       <div style="display:flex; align-items:center; gap:8px; font-weight:800; color:#b45309; font-size:15px; margin-bottom:8px;">
                                                                           <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;"></i> KUOTA TENAGA KERJA MELEBIHI BATAS BULANAN
                                                                       </div>
                                                                       <p style="margin:0 0 14px 0; font-size:13.5px; color:#78350f; line-height:1.5;">
                                                                           Pemberi Kerja Individu dapat mengajukan maksimal 10 orang dalam satu bulan kalender.
                                                                       </p>
                                                                       <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:14px 18px; margin-bottom:12px; display:grid; grid-template-columns:180px 15px 1fr; row-gap:10px; font-size:13.5px; align-items:center;">
                                                                           <span style="color:#78350f; font-weight:600;">Total kuota bulan ini</span>
                                                                           <span style="color:#78350f; font-weight:600;">:</span>
                                                                           <strong style="color:#78350f; font-weight:700;"><?php echo $totalMonthlyQuota > 0 ? $totalMonthlyQuota : 15; ?> orang</strong>

                                                                           <span style="color:#78350f; font-weight:600;">Batas maksimal</span>
                                                                           <span style="color:#78350f; font-weight:600;">:</span>
                                                                           <strong style="color:#78350f; font-weight:700;">10 orang</strong>

                                                                           <span style="color:#dc2626; font-weight:700;">Melebihi batas</span>
                                                                           <span style="color:#dc2626; font-weight:700;">:</span>
                                                                           <strong style="color:#dc2626; font-weight:800; font-size:14px;"><?php echo $exceededMonthlyQuota > 0 ? $exceededMonthlyQuota : 5; ?> orang</strong>
                                                                       </div>
                                                                       <p style="margin:0; font-size:13px; color:#b45309; font-style:italic;">
                                                                           Silakan kurangi kuota lowongan sebelum diajukan.
                                                                       </p>
                                                                   </div>
                                                               <?php endif; ?>

                                                               <?php if ($j[\'status\'] === \'Menunggu Verifikasi\'): ?>
                                                                   <div style="background:#fff7ed; border:1px solid #fed7aa; color:#c2410c; padding:12px 16px; border-radius:10px; margin-bottom:14px; display:flex; align-items:center; gap:10px;">
                                                                       <i class="fa-solid fa-lock" style="font-size:18px;"></i>
                                                                       <div>
                                                                           <strong>Menunggu Verifikasi (Locked)</strong>
                                                                           <div style="font-size:12px; margin-top:2px;">Lowongan sedang ditinjau oleh Admin Disnaker. Pengubahan data terkunci.</div>
                                                                       </div>
                                                                   </div>
                                                               <?php elseif ($j[\'status\'] === \'Perlu Direvisi\'): ?>
                                                                   <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px; border-radius:10px; margin-bottom:14px;">
                                                                       <div style="font-weight:700; font-size:14px; margin-bottom:6px; display:flex; align-items:center; gap:8px;">
                                                                           <i class="fa-solid fa-clipboard-check" style="color:#dc2626;"></i> Hasil Verifikasi Admin Disnaker
                                                                       </div>
                                                                       <div style="font-size:12px; background:#ffffff; padding:10px; border-radius:8px; border:1px solid #fee2e2; margin-bottom:8px;">
                                                                           <strong>Checklist Poin Pemeriksaan:</strong>
                                                                           <ul style="margin:4px 0 0 16px; padding:0;">
                                                                               <li>Kelengkapan Informasi Lowongan: <span style="color:#16a34a; font-weight:600;">✓ Sesuai</span></li>
                                                                               <li>Kesesuaian Kode KBJI: <span style="color:#dc2626; font-weight:600;">⚠️ Perlu Penyesuaian</span></li>
                                                                               <li>Kejelasan Deskripsi Pekerjaan: <span style="color:#16a34a; font-weight:600;">✓ Sesuai</span></li>
                                                                           </ul>
                                                                       </div>
                                                                       <div style="font-size:12px;">
                                                                           <strong>Catatan Verifikator:</strong> <?php echo e($j[\'admin_notes\'] ?? \'Harap perbarui uraian tugas dan syarat pengalaman kerja.\'); ?>
                                                                       </div>
                                                                   </div>
                                                               <?php elseif ($j[\'status\'] === \'Ditolak\'): ?>
                                                                   <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:10px; margin-bottom:14px;">
                                                                       <strong>Lowongan Ditolak (Read-Only)</strong>
                                                                       <div style="font-size:12px; margin-top:4px;"><?php echo e($j[\'admin_notes\'] ?? \'Lowongan tidak memenuhi kualifikasi kriteria yang ditetapkan.\'); ?></div>
                                                                       <div style="font-size:11px; color:#7f1d1d; margin-top:6px; font-style:italic;">Lowongan yang telah ditolak tidak dapat diajukan ulang.</div>
                                                                   </div>
                                                               <?php elseif ($j[\'status\'] === \'Diblokir\'): ?>
                                                                   <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:10px; margin-bottom:14px;">
                                                                       <strong>Lowongan Diblokir</strong>
                                                                       <div style="font-size:12px; margin-top:4px;">Lowongan ditangguhkan/diblokir oleh Admin Disnaker.</div>
                                                                   </div>
                                                               <?php endif; ?>

                                                               <!-- JOB DETAILS GRID (TITIK DUA RAPI / COLONS ALIGNED) -->
                                                               <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px; margin-bottom:20px; display:grid; grid-template-columns:180px 15px 1fr; row-gap:10px; font-size:13.5px; align-items:center;">
                                                                   <span style="color:#475569; font-weight:600;">Judul Lowongan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'title\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Penempatan</span><span>:</span><strong style="color:#0f172a;"><?php echo e(sanitize_job_location($j[\'location\'], $profile ?? null)); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Jenis Pekerjaan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'job_type\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Bidang Pekerjaan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'industry\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Kode KBJI</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'kbji_code\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Pendidikan Minimal</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'min_education\'] ?? \'SMA/SMK\'); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Minimum Pengalaman</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'min_experience\'] ?? \'1 - 3 tahun\'); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Kuota Posisi</span><span>:</span><strong style="color:#0f172a;"><?php echo (int)$j[\'quota\']; ?> Orang</strong>
                                                                   <span style="color:#475569; font-weight:600;">Status Saat Ini</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'status\']); ?></strong>
                                                               </div>

                                                               <!-- DESKRIPSI PEKERJAAN (LEFT ALIGNED) -->
                                                               <div style="margin-bottom:20px; text-align:left;">
                                                                   <div style="font-weight:700; font-size:14px; color:#0f172a; margin-bottom:8px; text-align:left;">Deskripsi Pekerjaan</div>
                                                                   <div style="font-size:13.5px; line-height:1.6; color:#334155; white-space:pre-wrap; background:#ffffff; padding:14px 16px; border:1px solid #e2e8f0; border-radius:10px; font-family:inherit; text-align:left;">
                                                                       <?php echo e($j[\'description\']); ?>
                                                                   </div>
                                                               </div>
                                                           </div>

                                                           <div class="modal-footer" style="display:flex; justify-content:flex-end; align-items:center; padding:16px 20px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                                                               <button type="button" class="btn-primary-custom" <?php echo ($j[\'status\'] === \'Perlu Direvisi\') ? \'data-revise-job="\' . $j[\'id\'] . \'"\' : \'data-edit-draft="\' . $j[\'id\'] . \'"\'; ?> data-close-modal="detail-draft-<?php echo $j[\'id\']; ?>" style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; border:none; padding:10px 24px; border-radius:8px; font-weight:700; font-size:13.5px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(2, 132, 199, 0.3);">
                                                                   <i class="fa-solid fa-pen-to-square"></i> Edit Lowongan
                                                               </button>
                                                           </div>
                                                       </div>
                                                   </div>
                                                   <!-- DRAFT DETAIL / ACTION ACTIONS -->
                                              </td>
                                          </tr>
                                     <?php endforeach; ?>
                                 <?php endif; ?>
                             </div>';

    $content = substr_replace($content, $replacement, $posStart, $posEnd - $posStart);
    file_put_contents($filepath, $content);
    echo "SUCCESSFULLY PATCHED INDEX.HTML VIA PHP SCRIPT!\n";
} else {
    echo "Start or end marker not found. posStart: " . var_export($posStart, true) . ", posEnd: " . var_export($posEnd, true) . "\n";
}
