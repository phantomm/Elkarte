<?php

/**
 * Installs the ElkArte db on the travis test server
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0
 *
 */

// Lots of needs
require_once(BOARDDIR . '/sources/database/Db.php');
require_once(BOARDDIR . '/sources/database/Db-abstract.class.php');
require_once(BOARDDIR . '/sources/Errors.php');
require_once(BOARDDIR . '/sources/Subs.php');
require_once(BOARDDIR . '/sources/subs/Cache.class.php');
require_once(BOARDDIR . '/sources/subs/Cache.subs.php');
require_once(BOARDDIR . '/sources/database/Database.subs.php');

/**
 * Used to install ElkArte SQL files to a database scheme
 */
Class Elk_Testing_Setup
{
	protected $_db;
	protected $_queries_parts;
	protected $_clean_queries_parts;
	protected $_queries;
	protected $_dbserver;
	protected $_name;
	protected $_user;
	protected $_passwd;
	protected $_prefix;

	/**
	 * Runs the querys defined in the install files to the db
	 */
	public function run_queries()
	{
		global $modSettings;

		$modSettings['disableQueryCheck'] = true;
		$query = '';
		$db = $this->_db;
		$db->skip_error();
		$db_table = $this->_db_table;

		if (empty($this->_clean_queries_parts))
			$this->_clean_queries_parts = $this->_queries_parts;

		foreach ($this->_clean_queries_parts as $part)
		{
			if (substr($part, -1) == ';')
			{
				$result = eval('return ' . $query . $part);
				if ($result === false)
					echo 'Query failed: ' . "\n" . $query . "\n" . substr($part, 0, -1) . "\n";

				$query = '';
			}
			else
				$query .= "\n" . $part;
		}
	}

	/**
	 * Loads the querys from the supplied sql install file
	 *
	 * @param string $file
	 */
	public function load_queries($file)
	{
		$this->_queries = str_replace('{$db_prefix}', $this->_db_prefix, file_get_contents($file));
		$this->_queries_parts = explode("\n", $this->_queries);
		$this->_fix_query_string();
	}

	/**
	 * Clear the DB for a new install
	 *
	 * @param string $file
	 */
	public function clear_tables()
	{
		// Get all the tables.
		$tables = $this->_db->db_list_tables($this->_db_name, $this->_db_prefix . '%');

		// Bu-bye
		foreach ($tables as $table)
			$this->_db_table->db_drop_table($table);
	}

	/**
	 * Does the variable replacements in the query strings {X} => data
	 */
	private function _fix_query_string()
	{
		foreach ($this->_queries_parts as $line)
		{
			if (!empty($line[0]) && $line[0] != '#')
			{
				$this->_clean_queries_parts[] = str_replace(
					array(
						'{$current_time}', '{$sched_task_offset}',
						'{BOARDDIR}', '{$boardurl}'
					),
					array(
						time(), '1',
						BOARDDIR, $this->_boardurl
					),
					$line
				);
			}
		}
	}

	/**
	 * Updates the settings.php file to work with a given install / db scheme
	 */
	public function prepare_settings()
	{
		$file = file_get_contents(BOARDDIR . '/Settings.php');

		$file = str_replace(array(
			'$boardurl = \'http://127.0.0.1/elkarte\';',
			'$db_type = \'mysql\';',
			'$db_name = \'elkarte\';',
			'$db_user = \'root\';',
			'$db_prefix = \'elkarte_\';',
			'$db_passwd = \'\';',
		),
		array(
			'$boardurl = \'' . $this->_boardurl . '\';',
			'$db_type = \'' . $this->_db_type . '\';',
			'$db_name = \'' . $this->_db_name . '\';',
			'$db_user = \'' . $this->_db_user . '\';',
			'$db_prefix = \'' . $this->_db_prefix . '\';',
			'$db_passwd = \'' . $this->_db_passwd . '\';',
		),
		$file);

		if (strpos($file, 'if (file_exist') !== false)
			$file = substr($file, 0, strpos($file, 'if (file_exist'));

		file_put_contents(BOARDDIR . '/Settings.php', $file);
	}

	/**
	 * Called after db is setup, calls functions to prepare for testing
	 */
	public function prepare()
	{
		$this->prepare_settings();
		$this->update();
		//$this->createTests();
	}

	/**
	 * Adds a user, sets time, prepars the forum for phpunit tests
	 */
	public function update()
	{
		global $settings, $db_type;
		global $time_start, $maintenance, $msubject, $mmessage, $mbname, $language;
		global $boardurl, $webmaster_email, $cookiename;
		global $db_server, $db_name, $db_user, $db_prefix, $db_persist, $db_error_send;
		global $modSettings, $context, $sc, $user_info, $topic, $board, $txt;
		global $ssi_db_user, $scripturl, $ssi_db_passwd, $db_passwd;
		global $sourcedir, $boarddir;

		define('SUBSDIR', BOARDDIR . '/sources/subs');
		define('EXTDIR', BOARDDIR . '/sources/ext');

		require_once(BOARDDIR . '/Settings.php');
		require_once(BOARDDIR . '/sources/Subs.php');
		require_once(BOARDDIR . '/sources/Load.php');
		require_once(SUBSDIR . '/Util.class.php');
		require_once(SUBSDIR . '/Auth.subs.php');

		spl_autoload_register('elk_autoloader');

		$settings['theme_dir'] = $settings['default_theme_dir'] = BOARDDIR . '/Themes/default';
		$settings['theme_url'] = $settings['default_theme_url'] = $boardurl . '/themes/default';

		// Create an admin member
		$db = database();

		// Get a security hash for this combination
		$password = stripslashes('test_admin_pwd');
		$passwd = validateLoginPassword($password, '', 'test_admin', true);

		$db->insert('', '
			{db_prefix}members',
			array(
				'member_name' => 'string-25', 'real_name' => 'string-25', 'passwd' => 'string', 'email_address' => 'string',
				'id_group' => 'int', 'posts' => 'int', 'date_registered' => 'int', 'hide_email' => 'int',
				'password_salt' => 'string', 'lngfile' => 'string', 'personal_text' => 'string', 'avatar' => 'string',
				'member_ip' => 'string', 'member_ip2' => 'string', 'buddy_list' => 'string', 'pm_ignore_list' => 'string',
				'message_labels' => 'string', 'website_title' => 'string', 'website_url' => 'string', 'location' => 'string',
				'signature' => 'string', 'usertitle' => 'string', 'secret_question' => 'string',
				'additional_groups' => 'string', 'ignore_boards' => 'string', 'openid_uri' => 'string',
			),
			array(
				'test_admin', 'test_admin', $passwd, 'email@testadmin.tld',
				1, 0, time(), 0,
				substr(md5(mt_rand()), 0, 4), '', '', '',
				'123.123.123.123', '123.123.123.123', '', '',
				'', '', '', '',
				'', '', '',
				'', '', '',
			),
			array('id_member')
		);

		$server_offset = @mktime(0, 0, 0, 1, 1, 1970);
		$timezone_id = 'Etc/GMT' . ($server_offset > 0 ? '+' : '') . ($server_offset / 3600);

		if (date_default_timezone_set($timezone_id))
		{
			$db->insert('',
				$db_prefix . 'settings',
				array(
					'variable' => 'string-255', 'value' => 'string-65534',
				),
				array(
					'default_timezone', $timezone_id,
				),
				array('variable')
			);
		}

		require_once(SUBSDIR . '/Members.subs.php');
		updateMemberStats();

		require_once(SUBSDIR . '/Messages.subs.php');
		updateMessageStats();

		require_once(SUBSDIR . '/Topic.subs.php');
		updateTopicStats();

		loadLanguage('Install');
		updateSubjectStats(1, htmlspecialchars($txt['default_topic_subject']));
	}

	public function createTests()
	{
		// Sometimes it is necessary to exclude some tests...
		$excluded = array(
			// At the moment we setup the testing environment with an already
			// installed forum, so test the installation would fail
			'TestInstall.php'
		);

		// Get all the files
		$allTests = $this->_testsInDir(BOARDDIR . '/tests/sources/*');
		$allTests = array_merge($allTests, $this->_testsInDir(BOARDDIR . '/tests/install/*'));

		// For each file create a test case
		foreach ($allTests as $key => $test)
		{
			$test = realpath($test);

			// Skip the excluded tests
			if (in_array(basename($test), $excluded))
				continue;

			file_put_contents(BOARDDIR . '/tests/run_' . md5($test) . '.php', '<?php

$testName = \'' . $test . '\';

define(\'TESTDIR\', dirname(__FILE__) . \'/\');
require_once(\'simpletest/autorun.php\');
require_once(TESTDIR . \'../Settings.php\');

class Test_' . $key . ' extends TestSuite
{
	function Test_' . $key . '()
	{
		$this->TestSuite(\'Test ' . $key . '\');

		$this->addFile(\'' . $test . '\');
	}
}');
		}
	}

	private function _testsInDir($dir)
	{
		$entities = glob($dir);
		$files = array();
		foreach ($entities as $entity)
		{
			if (is_dir($entity))
				$files = array_merge($files, $this->_testsInDir($entity . '/*'));
			else
				$files[] = $entity;
		}
		return $files;
	}
}