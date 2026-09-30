<?php
$baseDir = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID';
$indexFile = $baseDir . '/Index.html';
$indexCode = file_get_contents($indexFile);

// Normalize line endings to LF for matching
$indexCode = str_replace("\r\n", "\n", $indexCode);

$oldStart = '                        </div><!-- Detail Tabs Section -->
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';

$newStart = '                        </div><!-- Detail Tabs Section -->
                        <?php if ($dStatus !== \'Draft\'): ?>
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';

if (str_contains($indexCode, $oldStart)) {
    $indexCode = str_replace($oldStart, $newStart, $indexCode);
    echo "Replaced start of Detail Tabs Section.\n";
} else {
    echo "start not found.\n";
}

$oldEnd = '                                    </div>
                                </div>
                            </div>
                        </div>';

$newEnd = '                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>';

if (str_contains($indexCode, $oldEnd)) {
    $indexCode = str_replace($oldEnd, $newEnd, $indexCode);
    echo "Replaced end of Detail Tabs Section.\n";
} else {
    echo "end not found.\n";
}

file_put_contents($indexFile, $indexCode);
