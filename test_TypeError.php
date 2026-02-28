<?php
try {
    $x = function(string $s) {};
    $x(null);
} catch (\Exception $e) {
    echo "Caught exception\n";
} catch (\TypeError $e) {
    echo "Caught TypeError\n";
}
