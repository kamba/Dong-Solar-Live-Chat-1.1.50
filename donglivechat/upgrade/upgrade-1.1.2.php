<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_1_1_2($module) {
 if (!in_array(Configuration::get('DONGCHAT_POSITION'), ['left','right'], true)) {
  Configuration::updateValue('DONGCHAT_POSITION','right');
 }
 return true;
}
