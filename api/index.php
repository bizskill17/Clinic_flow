<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
session_name('clinic_flow_session'); session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start();
function respond(int $s,array $b):void{http_response_code($s);echo json_encode($b,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function body():array{$x=json_decode((string)file_get_contents('php://input'),true);return is_array($x)?$x:[];}
function clean(array $u):array{unset($u['passwordHash']);return $u;}
$cf=__DIR__.'/config.php';if(!is_file($cf))respond(503,['error'=>'Database configuration is missing.']);
try{$cfg=require $cf;$pdo=new PDO('mysql:host='.$cfg['host'].';dbname='.$cfg['name'].';charset=utf8mb4',$cfg['user'],$cfg['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_PERSISTENT=>true]);$pdo->exec('CREATE TABLE IF NOT EXISTS docs (collection VARCHAR(64) NOT NULL,id VARCHAR(100) NOT NULL,data LONGTEXT NOT NULL,updated_at DATETIME(3) NOT NULL,PRIMARY KEY(collection,id),KEY updated_at(updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');}catch(Throwable $e){error_log($e->getMessage());respond(500,['error'=>'Database connection failed.']);}
$cols=['spa_clients','spa_services','spa_invoices','spa_receipts','spa_categories','spa_cities','spa_states','spa_users','spa_business','ph_items','ph_item_categories','ph_suppliers','ph_moves','ph_receipts','ae_proc_categories','ae_procedures','ae_appointments','ae_consultations','ae_quotations','ae_sessions','ae_invoices','ae_consents','dept_openings','dept_usage','spa_mem_plans','spa_memberships','spa_redemptions','ph_supplier_payments','spa_mship_plans','spa_mships','wa_log','wa_inbox','exp_categories','exp_vendors','exp_expenses','exp_vendor_payments'];$method=$_SERVER['REQUEST_METHOD']??'GET';$in=$method==='GET'?[]:body();$action=$method==='GET'?($_GET['action']??'collection'):($in['action']??'collection');
function findUser(PDO $p,string $key):?array{$q=$p->prepare('SELECT id,data FROM docs WHERE collection=?');$q->execute(['spa_users']);foreach($q as $r){$u=json_decode($r['data'],true);if(is_array($u)&&((string)$r['id']===$key||strcasecmp((string)($u['userId']??''),$key)===0)){$u['id']=$r['id'];return $u;}}return null;}
function me(PDO $p):array{$id=$_SESSION['user_id']??'';$last=(int)($_SESSION['last_seen']??0);if(!$id||time()-$last>28800){session_unset();session_destroy();respond(401,['error'=>'Session expired.']);}$u=findUser($p,(string)$id);if(!$u||($u['status']??'Active')!=='Active'){session_unset();session_destroy();respond(403,['error'=>'Account is inactive.']);}$_SESSION['last_seen']=time();return $u;}
function admin(array $u):bool{return($u['role']??'')==='Admin';}function manager(array $u):bool{return in_array(($u['role']??''),['Admin','Manager'],true);}function roleDept(array $u):string{return['Spa'=>'spa','Aesthetics'=>'aes','Pharmacy'=>'pha'][$u['role']??'']??'';}
function allowed(array $u,string $c,string $method):bool{if(manager($u))return true;if($c==='wa_log')return $method==='GET';if($c==='wa_inbox')return false;if(in_array($c,['exp_categories','exp_vendors','exp_expenses','exp_vendor_payments'],true))return $method!=='DELETE';$d=roleDept($u);if(!$d)return false;if(in_array($c,['spa_clients','spa_cities','spa_states','spa_business'],true))return $method==='GET'||$c!=='spa_business';if($c==='spa_users')return $method==='GET';if($c==='ph_items'&&$d==='spa')return $method==='GET';if($d==='spa')return in_array($c,['spa_services','spa_invoices','spa_receipts','spa_categories','dept_openings','dept_usage','spa_mem_plans','spa_memberships','spa_redemptions','spa_mship_plans','spa_mships'],true);if($d==='aes')return in_array($c,['ae_proc_categories','ae_procedures','ae_appointments','ae_consultations','ae_quotations','ae_sessions','ae_invoices','ae_consents','dept_openings','dept_usage','ph_items'],true);if($d==='pha')return in_array($c,['ph_items','ph_item_categories','ph_suppliers','ph_moves','ph_receipts','spa_receipts','ph_supplier_payments'],true);return false;}
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
if($action==='pay_page'){header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex');payPage($pdo);exit;}
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
function waCfg(PDO $p):array{$q=$p->prepare('SELECT data FROM docs WHERE collection=? AND id=?');$q->execute(['wa_config','main']);$r=$q->fetch();$d=$r?(json_decode($r['data'],true)?:[]):[];$d+=['phoneId'=>'','wabaId'=>'','token'=>'','on'=>[],'tpl'=>[],'lang'=>'en','cronKey'=>'','verifyToken'=>'','appSecret'=>'','testMode'=>false,'testNumber'=>'','upiId'=>'','upiName'=>''];foreach(waGroups() as $g){if(!isset($d['on'][$g]))$d['on'][$g]=false;if(empty($d['tpl'][$g]))$d['tpl'][$g]=waDefaults()[$g];}return $d;}
function waPublic(array $c,bool $full):array{$o=['configured'=>$c['phoneId']!==''&&$c['token']!=='','on'=>$c['on'],'testMode'=>!empty($c['testMode']),'testNumber'=>!empty($c['testMode'])?(string)$c['testNumber']:''];if($full){$o+=['phoneId'=>$c['phoneId'],'wabaId'=>$c['wabaId'],'tokenMasked'=>$c['token']!==''?str_repeat('•',8).substr($c['token'],-4):'','tpl'=>$c['tpl'],'lang'=>$c['lang'],'verifyToken'=>$c['verifyToken'],'appSecretSet'=>$c['appSecret']!=='','testNumber'=>(string)$c['testNumber'],'upiId'=>(string)$c['upiId'],'upiName'=>(string)$c['upiName']];}return $o;}
/* test mode: every client message goes to the saved test number instead */
function waTest(array $c):string{return !empty($c['testMode'])?waPhone((string)$c['testNumber']):'';}
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
function waLogged(PDO $p,string $kind,string $key):bool{$q=$p->prepare("SELECT data FROM docs WHERE collection='wa_log' AND data LIKE ?");$q->execute(['%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],substr(json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,-1)).'%']);foreach($q as $r){$d=json_decode($r['data'],true);if(($d['kind']??'')===$kind&&($d['dedupe']??'')===$key&&in_array(($d['status']??''),['sent','delivered','read'],true))return true;}return false;}
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
/* public "Pay now" page: your UPI QR code and UPI ID */
function payPage(PDO $pdo):void{
  $h=function($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');};
  $q=$pdo->prepare('SELECT id,data FROM docs WHERE collection=? AND id IN (?,?)');$q->execute(['spa_business','profile','logo']);$bn='';$logo='';
  foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if($r['id']==='profile')$bn=(string)($d['name']??'');if($r['id']==='logo')$logo=(string)($d['data']??'');}
  if(strpos($logo,'data:image/')!==0)$logo='';$bn=$bn?:'Veda Associates';
  $c=waCfg($pdo);$upi=trim((string)$c['upiId']);$pn=trim((string)$c['upiName'])?:$bn;
  $link=$upi!==''?'upi://pay?'.http_build_query(['pa'=>$upi,'pn'=>$pn,'cu'=>'INR'],'','&',PHP_QUERY_RFC3986):'';
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Pay · '.$h($bn).'</title>'
   .'<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2a24;background:linear-gradient(160deg,#eef4f0 0%,#dfeae3 55%,#e9efe6 100%)}'
   .'.card{width:100%;max-width:400px;background:#fff;border-radius:20px;box-shadow:0 10px 40px rgba(30,60,45,.12);padding:28px 24px;text-align:center}'
   .'.brand{display:flex;flex-direction:column;align-items:center;gap:8px;margin-bottom:6px}.brand img{width:64px;height:64px;object-fit:contain;border-radius:14px}.brand b{font-size:19px}'
   .'.muted{color:#5d6b64;font-size:14px}.qr{margin:16px auto 4px;width:220px;height:220px}.qr svg{width:220px;height:220px}'
   .'.upi{display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;margin:12px 0 18px}.upi code{background:#eef3ef;padding:4px 10px;border-radius:6px;font-size:15px}'
   .'button.cp{font:inherit;font-size:13px;border:1px solid #cfd9d3;background:#fff;border-radius:8px;padding:4px 12px;cursor:pointer}'
   .'.pay{display:block;background:#1f6f4a;color:#fff;text-decoration:none;font-weight:600;font-size:17px;padding:14px;border-radius:12px}'
   .'.note{font-size:14px;background:#eef3ef;border-radius:12px;padding:12px;margin-top:18px}</style></head><body><main class="card">'
   .'<div class="brand">'.($logo!==''?'<img src="'.$h($logo).'" alt="">':'').'<b>'.$h($bn).'</b></div>';
  if($link===''){echo '<p class="muted">Online payment is not available right now. Please contact us on WhatsApp.</p>';}
  else{
    echo '<p class="muted" style="margin:0">Scan with any UPI app to pay</p><div class="qr" id="qr" data-l="'.$h($link).'"></div>'
     .'<div class="upi"><span class="muted">UPI ID</span><code id="upi">'.$h($upi).'</code><button class="cp" type="button" onclick="var b=this;navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'upi\').textContent).then(function(){b.textContent=\'Copied\'})">Copy</button></div>'
     .'<a class="pay" href="'.$h($link).'">Pay with UPI app</a>'
     .'<p class="note">After paying, please send the <b>payment screenshot</b> to us on WhatsApp. Our team will confirm it.</p>'
     .'<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script><script>try{var e=document.getElementById("qr"),q=qrcode(0,"M");q.addData(e.getAttribute("data-l"));q.make();e.innerHTML=q.createSvgTag({cellSize:5,margin:2,scalable:true})}catch(x){}</script>';
  }
  echo '</main></body></html>';}
/* a one-page sample PDF for template tests */
function waSamplePdf(string $text):string{
  $t=preg_replace('/[^\x20-\x7E]/','',$text);$t=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$t);
  $stream="BT /F1 18 Tf 72 760 Td ($t) Tj 0 -28 Td /F1 12 Tf (This is a test document from the clinic system.) Tj ET";
  $objs=["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>","<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream","<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"];
  $pdf="%PDF-1.4\n";$off=[];foreach($objs as $i=>$o){$off[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$o."\nendobj\n";}
  $x=strlen($pdf);$pdf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";foreach($off as $o)$pdf.=sprintf("%010d 00000 n \n",$o);
  return $pdf."trailer\n<< /Size ".(count($objs)+1)." /Root 1 0 R >>\nstartxref\n".$x."\n%%EOF\n";
}
/* Meta webhook: delivery/read/failed statuses and STOP replies. Public URL, checked with the verify token (and app secret if saved). */
if(($_GET['action']??'')==='wa_webhook'){
  $c=waCfg($pdo);
  if($method==='GET'){$okv=($_GET['hub_mode']??'')==='subscribe'&&$c['verifyToken']!==''&&hash_equals($c['verifyToken'],(string)($_GET['hub_verify_token']??''));save($pdo,'wa_runs','hook-verify',['at'=>gmdate('c'),'ok'=>$okv]);if($okv){header('Content-Type: text/plain');echo (string)($_GET['hub_challenge']??'');exit;}http_response_code(403);echo 'Forbidden';exit;}
  $raw=(string)file_get_contents('php://input');
  if($c['appSecret']!==''){$sig=(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??'');if(!hash_equals('sha256='.hash_hmac('sha256',$raw,$c['appSecret']),$sig)){save($pdo,'wa_runs','hook-last',['at'=>gmdate('c'),'state'=>'rejected','note'=>'App secret does not match']);http_response_code(403);exit;}}
  $j=json_decode($raw,true)?:[];
  $ns=0;$nm=0;foreach(($j['entry']??[]) as $en)foreach(($en['changes']??[]) as $ch){$ns+=count($ch['value']['statuses']??[]);$nm+=count($ch['value']['messages']??[]);}
  save($pdo,'wa_runs','hook-last',['at'=>gmdate('c'),'state'=>'ok','statuses'=>$ns,'messages'=>$nm,'field'=>(string)($j['entry'][0]['changes'][0]['field']??''),'waba'=>(string)($j['entry'][0]['id']??'')]);
  foreach(($j['entry']??[]) as $en)foreach(($en['changes']??[]) as $ch){$v=$ch['value']??[];
    foreach(($v['statuses']??[]) as $st){$wid=(string)($st['id']??'');if($wid==='')continue;
      $q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='wa_log' AND data LIKE ?");$q->execute(['%'.$wid.'%']);
      foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if(($d['waId']??'')!==$wid)continue;
        $new=(string)($st['status']??'');$rank=['sent'=>1,'delivered'=>2,'read'=>3,'failed'=>4];
        if(($rank[$new]??0)>=($rank[$d['status']??'sent']??0)||$new==='failed'){$d['status']=$new;$d[$new.'At']=gmdate('c');
          if($new==='failed'){$e=$st['errors'][0]??[];$d['error']=mb_substr(trim('(#'.($e['code']??'').') '.($e['title']??'').' '.($e['error_data']['details']??$e['message']??'')),0,300);}
          save($pdo,'wa_log',$r['id'],$d);}}}
    $names=[];foreach(($v['contacts']??[]) as $ct)$names[(string)($ct['wa_id']??'')]=(string)($ct['profile']['name']??'');
    foreach(($v['messages']??[]) as $m){waInboxStore($pdo,$m,$names);}
    foreach(($v['messages']??[]) as $m){$txt=strtolower(trim((string)($m['text']['body']??$m['button']['text']??'')));
      if(in_array($txt,['stop','unsubscribe','stop messages'],true)){$from=substr(preg_replace('/\D/','',(string)($m['from']??'')),-10);
        $q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='spa_clients'");$q->execute();
        foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if($from!==''&&substr(preg_replace('/\D/','',(string)($d['phone']??'')),-10)===$from){$d['waOff']=true;save($pdo,'spa_clients',$r['id'],$d);}}}}
  }
  respond(200,['ok'=>true]);
}
/* ---- WhatsApp inbox: incoming client messages and staff replies (Admin and Manager) ---- */
function waLast10(string $p):string{return substr(preg_replace('/\D/','',$p),-10);}
function waClientByPhone(PDO $pdo,string $p):array{$l=waLast10($p);if($l==='')return[];$q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='spa_clients' AND data LIKE ?");$q->execute(['%'.$l.'%']);foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if(waLast10((string)($d['phone']??''))===$l)return['id'=>$r['id'],'name'=>(string)($d['name']??'')];}return[];}
function waInboxStore(PDO $pdo,array $m,array $names):void{
  $id=(string)($m['id']??'');if($id==='')return;$from=(string)($m['from']??'');$t=(string)($m['type']??'text');
  $text=(string)($m['text']['body']??$m['button']['text']??$m['interactive']['button_reply']['title']??$m['interactive']['list_reply']['title']??$m['reaction']['emoji']??'');
  $media=$m[$t]??[];$cap=is_array($media)?(string)($media['caption']??''):'';
  if($t==='location'){$text='📍 '.trim(($m['location']['name']??'').' '.($m['location']['address']??'')).' ('.($m['location']['latitude']??'').', '.($m['location']['longitude']??'').')';}
  $cl=waClientByPhone($pdo,$from);
  save($pdo,'wa_inbox','in_'.preg_replace('/[^A-Za-z0-9_.=-]/','',$id),['dir'=>'in','waId'=>$id,'from'=>$from,'last10'=>waLast10($from),'profileName'=>$names[$from]??'','clientId'=>$cl['id']??'','clientName'=>$cl['name']??'',
    'type'=>$t,'text'=>$text?:$cap,'caption'=>$cap,'mediaId'=>is_array($media)?(string)($media['id']??''):'','mime'=>is_array($media)?(string)($media['mime_type']??''):'','filename'=>is_array($media)?(string)($media['filename']??''):'',
    'at'=>gmdate('c',(int)($m['timestamp']??time())),'read'=>false]);
}
function waGet(string $url,string $token):array{if(!function_exists('curl_init'))return[0,'',''];$h=curl_init($url);curl_setopt_array($h,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'clinic-system']);$raw=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($h,CURLINFO_CONTENT_TYPE);curl_close($h);return[$code,(string)$raw,$type];}
function waBase(array $c):string{return rtrim((string)($GLOBALS['cfg']['waBase']??'https://graph.facebook.com/v25.0'),'/');}
if($action==='wa_reply'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!manager($u))respond(403,['error'=>'Only Admin or Manager can reply.']);$c=waCfg($pdo);
  if($c['phoneId']===''||$c['token']==='')respond(400,['error'=>'WhatsApp is not set up yet.']);
  $l10=waLast10((string)($in['to']??''));$text=trim((string)($in['text']??''));if($l10===''||$text==='')respond(400,['error'=>'Type a message.']);
  $q=$pdo->prepare("SELECT data FROM docs WHERE collection='wa_inbox' AND data LIKE ?");$q->execute(['%"last10":"'.$l10.'"%']);$last=0;$to='';
  foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if(($d['dir']??'')==='in'){$ts=strtotime((string)($d['at']??''))?:0;if($ts>$last){$last=$ts;$to=(string)$d['from'];}}}
  if(!$last||time()-$last>86400)respond(400,['error'=>'The 24-hour reply window has closed. WhatsApp only allows an approved template now.']);
  [$code,$r]=waHttp(waBase($c).'/'.rawurlencode($c['phoneId']).'/messages',$c['token'],['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'text','text'=>['preview_url'=>false,'body'=>mb_substr($text,0,4000)]]);
  $ok=$code>=200&&$code<300&&!empty($r['messages'][0]['id']);$wid=$ok?(string)$r['messages'][0]['id']:'';
  $cl=waClientByPhone($pdo,$to);$id='out_'.($wid!==''?preg_replace('/[^A-Za-z0-9_.=-]/','',$wid):bin2hex(random_bytes(8)));
  $rec=['dir'=>'out','waId'=>$wid,'from'=>$to,'last10'=>$l10,'clientId'=>$cl['id']??'','clientName'=>$cl['name']??'','type'=>'text','text'=>$text,'at'=>gmdate('c'),'read'=>true,'by'=>$u['name']??'','status'=>$ok?'sent':'failed','error'=>$ok?'':waErr($r)];
  save($pdo,'wa_inbox',$id,$rec);$rec['id']=$id;
  if(!$ok)respond(400,['error'=>$rec['error'],'rec'=>$rec]);respond(200,['ok'=>true,'rec'=>$rec]);}
if($action==='wa_read'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!manager($u))respond(403,['error'=>'Not permitted.']);$c=waCfg($pdo);$l10=waLast10((string)($in['to']??''));if($l10==='')respond(400,['error'=>'Missing number.']);
  $q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='wa_inbox' AND data LIKE ?");$q->execute(['%"last10":"'.$l10.'"%']);$latest='';$lt='';
  foreach($q->fetchAll() as $r){$d=json_decode($r['data'],true)?:[];if(($d['dir']??'')==='in'&&empty($d['read'])){$d['read']=true;save($pdo,'wa_inbox',$r['id'],$d);if(($d['at']??'')>$lt){$lt=$d['at'];$latest=(string)$d['waId'];}}}
  if($latest!==''&&$c['token']!==''&&$c['phoneId']!=='')waHttp(waBase($c).'/'.rawurlencode($c['phoneId']).'/messages',$c['token'],['messaging_product'=>'whatsapp','status'=>'read','message_id'=>$latest]);
  respond(200,['ok'=>true]);}
if($action==='wa_media'){$u=me($pdo);if(!manager($u)){http_response_code(403);exit;}$c=waCfg($pdo);$id=(string)($_GET['id']??'');$d=waDoc($pdo,'wa_inbox',$id);if(empty($d['mediaId'])){http_response_code(404);echo 'Not found';exit;}
  $dir=fileDir($cfg);$key=hash('sha256',$d['mediaId']);$exts=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','audio/ogg'=>'ogg','audio/mpeg'=>'mp3','video/mp4'=>'mp4'];$mime=strtok((string)($d['mime']??''),';');$ext=$exts[$mime]??'bin';$path=$dir.'/wa_'.$key.'.'.$ext;
  if(!is_file($path)){[$code,$raw]=waGet(waBase($c).'/'.rawurlencode($d['mediaId']),$c['token']);$meta=json_decode($raw,true)?:[];if(empty($meta['url'])){http_response_code(404);echo 'This file is no longer available from WhatsApp.';exit;}[$c2,$bin]=waGet((string)$meta['url'],$c['token']);if($c2<200||$c2>=300||$bin===''){http_response_code(502);echo 'Could not download the file.';exit;}file_put_contents($path,$bin);}
  header_remove('Content-Type');header('Content-Type: '.($mime?:'application/octet-stream'));header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="'.preg_replace('/[^A-Za-z0-9._ -]/','',(string)($d['filename']??'')?:('whatsapp.'.$ext)).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;}
/* link the app to the WhatsApp account so Meta sends delivery reports and incoming messages */
if($action==='wa_subscribe'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can do this.']);$c=waCfg($pdo);if($c['wabaId']===''||$c['token']==='')respond(400,['error'=>'Save the WhatsApp Business Account ID and the token first.']);
  [$code,$r]=waHttp(waBase($c).'/'.rawurlencode($c['wabaId']).'/subscribed_apps',$c['token'],[]);if(!($code>=200&&$code<300&&!empty($r['success'])))respond(400,['error'=>waErr($r)]);
  [$c2,$raw2]=waGet(waBase($c).'/'.rawurlencode($c['wabaId']).'/subscribed_apps',$c['token']);$l=json_decode($raw2,true)?:[];$apps=[];foreach(($l['data']??[]) as $x){$d=$x['whatsapp_business_api_data']??$x;$apps[]=['id'=>(string)($d['id']??''),'name'=>(string)($d['name']??'')];}
  respond(200,['ok'=>true,'apps'=>$apps]);}
if($action==='wa_status'){me($pdo);waDaily($pdo);respond(200,waPublic(waCfg($pdo),false));}
if($action==='wa_get'){$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can open WhatsApp settings.']);respond(200,waPublic(waCfg($pdo),true)+['lastRun'=>waLastRun($pdo),'lastHook'=>(string)(waDoc($pdo,'wa_runs','hook-last')['at']??''),'hook'=>waDoc($pdo,'wa_runs','hook-last')?:null,'verify'=>waDoc($pdo,'wa_runs','hook-verify')?:null]);}
if($action==='wa_save'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can change WhatsApp settings.']);$c=waCfg($pdo);
  foreach(['phoneId','wabaId'] as $k)if(isset($in[$k]))$c[$k]=preg_replace('/\D/','',(string)$in[$k]);
  if(isset($in['token'])&&trim((string)$in['token'])!=='')$c['token']=trim((string)$in['token']);
  if(!empty($in['clearToken']))$c['token']='';
  if($c['verifyToken']==='')$c['verifyToken']=bin2hex(random_bytes(12));
  if(!empty($in['clearSecret']))$c['appSecret']='';
  if(isset($in['appSecret'])&&trim((string)$in['appSecret'])!=='')$c['appSecret']=preg_replace('/[^a-f0-9]/i','',(string)$in['appSecret']);
  if(isset($in['upiId']))$c['upiId']=mb_substr(preg_replace('/[^A-Za-z0-9.\-_@]/','',(string)$in['upiId']),0,80);
  if(isset($in['upiName']))$c['upiName']=mb_substr(trim(preg_replace('/\s+/',' ',(string)$in['upiName'])),0,60);
  if(isset($in['testNumber']))$c['testNumber']=preg_replace('/\D/','',(string)$in['testNumber']);
  if(isset($in['testMode'])){$c['testMode']=(bool)$in['testMode'];if($c['testMode']&&strlen(waPhone($c['testNumber']))<11)respond(400,['error'=>'Enter a valid test mobile number to turn on test mode.']);}
  if(isset($in['lang']))$c['lang']=preg_replace('/[^A-Za-z_]/','',(string)$in['lang'])?:'en';
  foreach(waGroups() as $g){if(isset($in['on'][$g]))$c['on'][$g]=(bool)$in['on'][$g];if(isset($in['tpl'][$g])){$t=preg_replace('/[^a-z0-9_]/','',strtolower((string)$in['tpl'][$g]));$c['tpl'][$g]=$t?:waDefaults()[$g];}}
  save($pdo,'wa_config','main',$c);respond(200,waPublic($c,true)+['lastRun'=>waLastRun($pdo)]);}
if($action==='wa_test'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);if(!admin($u))respond(403,['error'=>'Only Admin can send a test.']);$c=waCfg($pdo);if($c['phoneId']===''||$c['token']==='')respond(400,['error'=>'Save the Phone number ID and Access token first.']);$to=waPhone((string)($in['to']??''));if(strlen($to)<11)respond(400,['error'=>'Enter a valid mobile number.']);
  $which=(string)($in['tpl']??'hello_world');
  if(in_array($which,waGroups(),true)){$biz=waDoc($pdo,'spa_business','profile');$bn=(string)($biz['name']??'us');$amt="\u{20B9}1,000.00";$today=(new DateTime('now',new DateTimeZone('Asia/Kolkata')))->format('d M Y');
    $P=['invoice'=>['Test',$bn,'INV-TEST',$amt],'quotation'=>['Test',$bn,'QTN-TEST',$amt],'receipt'=>['Test',$amt,'RCT-TEST',$bn],'prescription'=>['Test',$bn,$today],'education'=>['Test','Sample procedure',$bn],'birthday'=>['Test',$bn]];
    $file=null;$tmp=null;if($which!=='birthday'){$tmp=tempnam(sys_get_temp_dir(),'wat');file_put_contents($tmp,waSamplePdf('Test '.ucfirst($which).' - '.$bn));$file=[$tmp,'TEST-'.strtoupper($which).'.pdf'];}
    $r=waSendTpl($pdo,$c,$which,$to,$P[$which],$file);if($tmp)@unlink($tmp);}
  else{$tc=$c;$tc['tpl']['test']='hello_world';$tc['lang']='en_US';$r=waSendTpl($pdo,$tc,'test',$to,[],null);}
  waLog($pdo,['kind'=>'test','group'=>'test','to'=>$to,'clientName'=>'Test message','status'=>$r['ok']?'sent':'failed','error'=>$r['error']??'','waId'=>$r['waId']??'','by'=>$u['name']??'']);
  if(!$r['ok'])respond(400,['error'=>$r['error']]);respond(200,['ok'=>true]);}
if($action==='wa_send'){if($method!=='POST')respond(405,['error'=>'Method not allowed.']);$u=me($pdo);$c=waCfg($pdo);$group=(string)($in['group']??'');if(!in_array($group,waGroups(),true))respond(400,['error'=>'Unknown message type.']);
  $manual=!empty($in['manual']);$base=['kind'=>(string)($in['kind']??$group),'group'=>$group,'docId'=>(string)($in['docId']??''),'docNo'=>(string)($in['docNo']??''),'clientId'=>(string)($in['clientId']??''),'clientName'=>(string)($in['clientName']??''),'dedupe'=>(string)($in['dedupe']??''),'manual'=>$manual,'by'=>$u['name']??''];
  if($c['phoneId']===''||$c['token']==='')respond(200,['skipped'=>'WhatsApp is not set up yet.']);
  if(!$manual&&empty($c['on'][$group]))respond(200,['skipped'=>'Automatic sending is off for this message.']);
  $cl=$base['clientId']!==''?waDoc($pdo,'spa_clients',$base['clientId']):[];
  if(!empty($cl['waOff']))respond(200,['skipped'=>'The client has WhatsApp messages turned off.']);
  $to=waPhone((string)($in['to']??($cl['phone']??'')));$tn=waTest($c);
  if($tn!==''){$base['test']=true;$base['origTo']=$to;$to=$tn;if($base['dedupe']!=='')$base['dedupe']='test:'.$base['dedupe'];}
  if(strlen($to)<11){$l=waLog($pdo,$base+['to'=>$to,'status'=>'failed','error'=>'No valid mobile number for the client.']);respond(200,['ok'=>false,'error'=>$l['error'],'log'=>$l]);}
  if(!$manual&&$base['dedupe']!==''&&waLogged($pdo,$base['kind'],$base['dedupe']))respond(200,['skipped'=>'Already sent before.']);
  $file=null;$tmp=null;
  if(!empty($in['pdf'])){$bin=base64_decode((string)$in['pdf'],true);if($bin===false||strlen($bin)>15*1024*1024||substr($bin,0,4)!=='%PDF')respond(400,['error'=>'The PDF could not be read.']);$tmp=tempnam(sys_get_temp_dir(),'wa');file_put_contents($tmp,$bin);$file=[$tmp,preg_replace('/[^A-Za-z0-9._ -]/','',(string)($in['filename']??'document.pdf'))?:'document.pdf'];}
  elseif(!empty($in['fileId'])){$hit=fileFind(fileDir($cfg),(string)$in['fileId']);if(!$hit||$hit[1]!=='application/pdf'){$l=waLog($pdo,$base+['to'=>$to,'status'=>'failed','error'=>'The PDF file was not found on the server.']);respond(200,['ok'=>false,'error'=>$l['error'],'log'=>$l]);}$file=[$hit[0],preg_replace('/[^A-Za-z0-9._ -]/','',(string)($in['filename']??'document.pdf'))?:'document.pdf'];}
  $params=array_values(array_map('strval',is_array($in['params']??null)?$in['params']:[]));
  $r=waSendTpl($pdo,$c,$group,$to,$params,$file);if($tmp)@unlink($tmp);
  $l=waLog($pdo,$base+['to'=>$to,'status'=>$r['ok']?'sent':'failed','error'=>$r['error']??'','waId'=>$r['waId']??'']);
  respond(200,['ok'=>$r['ok'],'error'=>$r['error']??'','log'=>$l,'test'=>$tn!=='']);}
/* daily job (Hostinger cron): birthday wishes. Called with the secret cron key. */
/* birthday wishes for today (IST); safe to call many times: each client gets one wish per year */
function waBirthdays(PDO $pdo,array $c):array{
  $now=new DateTime('now',new DateTimeZone('Asia/Kolkata'));$md=$now->format('m-d');$yr=$now->format('Y');
  $biz=waDoc($pdo,'spa_business','profile');$bname=(string)($biz['name']??'');$sent=0;$failed=0;
  $q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='spa_clients'");$q->execute();$rows=$q->fetchAll();
  foreach($rows as $row){$cl=json_decode($row['data'],true)?:[];$dob=(string)($cl['dob']??'');if(strlen($dob)<10||substr($dob,5,5)!==$md||!empty($cl['waOff']))continue;
    $to=waPhone((string)($cl['phone']??''));$tn=waTest($c);$orig=$to;if($tn!==''){$to=$tn;}if(strlen($to)<11)continue;$dk=($tn!==''?'test:':'').$row['id'].'|'.$yr;if(waLogged($pdo,'birthday',$dk))continue;
    $first=trim(explode(' ',trim((string)($cl['name']??'')))[0]??'');$r=waSendTpl($pdo,$c,'birthday',$to,[$first?:'there',$bname?:'us'],null);
    waLog($pdo,['kind'=>'birthday','group'=>'birthday','clientId'=>$row['id'],'clientName'=>(string)($cl['name']??''),'to'=>$to,'dedupe'=>$dk,'status'=>$r['ok']?'sent':'failed','error'=>$r['error']??'','waId'=>$r['waId']??'','by'=>'Automatic (daily)']+($tn!==''?['test'=>true,'origTo'=>$orig]:[]));
    $r['ok']?$sent++:$failed++;}
  return['sent'=>$sent,'failed'=>$failed];
}
/* runs once a day, the first time the system is used after 9:00 AM IST (no cron needed) */
function waDaily(PDO $pdo):void{
  $c=waCfg($pdo);if($c['phoneId']===''||$c['token']===''||empty($c['on']['birthday']))return;
  $now=new DateTime('now',new DateTimeZone('Asia/Kolkata'));if((int)$now->format('G')<9)return;
  $id='bday-'.$now->format('Y-m-d').(waTest($c)!==''?'-test':'');
  try{$q=$pdo->prepare('INSERT INTO docs(collection,id,data,updated_at)VALUES(?,?,?,CURRENT_TIMESTAMP)');$q->execute(['wa_runs',$id,json_encode(['state'=>'running','at'=>gmdate('c')])]);}catch(Throwable $e){return;}
  @set_time_limit(300);ignore_user_abort(true);$r=waBirthdays($pdo,$c);
  save($pdo,'wa_runs',$id,['state'=>'done','at'=>gmdate('c'),'sent'=>$r['sent'],'failed'=>$r['failed']]);
}
function waLastRun(PDO $pdo):?array{$q=$pdo->prepare("SELECT id,data FROM docs WHERE collection='wa_runs' AND id LIKE 'bday-%' ORDER BY id DESC LIMIT 1");$q->execute();$r=$q->fetch();if(!$r)return null;$d=json_decode($r['data'],true)?:[];$d['day']=substr($r['id'],5);return $d;}
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


