<?php
header('Content-Type: application/json');
echo json_encode(array('ok' => true, 'php' => PHP_VERSION, 'post_max_size' => ini_get('post_max_size'), 'memory_limit' => ini_get('memory_limit')));
