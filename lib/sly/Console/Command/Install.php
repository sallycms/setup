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

		// load our language file
		$container->getI18N()->appendFile(SLY_SALLYFOLDER.'/setup/lang');

		// check system config and stop with an error page if any serious problems arise
		$params = array('errors' => false);
		$params = sly_Util_Setup::checkSystem($params);
		$params = sly_Util_Setup::checkPdoDrivers($params);
		$params = sly_Util_Setup::checkDirectories($params);
		$params = sly_Util_Setup::checkHttpAccess($params);

		$output->writeln('System Check');
		$output->writeln('------------');
		$output->writeln('');

		//////////////////////////////////////////////////////////////////////////
		// explain PHP config

		$results = $params['results'];
		$exts    = $params['exts'];

		sort($exts);
		$extensions = array();

		foreach ($exts as $ext) {
			$extensions[] = $this->renderResult($results['ext_'.$ext], $ext);
		}

		$output->writeln('  PHP');
		$output->writeln('    version         : '.$this->renderResult($results['version']));
		$output->writeln('    memory limit    : '.$this->renderResult($results['mem_limit']));
		$output->writeln('    register_globals: '.$this->renderResult($results['register_globals']));
		$output->writeln('    magic_quotes    : '.$this->renderResult($results['magic_quotes']));
		$output->writeln('    safe_mode       : '.$this->renderResult($results['safe_mode']));
		$output->writeln('    open_basedir    : '.$this->renderResult($results['open_basedir']));
		$output->writeln('    extensions      : '.implode(', ', $extensions));

		//////////////////////////////////////////////////////////////////////////
		// show PDO driver status

		if (!empty($params['pdoDrivers'])) {
			$output->writeln('');
			$output->writeln('    <error>No PDO drivers found!</error>');
			$output->writeln('    '.strip_tags(t('pdo_driver_help')));
		}
		else {
			$output->writeln('    PDO drivers     : <info>'.implode('</info>, <info>', $params['availPdoDrivers']).'</info>');
		}

		//////////////////////////////////////////////////////////////////////////
		// show directory status

		$output->writeln('');
		$output->writeln('  Directories');

		if (!empty($params['directories'])) {
			$output->writeln('');
			$output->writeln('    <error>Not all required directories could be created!</error>');

			foreach ($params['directories'] as $dir) {
				$output->writeln('     - '.$dir);
			}
		}
		else {
			$output->writeln('    <info>all directories are okay</info>');
		}

		//////////////////////////////////////////////////////////////////////////
		// show HTTP access protection status

		$output->writeln('');
		$output->writeln('  HTTP access protection');

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
			$output->writeln('    <info>all directories are protected</info>');
		}

		$output->writeln('');

		if ($params['errors']) {
			return 1;
		}
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
