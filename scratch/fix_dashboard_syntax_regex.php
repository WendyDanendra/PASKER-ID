<?php
$baseDir = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID';
$dashFile = $baseDir . '/dashboard.php';
$dashCode = file_get_contents($dashFile);

$oldChunk = '$isReactivation = ($isFullDisable || ($profile[\'verification_status\'] ?? \'\') === \'FULL_DISABLED\');

        if ($profile) {
            $stmt = db()->prepare(\'UPDATE employer_profiles SET
                owner_name = ?, nik = ?, phone = ?, whatsapp = ?, profession = ?, npwp = ?,
                linkedin = ?, facebook = ?, instagram = ?,
                same_location_siapkerja = ?, province = ?, city = ?, domicile_city_id = ?, district = ?, village = ?, postal_code = ?,
                same_address_siapkerja = ?, address = ?, address_detail = ?,
                latitude = ?, longitude = ?, permit_document = ?, doc_permission = ?,
                workplace_photo = ?, doc_location_photo = ?,
                description = ?, user_consent = ?, consent_accepted = ?,
                entity_type = "Individu", verification_status = ($isReactivation || !empty($profile[\'last_activated_at\']) || ($profile[\'verification_status\'] ?? \'\') === \'INACTIVE_REVERIFICATION_REQUIRED\' ? "REVERIFICATION_PENDING" : "PENDING"), verified = 0, active_until = NULL,
                extension_requested = 0, extension_status = "NONE",
                assigned_to = NULL, assigned_at = NULL, verifier_notes = NULL, verification_checklist = NULL,
                manual_review_status = NULL, consent_data_hash = NULL, consent_agreed = 0,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?\');
            $stmt->execute([
                $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $permitDoc, $permitDoc,
                $workplacePhoto, $workplacePhoto,
                $description, $consent, $consent,
                $user[\'id\']
            ]);';

$newChunk = '$isReactivation = ($isFullDisable || ($profile[\'verification_status\'] ?? \'\') === \'FULL_DISABLED\' || ($profile[\'verification_status\'] ?? \'\') === \'INACTIVE_REVERIFICATION_REQUIRED\' || !empty($profile[\'last_activated_at\']));
        $newVerStatus = $isReactivation ? \'REVERIFICATION_PENDING\' : \'PENDING\';

        if ($profile) {
            $stmt = db()->prepare(\'UPDATE employer_profiles SET
                owner_name = ?, nik = ?, phone = ?, whatsapp = ?, profession = ?, npwp = ?,
                linkedin = ?, facebook = ?, instagram = ?,
                same_location_siapkerja = ?, province = ?, city = ?, domicile_city_id = ?, district = ?, village = ?, postal_code = ?,
                same_address_siapkerja = ?, address = ?, address_detail = ?,
                latitude = ?, longitude = ?, permit_document = ?, doc_permission = ?,
                workplace_photo = ?, doc_location_photo = ?,
                description = ?, user_consent = ?, consent_accepted = ?,
                entity_type = "Individu", verification_status = ?, verified = 0, active_until = NULL,
                extension_requested = 0, extension_status = "NONE",
                assigned_to = NULL, assigned_at = NULL, verifier_notes = NULL, verification_checklist = NULL,
                manual_review_status = NULL, consent_data_hash = NULL, consent_agreed = 1,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?\');
            $stmt->execute([
                $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $permitDoc, $permitDoc,
                $workplacePhoto, $workplacePhoto,
                $description, $consent, $consent,
                $newVerStatus,
                $user[\'id\']
            ]);

            if ($isReactivation) {
                record_audit_log(\'employer\', $user[\'id\'], \'REVERIFICATION_SUBMITTED\', "Pengajuan Verifikasi Ulang Profil dikirim oleh pengguna.", $user[\'name\'] ?? $ownerName, \'employer\', true);
            } else {
                record_audit_log(\'employer\', $user[\'id\'], \'INITIAL_VERIFICATION_SUBMITTED\', "Pengajuan Verifikasi Profil Pertama dikirim oleh pengguna.", $user[\'name\'] ?? $ownerName, \'employer\', true);
            }';

// Normalize CRLF to LF for matching
$dashCodeNorm = str_replace("\r\n", "\n", $dashCode);
$oldChunkNorm = str_replace("\r\n", "\n", $oldChunk);

if (str_contains($dashCodeNorm, $oldChunkNorm)) {
    $dashCodeNorm = str_replace($oldChunkNorm, $newChunk, $dashCodeNorm);
    file_put_contents($dashFile, $dashCodeNorm);
    echo "Successfully replaced chunk in dashboard.php\n";
} else {
    echo "Could not find exact chunk.\n";
}
