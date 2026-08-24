<?php declare(strict_types=1);

ob_start();
echo 'Balanced view output.';
$output = ob_get_clean();

echo $output;
