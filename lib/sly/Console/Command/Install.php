<?php
/*
 * Copyright (c) 2013, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use sly\Console\Command\Base;

class sly_Console_Command_Install extends Base {
	protected $availableDbOptions = null;
	protected $userExists         = null;
	protected $databaseConfig     = null;

	protected function configure() {
		$this
			->setName('sly:install')
			->setDescription('Perform initial system installation')
			->setDefinition(array(
				new InputArgument('db-name', InputArgument::REQUIRED, 'The name of the database to use'),
				new InputArgument('db-user', InputArgument::REQUIRED, 'The database username'),
				new InputArgument('db-pass', InputArgument::REQUIRED, 'The database password'),
				new InputArgument('password', InputArgument::OPTIONAL, 'The password for the new admin account'),
				new InputArgument('username', InputArgument::OPTIONAL, 'The username for the new admin account'),
				new InputOption('timezone', null, InputOption::VALUE_REQUIRED, 'The project timezone', 'UTC'),
				new InputOption('name', null, InputOption::VALUE_REQUIRED, 'The project name', 'SallyCMS-Projekt'),
				new InputOption('db-host', null, InputOption::VALUE_REQUIRED, 'The database host', 'localhost'),
				new InputOption('db-driver', null, InputOption::VALUE_REQUIRED, 'The database driver to use', 'mysql'),
				new InputOption('db-prefix', null, InputOption::VALUE_REQUIRED, 'The database table prefix', 'sly_'),
				new InputOption('db-init', null, InputOption::VALUE_REQUIRED, 'The database init action to perform (drop, setup or nop)', 'drop'),
				new InputOption('create-db', null, InputOption::VALUE_NONE, 'To create the database if it does not yet exist'),
				new InputOption('no-user', null, InputOption::VALUE_NONE, 'To not create/update the admin account (only in conjunction with setup or nop installations)'),
			));
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$container = $this->getContainer();

		// load our language file
		$container->getI18N()->appendFile(SLY_SALLYFOLDER.'/setup/lang');

		// make sure there is no attempt to build a real cache
		$container['sly-cache'] = new BabelCache_Blackhole();

		$output->writeln(array(
			'System Check',
			'------------',
			''
		));

		// check overall system status
		$healthy = $this->systemCheck($input, $output, $container);
		if (!$healthy) return 1;

		// check database connection
		$healthy = $this->checkDatabase($input, $output, $container);
		if (!$healthy) return 1;

		// system is healthy, now we can do our actual work

		$output->writeln(array(
			'',
			'  <info>All systems are ready for take-off.</info>',
			'',
			'Installation',
			'------------',
			''
		));

		// write project configuration
		$healthy = $this->writeConfig($input, $output, $container);
		if (!$healthy) return 1;

		// perform database setup
		$healthy = $this->setupDatabase($input, $output, $container);
		if (!$healthy) return 1;

		// create/update the admin user
		$healthy = $this->setupAdminAccount($input, $output, $container);
		if (!$healthy) return 1;

		// finish up
		$config = $container->getConfig();
		$config->set('setup', false)->store();

		$output->writeln(array(
			'',
			'Success',
			'-------',
			'',
			'  <info>The installation bas been completed successfully!</info>',
			'',
			'  You can now log-in into the backend and start managing your',
			'  project, change settings and create your content.',
			'',
			'  <info>Have fun!</info>'
		));

		return 0;
	}

	protected function systemCheck(InputInterface $input, OutputInterface $output, sly_Container $container) {
		// check system config and stop with an error page if any serious problems arise
		$params = array('errors' => false);
		$params = sly_Util_Setup::checkSystem($params);
		$params = sly_Util_Setup::checkPdoDrivers($params);
		$params = sly_Util_Setup::checkDirectories($params);
		$params = sly_Util_Setup::checkHttpAccess($params);

		//////////////////////////////////////////////////////////////////////////
		// explain PHP config

		$results = $params['results'];
		$exts    = $params['exts'];

		sort($exts);
		$extensions = array();

		foreach ($exts as $ext) {
			$extensions[] = $this->renderResult($results['ext_'.$ext], $ext);
		}

		$output->writeln(array(
			'  <comment>PHP</comment>',
			'    version         : '.$this->renderResult($results['version']),
			'    memory limit    : '.$this->renderResult($results['mem_limit']),
			'    register_globals: '.$this->renderResult($results['register_globals']),
			'    magic_quotes    : '.$this->renderResult($results['magic_quotes']),
			'    safe_mode       : '.$this->renderResult($results['safe_mode']),
			'    open_basedir    : '.$this->renderResult($results['open_basedir']),
			'    extensions      : '.implode(', ', $extensions)
		));

		//////////////////////////////////////////////////////////////////////////
		// show PDO driver status

		if (!empty($params['pdoDrivers'])) {
			$output->writeln(array(
				'',
				'    <error>No PDO drivers found!</error>',
				'    '.strip_tags(t('pdo_driver_help'))
			));
		}
		else {
			$output->writeln('    PDO drivers     : <info>'.implode('</info>, <info>', $params['availPdoDrivers']).'</info>');
		}

		//////////////////////////////////////////////////////////////////////////
		// show directory status

		$output->writeln('');
		$output->writeln('  <comment>Directories</comment>');

		if (!empty($params['directories'])) {
			$output->writeln('');
			$output->writeln('    <error>Not all required directories could be created!</error>');

			foreach ($params['directories'] as $dir) {
				$output->writeln('     - '.$dir);
			}
		}
		else {
			$output->writeln('    <info>All required directories exist.</info>');
		}

		//////////////////////////////////////////////////////////////////////////
		// show HTTP access protection status

		$output->writeln('');
		$output->writeln('  <comment>HTTP access protection</comment>');

		if (!empty($params['httpAccess'])) {
			$output->writeln('');
			$output->writeln('    <error>Not all required directories could be protected against HTTP access!</error>');

			foreach ($params['httpAccess'] as $dir) {
				$output->writeln('     - '.$dir);
			}

			$output->writeln('    Make sure to put an .htaccess file in the directories named above,');
			$output->writeln('    otherwise private data is accessible for anyone on the web.');
		}
		else {
			$output->writeln('    <info>All private directories seem properly protected.</info>');
		}

		$output->writeln('');

		return !$params['errors'];
	}

	protected function checkDatabase(InputInterface $input, OutputInterface $output, sly_Container $container) {
		$database = $input->getArgument('db-name');
		$username = $input->getArgument('db-user');
		$password = $input->getArgument('db-pass');
		$host     = $input->getOption('db-host') ?: 'localhost';
		$prefix   = $input->getOption('db-prefix') ?: 'sly_';
		$create   = $input->getOption('create-db');
		$driver   = strtolower($input->getOption('db-driver') ?: 'mysql');
		$config   = array(
			'dbname'       => $database,
			'user'         => $username,
			'password'     => $password,
			'host'         => $host,
			'driver'       => $driver,
			'table_prefix' => $prefix
		);

		$output->writeln('  <comment>Database</comment>');

		//////////////////////////////////////////////////////////////////////////
		// check connection

		try {
			if (!class_exists('sly_DB_PDO_Driver_'.strtoupper($driver))) {
				throw new sly_DB_PDO_Exception('Unknown Database Driver: '.$driver);
			}

			$driverClass = 'sly_DB_PDO_Driver_'.strtoupper($driver);
			$driver      = new $driverClass($host, $username, $password, $database);
		}
		catch (Exception $e) {
			$output->writeln('    <error>'.$e->getMessage().'</error>');
			return false;
		}

		$output->write(sprintf('    Connecting via %s...', $driver->getDSN()));

		try {
			$persistence = sly_Util_Setup::checkDatabaseConnection($config, $create, false, true);
			$output->writeln(' <info>success</info>.');

			// make sure everyone uses this custom persistence from now on
			$container['sly-persistence'] = $persistence;

			// remember the configuration for later, when we want to write it
			$this->databaseConfig = $config;
		}
		catch (Exception $e) {
			$output->writeln(' <error>failure!</error>');
			$output->writeln('    <error>'.$e->getMessage().'</error>');

			return false;
		}

		//////////////////////////////////////////////////////////////////////////
		// check for required tables

		$output->write('    Checking system tables...');

		$db     = $container->getPersistence();
		$params = sly_Util_Setup::checkDatabaseTables(array(), $prefix, $db);
		$actions = $params['actions'];

		// to make the CL interface more stable, we always offer the 'drop' option,
		// even if no tables exist
		$actions[] = 'drop';
		$actions   = array_unique($actions);

		sort($actions);

		// remember this for later
		$this->availableDbOptions = $actions;

		$output->writeln(' <info>success</info>, available options are '.implode(', ', $actions).'.');

		//////////////////////////////////////////////////////////////////////////
		// check for at least one admin account

		$output->write('    Checking for root account...');

		$params           = sly_Util_Setup::checkUser(array(), $prefix, $db);
		$this->userExists = $params['userExists'];

		$output->writeln($this->userExists ? ' <info>success</info>.' : ' <comment>none found</comment>.');

		//////////////////////////////////////////////////////////////////////////
		// check whether --no-user and username/password arguments match the
		// current situation

		$dbInitAction = $input->getOption('db-init');
		$username     = $input->getArgument('username');
		$password     = $input->getArgument('password');

		if (!in_array($dbInitAction, array('nop', 'drop', 'setup'))) {
			$output->writeln('    <error>Invalid value for --db-init given.</error>');
			return false;
		}

		if (!$this->userExists) {
			if ($input->getOption('no-user')) {
				$output->writeln('    <error>No account was found, so you must not use the --no-user option.</error>');
				return false;
			}

			if (empty($username) || empty($password)) {
				$output->writeln('    <error>You must define the username and password for the first account as CLI arguments.</error>');
				return false;
			}
		}
		elseif ($dbInitAction === 'drop') {
			if ($input->getOption('no-user')) {
				$output->writeln('    <error>You must not use the --no-user option in conjunction with --db-init=drop.</error>');
				return false;
			}

			if (empty($username) || empty($password)) {
				$output->writeln('    <error>You must define the username and password for the first account as CLI arguments.</error>');
				return false;
			}
		}

		return true;
	}

	protected function writeConfig(InputInterface $input, OutputInterface $output, sly_Container $container) {
		$output->write('  Writing project configuration...');

		$timezone    = $input->getOption('timezone') ?: 'UTC';
		$projectName = $input->getOption('name') ?: 'SallyCMS-Projekt';
		$config      = $container->getConfig();

		$localWriter = $container['sly-config-writer'];
		$localWriter->writeLocal(array('database' => $this->databaseConfig));

		$config->setStatic('database', $this->databaseConfig);

		// create system ID
		$systemID = sha1(sly_Util_Password::getRandomData(40));
		$systemID = substr($systemID, 0, 20);

		$config->set('instname', 'sly'.$systemID)
			->set('timezone', $timezone)
			->set('projectname', $projectName)
			->store();


		$output->writeln(' <info>success</info>.');

		return true;
	}

	protected function setupDatabase(InputInterface $input, OutputInterface $output, sly_Container $container) {
		$create  = $input->getOption('create-db');
		$action  = $input->getOption('db-init');
		$options = $this->availableDbOptions;

		if (!in_array($action, $options, true)) {
			$output->writeln('  <error>Cannot perform "'.$action.'" action to initialize the database.</error>');
			$output->writeln('  When in doubt, use "drop" to drop all Sally tables (and lose all existing content).');
			return false;
		}

		$config      = $container->getConfig();
		$prefix      = $config->get('database/table_prefix');
		$driver      = $config->get('database/driver');
		$persistence = $container->getPersistence();

		try {
			sly_Util_Setup::setupDatabase($action, $prefix, $driver, $persistence, $output, true);
		}
		catch (Exception $e) {
			$output->writeln(' <error>failure!</error>');
			$output->writeln(' <error>'.$e->getMessage().'</error>');

			return false;
		}

		return true;
	}

	protected function setupAdminAccount(InputInterface $input, OutputInterface $output, sly_Container $container) {
		$noUser = $input->getOption('no-user');

		if (!$noUser) {
			$username = $input->getArgument('username');
			$password = $input->getArgument('password');

			try {
				$service = $container->getUserService();
				sly_Util_Setup::createOrUpdateUser($username, $password, $service, $output);
			}
			catch (Exception $e) {
				$output->writeln(' <error>failure!</error>');
				$output->writeln(' <error>'.$e->getMessage().'</error>');

				return false;
			}
		}

		return true;
	}

	protected function renderResult(array $result, $showRange = true) {
		return str_replace(
			array('<warning>', '</warning>', '<success>', '</success>'),
			array('<comment>', '</comment>', '<info>', '</info>'),
			sly_Util_Setup::getWidget(
				$result, null, $showRange,
				'<{tclass}>{text}</{tclass}>',
				'<{tclass}>{text}</{tclass}> ({tooltip})',
				false
			)
		);
	}
}
