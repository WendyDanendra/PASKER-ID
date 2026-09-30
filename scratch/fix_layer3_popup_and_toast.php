<?php
$baseDir = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID';

// 1. Update Index.html
$indexFile = $baseDir . '/Index.html';
$indexCode = file_get_contents($indexFile);

// 1.1 Suppress Toast notification when layer3_error is present in URL
$oldToastCheck = '$isAnyModalOpenOnLoad = !empty($_GET[\'open_draft\']) || !empty($_GET[\'job_detail\']) || !empty($_GET[\'kbji_conflict\']) || !empty($kbjiDuplicateError) || !empty($pendingPopupMessage) || !empty($_SESSION[\'kbji_duplicate_error\']);';
$newToastCheck = '$isAnyModalOpenOnLoad = !empty($_GET[\'open_draft\']) || !empty($_GET[\'job_detail\']) || !empty($_GET[\'kbji_conflict\']) || !empty($kbjiDuplicateError) || !empty($pendingPopupMessage) || !empty($_SESSION[\'kbji_duplicate_error\']) || !empty($_GET[\'layer3_error\']);';

if (str_contains($indexCode, $oldToastCheck)) {
    $indexCode = str_replace($oldToastCheck, $newToastCheck, $indexCode);
}

// 1.2 Fix grid column width for Layer 3 Warning Box colons alignment (160px -> 190px)
$oldGridCol = 'display:inline-grid; grid-template-columns:160px 15px 1fr;';
$newGridCol = 'display:grid; grid-template-columns:190px 15px 1fr;';

if (str_contains($indexCode, $oldGridCol)) {
    $indexCode = str_replace($oldGridCol, $newGridCol, $indexCode);
}

// 1.3 Hide Detail Tabs Section for Draft jobs so only the ASCII mockup card shows
$oldTabsStart = '</div><!-- Detail Tabs Section -->
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
$newTabsStart = '</div><!-- Detail Tabs Section -->
                        <?php if ($dStatus !== \'Draft\'): ?>
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';

if (str_contains($indexCode, $oldTabsStart)) {
    $indexCode = str_replace($oldTabsStart, $newTabsStart, $indexCode);
}

// Close the if ($dStatus !== 'Draft') at the end of detail tabs section
$oldTabsEnd = '</div>
                    </div>
                </section>';
$newTabsEnd = '</div>
                        <?php endif; ?>
                    </div>
                </section>';

if (str_contains($indexCode, $oldTabsEnd)) {
    $indexCode = str_replace($oldTabsEnd, $newTabsEnd, $indexCode);
}

file_put_contents($indexFile, $indexCode);
echo "Index.html updated for Layer 3 popup and toast suppression.\n";


// 2. Update dashboard.php to unset flash on layer3_error
$dashFile = $baseDir . '/dashboard.php';
$dashCode = file_get_contents($dashFile);

$oldLayer3Redirect = '// Layer 3: Monthly quota exceeded (>10) - Open modal directly without top toast notification
                redirect(\'dashboard.php?open_draft=\' . $jobId . \'&layer3_error=1#lowongan\');';
$newLayer3Redirect = '// Layer 3: Monthly quota exceeded (>10) - Open modal directly without top toast notification
                unset($_SESSION[\'flash\']);
                redirect(\'dashboard.php?open_draft=\' . $jobId . \'&layer3_error=1#lowongan\');';

if (str_contains($dashCode, $oldLayer3Redirect)) {
    $dashCode = str_replace($oldLayer3Redirect, $newLayer3Redirect, $dashCode);
    file_put_contents($dashFile, $dashCode);
    echo "dashboard.php updated.\n";
} else {
    echo "dashboard.php target string not found or already updated.\n";
}
