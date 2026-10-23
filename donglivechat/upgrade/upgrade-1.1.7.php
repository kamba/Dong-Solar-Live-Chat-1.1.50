<?php
if (!defined('_PS_VERSION_')) { exit; }
function upgrade_module_1_1_7($module) { return $module->upgradeSchema(); }
