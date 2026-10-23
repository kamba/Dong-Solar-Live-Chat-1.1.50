<?php
if (!defined('_PS_VERSION_')) { exit; }
class Donglivechat extends Module {
 public function __construct(){ $this->name='donglivechat'; $this->tab='front_office_features'; $this->version='1.1.50'; $this->author='Dong Solar Limited'; $this->bootstrap=true; parent::__construct(); $this->displayName='Dong Solar Live Chat'; $this->description='Visitor tracking and operator-initiated live chat.'; $this->ps_versions_compliancy=['min'=>'9.0.0','max'=>'9.9.9']; }
 public function install(){ Configuration::updateValue('DONGCHAT_ENABLED',1);Configuration::updateValue('DONGCHAT_VISIBILITY_MODE','always');Configuration::updateValue('DONGCHAT_AI_ENABLED',0);Configuration::updateValue('DONGCHAT_AUTO_POPUP',1);Configuration::updateValue('DONGCHAT_POSITION','right');Configuration::updateValue('DONGCHAT_HOURS_ENABLED',0);Configuration::updateValue('DONGCHAT_HOURS_TZ','Africa/Nairobi');foreach(['mon','tue','wed','thu','fri','sat','sun'] as $day){Configuration::updateValue('DONGCHAT_HOURS_'.$day.'_ENABLED',in_array($day,['mon','tue','wed','thu','fri'],true)?1:0);Configuration::updateValue('DONGCHAT_HOURS_'.$day.'_START','08:00');Configuration::updateValue('DONGCHAT_HOURS_'.$day.'_END','17:00');}Configuration::updateValue('DONGCHAT_AI_MODEL','gpt-4.1-mini');Configuration::updateValue('DONGCHAT_AI_PROMPT','You are Dong Solar Limited customer support in Kenya. Be helpful and concise. Dong Solar sells solar lighting and portable power stations but does not offer installation services. Never invent prices, stock levels, warranty terms or specifications. Refer uncertain questions to a human operator.'); if(!parent::install() || !$this->registerHook('displayHeader') || !$this->registerHook('displayFooter')) return false; foreach($this->sql() as $q){if(!Db::getInstance()->execute($q))return false;} $tab=new Tab();$tab->class_name='AdminDongLiveChat';$tab->module=$this->name;$tab->id_parent=(int)Tab::getIdFromClassName('AdminParentCustomer');$tab->active=1;foreach(Language::getLanguages(false) as $l)$tab->name[$l['id_lang']]='Live Chat';return $tab->add() && $this->upgradeSchema(); }
 public function upgradeSchema(){
  $db=Db::getInstance();$p=_DB_PREFIX_;
  foreach(['name'=>'VARCHAR(160) NOT NULL DEFAULT \'\'','email'=>'VARCHAR(254) NOT NULL DEFAULT \'\'','phone'=>'VARCHAR(50) NOT NULL DEFAULT \'\'','notes'=>'TEXT NULL','ip_address'=>"VARCHAR(45) NOT NULL DEFAULT ''",'referrer_url'=>"VARCHAR(500) NOT NULL DEFAULT ''",'page_title'=>"VARCHAR(255) NOT NULL DEFAULT ''",'geo_country'=>"VARCHAR(100) NOT NULL DEFAULT ''",'geo_city'=>"VARCHAR(120) NOT NULL DEFAULT ''",'device_os'=>"VARCHAR(100) NOT NULL DEFAULT ''"] as $column=>$definition){
   $exists=$db->executeS('SHOW COLUMNS FROM `'.$p.'dongchat_visitor` LIKE "'.pSQL($column).'"');
   if(!$exists && !$db->execute('ALTER TABLE `'.$p.'dongchat_visitor` ADD `'.$column.'` '.$definition))return false;
  }
  $exists=$db->executeS('SHOW COLUMNS FROM `'.$p.'dongchat_message` LIKE "sender"');
  if($exists && !$db->execute('ALTER TABLE `'.$p.'dongchat_message` MODIFY sender ENUM("visitor","operator","ai") NOT NULL'))return false;
  // v1.1.18: employee identity is referenced by both the operator sender and storefront poll.
  // Existing installs predate this column; CREATE TABLE IF NOT EXISTS does not add it.
  $employeeColumn=$db->executeS('SHOW COLUMNS FROM `'.$p.'dongchat_message` LIKE "id_employee"');
  if(!$employeeColumn && !$db->execute('ALTER TABLE `'.$p.'dongchat_message` ADD `id_employee` INT UNSIGNED DEFAULT NULL AFTER `sender`'))return false;
  // Independent, append-only visit events; existing visitor and message tables remain untouched.
  if(!$db->execute('CREATE TABLE IF NOT EXISTS `'.$p.'dongchat_visit` (id_visit BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_visitor INT UNSIGNED NOT NULL, page_path VARCHAR(500) NOT NULL DEFAULT \'\', page_title VARCHAR(255) NOT NULL DEFAULT \'\', visited_at DATETIME NOT NULL, INDEX visitor_time (id_visitor,visited_at), INDEX visit_time (visited_at)) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4'))return false;
  $urlCol=$db->executeS('SHOW COLUMNS FROM `'.$p.'dongchat_visit` LIKE "page_url"');
  if(!$urlCol && !$db->execute('ALTER TABLE `'.$p.'dongchat_visit` ADD `page_url` VARCHAR(600) NOT NULL DEFAULT \'\' AFTER `page_path`'))return false;
  return true;
 }
 public function getContent(){
  if(Tools::isSubmit('submitDongChatSettings')){
   // The PrestaShop 9 module configuration route authenticates the employee and validates its own route token.
   // Do not compare that Symfony token with the legacy AdminModules token: they are different formats.
   if (!$this->context->employee || !(int)$this->context->employee->id || !$this->context->employee->isLoggedBack()) {
    return $this->displayError('Administrator authentication required');
   }
   // Validate the entire form before changing any saved configuration.
   $mode=(string)Tools::getValue('DONGCHAT_VISIBILITY_MODE',$this->chatVisibilityMode());
   if(!in_array($mode,['always','scheduled','disabled'],true))return $this->displayError('Invalid storefront chat visibility mode');
   $position=(string)Tools::getValue('DONGCHAT_POSITION',Configuration::get('DONGCHAT_POSITION')?:'right');
   if(!in_array($position,['left','right'],true))return $this->displayError('Invalid chat position');
   $tz=(string)Tools::getValue('DONGCHAT_HOURS_TZ',Configuration::get('DONGCHAT_HOURS_TZ')?:'Africa/Nairobi');
   if(!in_array($tz,timezone_identifiers_list(),true))return $this->displayError('Invalid timezone');
   $schedule=[];
   foreach(['mon','tue','wed','thu','fri','sat','sun'] as $day){
    $enabled=(int)(bool)Tools::getValue('DONGCHAT_HOURS_'.$day.'_ENABLED',Configuration::get('DONGCHAT_HOURS_'.$day.'_ENABLED'));
    $start=trim((string)Tools::getValue('DONGCHAT_HOURS_'.$day.'_START',Configuration::get('DONGCHAT_HOURS_'.$day.'_START')?:'08:00'));
    $end=trim((string)Tools::getValue('DONGCHAT_HOURS_'.$day.'_END',Configuration::get('DONGCHAT_HOURS_'.$day.'_END')?:'17:00'));
    // In Always On / Disabled mode the schedule is not relevant. Closed days need no times.
    if($mode==='scheduled' && $enabled){
     if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$start)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$end))return $this->displayError('Invalid hours for '.$day.' (use HH:MM)');
    }
    // Do not store invalid time strings; keep the last valid values instead.
    if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$start)){
     $previous=(string)Configuration::get('DONGCHAT_HOURS_'.$day.'_START');
     $start=preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$previous)?$previous:'08:00';
    }
    if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$end)){
     $previous=(string)Configuration::get('DONGCHAT_HOURS_'.$day.'_END');
     $end=preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$previous)?$previous:'17:00';
    }
    $schedule[$day]=['enabled'=>$enabled,'start'=>$start,'end'=>$end];
   }
   $model=trim((string)Tools::getValue('DONGCHAT_AI_MODEL'));
   if(!preg_match('/^[a-zA-Z0-9._-]{2,80}$/',$model))return $this->displayError('Invalid model name');
   Configuration::updateValue('DONGCHAT_VISIBILITY_MODE',$mode);
   Configuration::updateValue('DONGCHAT_ENABLED',$mode==='disabled'?0:1);
   Configuration::updateValue('DONGCHAT_HOURS_ENABLED',$mode==='scheduled'?1:0);
   foreach(['AI_ENABLED','AUTO_POPUP'] as $key)Configuration::updateValue('DONGCHAT_'.$key,(int)(bool)Tools::getValue('DONGCHAT_'.$key));
   Configuration::updateValue('DONGCHAT_POSITION',$position);
   Configuration::updateValue('DONGCHAT_HOURS_TZ',$tz);
   foreach($schedule as $day=>$hours){foreach($hours as $key=>$value)Configuration::updateValue('DONGCHAT_HOURS_'.$day.'_'.strtoupper($key),$value);}

   Configuration::updateValue('DONGCHAT_AI_MODEL',$model);
   Configuration::updateValue('DONGCHAT_AI_PROMPT',substr((string)Tools::getValue('DONGCHAT_AI_PROMPT'),0,6000));
   $key=trim((string)Tools::getValue('DONGCHAT_API_KEY'));
   if($key!=='')Configuration::updateValue('DONGCHAT_API_KEY',$key);
   if(Tools::getValue('DONGCHAT_CLEAR_KEY'))Configuration::deleteByName('DONGCHAT_API_KEY');
   return $this->displayConfirmation('Settings saved').$this->settingsForm();
  }
  return $this->settingsForm();
 }
 private function settingsForm(){
  $fields=[['type'=>'select','label'=>'Chat icon position','name'=>'DONGCHAT_POSITION','options'=>['query'=>[['id'=>'right','name'=>'Right side'],['id'=>'left','name'=>'Left side']],'id'=>'id','name'=>'name'],'desc'=>'Choose where the chat button and chat window appear on the storefront.'],['type'=>'select','label'=>'Storefront chat visibility','name'=>'DONGCHAT_VISIBILITY_MODE','options'=>['query'=>[['id'=>'always','name'=>'Always on — show chat 24/7'],['id'=>'scheduled','name'=>'Working hours — show only during selected hours'],['id'=>'disabled','name'=>'Disabled — hide chat completely from visitors']],'id'=>'id','name'=>'name'],'desc'=>'Choose one mode. Working hours below apply only when Working hours is selected. The administrator inbox remains available in every mode.'],['type'=>'switch','label'=>'Enable AI replies','name'=>'DONGCHAT_AI_ENABLED','is_bool'=>true,'values'=>[['id'=>'ai_on','value'=>1,'label'=>'Yes'],['id'=>'ai_off','value'=>0,'label'=>'No']]],['type'=>'switch','label'=>'Automatic operator popup','name'=>'DONGCHAT_AUTO_POPUP','is_bool'=>true,'values'=>[['id'=>'popup_on','value'=>1,'label'=>'Yes'],['id'=>'popup_off','value'=>0,'label'=>'No']]],['type'=>'text','label'=>'OpenAI model','name'=>'DONGCHAT_AI_MODEL'],['type'=>'password','label'=>'OpenAI API key (leave blank to retain existing)','name'=>'DONGCHAT_API_KEY','desc'=>'Stored in PrestaShop configuration. Restrict access to trusted administrators.'],['type'=>'switch','label'=>'Delete stored API key','name'=>'DONGCHAT_CLEAR_KEY','is_bool'=>true,'values'=>[['id'=>'clear_on','value'=>1,'label'=>'Yes'],['id'=>'clear_off','value'=>0,'label'=>'No']]],['type'=>'textarea','label'=>'AI instructions','name'=>'DONGCHAT_AI_PROMPT','rows'=>7,'cols'=>70]];
  $fields[]=['type'=>'select','label'=>'Working-hours timezone','name'=>'DONGCHAT_HOURS_TZ','options'=>['query'=>[['id'=>'Africa/Nairobi','name'=>'Africa/Nairobi (Kenya)'],['id'=>'UTC','name'=>'UTC'],['id'=>'Africa/Kampala','name'=>'Africa/Kampala'],['id'=>'Africa/Dar_es_Salaam','name'=>'Africa/Dar_es_Salaam']],'id'=>'id','name'=>'name']];
  foreach(['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'] as $day=>$label){
   $fields[]=['type'=>'switch','label'=>$label.' enabled','name'=>'DONGCHAT_HOURS_'.$day.'_ENABLED','is_bool'=>true,'values'=>[['id'=>$day.'_on','value'=>1,'label'=>'Yes'],['id'=>$day.'_off','value'=>0,'label'=>'No']]];
   $fields[]=['type'=>'text','label'=>$label.' opening time','name'=>'DONGCHAT_HOURS_'.$day.'_START','desc'=>'24-hour HH:MM, e.g. 08:00'];
   $fields[]=['type'=>'text','label'=>$label.' closing time','name'=>'DONGCHAT_HOURS_'.$day.'_END','desc'=>'24-hour HH:MM, e.g. 17:00. Same opening and closing time means closed. Overnight shifts are supported.'];
  }
  $helper=new HelperForm();$helper->module=$this;$helper->name_controller=$this->name;$helper->identifier=$this->identifier;$helper->token=Tools::getAdminTokenLite('AdminModules');$helper->currentIndex=AdminController::$currentIndex.'&configure='.$this->name;$helper->submit_action='submitDongChatSettings';$helper->default_form_language=(int)Configuration::get('PS_LANG_DEFAULT');$helper->fields_value=[];
  foreach($fields as $field){
   $name=$field['name'];
   if($name==='DONGCHAT_API_KEY')$helper->fields_value[$name]='';
   elseif($name==='DONGCHAT_CLEAR_KEY')$helper->fields_value[$name]=0;
   elseif($name==='DONGCHAT_VISIBILITY_MODE')$helper->fields_value[$name]=$this->chatVisibilityMode();
   else $helper->fields_value[$name]=Configuration::get($name);
  }
    return '<p><a class="btn btn-primary" href="'.htmlspecialchars($this->context->link->getAdminLink('AdminDongLiveChat'),ENT_QUOTES,'UTF-8').'">Open Live Chat Inbox</a></p><p>API calls are billed separately by OpenAI. AI replies are disabled until enabled and configured.</p>'.$helper->generateForm([['form'=>['legend'=>['title'=>'Dong Solar Live Chat Settings'],'input'=>$fields,'submit'=>['title'=>'Save settings']]]]);
 }
 public function repairAdminTab(){
  $id=(int)Tab::getIdFromClassName('AdminDongLiveChat');
  $parent=(int)Tab::getIdFromClassName('AdminParentCustomer');
  if(!$parent)$parent=(int)Tab::getIdFromClassName('AdminParentCustomerThreads');
  if($id){$tab=new Tab($id);}else{$tab=new Tab();$tab->class_name='AdminDongLiveChat';}
  $tab->module=$this->name;$tab->active=1;
  if($parent)$tab->id_parent=$parent;
  foreach(Language::getLanguages(false) as $language)$tab->name[(int)$language['id_lang']]='Dong Solar Live Chat';
  return $id ? $tab->update() : $tab->add();
 }
 public function uninstall(){ $id=(int)Tab::getIdFromClassName('AdminDongLiveChat');if($id){$t=new Tab($id);$t->delete();} return parent::uninstall(); }
 private function sql(){ $p=_DB_PREFIX_;return ["CREATE TABLE IF NOT EXISTS `{$p}dongchat_visitor` (id_visitor INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash CHAR(64) NOT NULL UNIQUE, id_customer INT UNSIGNED DEFAULT NULL, current_page VARCHAR(500) NOT NULL DEFAULT '', last_seen DATETIME NOT NULL, created_at DATETIME NOT NULL, dismissed TINYINT(1) NOT NULL DEFAULT 0, ip_address VARCHAR(45) NOT NULL DEFAULT '', referrer_url VARCHAR(500) NOT NULL DEFAULT '', INDEX(last_seen)) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8mb4", "CREATE TABLE IF NOT EXISTS `{$p}dongchat_message` (id_message BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,id_visitor INT UNSIGNED NOT NULL, sender ENUM('visitor','operator') NOT NULL, id_employee INT UNSIGNED DEFAULT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL, INDEX(id_visitor,id_message)) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8mb4"]; }
 public function chatVisibilityMode(){
  $mode=(string)Configuration::get('DONGCHAT_VISIBILITY_MODE');
  if(in_array($mode,['always','scheduled','disabled'],true))return $mode;
  if(!(int)Configuration::get('DONGCHAT_ENABLED'))return 'disabled';
  return (int)Configuration::get('DONGCHAT_HOURS_ENABLED')?'scheduled':'always';
 }
 public function isChatVisible(){
  $mode=$this->chatVisibilityMode();
  return $mode==='always' || ($mode==='scheduled' && $this->isWithinWorkingHours());
 }
 public function isWithinWorkingHours(){
  if(!(int)Configuration::get('DONGCHAT_HOURS_ENABLED'))return true;
  $tz=(string)Configuration::get('DONGCHAT_HOURS_TZ');
  try{$now=new DateTimeImmutable('now',new DateTimeZone($tz?:'Africa/Nairobi'));}catch(Exception $e){return false;}
  $days=['mon','tue','wed','thu','fri','sat','sun'];$today=(int)$now->format('N')-1;
  $minute=(int)$now->format('G')*60+(int)$now->format('i');
  foreach([0,1] as $offset){
   $day=$days[($today-$offset+7)%7];
   if(!(int)Configuration::get('DONGCHAT_HOURS_'.$day.'_ENABLED'))continue;
   $start=(string)Configuration::get('DONGCHAT_HOURS_'.$day.'_START');$end=(string)Configuration::get('DONGCHAT_HOURS_'.$day.'_END');
   if(!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/',$start,$a)||!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/',$end,$b))continue;
   $from=(int)$a[1]*60+(int)$a[2];$to=(int)$b[1]*60+(int)$b[2];
   if($from===$to)continue;
   if($offset===0 && ($from<$to ? ($minute >=$from && $minute<$to):$minute >=$from))return true;
   if($offset===1 && $from>$to && $minute<$to)return true;
  }
  return false;
 }
 public function hookDisplayHeader(){ if(!$this->isChatVisible())return; $this->context->controller->registerStylesheet('dongchat-css','modules/'.$this->name.'/views/css/chat.css',['media'=>'all','priority'=>200]);/* JavaScript loaded directly after the widget in displayFooter. */ }
 public function hookDisplayFooter(){ $this->context->smarty->assign(['dongchat_endpoint'=>$this->context->link->getModuleLink($this->name,'api',[],true)]);if(!$this->isChatVisible())return ''; $this->context->smarty->assign(['dongchat_popup'=>(int)Configuration::get('DONGCHAT_AUTO_POPUP'),'dongchat_ai'=>(int)Configuration::get('DONGCHAT_AI_ENABLED'),'dongchat_position'=>Configuration::get('DONGCHAT_POSITION')==='left'?'left':'right']);return $this->display(__FILE__,'views/templates/hook/widget.tpl').'<script src="'.htmlspecialchars($this->_path.'views/js/chat.js?v=1149',ENT_QUOTES,'UTF-8').'" defer></script>'; }
}
