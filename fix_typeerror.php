<?php

$file = 'app/Http/Controllers/Api/SetController.php';
$content = file_get_contents($file);
$content = str_replace(
    "} catch (\Exception \$e) {",
    "} catch (\Throwable \$e) {",
    $content
);
file_put_contents($file, $content);
