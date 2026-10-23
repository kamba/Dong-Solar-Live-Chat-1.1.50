<?php
class AdminDongLiveChatController extends ModuleAdminController {
 public function __construct(){parent::__construct();$this->bootstrap=true;$this->meta_title='Dong Solar Live Chat';}
 public function initContent(){
  // Repair partially applied upgrades before querying newly introduced columns.
  if (!$this->module->upgradeSchema()) {
   throw new Exception('Dong Solar Live Chat database schema upgrade failed. Check database permissions and PrestaShop logs.');
  }
  parent::initContent();$this->context->controller->addCSS($this->module->getPathUri().'views/css/admin.css?v=1148');$this->context->smarty->assign(['dongchat_admin_url'=>$this->context->link->getAdminLink('AdminDongLiveChat'),'dongchat_js_url'=>$this->module->getPathUri().'views/js/admin.js?v=1148','dongchat_tabs_js_url'=>$this->module->getPathUri().'views/js/admin-tabs.js?v=1148','dongchat_employee_id'=>(int)$this->context->employee->id]);$initialVisitors = Db::getInstance()->executeS('SELECT id_visitor,id_customer,name,email,phone,notes,ip_address,geo_country,geo_city,device_os,referrer_url,current_page,page_title,last_seen FROM `'._DB_PREFIX_.'dongchat_visitor` ORDER BY last_seen DESC LIMIT 200');
  if (!is_array($initialVisitors)) $initialVisitors = [];
  $initialVisitors=$this->decorateVisitors($initialVisitors);
  $selectedId = (int) Tools::getValue('dc_visitor');
  $selectedVisitor = null;
  foreach ($initialVisitors as $visitor) {
   if ((int)$visitor['id_visitor'] === $selectedId) { $selectedVisitor = $visitor; break; }
  }
  $this->context->smarty->assign('dongchat_selected_visitor', $selectedVisitor);
  $selectedMessages = [];
  if ($selectedVisitor) {
   $selectedMessages = Db::getInstance()->executeS('SELECT sender,body,created_at FROM `'._DB_PREFIX_.'dongchat_message` WHERE id_visitor='.(int)$selectedId.' ORDER BY id_message DESC LIMIT 100');
   if (!is_array($selectedMessages)) $selectedMessages = [];
   $selectedMessages = array_reverse($selectedMessages);
  }
  $this->context->smarty->assign('dongchat_selected_messages', $selectedMessages);
  // Server-rendered leads directory: independent of live chat polling and JS.
  $leadQuery=trim((string)Tools::getValue('dc_lead_q',''));
  $leadQuery=mb_substr($leadQuery,0,100);
  $leadPage=max(1,min(10000,(int)Tools::getValue('dc_lead_page',1)));
  $leadWhere=$this->leadsWhere($leadQuery);
  $leadTotal=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'dongchat_visitor` WHERE '.$leadWhere);
  $leadPages=max(1,(int)ceil($leadTotal/50));$leadPage=min($leadPage,$leadPages);
  $leadOffset=($leadPage-1)*50;
  $leadRows=Db::getInstance()->executeS('SELECT id_visitor,name,email,phone,notes,geo_city,geo_country,device_os,current_page,created_at,last_seen FROM `'._DB_PREFIX_.'dongchat_visitor` WHERE '.$leadWhere.' ORDER BY last_seen DESC LIMIT '.(int)$leadOffset.',50');
  $leadBase=$this->context->link->getAdminLink('AdminDongLiveChat');
  $this->context->smarty->assign(['dongchat_leads'=>is_array($leadRows)?$leadRows:[], 'dongchat_lead_total'=>$leadTotal,'dongchat_lead_query'=>$leadQuery,'dongchat_lead_page'=>$leadPage,'dongchat_lead_pages'=>$leadPages,'dongchat_lead_prev_url'=>$leadBase.'&dc_lead_page='.max(1,$leadPage-1).'&dc_lead_q='.rawurlencode($leadQuery).'&dc_tab=leads','dongchat_lead_next_url'=>$leadBase.'&dc_lead_page='.min($leadPages,$leadPage+1).'&dc_lead_q='.rawurlencode($leadQuery).'&dc_tab=leads','dongchat_lead_export_url'=>$leadBase.'&ajax=1&action=LeadsExport&dc_lead_q='.rawurlencode($leadQuery),'dongchat_admin_token'=>Tools::getAdminTokenLite('AdminDongLiveChat'),'dongchat_open_tab'=>(in_array((string)Tools::getValue('dc_tab'),['leads','visits'],true)?(string)Tools::getValue('dc_tab'):'live')]);

  // Server-render visit history, independent of analytics AJAX and live polling.
  $visitPage=max(1,min(10000,(int)Tools::getValue('dc_visit_page',1)));
  $visitTotal=(int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `'._DB_PREFIX_.'dongchat_visit`');
  $visitPages=max(1,(int)ceil($visitTotal/50));$visitPage=min($visitPage,$visitPages);
  $visitOffset=($visitPage-1)*50;
  $visits=Db::getInstance()->executeS('SELECT h.id_visit,h.id_visitor,h.page_path,h.page_url,h.page_title,h.visited_at,v.name,v.geo_city,v.geo_country FROM `'._DB_PREFIX_.'dongchat_visit` h LEFT JOIN `'._DB_PREFIX_.'dongchat_visitor` v ON v.id_visitor=h.id_visitor ORDER BY h.id_visit DESC LIMIT '.(int)$visitOffset.',50');
  $this->context->smarty->assign(['dongchat_visits'=>is_array($visits)?$visits:[],'dongchat_visit_total'=>$visitTotal,'dongchat_visit_page'=>$visitPage,'dongchat_visit_pages'=>$visitPages,'dongchat_visit_prev_url'=>$leadBase.'&dc_tab=visits&dc_visit_page='.max(1,$visitPage-1),'dongchat_visit_next_url'=>$leadBase.'&dc_tab=visits&dc_visit_page='.min($visitPages,$visitPage+1)]);
  $counts=$this->visitorCounts();
  $this->context->smarty->assign('dongchat_counts', $counts ?: ['active_visitors'=>0,'hour_visitors'=>0]);
  $this->context->smarty->assign('dongchat_initial_visitors', $initialVisitors);
  $this->context->smarty->assign('dongchat_initial_json', json_encode($initialVisitors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE));$this->content=$this->context->smarty->fetch($this->module->getLocalPath().'views/templates/admin/dashboard.tpl');$this->context->smarty->assign('content',$this->content);}
 private function decorateVisitors($rows){
  $rows=$this->fillCampaignSources($rows);
  foreach($rows as &$v){
   $page=(string)($v['current_page']??'');
   // Heuristic only: repeated malformed login redirects are likely automated requests.
   $v['online']=(strtotime((string)($v['last_seen']??'')) >= time()-60);
   $v['suspected_bot']=(bool)(preg_match('~^/login\?back=https?:/?(?:/)?dongsolar\.co\.ke/login\?back=~i',$page));
  }
  unset($v);
  return $rows;
 }
 private function fillCampaignSources($rows){
  foreach($rows as &$visitor){
   if(!empty($visitor['referrer_url']))continue;
   $query=parse_url((string)($visitor['current_page']??''),PHP_URL_QUERY);
   if(!is_string($query)||$query==='')continue;
   parse_str($query,$params);
   if(!empty($params['utm_source'])&&is_string($params['utm_source'])){
    $source=substr(trim($params['utm_source']),0,80);
    if(preg_match('/^[a-zA-Z0-9._ -]{1,80}$/D',$source))$visitor['referrer_url']='Campaign: '.$source;
   }else{
    foreach(['gclid'=>'Google Ads','gbraid'=>'Google Ads','wbraid'=>'Google Ads','fbclid'=>'Facebook / Instagram','msclkid'=>'Microsoft Ads','ttclid'=>'TikTok'] as $key=>$source){
     if(isset($params[$key])){$visitor['referrer_url']='Campaign: '.$source;break;}
    }
   }
  }
  unset($visitor);
  return $rows;
 }
 private function respond($json){ if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); } die($json); }
 public function displayAjax(){
  try {
   $action = (string) Tools::getValue('action');
   switch (strtolower($action)) {
    case 'list': $this->ajaxProcessList(); break;
    case 'leadsexport': $this->ajaxProcessLeadsExport(); break;
    case 'stats': $this->ajaxProcessStats(); break;
    case 'history': $this->ajaxProcessHistory(); break;
    case 'retention': $this->ajaxProcessRetention(); break;
    case 'messages': $this->ajaxProcessMessages(); break;
    case 'profile': $this->ajaxProcessProfile(); break;
    case 'send': $this->ajaxProcessSend(); break;
    default: $this->respond(json_encode(['error'=>'Unknown action']));
   }
  } catch (\Throwable $e) {
   PrestaShopLogger::addLog('Dong Live Chat AJAX error: '.$e->getMessage(), 3);
   if (!headers_sent()) http_response_code(500);
   $this->respond(json_encode(['error'=>'Server error; see PrestaShop logs']));
  }
 }
 private function leadsWhere($query){
  $where="(TRIM(COALESCE(`name`,'')) <> '' OR TRIM(COALESCE(`email`,'')) <> '' OR TRIM(COALESCE(`phone`,'')) <> '')";
  if($query!==''){
   $term=pSQL('%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query).'%');
   $where.=" AND (`name` LIKE '".$term."' OR `email` LIKE '".$term."' OR `phone` LIKE '".$term."' OR `notes` LIKE '".$term."')";
  }
  return $where;
 }
 private function csvSafe($value){
  $value=(string)$value;
  // Spreadsheet applications interpret leading =,+,-,@ as formulas.
  if(preg_match('/^[\x00-\x20]*[=+@-]/u',$value))$value="'".$value;
  return $value;
 }
 public function ajaxProcessLeadsExport(){
  $query=mb_substr(trim((string)Tools::getValue('dc_lead_q','')),0,100);
  $where=$this->leadsWhere($query);
  if(!headers_sent()){
   header('Content-Type: text/csv; charset=UTF-8');
   header('Content-Disposition: attachment; filename="dongsolar-leads-'.date('Y-m-d').'.csv"');
   header('Cache-Control: no-store');
  }
  $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
  fputcsv($out,['Visitor ID','Name','Email','Phone','Notes','City','Country','Device','Last page','First seen','Last seen']);
  // Batch reads keep memory bounded and limit impact on shared hosting.
  $lastId=0;
  do{
   $rows=Db::getInstance()->executeS('SELECT id_visitor,name,email,phone,notes,geo_city,geo_country,device_os,current_page,created_at,last_seen FROM `'._DB_PREFIX_.'dongchat_visitor` WHERE '.$where.' AND id_visitor > '.(int)$lastId.' ORDER BY id_visitor ASC LIMIT 500');
   if(!is_array($rows)||!$rows)break;
   foreach($rows as $r){$lastId=(int)$r['id_visitor'];fputcsv($out,array_map([$this,'csvSafe'],array_values($r)));}
  }while(count($rows)===500);
  fclose($out);exit;
 }
 private function visitorCounts(){
  // Match PHP timestamps used when writing last_seen, not the SQL server timezone.
  $now=time();$minute=pSQL(date('Y-m-d H:i:s',$now-60));$hour=pSQL(date('Y-m-d H:i:s',$now-3600));
  return Db::getInstance()->getRow('SELECT SUM(last_seen >= "'.$minute.'") AS active_visitors, SUM(last_seen >= "'.$hour.'") AS hour_visitors FROM `'._DB_PREFIX_.'dongchat_visitor`');
 }
 public function ajaxProcessStats(){
  $counts=$this->visitorCounts();
  $this->respond(json_encode(['stats'=>$counts ?: ['active_visitors'=>0,'hour_visitors'=>0]]));
 }
 private function retentionValues(){
  $visitor=(int)Configuration::get('DONGCHAT_RETENTION_VISITORS');
  $messages=(int)Configuration::get('DONGCHAT_RETENTION_MESSAGES');
  return ['visitors'=>in_array($visitor,[0,30,60,90,180,365],true)?$visitor:0,
          'messages'=>in_array($messages,[0,90,180,365,730],true)?$messages:0];
 }
 public function ajaxProcessHistory(){
  $db=Db::getInstance();$p=_DB_PREFIX_;
  $days=(int)Tools::getValue('days',30);if(!in_array($days,[7,30,90],true))$days=30;
  $offset=max(0,min(100000,(int)Tools::getValue('offset',0)));
  $since=pSQL(date('Y-m-d H:i:s',time()-$days*86400));
  $where=' WHERE created_at >= "'.$since.'"';
  $total=(int)$db->getValue('SELECT COUNT(*) FROM `'.$p.'dongchat_visitor`'.$where);
  $rows=$db->executeS('SELECT id_visitor,created_at,last_seen,geo_country,geo_city,device_os,current_page,page_title,referrer_url FROM `'.$p.'dongchat_visitor`'.$where.' ORDER BY created_at DESC LIMIT '.(int)$offset.',100');
  $groups=[];
  foreach(['geo_country'=>'countries','geo_city'=>'cities','device_os'=>'devices','current_page'=>'pages'] as $field=>$key){
   $groupRows=$db->executeS('SELECT `'.$field.'` AS label,COUNT(*) AS total FROM `'.$p.'dongchat_visitor`'.$where.' AND `'.$field.'` IS NOT NULL AND `'.$field.'` <> "" GROUP BY `'.$field.'` ORDER BY total DESC LIMIT 10');$groups[$key]=is_array($groupRows)?$groupRows:[];
  }
  $chats=(int)$db->getValue('SELECT COUNT(DISTINCT m.id_visitor) FROM `'.$p.'dongchat_message` m INNER JOIN `'.$p.'dongchat_visitor` v ON v.id_visitor=m.id_visitor WHERE v.created_at >= "'.$since.'"');
  $this->respond(json_encode(['ok'=>true,'days'=>$days,'total'=>$total,'chats'=>$chats,'rows'=>$rows?:[],'groups'=>$groups,'retention'=>$this->retentionValues()]));
 }
 public function ajaxProcessRetention(){
  if($_SERVER['REQUEST_METHOD']!=='POST')$this->respond(json_encode(['error'=>'POST required']));
  $v=(int)Tools::getValue('visitors',0);$m=(int)Tools::getValue('messages',0);
  if(!in_array($v,[0,30,60,90,180,365],true)||!in_array($m,[0,90,180,365,730],true))$this->respond(json_encode(['error'=>'Invalid retention period']));
  Configuration::updateValue('DONGCHAT_RETENTION_VISITORS',$v);
  Configuration::updateValue('DONGCHAT_RETENTION_MESSAGES',$m);
  if((string)Tools::getValue('run')!=='1')$this->respond(json_encode(['ok'=>true,'deleted'=>0]));
  $db=Db::getInstance();$p=_DB_PREFIX_;$deletedMessages=0;$deletedVisitors=0;
  // Delete only in small batches to avoid locking busy stores; rerun for more.
  if($m){$cutoff=pSQL(date('Y-m-d H:i:s',time()-$m*86400));
   $ids=$db->executeS('SELECT id_message FROM `'.$p.'dongchat_message` WHERE created_at < "'.$cutoff.'" ORDER BY id_message LIMIT 500');
   if($ids){$ids=array_map('intval',array_column($ids,'id_message'));$deletedMessages=count($ids);if(!$db->execute('DELETE FROM `'.$p.'dongchat_message` WHERE id_message IN ('.implode(',',$ids).')'))throw new Exception('Could not remove expired messages');}
  }
  if($v){$cutoff=pSQL(date('Y-m-d H:i:s',time()-$v*86400));
   // Preserve visitor profiles attached to any retained conversation.
   $ids=$db->executeS('SELECT v.id_visitor FROM `'.$p.'dongchat_visitor` v WHERE v.last_seen < "'.$cutoff.'" AND NOT EXISTS (SELECT 1 FROM `'.$p.'dongchat_message` m WHERE m.id_visitor=v.id_visitor) ORDER BY v.id_visitor LIMIT 500');
   if($ids){$ids=array_map('intval',array_column($ids,'id_visitor'));$deletedVisitors=count($ids);if(!$db->execute('DELETE FROM `'.$p.'dongchat_visitor` WHERE id_visitor IN ('.implode(',',$ids).')'))throw new Exception('Could not remove expired visitor records');}
  }
  // Remove orphaned visit rows for visitors removed above, in small batches.
  $orphanVisits=$db->executeS('SELECT h.id_visit FROM `'.$p.'dongchat_visit` h LEFT JOIN `'.$p.'dongchat_visitor` v ON v.id_visitor=h.id_visitor WHERE v.id_visitor IS NULL ORDER BY h.id_visit LIMIT 500');
  if($orphanVisits){$ids=array_map('intval',array_column($orphanVisits,'id_visit'));$db->execute('DELETE FROM `'.$p.'dongchat_visit` WHERE id_visit IN ('.implode(',',$ids).')');}
  $this->respond(json_encode(['ok'=>true,'deleted_messages'=>$deletedMessages,'deleted_visitors'=>$deletedVisitors]));
 }
 public function ajaxProcessList(){ $db=Db::getInstance();$p=_DB_PREFIX_;$rows=$db->executeS('SELECT v.id_visitor,v.id_customer,v.name,v.email,v.phone,v.notes,v.ip_address,v.geo_country,v.geo_city,v.device_os,v.referrer_url,v.current_page,v.page_title,v.last_seen,v.dismissed,(SELECT body FROM `'.$p.'dongchat_message` m WHERE m.id_visitor=v.id_visitor ORDER BY id_message DESC LIMIT 1) last_message FROM `'.$p.'dongchat_visitor` v ORDER BY last_seen DESC LIMIT 200');$incoming=$db->executeS('SELECT id_message,id_visitor FROM `'.$p.'dongchat_message` WHERE sender=\'visitor\' ORDER BY id_message DESC LIMIT 100');$this->respond(json_encode(['visitors'=>$this->decorateVisitors($rows === false ? [] : $rows),'incoming'=>$incoming ?: []])); }
 public function ajaxProcessMessages(){ $id=(int)Tools::getValue('id_visitor');$p=_DB_PREFIX_;$rows=Db::getInstance()->executeS('SELECT id_message,sender,body,created_at FROM `'.$p.'dongchat_message` WHERE id_visitor='.$id.' ORDER BY id_message DESC LIMIT 100');$this->respond(json_encode(['messages'=>array_reverse($rows ?: [])])); }
 public function ajaxProcessProfile(){
  if($_SERVER['REQUEST_METHOD']!=='POST')$this->respond(json_encode(['error'=>'POST required']));
  $id=(int)Tools::getValue('id_visitor');$db=Db::getInstance();$p=_DB_PREFIX_;
  if(!$id || !$db->getValue('SELECT id_visitor FROM `'.$p.'dongchat_visitor` WHERE id_visitor='.$id))$this->respond(json_encode(['error'=>'Visitor not found']));
  $name=trim((string)Tools::getValue('name'));$email=trim((string)Tools::getValue('email'));$phone=trim((string)Tools::getValue('phone'));$notes=trim((string)Tools::getValue('notes'));
  if(mb_strlen($name)>160 || mb_strlen($email)>254 || ($email!==''&&!Validate::isEmail($email)) || mb_strlen($phone)>50 || mb_strlen($notes)>5000)$this->respond(json_encode(['error'=>'Invalid visitor details']));
  $ok=$db->update('dongchat_visitor',['name'=>pSQL($name),'email'=>pSQL($email),'phone'=>pSQL($phone),'notes'=>pSQL($notes,true)],'id_visitor='.$id);
  $this->respond(json_encode(['ok'=>(bool)$ok]));
 }
 public function ajaxProcessSend(){if($_SERVER['REQUEST_METHOD']!=='POST')$this->respond(json_encode(['error'=>'Method not allowed']));$id=(int)Tools::getValue('id_visitor');$body=trim((string)Tools::getValue('body'));if(!$id||!Tools::strlen($body)||Tools::strlen($body)>2000)$this->respond(json_encode(['error'=>'Invalid message']));$p=_DB_PREFIX_;if(!Db::getInstance()->getValue('SELECT id_visitor FROM `'.$p.'dongchat_visitor` WHERE id_visitor='.$id))$this->respond(json_encode(['error'=>'Visitor not found']));Db::getInstance()->insert('dongchat_message',['id_visitor'=>$id,'sender'=>'operator','id_employee'=>(int)$this->context->employee->id,'body'=>pSQL($body,true),'created_at'=>date('Y-m-d H:i:s')]);$this->respond(json_encode(['ok'=>true])); }
}
