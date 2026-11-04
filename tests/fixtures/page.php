<?php

if ($_SERVER['REQUEST_URI'] === '/error') {
  http_response_code(500);
}
header('Content-Type: text/html');
echo '<!doctype html><html><body><h1>Browser capture fixture</h1></body></html>';
