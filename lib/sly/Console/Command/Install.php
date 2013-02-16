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
				new InputOption('no-db-init', null, InputOption::VALUE_NONE, 'To perform no changes to the database.'),
				new InputOption('create-db', null, InputOption::VALUE_NONE, 'To create the database if it does not yet exist.'),
				new InputOption('no-user', null, InputOption::VALUE_NONE, 'To not create/update the admin account.'),
			));
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$container = $this->getContainer();

		// check system config and stop with an error page if any serious problems arise
		$params = array('errors' => false);
		$params = sly_Util_Setup::checkSystem($params);
		$params = sly_Util_Setup::checkPdoDrivers($params);
		$params = sly_Util_Setup::checkDirectories($params);
		$params = sly_Util_Setup::checkHttpAccess($params);

		if ($params['errors']) {
			$this->syscheckView($params);
			return 1;
		}
	}
}
