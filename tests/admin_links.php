<?php
declare(strict_types=1);
require __DIR__.'/../helpers/format.php';
$server=['id'=>7,'type'=>'marzban','remark'=>'test'];
$user=['username'=>'u','status'=>'active','subscription_url'=>'','raw'=>['admin'=>['username'=>'Admin_Name']]];
$card=Formatter::userCard($server,$user,'holder_bot');
if(!preg_match('~https://t\.me/holder_bot\?start=admin_7_([A-Za-z0-9_-]+)~',$card,$match))throw new RuntimeException('Administrator owner link missing');
$encoded=strtr($match[1],'-_','+/');$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);
if(base64_decode($encoded,true)!=='Admin_Name')throw new RuntimeException('Administrator owner link damaged username');
echo "PASS: user cards link safely to administrator details\n";
