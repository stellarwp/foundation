<?php declare(strict_types=1);

ob_start();
echo 'Partial view output.';

throw new RuntimeException('View rendering failed.');
