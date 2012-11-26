<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Controller_Setup_Setup extends sly_Controller_Setup_Base implements sly_Controller_Interface {
	protected $flash;
	protected $results;

	protected function init() {
		$this->flash = sly_Core::getFlashMessage();

		// check system config and stop with an error page if any serious problems arise
		$params = array('errors' => false);
		$params = sly_Util_Setup::checkSystem($params);
		$params = sly_Util_Setup::checkPdoDrivers($params);
		$params = sly_Util_Setup::checkDirectories($params);
		$params = sly_Util_Setup::checkHttpAccess($params);

		if ($params['errors']) {
			$this->syscheckView($params);
			return false;
		}

		return true;
	}

	public function checkPermission($action) {
		return true;
	}

	public function indexAction()	{
		// Just load defaults and this should be the only time to do so.
		// Beware that when restarting the setup, the configuration is already present.
		$config = $this->getContainer()->getConfig();

		if (!$config->has('DEFAULT_LOCALE')) {
			$config->loadProjectDefaults(SLY_COREFOLDER.'/config/sallyProjectDefaults.yml');
			$config->loadLocalDefaults(SLY_COREFOLDER.'/config/sallyLocalDefaults.yml');

			// create system ID
			$systemID = sha1(sly_Util_Password::getRandomData(40));
			$systemID = substr($systemID, 0, 20);

			$config->setLocal('INSTNAME', 'sly'.$systemID);
		}

		if ($this->init()) {
			$this->configView();
		}
	}

	public function saveconfigAction() {
		if (!$this->init()) return;

		$request = $this->getRequest();

		// allow only POST requests
		if (!$request->isMethod('POST')) {
			return $this->redirectResponse();
		}

		// save new config values
		$container = $this->getContainer();
		$config    = $container->getConfig();
		$session   = $container->getSession();

		// check for accepted license
		if (!$request->post('license', 'bool', false)) {
			$this->flash->appendWarning(t('must_accept_license'));
			$session->set('license', false);

			return $this->configView();
		}

		// retrieve general config
		$projectName = $request->post('projectname', 'string', '');
		$timezone    = $request->post('timezone', 'string', 'UTC');

		// retrieve database config
		$driver   = $request->post('driver', 'string', 'mysql');
		$host     = $request->post('host', 'string', 'localhost');
		$login    = $request->post('username', 'string', '');
		$password = $request->post('password', 'string', '');
		$name     = $request->post('database', 'string', '');
		$prefix   = $request->post('prefix', 'string', 'sly_');
		$create   = $request->post('create', 'bool') && ($driver !== 'sqlite' && $driver !== 'oci');
		$dbConfig = array(
			'DRIVER'       => $driver,
			'HOST'         => $host,
			'LOGIN'        => $login,
			'PASSWORD'     => $password,
			'NAME'         => $name,
			'TABLE_PREFIX' => $prefix
		);

		// update configuration
		$config->set('PROJECTNAME', $projectName);
		$config->set('TIMEZONE', $timezone);
		$config->set('DEFAULT_LOCALE', $session->get('locale', 'string', sly_Core::getDefaultLocale()));
		$config->setLocal('DATABASE', $dbConfig);

		// remember the accepted license
		$session->set('license', true);

		// check connection and either forward to the next page or show the config form again
		$valid = sly_Util_Setup::checkDatabaseConnection($dbConfig, $create);

		return $valid ? $this->redirectResponse(array(), 'initdb') : $this->configView();
	}

	public function initdbAction() {
		if (!$this->init()) return;

		$container = $this->getContainer();
		$config    = $container->getConfig();
		$session   = $container->getSession();

		// if there is no valid db config, go back to the config page
		if (!sly_Util_Setup::checkDatabaseConnection($config->get('DATABASE'), false)) {
			return $this->redirectResponse();
		}

		$request        = $this->getRequest();
		$dbInitFunction = $request->post('db_init_function', 'string', '');

		// do not just check for POST, since we may have been forwarded from the previous action
		if ($dbInitFunction) {
			$config  = sly_Core::config();
			$prefix  = $config->get('DATABASE/TABLE_PREFIX');
			$driver  = $config->get('DATABASE/DRIVER');
			$success = true;

			// benötigte Tabellen prüfen

			$requiredTables = array(
				$prefix.'article',
				$prefix.'article_slice',
				$prefix.'clang',
				$prefix.'file',
				$prefix.'file_category',
				$prefix.'user',
				$prefix.'slice',
				$prefix.'registry'
			);

			switch ($dbInitFunction) {
				case 'drop': // delete old database
					$db = sly_DB_Persistence::getInstance();

					// 'DROP TABLE IF EXISTS' is MySQL-only...
					foreach ($db->listTables() as $tblname) {
						if (in_array($tblname, $requiredTables)) $db->query('DROP TABLE '.$tblname);
					}

					// fallthrough

				case 'setup': // setup empty database with fresh tables
					$script  = SLY_COREFOLDER.'/install/'.strtolower($driver).'.sql';
					$success = $this->setupImport($script);

					break;

				case 'nop': // do nothing
				default:
			}

			// Wenn kein Fehler aufgetreten ist, aber auch etwas geändert wurde, prüfen
			// wir, ob dadurch alle benötigten Tabellen erzeugt wurden.

			if ($success) {
				$existingTables = array();
				$db             = sly_DB_Persistence::getInstance();

				foreach ($db->listTables() as $tblname) {
					if (substr($tblname, 0, strlen($prefix)) === $prefix) {
						$existingTables[] = $tblname;
					}
				}

				foreach (array_diff($requiredTables, $existingTables) as $missingTable) {
					$this->flash->appendWarning(t('setup_initdb_table_not_found', $missingTable));
					$success = false;
				}
			}

			if ($success) {
				return $this->configAction();
			}

			$this->flash->appendWarning(t('setup_initdb_reinit'));
		}

		$this->render('setup/initdb.phtml', array(
			'dbInitFunction'  => $dbInitFunction,
			'dbInitFunctions' => array('setup', 'nop', 'drop')
		), false);
	}

	public function createuserAction($redirected = false) {
		$this->init();

		$config      = sly_Core::config();
		$request     = $this->getRequest();
		$prefix      = $config->get('DATABASE/TABLE_PREFIX');
		$pdo         = sly_DB_Persistence::getInstance();
		$usersExist  = $pdo->listTables($prefix.'user') && $pdo->magicFetch('user', 'id') !== false;
		$createAdmin = !$request->post('no_admin', 'boolean', false);
		$adminUser   = $request->post('admin_user', 'string');
		$adminPass   = $request->post('admin_pass', 'string');
		$success     = true;

		if ($request->isMethod('POST') && !$redirected) {
			if ($createAdmin) {
				if (empty($adminUser)) {
					$this->flash->appendWarning(t('setup_createuser_no_admin_given'));
					$success = false;
				}

				if (empty($adminPass)) {
					$this->flash->appendWarning(t('setup_createuser_no_password_given'));
					$success = false;
				}

				if ($success) {
					$service = sly_Service_Factory::getUserService();
					$user    = $service->find(array('login' => $adminUser));
					$user    = empty($user) ? new sly_Model_User() : reset($user);

					$user->setName(ucfirst(strtolower($adminUser)));
					$user->setLogin($adminUser);
					$user->setRights('#admin[]#');
					$user->setStatus(true);
					$user->setCreateDate(time());
					$user->setUpdateDate(time());
					$user->setLastTryDate(0);
					$user->setCreateUser('setup');
					$user->setUpdateUser('setup');
					$user->setPassword($adminPass);
					$user->setRevision(0);

					try {
						$service->save($user, $user);
					}
					catch (Exception $e) {
						$this->flash->appendWarning(t('setup_createuser_cant_create_admin'));
						$this->flash->appendWarning($e->getMessage());
						$success = false;
					}
				}
			}
			elseif (!$usersExist) {
				$this->flash->appendWarning(t('setup_createuser_no_users_found'));
				$success = false;
			}

			if ($success) {
				return $this->finishAction();
			}
		}

		$this->render('setup/createuser.phtml', array(
			'usersExist' => $usersExist,
			'adminUser'  => $adminUser
		), false);
	}

	public function finishAction() {
		$this->init();
		sly_Core::config()->setLocal('SETUP', false);
		$this->render('setup/finish.phtml', array(), false);
	}

	protected function configView() {
		$container = $this->getContainer();
		$config    = $container->getConfig();
		$session   = $container->getSession();
		$database  = $config->get('DATABASE');
		$params    = array(
			'projectName' => $config->get('PROJECTNAME'),
			'timezone'    => $config->get('TIMEZONE'),
			'errors'      => false,
			'license'     => $session->get('license', 'bool', false),
			'database'    => array(
				'driver'   => $database['DRIVER'],
				'host'     => $database['HOST'],
				'username' => $database['LOGIN'],
				'password' => $database['PASSWORD'],
				'name'     => $database['NAME'],
				'prefix'   => $database['TABLE_PREFIX'],
				'drivers'  => sly_DB_PDO_Driver::getAvailable()
			)
		);

		$params = sly_Util_Setup::checkSystem($params);
		$this->render('setup/index.phtml', $params, false);
	}

	protected function syscheckView(array $viewParams) {
		$this->render('setup/syscheck.phtml', $viewParams, false);
	}
}
