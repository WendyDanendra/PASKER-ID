<?php
$filepath = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID/Index.html';
$content = file_get_contents($filepath);

$target = '                    </div>
                </div>



                <!-- MODAL POP-UP KONFLIK KBJI -->';

$replacement = '                    </div>
                </div>
                <?php endif; ?>



                <!-- MODAL POP-UP KONFLIK KBJI -->';

$content = str_replace($target, $replacement, $content);
$targetCRLF = str_replace("\n", "\r\n", $target);
$replacementCRLF = str_replace("\n", "\r\n", $replacement);
$content = str_replace($targetCRLF, $replacementCRLF, $content);

file_put_contents($filepath, $content);
echo "Added missing endif to Index.html\n";
