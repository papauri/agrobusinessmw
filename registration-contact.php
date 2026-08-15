<?php
/** AgroBusiness Malawi — final server-side contact validation. */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'POST method required']); exit; }
function fail(string $message, int $status=400) { http_response_code($status); echo json_encode(['success'=>false,'error'=>$message]); exit; }
function canonical_phone(?string $value): ?string {
    $value=trim((string)$value); if($value==='') return null;
    $value=preg_replace('/[^0-9+]/','',$value); $value=preg_replace('/^\++/','+',$value); if($value==='') return null;
    if($value[0]==='+') return preg_match('/^\+[1-9][0-9]{7,14}$/',$value)?$value:null;
    if(strpos($value,'265')===0 && strlen($value)>=10){$v='+'.$value;return preg_match('/^\+[1-9][0-9]{7,14}$/',$v)?$v:null;}
    if(preg_match('/^0[0-9]{9}$/',$value)){$v='+265'.substr($value,1);return preg_match('/^\+[1-9][0-9]{7,14}$/',$v)?$v:null;}
    if(preg_match('/^[89][0-9]{8}$/',$value)){$v='+265'.$value;return preg_match('/^\+[1-9][0-9]{7,14}$/',$v)?$v:null;}
    return null;
}
function stmt_one(mysqli_stmt $stmt): ?array {
    $meta=$stmt->result_metadata(); if(!$meta) return null; $fields=[]; $row=[];
    while($field=$meta->fetch_field()){ $row[$field->name]=null; $fields[]=&$row[$field->name]; }
    call_user_func_array([$stmt,'bind_result'],$fields);
    return $stmt->fetch()?array_map(fn($v)=>$v,$row):null;
}
$envFile=__DIR__.'/.env';
if(file_exists($envFile)) foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){if($line===''||$line[0]==='#'||strpos($line,'=')===false)continue;[$key,$val]=explode('=',$line,2);$_ENV[trim($key)]=trim($val);}
$host=$_ENV['DB_HOST']??'';$user=$_ENV['DB_USER']??'';$pass=$_ENV['DB_PASS']??'';$name=$_ENV['DB_NAME']??'';$port=(int)($_ENV['DB_PORT']??3306);
if(!$host||!$user||!$name)fail('Database configuration is missing.',500);
$db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);if(!@$db->real_connect($host,$user,$pass,$name,$port))fail('Database connection failed.',500);$db->set_charset('utf8mb4');
$body=json_decode(file_get_contents('php://input'),true)??[];$ref=strtoupper(trim((string)($body['application_ref']??'')));$phone=canonical_phone($body['phone_number']??null);$whatsapp=canonical_phone($body['whatsapp_number']??null);
if(!preg_match('/^AGR-[0-9]{8}-[A-Z0-9]{5}$/',$ref))fail('Invalid application reference.');
if($phone===null)fail('A valid international phone number is required.');
if(($body['whatsapp_number']??'')!==''&&$whatsapp===null)fail('WhatsApp number is invalid.');
$check=$db->prepare('SELECT id FROM onboarding_applications WHERE application_ref=? AND phone_number=? LIMIT 1');if(!$check)fail('Could not validate application.',500);$check->bind_param('ss',$ref,$phone);$check->execute();if(!stmt_one($check))fail('Application and phone number do not match.');
$update=$db->prepare('UPDATE onboarding_applications SET phone_number=?, whatsapp_number=? WHERE application_ref=?');if(!$update)fail('Could not prepare contact update.',500);$update->bind_param('sss',$phone,$whatsapp,$ref);if(!$update->execute())fail('Could not save contact details.',500);
echo json_encode(['success'=>true,'phone_number'=>$phone,'whatsapp_number'=>$whatsapp]);
