<?php

class DatabaseSetupException extends \OC\HintException
{
}

class OC_Setup {
	static $dbSetupClasses = array(
		'mysql' => '\OC\Setup\MySQL',
		'pgsql' => '\OC\Setup\PostgreSQL',
		'oci'   => '\OC\Setup\OCI',
		'mssql' => '\OC\Setup\MSSQL',
		'sqlite' => '\OC\Setup\Sqlite',
		'sqlite3' => '\OC\Setup\Sqlite',
	);

	public static function getTrans(){
		return \OC::$server->getL10N('lib');
	}

	public static function install($options) {
		$l = self::getTrans();

		$error = array();
		$dbtype = $options['dbtype'];

		if(empty($options['adminlogin'])) {
			$error[] = $l->t('Set an admin username.');
		}
		if(empty($options['adminpass'])) {
			$error[] = $l->t('Set an admin password.');
		}
		if(empty($options['directory'])) {
			$options['directory'] = OC::$SERVERROOT."/data";
		}

		if (!isset(self::$dbSetupClasses[$dbtype])) {
			$dbtype = 'sqlite';
		}

		$username = htmlspecialchars_decode($options['adminlogin']);
		$password = htmlspecialchars_decode($options['adminpass']);
		$datadir = htmlspecialchars_decode($options['directory']);

		$class = self::$dbSetupClasses[$dbtype];
		/** @var \OC\Setup\AbstractDatabase $dbSetup */
		$dbSetup = new $class(self::getTrans(), 'db_structure.xml');
		$error = array_merge($error, $dbSetup->validate($options));

		// validate the data directory
		if (
			(!is_dir($datadir) and !mkdir($datadir)) or
			!is_writable($datadir)
		) {
			$error[] = $l->t("Can't create or write into the data directory %s", array($datadir));
		}

		if(count($error) != 0) {
			return $error;
		}

		//no errors, good
		if(    isset($options['trusted_domains'])
		    && is_array($options['trusted_domains'])) {
			$trustedDomains = $options['trusted_domains'];
		} else {
			$trustedDomains = array(OC_Request::serverHost());
		}

		if (OC_Util::runningOnWindows()) {
			$datadir = rtrim(realpath($datadir), '\\');
		}

		//use sqlite3 when available, otherise sqlite2 will be used.
		if($dbtype=='sqlite' and class_exists('SQLite3')) {
			$dbtype='sqlite3';
		}

		//generate a random salt that is used to salt the local user passwords
		$salt = \OC::$server->getSecureRandom()->getLowStrengthGenerator()->generate(30);
		\OC::$server->getConfig()->setSystemValue('passwordsalt', $salt);

		// generate a secret
		$secret = \OC::$server->getSecureRandom()->getMediumStrengthGenerator()->generate(48);
		\OC::$server->getConfig()->setSystemValue('secret', $secret);

		//write the config file
		\OC::$server->getConfig()->setSystemValue('trusted_domains', $trustedDomains);
		\OC::$server->getConfig()->setSystemValue('datadirectory', $datadir);
		\OC::$server->getConfig()->setSystemValue('overwritewebroot', OC::$WEBROOT);
		\OC::$server->getConfig()->setSystemValue('dbtype', $dbtype);
		\OC::$server->getConfig()->setSystemValue('version', implode('.', OC_Util::getVersion()));

		try {
			$dbSetup->initialize($options);
			$dbSetup->setupDatabase($username);
		} catch (DatabaseSetupException $e) {
			$error[] = array(
				'error' => $e->getMessage(),
				'hint' => $e->getHint()
			);
			return($error);
		} catch (Exception $e) {
			$error[] = array(
				'error' => 'Error while trying to create admin user: ' . $e->getMessage(),
				'hint' => ''
			);
			return($error);
		}

		//create the user and group
		try {
			OC_User::createUser($username, $password);
		}
		catch(Exception $exception) {
			$error[] = $exception->getMessage();
		}

		if(count($error) == 0) {
			$appConfig = \OC::$server->getAppConfig();
			$appConfig->setValue('core', 'installedat', microtime(true));
			$appConfig->setValue('core', 'lastupdatedat', microtime(true));

			OC_Group::createGroup('admin');
			OC_Group::addToGroup($username, 'admin');
			OC_User::login($username, $password);

			//guess what this does
			OC_Installer::installShippedApps();

			// create empty file in data dir, so we can later find
			// out that this is indeed an ownCloud data directory
			file_put_contents(OC_Config::getValue('datadirectory', OC::$SERVERROOT.'/data').'/.ocdata', '');

			// Update htaccess files for apache hosts
			if (isset($_SERVER['SERVER_SOFTWARE']) && strstr($_SERVER['SERVER_SOFTWARE'], 'Apache')) {
				self::updateHtaccess();
				self::protectDataDirectory();
			}

			//and we are done
			OC_Config::setValue('installed', true);
		}

		return $error;
	}

	/**
	 * Append the correct ErrorDocument path for Apache hosts
	 */
	public static function updateHtaccess() {
		$content = "\n";
		$content.= "ErrorDocument 403 ".OC::$WEBROOT."/core/templates/403.php\n";//custom 403 error page
		$content.= "ErrorDocument 404 ".OC::$WEBROOT."/core/templates/404.php";//custom 404 error page
		@file_put_contents(OC::$SERVERROOT.'/.htaccess', $content, FILE_APPEND); //suppress errors in case we don't have permissions for it
	}

	public static function protectDataDirectory() {
		//Require all denied
		$now =  date('Y-m-d H:i:s');
		$content = "# Generated by ownCloud on $now\n";
		$content.= "# line below if for Apache 2.4\n";
		$content.= "<ifModule mod_authz_core>\n";
		$content.= "Require all denied\n";
		$content.= "</ifModule>\n\n";
		$content.= "# line below if for Apache 2.2\n";
		$content.= "<ifModule !mod_authz_core>\n";
		$content.= "deny from all\n";
		$content.= "</ifModule>\n\n";
		$content.= "# section for Apache 2.2 and 2.4\n";
		$content.= "IndexIgnore *\n";
		file_put_contents(OC_Config::getValue('datadirectory', OC::$SERVERROOT.'/data').'/.htaccess', $content);
		file_put_contents(OC_Config::getValue('datadirectory', OC::$SERVERROOT.'/data').'/index.html', '');
	}

	/**
	 * Post installation checks
	 */
	public static function postSetupCheck($params) {
		// setup was successful -> webdav testing now
		$l = self::getTrans();
		if (OC_Util::isWebDAVWorking()) {
			header("Location: ".OC::$WEBROOT.'/');
		} else {

			$error = $l->t('Your web server is not yet properly setup to allow files synchronization because the WebDAV interface seems to be broken.');
			$hint = $l->t('Please double check the <a href=\'%s\'>installation guides</a>.',
				\OC_Helper::linkToDocs('admin-install'));

			OC_Template::printErrorPage($error, $hint);
			exit();
		}
	}
}
