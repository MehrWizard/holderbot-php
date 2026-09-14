<?php
declare(strict_types=1);
require __DIR__.'/../tgbot.php';
$now=time();
$source=['chat_id'=>99,'message_id'=>42,'date'=>$now-610];
if(!tg_callback_message_is_old(99,42,$source)
    || tg_callback_message_is_old(99,42,['chat_id'=>99,'message_id'=>42,'date'=>$now-590])
    || tg_callback_message_is_old(99,43,$source)
    || tg_callback_message_is_old(98,42,$source)
    || tg_callback_message_is_old(99,42,['chat_id'=>99,'message_id'=>42])) {
    throw new RuntimeException('Callback age boundary or message identity is incorrect');
}
echo "PASS: callback message age and identity boundary\n";
