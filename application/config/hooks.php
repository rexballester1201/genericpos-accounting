<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	https://codeigniter.com/user_guide/general/hooks.html
|
*/

/*
| -------------------------------------------------------------------------
| SETTINGS OVERRIDE
| -------------------------------------------------------------------------
| Pushes admin-set values from gp_settings into CI's config, so every existing
| $this->config->item() call picks them up without changing a single call site.
|
| NOTE: post_controller_constructor, not pre_controller. It must run AFTER the
| controller's __construct(), because that is where controllers load the
| database — and this hook deliberately never loads one itself. The SPA shell
| renders without a database on purpose (see autoload.php); connecting here
| would undo that and turn a database outage into a blank page.
|
| Without this hook the admin settings screen saves successfully and has no
| effect whatsoever. That was the observed behaviour before it was added.
*/
$hook['post_controller_constructor'] = array(
    'class'    => 'SettingsOverride',
    'function' => 'apply',
    'filename' => 'SettingsOverride.php',
    'filepath' => 'hooks',
);
