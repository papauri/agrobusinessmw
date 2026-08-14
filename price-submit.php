<?php
// Public community price submission endpoint with optional market, area and email.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['success'=>false,'error'=>'POST required.'],405);

$envFile=__DIR__.'/.env';
if(file_exists($envFile)) foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    if($line===''||$line[0]==='#'||strpos($line,'=')===false)continue;
    [$key,$value]=explode('=',$line,2);
    $_ENV[trim($key)]=trim($value);
}

$db=@new mysqli($_ENV['DB_HOST']??'localhost',$_ENV['DB_USER']??'',$_ENV['DB_PASS']??'',$_ENV['DB_NAME']??'',(int)($_ENV['DB_PORT']??3306));
if($db->connect_error)respond(['success'=>false,'error'=>'Database unavailable.'],503);
$db->set_charset('utf8mb4');

$cropId=(int)($_POST['crop_id']??$_POST['crop']??0);
$price=(float)($_POST['price_per_kg']??$_POST['price']??0);
$marketId=(int)($_POST['market_id']??0);
$areaId=(int)($_POST['area_id']??0);
$districtId=(int)($_POST['district_id']??0);
$email=trim((string)($_POST['email']??''));
$submittedBy=trim((string)($_POST['submitted_by']??'Community reporter'));

if($cropId<=0||$price<=0)respond(['success'=>false,'error'=>'Crop and a valid price per kg are required.'],422);
if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))respond(['success'=>false,'error'=>'Please enter a valid email address or leave it blank.'],422);

$marketName=null;$marketDistrict=null;$areaDistrict=null;

if($marketId>0){
    $s=$db->prepare('SELECT name,district_id FROM price_markets WHERE id=? AND active=1 LIMIT 1');
    $s->bind_param('i',$marketId);
    $s->execute();
    $s->bind_result($marketName,$marketDistrict);
    if(!$s->fetch()){$s->close();respond(['success'=>false,'error'=>'Selected market is not available.'],422);}
    $s->close();
}

$areaName=null;
if($areaId>0){
    $s=$db->prepare('SELECT name,district_id FROM price_areas WHERE id=? AND active=1 LIMIT 1');
    $s->bind_param('i',$areaId);
    $s->execute();
    $s->bind_result($areaName,$areaDistrict);
    if(!$s->fetch()){$s->close();respond(['success'=>false,'error'=>'Selected area is not available.'],422);}
    $s->close();
}

if($marketDistrict&&$areaDistrict&&$marketDistrict!==$areaDistrict)
    respond(['success'=>false,'error'=>'Market and area must be in the same district.'],422);

$resolvedDistrict=$marketDistrict?:$areaDistrict?:($districtId?:null);
if($resolvedDistrict&&$districtId&&$resolvedDistrict!==$districtId)
    respond(['success'=>false,'error'=>'Location selection does not match the district.'],422);

$status='pending';
$flagReason=null;
$unit='kg';
$channel='web';
$verified=0;
$isMember=0;
$pricePerBag=null;

/*
 * Use NULLIF for optional integer IDs. This prevents an unselected
 * market/area/district from being stored as numeric 0.
 */
$stmt=$db->prepare(
    'INSERT INTO crowdsourced_prices
     (crop_id,district_id,market_id,area_id,email,market_name,price_per_kg,
      price_per_bag,unit,submitted_by,channel,verified,status,is_member,
      flag_reason,created_at)
     VALUES (?,NULLIF(?,0),NULLIF(?,0),NULLIF(?,0),NULLIF(?,\'\'),?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
);

if(!$stmt)respond(['success'=>false,'error'=>'Price submission could not be prepared.'],500);

$stmt->bind_param(
    'iiiissddsssisis',
    $cropId,
    $resolvedDistrict,
    $marketId,
    $areaId,
    $email,
    $marketName,
    $price,
    $pricePerBag,
    $unit,
    $submittedBy,
    $channel,
    $verified,
    $status,
    $isMember,
    $flagReason
);

if(!$stmt->execute()){
    $err=$stmt->error;
    $stmt->close();
    respond(['success'=>false,'error'=>'Price submission failed.','detail'=>$err],500);
}

$id=$stmt->insert_id;
$stmt->close();

respond([
    'success'=>true,
    'message'=>'Thank you. Your price report has been submitted for review.',
    'price_report_id'=>(int)$id,
    'status'=>'pending'
]);
