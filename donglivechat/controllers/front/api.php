<?php
class DonglivechatApiModuleFrontController extends ModuleFrontController {
 public $ajax=true;
 private function visitorIp(){ $ip=(string)Tools::getRemoteAddr(); return filter_var($ip,FILTER_VALIDATE_IP) ? $ip : ''; }
 private function deviceOS(){
  $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
  if(preg_match('/iPhone|iPod/i',$ua))return 'iPhone (iOS)';
  if(preg_match('/iPad/i',$ua))return 'iPad (iPadOS)';
  if(preg_match('/Android/i',$ua))return preg_match('/Mobile/i',$ua)?'Android Mobile':'Android Tablet';
  if(preg_match('/Windows Phone/i',$ua))return 'Windows Mobile';
  if(preg_match('/Windows/i',$ua))return 'Windows Desktop';
  if(preg_match('/Macintosh|Mac OS X/i',$ua))return 'macOS Desktop';
  if(preg_match('/CrOS/i',$ua))return 'ChromeOS Desktop';
  if(preg_match('/Linux/i',$ua))return 'Linux Desktop';
  return 'Other / Unknown';
 }
 private function geoLocation($ip){
  $empty=['geo_country'=>'','geo_city'=>''];
  if(!$ip || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return $empty;
  // Prefer PrestaShop's existing local MaxMind City database; never call an external API.
  $paths=[];
  if(defined('_PS_GEOIP_DIR_'))$paths[]=_PS_GEOIP_DIR_.'GeoLite2-City.mmdb';
  $paths[]=_PS_ROOT_DIR_.'/var/geoip/GeoLite2-City.mmdb';
  $paths[]=_PS_ROOT_DIR_.'/tools/geoip/GeoLite2-City.mmdb';
  $paths[]=_PS_ROOT_DIR_.'/app/Resources/geoip/GeoLite2-City.mmdb';
  $path='';foreach($paths as $candidate){if(is_readable($candidate)){$path=$candidate;break;}}
  if(!$path || !class_exists('\GeoIp2\Database\Reader'))return $empty;
  try{$reader=new \GeoIp2\Database\Reader($path);$record=$reader->city($ip);
   $country=trim((string)($record->country->name??''));$city=trim((string)($record->city->name??''));
   if(method_exists($reader,'close'))$reader->close();
   return ['geo_country'=>mb_substr($country,0,100),'geo_city'=>mb_substr($city,0,120)];
  }catch(\Throwable $e){return $empty;}
 }
 private function referrer(){
  $raw=trim((string)Tools::getValue('referrer'));
  if($raw==='' || strlen($raw)>2048)return '';
  // Allow only public HTTP(S) origins, and avoid storing paths or sensitive query strings.
  $url=parse_url($raw);
  if(!is_array($url) || !isset($url['scheme'],$url['host']) || !in_array(strtolower($url['scheme']),['http','https'],true))return '';
  $host=strtolower(rtrim($url['host'],'.'));
  if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return '';
  $siteHost=strtolower((string)Tools::getHttpHost());$siteHost=preg_replace('/:\d+$/','',$siteHost);
  if($host===$siteHost || $host==='www.'.$siteHost || 'www.'.$host===$siteHost)return '';
  return substr($host,0,250);
 }
 private function campaignSource(){
  $source=trim((string)Tools::getValue('campaign_source'));
  if($source===''){$source=$this->sourceFromPage((string)Tools::getValue('page'));}
  if(!preg_match('/^[a-zA-Z0-9._ -]{1,80}$/D',$source))return '';
  return 'Campaign: '.substr($source,0,80);
 }
 private function sourceFromPage($page){
  $query=parse_url($page,PHP_URL_QUERY);if(!is_string($query)||$query==='')return '';
  parse_str($query,$params);
  if(!empty($params['utm_source'])&&is_string($params['utm_source']))return substr($params['utm_source'],0,80);
  foreach(['gclid'=>'Google Ads','gbraid'=>'Google Ads','wbraid'=>'Google Ads','fbclid'=>'Facebook / Instagram','msclkid'=>'Microsoft Ads','ttclid'=>'TikTok'] as $key=>$source){if(isset($params[$key]))return $source;}
  return '';
 }
 private function aiReply($id){
  if(!Configuration::get('DONGCHAT_AI_ENABLED'))return;
  $key=(string)Configuration::get('DONGCHAT_API_KEY');if(!$key || !function_exists('curl_init'))return;
  $p=_DB_PREFIX_;$db=Db::getInstance();
  $history=$db->executeS('SELECT sender,body FROM `'.$p.'dongchat_message` WHERE id_visitor='.(int)$id.' ORDER BY id_message DESC LIMIT 12');
  $history=array_reverse($history);$input=[];
  foreach($history as $m){if($m['sender']==='operator')continue;$input[]=['role'=>$m['sender']==='visitor'?'user':'assistant','content'=>mb_substr($m['body'],0,2000)];}
  $payload=['model'=>Configuration::get('DONGCHAT_AI_MODEL'),'instructions'=>Configuration::get('DONGCHAT_AI_PROMPT'),'input'=>$input,'max_output_tokens'=>350,'store'=>false];
  $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5]);
  $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($status<200||$status>=300||!$raw)return;
  $response=json_decode($raw,true);$answer='';foreach(($response['output']??[]) as $item){foreach(($item['content']??[]) as $part){if(($part['type']??'')==='output_text')$answer.=$part['text'];}}
  $answer=trim(mb_substr($answer,0,2000));if($answer!=='')$db->insert('dongchat_message',['id_visitor'=>(int)$id,'sender'=>'ai','body'=>pSQL($answer,true),'created_at'=>date('Y-m-d H:i:s')]);
 }
 private function reply($a,$status=200){http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');die(json_encode($a));}
 public function initContent(){parent::initContent();$action=(string)Tools::getValue('action');if(!(new Donglivechat())->isChatVisible())$this->reply(['error'=>'Chat is unavailable'],403);$token=(string)Tools::getValue('visitor_token');if(!preg_match('/^[a-f0-9]{64}$/D',$token))$this->reply(['error'=>'Invalid session'],403);$hash=hash('sha256',$token);$db=Db::getInstance();$p=_DB_PREFIX_;$row=$db->getRow('SELECT * FROM `'.$p.'dongchat_visitor` WHERE token_hash="'.pSQL($hash).'"');if(!$row && $action==='ping'){$db->insert('dongchat_visitor',['token_hash'=>pSQL($hash),'created_at'=>date('Y-m-d H:i:s'),'last_seen'=>date('Y-m-d H:i:s'),'ip_address'=>pSQL($this->visitorIp()),'referrer_url'=>pSQL($this->referrer() ?: $this->campaignSource()),'device_os'=>pSQL($this->deviceOS())]);$row=['id_visitor'=>(int)$db->Insert_ID(),'dismissed'=>0];}if(!$row)$this->reply(['error'=>'Session not found'],404);$id=(int)$row['id_visitor'];
 if($action==='ping'){ $page=substr(strip_tags((string)(isset($_POST['dc_page_path']) ? $_POST['dc_page_path'] : Tools::getValue('page'))),0,500);$pageTitle=trim(strip_tags((string)Tools::getValue('page_title')));$pageTitle=mb_substr(preg_replace('/[\x00-\x1F\x7F]/u',' ',$pageTitle),0,255);$data=['page_title'=>pSQL($pageTitle),'last_seen'=>date('Y-m-d H:i:s'),'ip_address'=>pSQL($this->visitorIp())]; if($page!=='' && $page!=='/')$data['current_page']=pSQL($page); elseif($page==='/' && empty($row['current_page']))$data['current_page']='/';if(empty($row['device_os']))$data['device_os']=pSQL($this->deviceOS());if(empty($row['geo_country']) && empty($row['geo_city'])){ $geo=$this->geoLocation($this->visitorIp());if($geo['geo_country']!=='' || $geo['geo_city']!=='')$data=array_merge($data,array_map('pSQL',$geo)); }// Save a page visit only on navigation or a return after 30 minutes, not on every heartbeat.
  // Store the path without query strings (which can contain payment or login tokens).
  $path=parse_url($page,PHP_URL_PATH);
  // The storefront sends a relative path; construct a safe same-origin absolute link.
  $origin=rtrim($this->context->link->getBaseLink(),'/');
  $safeUrl=$origin.(is_string($path)?$path:'');
  if(is_string($path) && $path!=='' && $path[0]==='/' && strlen($path)<=500){
   $previous=$db->getRow('SELECT page_path,visited_at FROM `'.$p.'dongchat_visit` WHERE id_visitor='.$id.' ORDER BY id_visit DESC');
   $isNewPage=!$previous || (string)$previous['page_path']!==$path;
   $isReturn=$previous && strtotime((string)$previous['visited_at'])<time()-1800;
   if($isNewPage || $isReturn){
    $db->insert('dongchat_visit',['id_visitor'=>$id,'page_path'=>pSQL($path),'page_url'=>pSQL(substr($safeUrl,0,600)),'page_title'=>pSQL($pageTitle),'visited_at'=>date('Y-m-d H:i:s')]);
   }
  }
  if($this->context->customer->isLogged())$data['id_customer']=(int)$this->context->customer->id;if(empty($row['referrer_url'])){ $ref=$this->referrer() ?: $this->campaignSource();if($ref!=='')$data['referrer_url']=pSQL($ref);}$db->update('dongchat_visitor',$data,'id_visitor='.$id);}
 elseif($action==='send'){if($_SERVER['REQUEST_METHOD']!=='POST')$this->reply(['error'=>'Method not allowed'],405);$body=trim((string)Tools::getValue('body'));if(!Tools::strlen($body)||Tools::strlen($body)>2000)$this->reply(['error'=>'Message must be 1–2000 characters'],422);$recent=(int)$db->getValue('SELECT COUNT(*) FROM `'.$p.'dongchat_message` WHERE id_visitor='.$id.' AND sender="visitor" AND created_at > DATE_SUB(NOW(),INTERVAL 1 MINUTE)');if($recent>=8)$this->reply(['error'=>'Too many messages'],429);$db->insert('dongchat_message',['id_visitor'=>$id,'sender'=>'visitor','body'=>pSQL($body,true),'created_at'=>date('Y-m-d H:i:s')]);$this->aiReply($id);}
 elseif($action==='dismiss'){$db->update('dongchat_visitor',['dismissed'=>1],'id_visitor='.$id);}
 elseif($action!=='poll')$this->reply(['error'=>'Unknown action'],400);
 $after=max(0,(int)Tools::getValue('after'));$messages=$db->executeS('SELECT m.id_message,m.sender,m.body,m.created_at,m.id_employee,e.firstname AS operator_firstname,e.lastname AS operator_lastname FROM `'.$p.'dongchat_message` m LEFT JOIN `'.$p.'employee` e ON (m.sender="operator" AND m.id_employee=e.id_employee) WHERE m.id_visitor='.$id.' AND m.id_message>'.$after.' ORDER BY m.id_message ASC LIMIT 100');
 $operator=$db->getRow('SELECT e.id_employee,e.firstname,e.lastname FROM `'.$p.'dongchat_message` m INNER JOIN `'.$p.'employee` e ON m.id_employee=e.id_employee WHERE m.id_visitor='.$id.' AND m.sender="operator" ORDER BY m.id_message DESC');
 $identity=null;
 if($operator){$employeeId=(int)$operator['id_employee'];$name=trim($operator['firstname'].' '.$operator['lastname']);$photo='';
  // Only advertise an avatar URL if the employee image actually exists on disk.
  $photoPath=_PS_IMG_DIR_.'e/'.$employeeId.'.jpg';
  if(is_file($photoPath))$photo=$this->context->link->getBaseLink().'img/e/'.$employeeId.'.jpg';
  $identity=['name'=>$name,'avatar_url'=>$photo];
 }
 $this->reply(['messages'=>$messages ?: [],'operator'=>$identity,'dismissed'=>(bool)$row['dismissed']]); }
}
