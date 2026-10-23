<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_1_1_22($module) {
 $mode = !(int)Configuration::get('DONGCHAT_ENABLED') ? 'disabled' : ((int)Configuration::get('DONGCHAT_HOURS_ENABLED') ? 'scheduled' : 'always');
 return Configuration::updateValue('DONGCHAT_VISIBILITY_MODE', $mode);
}
