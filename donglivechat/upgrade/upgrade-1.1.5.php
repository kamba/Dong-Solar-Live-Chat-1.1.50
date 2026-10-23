<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_1_1_5($module) { return $module->upgradeSchema() && $module->repairAdminTab(); }
