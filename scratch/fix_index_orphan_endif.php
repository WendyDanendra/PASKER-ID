<?php
$filepath = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID/Index.html';
$content = file_get_contents($filepath);

$content = str_replace("                            </div>\n                        </div>\n                        <?php endif; ?>\n\n                        <!-- RIGHT COLUMN: PIC PEMBERI KERJA -->", "                            </div>\n                        </div>\n\n                        <!-- RIGHT COLUMN: PIC PEMBERI KERJA -->", $content);

$content = str_replace("                            </div>\n                        </div>\n                        <?php endif; ?>\n                    </div>", "                            </div>\n                        </div>\n                    </div>", $content);

// Also try with CRLF
$content = str_replace("                            </div>\r\n                        </div>\r\n                        <?php endif; ?>\r\n\r\n                        <!-- RIGHT COLUMN: PIC PEMBERI KERJA -->", "                            </div>\r\n                        </div>\r\n\r\n                        <!-- RIGHT COLUMN: PIC PEMBERI KERJA -->", $content);

$content = str_replace("                            </div>\r\n                        </div>\r\n                        <?php endif; ?>\r\n                    </div>", "                            </div>\r\n                        </div>\r\n                    </div>", $content);

file_put_contents($filepath, $content);
echo "Orphan endif removed from Index.html\n";
