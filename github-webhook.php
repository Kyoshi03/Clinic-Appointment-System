<?php
$payload = file_get_contents('php://input');

file_put_contents(
    'webhook-log.txt',
    date('Y-m-d H:i:s') . " - Webhook received\n",
    FILE_APPEND
);

http_response_code(200);
echo "Webhook received";
?>
Compose
Write to Vincent Cyre
