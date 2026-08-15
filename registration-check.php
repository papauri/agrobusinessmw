<?php
/** AgroBusiness Malawi — registration preflight validation. */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'GET method required']); exit; }
function canonical_phone(?string $value): ?string {
    $value=trim((string)$value); if($value==='') return null; $value=preg_replace('/[^0-9+]/','',$value); $value=preg_replace('/^\++/','+',$value); if($value==='')return null;
    if($value[0]==='+')return preg_match('/^\+[1-9][0-9]{7,14}$/',$value)?$value:null;
    if(str_starts_with($value,'265')&&strlen($value)>=10){$v='+'.$value;return preg_match('/^\+[1-9][0-9]{7,14}$/',$v)?$v:null;}
    if(preg_match('/^0[0-9]{9}$/',$value)){$v='+265'.substr($value,1);return preg_match('/^\+[1-9][0-9]{7,14}$/',$v)?$v:null;}
    if(preg_match('/^[89][0-9]{8}$/',$value)){$v='+265'.$value;return preg_match('/^\+[1-9][0-9]{7,14}$/',$v)?$v:null;}
    return null;
}
function stmt_all(mysqli_stmt $stmt): array {
    $meta=$stmt->result_metadata(); if(!$meta)return[]; $fields=[];$row=[];
    while($field=$meta->fetch_field()){$row[$field->name]=null;$fields[]=&$row[$field->name];}
    call_user_func_array([$stmt,'bind_result'],$fields);$rows=[];while($stmt->fetch())$rows[]=array_map(fn($v)=>$v,$row);return$rows;
}
$envFile=__DIR__.'/.env';if(file_exists($envFile))foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)as$line){if($line===''||$line[0]==='#'||strpos($line,'=')===false)continue;[$key,$val]=explode('=',$line,2);$_ENV[trim($key)]=trim($val);}
$host=$_ENV['DB_HOST']??'';$user=$_ENV['DB_USER']??'';$pass=$_ENV['DB_PASS']??'';$name=$_ENV['DB_NAME']??'';$port=(int)($_ENV['DB_PORT']??3306);
if(!$host||!$user||!$name){http_response_code(500);echo json_encode(['success'=>false,'error'=>'Database configuration is missing.']);exit;}
$db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);if(!@$db->real_connect($host,$user,$pass,$name,$port)){http_response_code(500);echo json_encode(['success'=>false,'error'=>'Database connection failed.']);exit;}$db->set_charset('utf8mb4');
$phone=canonical_phone($_GET['phone']??null);$whatsapp=canonical_phone($_GET['whatsapp_number']??null);$email=trim($_GET['email']??'');$nationalId=trim($_GET['national_id']??'');$fullName=trim($_GET['full_name']??'');$matches=[];
if($phone===null){echo json_encode(['success'=>false,'error'=>'Invalid phone number.']);exit;}
if(($_GET['whatsapp_number']??'')!==''&&$whatsapp===null){echo json_encode(['success'=>false,'error'=>'Invalid WhatsApp number.']);exit;}
$lookup=function(string $sql,string $value,string $field,bool $hard)use($db,&$matches){$st=$db->prepare($sql);if(!$st)return;$st->bind_param('s',$value);$st->execute();foreach(stmt_all($st)as$row)$matches[]=['field'=>$field,'hard'=>$hard,'ref'=>$row['application_ref'],'status'=>$row['status'],'type'=>$row['user_type']];};
$lookup('SELECT application_ref,status,user_type FROM onboarding_applications WHERE phone_number=? LIMIT 1',$phone,'phone',true);
if($whatsapp!==null)$lookup('SELECT application_ref,status,user_type FROM onboarding_applications WHERE whatsapp_number=? LIMIT 1',$whatsapp,'whatsapp',true);
if($email!=='')$lookup('SELECT application_ref,status,user_type FROM onboarding_applications WHERE email<>\'\' AND email=? LIMIT 1',$email,'email',true);
if($nationalId!=='')$lookup('SELECT application_ref,status,user_type FROM onboarding_applications WHERE national_id<>\'\' AND national_id=? LIMIT 1',$nationalId,'national_id',true);
if($fullName!=='')$lookup('SELECT application_ref,status,user_type FROM onboarding_applications WHERE full_name=? LIMIT 1',$fullName,'name',false);
echo json_encode(['success'=>true,'exists'=>count($matches)>0,'matches'=>$matches]);
