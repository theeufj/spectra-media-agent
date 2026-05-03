<?php
$css = file_get_contents('https://proveably.com/static/css/output.css?v=fd4ff99c');
preg_match_all('/#([a-fA-F0-9]{6})\b/', $css, $matches);
$colors = array_unique(array_map('strtolower', $matches[0]));
print_r($colors);
