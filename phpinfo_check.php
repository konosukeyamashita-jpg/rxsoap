<?php
header('Content-Type: text/plain; charset=utf-8');
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "max_input_time: " . ini_get('max_input_time') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "default_socket_timeout: " . ini_get('default_socket_timeout') . "\n";
echo "php_sapi_name: " . php_sapi_name() . "\n";
echo "user_ini.filename: " . ini_get('user_ini.filename') . "\n";
echo "user_ini.cache_ttl: " . ini_get('user_ini.cache_ttl') . "\n";
