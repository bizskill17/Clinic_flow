<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
session_name('clinic_flow_session'); session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start();
function respond(int $s,array $b):void{http_response_code($s);echo json_encode($b,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function body():array{$x=json_decode((string)file_get_contents('php://input'),true);return is_array($x)?$x:[];}
function clean(array $u):array{unset($u['passwordHash']);return $u;}
$cf=__DIR__.'/config.php';if(!is_file($cf))respond(503,['error'=>'Database configuration is missing.']);
try{$cfg=require $cf;$pdo=new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['name'].';charset=utf8mb4',$cfg['user'],$cfg['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_PERSISTENT=>true]);$pdo->exec('CREATE TABLE IF NOT EXISTS docs (collection VARCHAR(64) NOT NULL,id VARCHAR(100) NOT NULL,data LONGTEXT NOT NULL,updated_at DATETIME(3) NOT NULL,PRIMARY KEY(collection,id),KEY updated_at(updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');}catch(Throwable $e){error_log($e->getMessage());respond(500,['error'=>'Database connection failed.']);}
$cols=['spa_clients','spa_services','spa_invoices','spa_receipts','spa_categories','spa_cities','spa_states','spa_users','spa_business','ph_items','ph_item_categories','ph_suppliers','ph_moves','ph_receipts','ae_proc_categories','ae_procedures','ae_appointments','ae_consultations','ae_quotations','ae_sessions','ae_invoices','ae_consents','dept_openings','dept_usage','spa_mem_plans','spa_memberships','spa_redemptions','ph_supplier_payments','spa_mship_plans','spa_mships','wa_log','exp_categories','exp_vendors','exp_expenses','exp_vendor_payments'];$method=$_SERVER['REQUEST_METHOD']??'GET';$in=$method==='GET'?[]:body();$action=$method==='GET'?($_GET['action']??'collection'):($in['action']??'collection');
function findUser(PDO $p,string $key):?array{$q=$p->prepare('SELECT id,data FROM docs WHERE collection=?');$q->execute(['spa_users']);foreach($q as $r){$u=json_decode($r['data'],true);if(is_array($u)&&((string)$r['id']===$key||strcasecmp((string)($u['userId']??''),$key)===0)){$u['id']=$r['id'];return $u;}}return null;}
function me(PDO $p):array{$id=$_SESSION['user_id']??'';$last=(int)($_SESSION['last_seen']??0);if(!$id||time()-$last>28800){session_unset();session_destroy();respond(401,['error'=>'Session expired.']);}$u=findUser($p,(string)$id);if(!$u||($u['status']??'Active')!=='Active'){session_unset();session_destroy();respond(403,['error'=>'Account is inactive.']);}$_SESSION['last_seen']=time();return $u;}
function admin(array $u):bool{return($u['role']??'')==='Admin';}function manager(array $u):bool{return in_array(($u['role']??''),['Admin','Manager'],true);}function roleDept(array $u):string{return['Spa'=>'spa','Aesthetics'=>'aes','Pharmacy'=>'pha'][$u['role']??'']??'';}
function allowed(array $u,string $c,string $method):bool{if(manager($u))return true;if($c==='wa_log')return $method==='GET';if(in_array($c,['exp_categories','exp_vendors','exp_expenses','exp_vendor_payments'],true))return $method!=='DELETE';$d=roleDept($u);if(!$d)return false;if(in_array($c,['spa_clients','spa_cities','spa_states','spa_business'],true))return $method==='GET'||$c!=='spa_business';if($c==='spa_users')return $method==='GET';if($c==='ph_items'&&$d==='spa')return $method==='GET';if($d==='spa')return in_array($c,['spa_services','spa_invoices','spa_receipts','spa_categories','dept_openings','dept_usage','spa_mem_plans','spa_memberships','spa_redemptions','spa_mship_plans','spa_mships'],true);if($d==='aes')return in_array($c,['ae_proc_categories','ae_procedures','ae_appointments','ae_consultations','ae_quotations','ae_sessions','ae_invoices','ae_consents','dept_openings','dept_usage','ph_items'],true);if($d==='pha')return in_array($c,['ph_items','ph_item_categories','ph_suppliers','ph_moves','ph_receipts','spa_receipts','ph_supplier_payments'],true);return false;}
/* Actor-aware writes share the same locked check for forms and bulk imports. */
function save(PDO $p,string $c,string $id,array $d,bool $cancelling=false,?array $actor=null):array{
 $own=!$p->inTransaction();if($own)$p->beginTransaction();$related=[];
 try{
  $q=$p->prepare('SELECT data FROM docs WHERE collection=? AND id=? FOR UPDATE');$q->execute([$c,$id]);$stored=$q->fetchColumn();$old=$stored===false?[]:(json_decode((string)$stored,true)?:[]);
  if($actor!==null&&$stored!==false&&!admin($actor))throw new DomainException('Only Admin can edit existing records, including bulk imports.');
  if(!$cancelling&&(!empty($old['cancelled'])||!empty($d['cancelled'])))throw new RuntimeException('Cancelled vouchers cannot be edited. Use the cancellation action for active vouchers.');
  $q=$p->prepare('INSERT INTO docs(collection,id,data,updated_at)VALUES(?,?,?,NOW(3)) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_at=NOW(3)');$q->execute([$c,$id,json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  // Completing a linked appointment is a server-derived effect of a saved consultation/session.
  // Staff never receive permission to edit the appointment directly.
  if($actor!==null&&in_array($c,['ae_consultations','ae_sessions'],true)&&!empty($d['apptId'])){
   $apptId=(string)$d['apptId'];$q=$p->prepare('SELECT data FROM docs WHERE collection=? AND id=? FOR UPDATE');$q->execute(['ae_appointments',$apptId]);$value=$q->fetchColumn();$appt=$value===false?null:json_decode((string)$value,true);
   if(!$appt||!empty($appt['cancelled'])||($appt['status']??'')==='Cancelled'||($appt['clientId']??'')!==($d['clientId']??''))throw new RuntimeException('Choose an active appointment for this client.');
   if(($appt['status']??'')!=='Completed'){$appt['status']='Completed';$appt['updatedAt']=$d['updatedAt']??gmdate('Y-m-d\TH:i:s.000\Z');save($p,'ae_appointments',$apptId,$appt);}
   $appt['id']=$apptId;$related[]=['collection'=>'ae_appointments','record'=>$appt];
  }
  if($own)$p->commit();return $related;
 }catch(Throwable $e){if($own&&$p->inTransaction())$p->rollBack();throw $e;}
}
if($action==='brand'){$q=$pdo->prepare('SELECT id,data FROM docs WHERE collection=? AND id IN (?,?)');$q->execute(['spa_business','profile','logo']);$n='';$l='';foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if($r['id']==='profile')$n=(string)($d['name']??'');if($r['id']==='logo')$l=(string)($d['data']??'');}respond(200,['name'=>$n,'logo'=>$l]);}
if($action==='login'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=findUser($pdo,trim((string)($in['userId']??'')));if(!$u||($u['status']??'Active')!=='Active'||empty($u['passwordHash'])||!password_verify((string)($in['password']??''),(string)$u['passwordHash']))respond(401,['error'=>'Invalid User ID or password.']);session_regenerate_id(true);$_SESSION['user_id']=$u['id'];$_SESSION['last_seen']=time();respond(200,['user'=>clean($u)]);}
if($action==='logout'){session_unset();session_destroy();respond(200,['ok'=>true]);}
if($action==='me'){respond(200,['user'=>clean(me($pdo))]);}
if($action==='change_password'){$u=me($pdo);$next=(string)($in['newPassword']??'');if(strlen($next)<8||!password_verify((string)($in['oldPassword']??''),(string)($u['passwordHash']??'')))respond(400,['error'=>'Password change failed.']);$u['passwordHash']=password_hash($next,PASSWORD_DEFAULT);$u['mustChangePassword']=false;save($pdo,'spa_users',$u['id'],$u);respond(200,['ok'=>true]);}
if($action==='reset_password'){$actor=me($pdo);$target=findUser($pdo,(string)($in['userId']??''));$next=(string)($in['newPassword']??'');if(!admin($actor)||!$target||strlen($next)<8)respond(403,['error'=>'Not permitted.']);if(!admin($actor)&&($target['role']??'')==='Admin')respond(403,['error'=>'Managers cannot modify Admin accounts.']);$target['passwordHash']=password_hash($next,PASSWORD_DEFAULT);$target['mustChangePassword']=true;save($pdo,'spa_users',$target['id'],$target);respond(200,['ok'=>true]);}
/* ---- files (photos, consent PDFs, educational PDFs): stored privately, only logged-in users can read them ---- */
if($method==='POST'&&isset($_POST['action']))$action=(string)$_POST['action'];
function fileDir(array $cfg):string{$d=rtrim((string)($cfg['uploads']??dirname(__DIR__).'/uploads'),'/');if(!is_dir($d)&&!@mkdir($d,0750,true))respond(500,['error'=>'Upload folder could not be created.']);$h=$d.'/.htaccess';if(!is_file($h))@file_put_contents($h,"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");if(!is_file($d.'/index.html'))@file_put_contents($d.'/index.html','');return $d;}
function fileExt():array{return['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];}
function fileFind(string $dir,string $id):?array{if(!preg_match('/^[a-f0-9]{32}$/',$id))return null;foreach(fileExt() as $mime=>$ext){$f=$dir.'/'.$id.'.'.$ext;if(is_file($f))return[$f,$mime];}return null;}
if($action==='file'){$u=me($pdo);$hit=fileFind(fileDir($cfg),(string)($_GET['id']??''));if(!$hit){http_response_code(404);header('Content-Type: text/plain');echo 'Not found';exit;}header_remove('Content-Type');header('Content-Type: '.$hit[1]);header('Content-Length: '.filesize($hit[0]));header('Content-Disposition: inline');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=86400');readfile($hit[0]);exit;}
if($action==='file_upload'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!manager($u)&&!in_array(roleDept($u),['spa','aes','pha'],true))respond(403,['error'=>'Not permitted for your role.']);$f=$_FILES['file']??null;if(!$f||($f['error']??1)!==UPLOAD_ERR_OK)respond(400,['error'=>'Upload failed. The file may be too large.']);if($f['size']>15*1024*1024)respond(400,['error'=>'The file must be 15 MB or smaller.']);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=fileExt()[$mime]??null;if(!$ext)respond(400,['error'=>'Only PDF, JPG or PNG files are allowed.']);$id=bin2hex(random_bytes(16));if(!move_uploaded_file($f['tmp_name'],fileDir($cfg).'/'.$id.'.'.$ext))respond(500,['error'=>'Could not save the file.']);respond(200,['id'=>$id,'type'=>$mime]);}
if($action==='file_delete'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can delete existing files.']);$hit=fileFind(fileDir($cfg),(string)($in['id']??''));if($hit)@unlink($hit[0]);respond(200,['ok'=>true]);}
/* ---- WhatsApp (Meta Cloud API): settings are kept server-side; the access token never goes back to the browser ---- */
function waGroups():array{return['invoice','quotation','receipt','prescription','education','birthday'];}
function waDefaults():array{return['invoice'=>'veda_invoice','quotation'=>'veda_quotation','receipt'=>'veda_receipt','prescription'=>'veda_prescription','education'=>'veda_education','birthday'=>'veda_birthday'];}
function waCfg(PDO $p):array{$q=$p->prepare('SELECT data FROM docs WHERE collection=? AND id=?');$q->execute(['wa_config','main']);$r=$q->fetch();$d=$r?(json_decode($r['data'],true)?:[]):[];$d+=['phoneId'=>'','wabaId'=>'','token'=>'','on'=>[],'tpl'=>[],'lang'=>'en','cronKey'=>''];foreach(waGroups() as $g){if(!isset($d['on'][$g]))$d['on'][$g]=false;if(empty($d['tpl'][$g]))$d['tpl'][$g]=waDefaults()[$g];}return $d;}
function waPublic(array $c,bool $full):array{$o=['configured'=>$c['phoneId']!==''&&$c['token']!=='','on'=>$c['on']];if($full){$o+=['phoneId'=>$c['phoneId'],'wabaId'=>$c['wabaId'],'tokenMasked'=>$c['token']!==''?str_repeat('•',8).substr($c['token'],-4):'','tpl'=>$c['tpl'],'lang'=>$c['lang']];}return $o;}
function waPhone(string $s):string{$d=preg_replace('/\D/','',$s);if(strlen($d)===11&&$d[0]==='0')$d=substr($d,1);if(strlen($d)===10)$d='91'.$d;return $d;}
function waHttp(string $url,string $token,$body,bool $multipart=false):array{
  if(!function_exists('curl_init'))return[0,['error'=>['message'=>'The server is missing the PHP curl extension.']]];
  $h=curl_init($url);$hdr=['Authorization: Bearer '.$token];
  if($multipart)$hdr[]='Expect:';else $hdr[]='Content-Type: application/json';
  curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$hdr,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_CONNECTTIMEOUT=>15]);
  curl_setopt($h,CURLOPT_POSTFIELDS,$multipart?$body:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  $raw=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$err=curl_error($h);curl_close($h);
  if($raw===false)return[0,['error'=>['message'=>'Could not reach Meta: '.$err]]];
  return[$code,json_decode((string)$raw,true)?:[]];
}
function waErr(array $r):string{$e=$r['error']??[];$m=(string)($e['error_user_msg']??$e['message']??'Unknown error from Meta.');if(!empty($e['error_data']['details']))$m.=' — '.$e['error_data']['details'];return mb_substr($m,0,300);}
function waLog(PDO $p,array $rec):array{$id='wl'.bin2hex(random_bytes(8));$rec['at']=gmdate('c');save($p,'wa_log',$id,$rec);$rec['id']=$id;return $rec;}
function waLogged(PDO $p,string $kind,string $key):bool{$q=$p->prepare("SELECT data FROM docs WHERE collection='wa_log' AND data LIKE ?");$q->execute(['%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],substr(json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,-1)).'%']);foreach($q as $r){$d=json_decode($r['data'],true);if(($d['kind']??'')===$kind&&($d['dedupe']??'')===$key&&($d['status']??'')==='sent')return true;}return false;}
function waDoc(PDO $p,string $c,string $id):array{$q=$p->prepare('SELECT data FROM docs WHERE collection=? AND id=?');$q->execute([$c,$id]);$r=$q->fetch();return $r?(json_decode($r['data'],true)?:[]):[];}
/* send one template message; $file = [path, filename] for a PDF header, or null */
function waSendTpl(PDO $p,array $cfg,string $group,string $to,array $params,?array $file):array{
  $base=rtrim((string)($GLOBALS['cfg']['waBase']??'https://graph.facebook.com/v25.0'),'/').'/'.rawurlencode($cfg['phoneId']);$comps=[];
  if($file){[$code,$r]=waHttp($base.'/media',$cfg['token'],['messaging_product'=>'whatsapp','type'=>'application/pdf','file'=>new CURLFile($file[0],'application/pdf',$file[1])],true);
    if($code<200||$code>=300||empty($r['id']))return['ok'=>false,'error'=>'PDF upload failed: '.waErr($r)];
    $comps[]=['type'=>'header','parameters'=>[['type'=>'document','document'=>['id'=>$r['id'],'filename'=>$file[1]]]]];}
  if($params)$comps[]=['type'=>'body','parameters'=>array_map(function($t){return['type'=>'text','text'=>mb_substr(preg_replace('/\s+/',' ',(string)$t),0,900)];},$params)];
  $tpl=['name'=>$cfg['tpl'][$group],'language'=>['code'=>$cfg['lang']?:'en']];if($comps)$tpl['components']=$comps;
  [$code,$r]=waHttp($base.'/messages',$cfg['token'],['messaging_product'=>'whatsapp','to'=>$to,'type'=>'template','template'=>$tpl]);
  if($code>=200&&$code<300&&!empty($r['messages'][0]['id']))return['ok'=>true,'waId'=>$r['messages'][0]['id']];
  return['ok'=>false,'error'=>waErr($r)];
}
if($action==='wa_status'){me($pdo);waDaily($pdo);respond(200,waPublic(waCfg($pdo),false));}
if($action==='wa_get'){$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can open WhatsApp settings.']);respond(200,waPublic(waCfg($pdo),true)+['lastRun'=>waLastRun($pdo)]);}
if($action==='wa_save'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can change WhatsApp settings.']);$c=waCfg($pdo);
  foreach(['phoneId','wabaId'] as $k)if(isset($in[$k]))$c[$k]=preg_replace('/\D/','',(string)$in[$k]);
  if(isset($in['token'])&&trim((string)$in['token'])!=='')$c['token']=trim((string)$in['token']);
  if(!empty($in['clearToken']))$c['token']='';
  if(isset($in['lang']))$c['lang']=preg_replace('/[^A-Za-z_]/','',(string)$in['lang'])?:'en';
  foreach(waGroups() as $g){if(isset($in['on'][$g]))$c['on'][$g]=(bool)$in['on'][$g];if(isset($in['tpl'][$g])){$t=preg_replace('/[^a-z0-9_]/','',strtolower((string)$in['tpl'][$g]));$c['tpl'][$g]=$t?:waDefaults()[$g];}}
  save($pdo,'wa_config','main',$c);respond(200,waPublic($c,true)+['lastRun'=>waLastRun($pdo)]);}
if($action==='wa_test'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can send a test.']);$c=waCfg($pdo);if($c['phoneId']===''||$c['token']==='')respond(400,['error'=>'Save the Phone number ID and Access token first.']);$to=waPhone((string)($in['to']??''));if(strlen($to)<11)respond(400,['error'=>'Enter a valid mobile number.']);
  $which=(string)($in['tpl']??'hello_world');if($which==='birthday'){$biz=waDoc($pdo,'spa_business','profile');$r=waSendTpl($pdo,$c,'birthday',$to,['Test',(string)($biz['name']??'us')],null);}else{$tc=$c;$tc['tpl']['test']='hello_world';$tc['lang']='en_US';$r=waSendTpl($pdo,$tc,'test',$to,[],null);}
  waLog($pdo,['kind'=>'test','group'=>'test','to'=>$to,'clientName'=>'Test message','status'=>$r['ok']?'sent':'failed','error'=>$r['error']??'','waId'=>$r['waId']??'','by'=>$u['name']??'']);
  if(!$r['ok'])respond(400,['error'=>$r['error']]);respond(200,['ok'=>true]);}
if($action==='wa_send'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);$c=waCfg($pdo);$group=(string)($in['group']??'');if(!in_array($group,waGroups(),true))respond(400,['error'=>'Unknown message type.']);
  $manual=!empty($in['manual']);$base=['kind'=>(string)($in['kind']??$group),'group'=>$group,'docId'=>(string)($in['docId']??''),'docNo'=>(string)($in['docNo']??''),'clientId'=>(string)($in['clientId']??''),'clientName'=>(string)($in['clientName']??''),'dedupe'=>(string)($in['dedupe']??''),'manual'=>$manual,'by'=>$u['name']??''];
  if($c['phoneId']===''||$c['token']==='')respond(200,['skipped'=>'WhatsApp is not set up yet.']);
  if(!$manual&&empty($c['on'][$group]))respond(200,['skipped'=>'Automatic sending is off for this message.']);
  $cl=$base['clientId']!==''?waDoc($pdo,'spa_clients',$base['clientId']):[];
  if(!empty($cl['waOff']))respond(200,['skipped'=>'The client has WhatsApp messages turned off.']);
  $to=waPhone((string)($in['to']??($cl['phone']??'')));
  if(strlen($to)<11){$l=waLog($pdo,$base+['to'=>$to,'status'=>'failed','error'=>'No valid mobile number for the client.']);respond(200,['ok'=>false,'error'=>$l['error'],'log'=>$l]);}
  if(!$manual&&$base['dedupe']!==''&&waLogged($pdo,$base['kind'],$base['dedupe']))respond(200,['skipped'=>'Already sent before.']);
  $file=null;$tmp=null;
  if(!empty($in['pdf'])){$bin=base64_decode((string)$in['pdf'],true);if($bin===false||strlen($bin)>15*1024*1024||substr($bin,0,4)!=='%PDF')respond(400,['error'=>'The PDF could not be read.']);$tmp=tempnam(sys_get_temp_dir(),'wa');file_put_contents($tmp,$bin);$file=[$tmp,preg_replace('/[^A-Za-z0-9._ -]/','',(string)($in['filename']??'document.pdf'))?:'document.pdf'];}
  elseif(!empty($in['fileId'])){$hit=fileFind(fileDir($cfg),(string)$in['fileId']);if(!$hit||$hit[1]!=='application/pdf'){$l=waLog($pdo,$base+['to'=>$to,'status'=>'failed','error'=>'The PDF file was not found on the server.']);respond(200,['ok'=>false,'error'=>$l['error'],'log'=>$l]);}$file=[$hit[0],preg_replace('/[^A-Za-z0-9._ -]/','',(string)($in['filename']??'document.pdf'))?:'document.pdf'];}
  $params=array_values(array_map('strval',is_array($in['params']??null)?$in['params']:[]));
  $r=waSendTpl($pdo,$c,$group,$to,$params,$file);if($tmp)@unlink($tmp);
  $l=waLog($pdo,$base+['to'=>$to,'status'=>$r['ok']?'sent':'failed','error'=>$r['error']??'','waId'=>$r['waId']??'']);
  respond(200,['ok'=>$r['ok'],'error'=>$r['error']??'','log'=>$l]);}
/* daily job (Hostinger cron): birthday wishes. Called with the secret cron key. */
/* birthday wishes for today (IST); safe to call many times: each client gets one wish per year */
function waBirthdays(PDO $pdo,array $c):array{
  $now=new DateTime('now',new DateTimeZone('Asia/Kolkata'));$md=$now->format('m-d');$yr=$now->format('Y');
  $biz=waDoc($pdo,'spa_business','profile');$bname=(string)($biz['name']??'');$sent=0;$failed=0;
  $q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='spa_clients'");$q->execute();$rows=$q->fetchAll();
  foreach($rows as $row){$cl=json_decode($row['data'],true)?:[];$dob=(string)($cl['dob']??'');if(strlen($dob)<10||substr($dob,5,5)!==$md||!empty($cl['waOff']))continue;
    $to=waPhone((string)($cl['phone']??''));if(strlen($to)<11)continue;$dk=$row['id'].'|'.$yr;if(waLogged($pdo,'birthday',$dk))continue;
    $first=trim(explode(' ',trim((string)($cl['name']??'')))[0]??'');$r=waSendTpl($pdo,$c,'birthday',$to,[$first?:'there',$bname?:'us'],null);
    waLog($pdo,['kind'=>'birthday','group'=>'birthday','clientId'=>$row['id'],'clientName'=>(string)($cl['name']??''),'to'=>$to,'dedupe'=>$dk,'status'=>$r['ok']?'sent':'failed','error'=>$r['error']??'','waId'=>$r['waId']??'','by'=>'Automatic (daily)']);
    $r['ok']?$sent++:$failed++;}
  return['sent'=>$sent,'failed'=>$failed];
}
/* runs once a day, the first time the system is used after 9:00 AM IST (no cron needed) */
function waDaily(PDO $pdo):void{
  $c=waCfg($pdo);if($c['phoneId']===''||$c['token']===''||empty($c['on']['birthday']))return;
  $now=new DateTime('now',new DateTimeZone('Asia/Kolkata'));if((int)$now->format('G')<9)return;
  $id='bday-'.$now->format('Y-m-d');
  try{$q=$pdo->prepare('INSERT INTO docs(collection,id,data,updated_at)VALUES(?,?,?,CURRENT_TIMESTAMP)');$q->execute(['wa_runs',$id,json_encode(['state'=>'running','at'=>gmdate('c')])]);}catch(Throwable $e){return;}
  @set_time_limit(300);ignore_user_abort(true);$r=waBirthdays($pdo,$c);
  save($pdo,'wa_runs',$id,['state'=>'done','at'=>gmdate('c'),'sent'=>$r['sent'],'failed'=>$r['failed']]);
}
function waLastRun(PDO $pdo):?array{$q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='wa_runs' ORDER BY id DESC LIMIT 1");$q->execute();$r=$q->fetch();if(!$r)return null;$d=json_decode($r['data'],true)?:[];$d['day']=substr($r['id'],5);return $d;}
/* ---- expenses: department staff see their own department; Admin-only categories (e.g. Salaries) are for Admin only ---- */
function expVisible(array $u,array $d):bool{if(admin($u))return true;if(!empty($d['adminOnly']))return false;if(manager($u))return true;$n=['spa'=>'Spa','aes'=>'Aesthetics','pha'=>'Pharmacy'][roleDept($u)]??'';return $n!==''&&($d['dept']??'')===$n;}
/* Cancellation is atomic, with a snapshot check against concurrent payments/stock use. */
if($action==='cancel_voucher'){
 if($method!=='POST')respond(405,['error'=>'Method not allowed.']);
 $actor=me($pdo);if(!admin($actor))respond(403,['error'=>'Only Admin can cancel vouchers.']);
 $voucherCols=['spa_invoices','spa_receipts','ph_receipts','ph_moves','ae_consultations','ae_quotations','ae_sessions','ae_invoices','ae_consents','dept_usage','spa_memberships','spa_redemptions','ph_supplier_payments','spa_mships','exp_expenses','exp_vendor_payments','ae_appointments'];
 $c=(string)($in['collection']??'');$id=(string)($in['id']??'');$reason=trim((string)($in['reason']??''));
 if(!in_array($c,$voucherCols,true)||$id===''||$reason===''||strlen($reason)>1200)respond(400,['error'=>'Choose a voucher and enter a cancellation reason.']);
 $snapshot=$in['snapshot']??[];$all=[];
 $pdo->beginTransaction();
 try{
  foreach(array_merge($voucherCols,['ph_items','dept_openings']) as $col){
   if(!isset($snapshot[$col])||!is_array($snapshot[$col]))throw new RuntimeException('Refresh the page before cancelling.');
   $q=$pdo->prepare('SELECT id,data FROM docs WHERE collection=? ORDER BY id FOR UPDATE');$q->execute([$col]);$actual=[];$visible=[];
   foreach($q as $row){$d=json_decode($row['data'],true)?:[];unset($d['id']);$actual[$row['id']]=$d;if(!in_array($col,['exp_expenses','exp_vendor_payments'],true)||expVisible($actor,$d))$visible[$row['id']]=$d;}
   $expected=[];foreach($snapshot[$col] as $d){$key=(string)($d['id']??'');unset($d['id']);$expected[$key]=$d;}
   if($visible!=$expected)throw new RuntimeException('Transactions changed. Refresh the page and try cancelling again.');
   $all[$col]=$actual;
  }
  $doc=$all[$c][$id]??null;if(!$doc||!empty($doc['cancelled']))throw new RuntimeException('Voucher was already cancelled or is unavailable.');
  if(in_array($c,['exp_expenses','exp_vendor_payments'],true)&&!expVisible($actor,$doc))throw new RuntimeException('Voucher is not available to your account.');
  $active=static function(string $col)use(&$all):array{return array_filter($all[$col]??[],static fn($r)=>empty($r['cancelled']));};
  if($c==='spa_invoices'&&((!empty($doc['memSaleId'])&&isset($active('spa_memberships')[$doc['memSaleId']]))||(!empty($doc['clubSaleId'])&&isset($active('spa_mships')[$doc['clubSaleId']]))))throw new RuntimeException('Cancel the linked package or membership first.');
  if($c==='spa_memberships')foreach($active('spa_redemptions') as $r)if(($r['memId']??'')===$id)throw new RuntimeException('Cancel package redemptions first.');
  if($c==='spa_mships')foreach($active('spa_invoices') as $r)foreach(($r['items']??[]) as $it)if(($it['clubId']??'')===$id)throw new RuntimeException('Cancel invoices using this membership first.');
  if($c==='spa_redemptions'&&!empty($doc['invoiceId'])&&isset($active('spa_invoices')[$doc['invoiceId']]))throw new RuntimeException('Cancel the linked tax invoice first.');
  if(in_array($c,['ae_consultations','ae_sessions'],true))foreach($active('ae_invoices') as $r)foreach(($r['lines']??[]) as $l)if(($l['src']??'')===($c==='ae_consultations'?'con':'ses')&&($l['srcId']??'')===$id)throw new RuntimeException('Cancel the linked invoice first.');
  $refs=['ae_appointments'=>['apptId'],'ae_consultations'=>['conId'],'ae_quotations'=>['quoteId'],'ae_sessions'=>['sesId'],'ae_consents'=>['consentId'],'spa_redemptions'=>['redId','redemptionId']];
  foreach(($refs[$c]??[]) as $ref)foreach($voucherCols as $col)foreach($active($col) as $key=>$r){if($col===$c&&(string)$key===$id)continue;$linked=($r[$ref]??'')===$id;foreach(array_merge($r['items']??[],$r['lines']??[]) as $it)$linked=$linked||($it[$ref]??'')===$id;if($linked)throw new RuntimeException('Cancel linked voucher '.($r['no']??$key).' first.');}
  if($c==='ae_consents')foreach($active('ae_sessions') as $r)if(($r['quoteId']??'')===($doc['quoteId']??'')&&($r['quoteLineId']??'')===($doc['quoteLineId']??''))throw new RuntimeException('Cancel related sessions first.');
  if($c==='ph_moves'){
   if(($doc['type']??'')==='Sale')foreach($active('ph_moves') as $r)if(($r['type']??'')==='Return'&&($r['saleId']??'')===$id)throw new RuntimeException('Cancel linked sales returns first.');
   $stock=static function(?string $exclude)use(&$all,$active):array{
    $b=['ph'=>[],'spa'=>[],'aes'=>[]];$add=static function(string $d,string $it,float $q)use(&$b):void{$b[$d][$it]=($b[$d][$it]??0)+$q;};
    foreach(($all['ph_items']??[]) as $it=>$r)$add('ph',(string)$it,(float)($r['openingQty']??0));
    foreach(($all['dept_openings']??[]) as $r)if(in_array(($r['dept']??''),['spa','aes'],true))$add($r['dept'],(string)($r['itemId']??''),(float)($r['qty']??0));
    foreach($active('ph_moves') as $key=>$r){if((string)$key===$exclude)continue;$type=$r['type']??'';foreach(($r['lines']??[]) as $l){$it=(string)($l['itemId']??'');$qty=(float)($l['qty']??0);$add('ph',$it,in_array($type,['Purchase','Return'],true)?$qty:-$qty);$dept=['Spa Issue'=>'spa','Aesthetics Issue'=>'aes'][$type]??'';if($dept!=='')$add($dept,$it,$qty*max(1,round((float)($all['ph_items'][$it]['secQty']??1))));}}
    foreach($active('dept_usage') as $r)if(in_array(($r['dept']??''),['spa','aes'],true))foreach(($r['lines']??[]) as $l)$add($r['dept'],(string)($l['itemId']??''),-(float)($l['qty']??0));
    foreach(['ae_sessions','ae_consultations'] as $col)foreach($active($col) as $r)foreach(($r['consumables']??[]) as $l)$add('aes',(string)($l['itemId']??''),-(float)($l['qty']??0));
    foreach(['spa_invoices','spa_redemptions'] as $col)foreach($active($col) as $r)foreach(($r['items']??[]) as $it)foreach(($it['consumables']??[]) as $l)$add('spa',(string)($l['itemId']??''),-(float)($l['qty']??0));
    return $b;
   };
   $before=$stock(null);$after=$stock($id);foreach($after as $dept=>$items)foreach($items as $it=>$qty)if($qty<min(0,$before[$dept][$it]??0)-0.00001)throw new RuntimeException('Stock from this voucher has been used. Cancel related issues or consumption first.');
  }

  $targets=[[$c,$id]];
  if(in_array($c,['spa_memberships','spa_mships'],true)&&!empty($doc['invoiceId'])){$iid=(string)$doc['invoiceId'];if(isset($all['spa_invoices'][$iid])&&empty($all['spa_invoices'][$iid]['cancelled']))$targets[]=['spa_invoices',$iid];}
  $stamp=gmdate('Y-m-d\TH:i:s.000\Z');$records=[];
  foreach($targets as [$col,$key]){$d=$all[$col][$key];$d['cancelled']=true;$d['cancelReason']=$reason;$d['cancelledAt']=$stamp;$d['cancelledBy']=$actor['name']??'';$d['updatedAt']=$stamp;save($pdo,$col,$key,$d,true);$d['id']=$key;$records[]=['collection'=>$col,'record'=>$d];}
  $pdo->commit();respond(200,['ok'=>true,'records'=>$records]);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();respond(409,['error'=>$e->getMessage()]);}
}

/* ---- bulk save (imports): up to 1000 records of one collection in one transaction ---- */
if($action==='bulk'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);$c=(string)($in['collection']??'');if(!in_array($c,$cols,true))respond(400,['error'=>'Unknown collection.']);if(in_array($c,['spa_users','spa_business'],true))respond(400,['error'=>'This list can’t be bulk saved.']);if(!allowed($u,$c,'POST'))respond(403,['error'=>'Not permitted for your role.']);$recs=$in['records']??null;if(!is_array($recs)||count($recs)>1000)respond(400,['error'=>'Invalid records.']);$pdo->beginTransaction();try{foreach($recs as $r){$id=(string)($r['id']??'');$d=$r['data']??null;if($id===''||strlen($id)>100||!is_array($d))throw new Exception('bad record');save($pdo,$c,$id,$d,false,$u);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->getMessage());respond($e instanceof DomainException?403:400,['error'=>$e instanceof DomainException?$e->getMessage():'Import failed: a record was invalid.']);}respond(200,['ok'=>true,'count'=>count($recs)]);}
$user=me($pdo);$c=$method==='GET'?($_GET['collection']??''):($in['collection']??'');if(!is_string($c)||!in_array($c,$cols,true))respond(400,['error'=>'Unknown collection.']);if(!allowed($user,$c,$method))respond(403,['error'=>'Not permitted for your role.']);
if($method==='GET'){$q=$pdo->prepare('SELECT id,data FROM docs WHERE collection=? ORDER BY updated_at ASC');$q->execute([$c]);$out=[];$mx=0;foreach($q as $r){$d=json_decode($r['data'],true);if(!is_array($d))continue;$d['id']=$r['id'];$mx=max($mx,(int)($d['seq']??0));if(($c==='exp_expenses'||$c==='exp_vendor_payments')&&!expVisible($user,$d))continue;if($c==='spa_users'){unset($d['passwordHash'],$d['mustChangePassword']);if(!manager($user))$d=array_intersect_key($d,array_flip(['id','name','role','status']));}$out[]=$d;}respond(200,['records'=>$out,'maxSeq'=>$mx]);}
if($method==='POST'){$id=(string)($in['id']??'');$d=$in['data']??null;if($id===''||!is_array($d))respond(400,['error'=>'Invalid record.']);if(($c==='exp_expenses'||$c==='exp_vendor_payments')&&!expVisible($user,$d))respond(403,['error'=>'You can only record expenses for your own department.']);if($c==='exp_categories'&&!empty($d['adminOnly'])&&!admin($user))respond(403,['error'=>'Only Admin can manage Admin-only categories.']);if($c==='spa_business'&&!admin($user))respond(403,['error'=>'Only Admin can change Business Details.']);if($c==='spa_users'){if(!manager($user))respond(403,['error'=>'Not permitted.']);$old=findUser($pdo,$id);$role=(string)($d['role']??($old['role']??''));if(!in_array($role,['Admin','Manager','Aesthetics','Spa','Pharmacy'],true))respond(400,['error'=>'Choose a valid role.']);if(!admin($user)&&(($old['role']??'')==='Admin'||$role==='Admin'))respond(403,['error'=>'Managers cannot modify Admin accounts.']);$pass=(string)($d['password']??'');unset($d['password']);if($pass!==''){if(strlen($pass)<8)respond(400,['error'=>'Password must be at least 8 characters.']);$d['passwordHash']=password_hash($pass,PASSWORD_DEFAULT);$d['mustChangePassword']=$old?true:!empty($d['mustChangePassword']);}elseif($old&&isset($old['passwordHash']))$d['passwordHash']=$old['passwordHash'];else respond(400,['error'=>'Password is required for a new user.']);if(!isset($d['mustChangePassword']))$d['mustChangePassword']=$old['mustChangePassword']??false;}try{$related=save($pdo,$c,$id,$d,false,$user);}catch(DomainException $e){respond(403,['error'=>$e->getMessage()]);}catch(RuntimeException $e){respond(409,['error'=>$e->getMessage()]);}$res=$d;unset($res['passwordHash'],$res['mustChangePassword']);$res['id']=$id;respond(200,['ok'=>true,'record'=>$res,'related'=>$related]);}
if($method==='DELETE'){if(!admin($user))respond(403,['error'=>'Only Admin can delete records.']);$id=(string)($in['id']??'');$target=$c==='spa_users'?findUser($pdo,$id):null;if($target&&!admin($user)&&($target['role']??'')==='Admin')respond(403,['error'=>'Managers cannot modify Admin accounts.']);$pdo->beginTransaction();$check=$pdo->prepare('SELECT data FROM docs WHERE collection=? AND id=? FOR UPDATE');$check->execute([$c,$id]);$existing=json_decode((string)($check->fetchColumn()?:'{}'),true)?:[];if(!empty($existing['cancelled'])){$pdo->rollBack();respond(409,['error'=>'Cancelled vouchers must be retained.']);}$q=$pdo->prepare('DELETE FROM docs WHERE collection=? AND id=?');$q->execute([$c,$id]);$pdo->commit();respond(200,['ok'=>true]);}respond(405,['error'=>'Method not allowed.']);


