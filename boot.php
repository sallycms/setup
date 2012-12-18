<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

// add the setup app
sly_Loader::addLoadPath(SLY_SALLYFOLDER.'/setup/lib/');

// init the app
$app = new sly_App_Setup();
sly_Core::setCurrentApp($app);
$app->initialize();

// ... and run it
$app->run();
