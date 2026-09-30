<?php
$filepath = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID/Index.html';
$lines = file($filepath);

$stack = [];
foreach ($lines as $i => $line) {
    $lineNum = $i + 1;
    // find all <?php if or <?php elseif or <?php else or <?php endif
    preg_match_all('/<\?php\s+(if|endif)\b/i', $line, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[1] as $m) {
        $token = strtolower($m[0]);
        if ($token === 'if') {
            $stack[] = ['line' => $lineNum, 'text' => trim($line)];
        } elseif ($token === 'endif') {
            if (empty($stack)) {
                echo "UNMATCHED endif at line {$lineNum}: " . trim($line) . "\n";
            } else {
                array_pop($stack);
            }
        }
    }
}

echo "Remaining unclosed IF tags: " . count($stack) . "\n";
foreach (array_slice($stack, -10) as $unclosed) {
    echo "Unclosed IF at line {$unclosed['line']}: {$unclosed['text']}\n";
}
