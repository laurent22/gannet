<?php

define('BASE_DIR', dirname(__FILE__));

error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('EST');

function Gannet_log($s) {
	echo '[Migration] ' . $s . "\n";
}

function Gannet_logError($s) {
	Gannet_log('[ERR] ' . $s); 
}

function Gannet_logWarn($s) {
	Gannet_log('[WARN] ' . $s); 
}

function Gannet_findFile($file) {
	if (file_exists($file)) return realpath($file);
	
	$output = null;
	if (strpos($file, '.') === 0) $output = dirname(__FILE__) . '/' . $file;
	if ($output && file_exists($output)) return realpath($output);
	
	$output = getcwd() . '/' . $file;
	if (file_exists($output)) return realpath($output);
	
	if (isset($_SERVER['PWD'])) {
		$output = $_SERVER['PWD'] . '/' . $file;
		if (file_exists($output)) return realpath($output);
	}
	
	return false;
}

class Gannet_Config {

	private $data_ = array();

	public function __construct($data) {
		// Set some default values:
		if (!array_key_exists('migration_table_name', $data)) $data['migration_table_name'] = 'dbmigrations';
		if (!array_key_exists('stop_on_error', $data)) $data['stop_on_error'] = true;
		$this->data_ = $data;
	}
	
	public function toPdoConnectionString($includeDatabaseName = true) {
		$s = '';
		foreach ($this->data_['connection'] as $k => $v) {
			if ($v === false) continue;
			if ($k == 'username') continue;
			if ($k == 'password') continue;
			if ($k == 'database' && $includeDatabaseName) $k = 'dbname';
			if ($k == 'hostname') $k = 'host';
			if ($s != '') $s .= ';';
			$s .= $k . '=' . $v;
		}
		if (isset($this->data_['charset'])) $s .= ';charset=' . $this->data_['charset'];
		$s = strtolower($this->data_['type']) . ':' . $s;
		return $s;
	}
	
	public function get($name) {
		$items = explode('.', $name);
		$v = $this->data_;
		while (count($items)) {
			$n = $items[0];
			unset($items[0]);
			$items = array_values($items);
			if (!array_key_exists($n, $v)) return null;
			$v = $v[$n];
		}
		return $v;
	}
	
}

class Gannet {
	
	private $db_ = null;
	private $config_ = null;
	
	public function __construct($config) {
		$this->config_ = $config;
	}
	
	public function onError() {
		if (!$this->config_->get('stop_on_error')) return;
		Gannet_log('Stopping migration because of an error ("stop_on_error" config parameter).');
		die();
	}
	
	public function version_compare($a, $b) {
		while (substr_count($a, '.') < 3) $a .= '.0';
		while (substr_count($b, '.') < 3) $b .= '.0';
		return version_compare($a, $b);
	}
	
	private function sort_versionFiles($a, $b) {
		return $this->version_compare($a['version'], $b['version']);
	}
	
	public function metaFiles($scriptFolder) {
		$output = array();
		foreach (glob($scriptFolder . "*.*") as $filePath) {
			$p = pathinfo($filePath);
			$n = $p['filename'];
			if ($n != 'after' && $n != 'before') continue;
			$ext = $p['extension'];
			if (isset($output[$n])) {
				Gannet_logWarn('Skipping duplicate meta file: ' . $filePath);
				continue;
			}		
			$output[$n] = array(
				'path' => $filePath,
				'type' => $ext
			);
		}
		return $output;
	}

	public function versionFiles($scriptFolder) {
		$temp = array();
		foreach (glob($scriptFolder . "*.*") as $filePath) {
			$p = pathinfo($filePath);
			$v = $p['filename'];
			if ($v == 'after' || $v == 'before') continue;
			$ext = $p['extension'];
			if (isset($temp[$v])) {
				Gannet_logWarn('Skipping duplicate version file: ' . $filePath);
				continue;
			}
			$temp[$v] = array(
				'path' => $filePath,
				'type' => $ext
			);
		}
		$output = array();
		foreach ($temp as $v => $d) {
			$d['version'] = $v;
			$output[] = $d;
		}
		usort($output, array($this, 'sort_versionFiles'));
		return $output;
	}
	
	public function dbConnect() {
		Gannet_log('Connecting to database...');
		
		$triedToCreateDb = false;
		while (true) {
			try {
				$this->db_ = new PDO(
					$this->config_->toPdoConnectionString(),
					$this->config_->get('connection.username'),
					$this->config_->get('connection.password'),
					array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
				);
				Gannet_log('Connection successful.');
				break;
			} catch (Exception $e) {
				// TODO: check if this is a generic error code or specific to MySQL
				if ($e->getCode() == 1049 && !$triedToCreateDb) {
					$triedToCreateDb = true;
					Gannet_log('Database does not exist - creating it.');
					$this->db_ = new PDO(
						$this->config_->toPdoConnectionString(false),
						$this->config_->get('connection.username'),
						$this->config_->get('connection.password'),
						array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
					);
					$this->db_->query('CREATE DATABASE ' . $this->config_->get('connection.database'));
				} else {
					Gannet_logError('Could not connect to database: ' . $e->getMessage());
					$this->onError();
					break;
				}
			}
		}
	}
	
	public function dbCheckMigrationTable() {
		$tableName = $this->config_->get('migration_table_name');
		
		$triedToCreateTable = false;
		while (true) {
			Gannet_log('Checking if "' . $tableName . '" table exists...');
			try {
				$this->db_->query('SELECT 1 FROM ' . $tableName . ' LIMIT 1');
				break;
			} catch (Exception $e) {
				if (!$triedToCreateTable) {
					// TODO: migration_table.sql has the table name hard-coded so won't make use of config value 'migration_table_name'
					$triedToCreateTable = true;
					Gannet_log('Migration table does not exist. Trying to create it...');
					$this->runCommand('sql', BASE_DIR . DIRECTORY_SEPARATOR . 'config/migration_table.sql');
				} else {
					Gannet_logError('Table ' . $tableName . ' does not exist or is not readable: ' . $e->getMessage());
					Gannet_logError('You can create it using "config/migration_table.sql".');
					$this->onError();
					break;
				}
			}
		}
	}
	
	public function dbCurrentVersion() {
		$tableName = $this->config_->get('migration_table_name');
		
		$maxId = $this->db_->query("SELECT max(id) FROM " . $tableName);
		if (!$maxId->rowCount()) return '0';
		$maxId = $maxId->fetch(PDO::FETCH_ASSOC);
		$maxId = $maxId['max(id)'];
		$s = $this->db_->query("SELECT * FROM " . $tableName . ' where id="' . $maxId . '"');
		$output = '0';
		if ($s->rowCount()) {
			$output = $s->fetch(PDO::FETCH_ASSOC);
			$output = $output['version'];
		}
		return $output;
	}
	
	public function dbNextVersion($current, $versions) {
		if (!count($versions)) return null;
		if ($current === null) return $versions[0];
		foreach ($versions as $v) {
			if ($this->version_compare($v['version'], $current) >= 1) {
				return $v;
			}
		}
		return null;
	}
	
	public function dbSaveVersionInfo($version) {
		$tableName = $this->config_->get('migration_table_name');
		$this->db_->query("INSERT INTO " . $tableName . ' (version) VALUES("' . $version . '")');
	}
	
	public function runCommand($type, $path) {
		$cmd = $this->config_->get('commands.' . $type . '.command');
		if (!$cmd) throw new Exception('No command defined for type: ' . $type); 
		$cmd = str_replace('{{connection.username}}', $this->config_->get('connection.username'), $cmd);
		$cmd = str_replace('{{connection.password}}', $this->config_->get('connection.password'), $cmd);
		$cmd = str_replace('{{connection.hostname}}', $this->config_->get('connection.hostname'), $cmd);
		$cmd = str_replace('{{connection.database}}', $this->config_->get('connection.database'), $cmd);
		$cmd = str_replace('{{file}}', $path, $cmd);
		Gannet_log('Running command: ' . $cmd);
		putenv("GANNET_CONNECTION_USERNAME=" . $this->config_->get('connection.username'));
		putenv("GANNET_CONNECTION_PASSWORD=" . $this->config_->get('connection.password'));
		putenv("GANNET_CONNECTION_HOSTNAME=" . $this->config_->get('connection.hostname'));
		putenv("GANNET_CONNECTION_DATABASE=" . $this->config_->get('connection.database'));
		exec($cmd, $output, $errorCode);
		$good = $this->config_->get('commands.' . $type . '.success_code');
		$errorCodeOk = $good === null ? true : $errorCode == $good;
		Gannet_log('Error code: ' . $errorCode . ' [' . ($errorCodeOk ? 'OK' : 'ERROR') . ']');
		if (count($output)) {
			echo implode("\n", $output);
			echo "\n";
		}
		return $errorCodeOk;
	}
	
	public function phpErrorHandler($errno, $errstr, $errfile, $errline) {
		Gannet_logError($errno . ': ' . $errstr . ' in ' . $errfile . ':' . $errline);
		$this->onError();
		return true;
	}
	
	public function onShutdown() {
		Gannet_log('Shutting down...');
	}

}

/**
 * Get command line arguments
 */

$argv = array();
if (!isset($_SERVER['argv'])) {
	Gannet_logWarn("Note: command line arguments are not available (`register_argc_argv` option might be off), so default settings  will be used.");
} else {
	$argv = $_SERVER['argv'];
}

if (count($argv) >= 2) {
	$configFilePath = $argv[1];
} else {
	$configFilePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.toml';	
}

$check = Gannet_findFile($configFilePath);
if (!$check || !file_exists($check)) throw new Exception('Could not find config file: ' . $configFilePath);
$configFilePath = $check;

/**
 * Initialize configuration
 */

require_once "Toml.php";
Gannet_log("Using config at " . $configFilePath);
$config = new Gannet_Config(Toml::parseFile($configFilePath));

$timezone = $config->get('timezone');
if (!empty($timezone)) date_default_timezone_set($timezone);

/**
 * Check script folder
 */
 
$scriptFolder = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR;
if ($config->get('script_path')) {
	$scriptFolder = $config->get('script_path');
	if (strpos($scriptFolder, '.') === 0) $scriptFolder = dirname(__FILE__) . DIRECTORY_SEPARATOR . $scriptFolder;
}

if (!strlen($scriptFolder)) throw new Exception('Script folder path is an empty string.');
$scriptFolder = realpath($scriptFolder);
if ($scriptFolder[strlen($scriptFolder) - 1] != DIRECTORY_SEPARATOR) $scriptFolder .= DIRECTORY_SEPARATOR;
if (!is_dir($scriptFolder)) throw new Exception('Script folder does not exist or is not a directory: ' . $scriptFolder);
Gannet_log("Using script folder: " . $scriptFolder);
	
/**
 * Initialize migration object
 */

$gannet = new Gannet($config);
set_error_handler(array($gannet, 'phpErrorHandler'));
register_shutdown_function(array($gannet, 'onShutdown'));
$gannet->dbConnect();
$gannet->dbCheckMigrationTable();
$versions = $gannet->versionFiles($scriptFolder);
$metaFiles = $gannet->metaFiles($scriptFolder);
$currentVersion = $gannet->dbCurrentVersion();

/**
 * Upgrade the database
 */

$isFirstFile = true;
$hasDoneSomething = false;
while (true) {
	$nextVersion = $gannet->dbNextVersion($currentVersion, $versions);

	Gannet_log('Current version: ' . $currentVersion);
	Gannet_log('Next version: ' . ($nextVersion ? $nextVersion['version'] : 'none'));
	if (!$nextVersion) break;
	
	if ($isFirstFile) {
		if (isset($metaFiles['before'])) {
			$f = $metaFiles['before'];
			Gannet_log('Running: ' . $f['path']);
			$ok = $gannet->runCommand($f['type'], $f['path']);
			if (!$ok) $gannet->onError();
		}
		$isFirstFile = false;
	}
	
	Gannet_log('Running: ' . $nextVersion['path']);
	$ok = $gannet->runCommand($nextVersion['type'], $nextVersion['path']);
	if (!$ok) $gannet->onError();
	
	$hasDoneSomething = true;
	$currentVersion = $nextVersion['version'];
	$gannet->dbSaveVersionInfo($currentVersion);
}

/*
 * Run the "after" script, if any
 */

if ($hasDoneSomething) {
	if (isset($metaFiles['after'])) {
		$f = $metaFiles['after'];
		Gannet_log('Running: ' . $f['path']);
		$ok = $gannet->runCommand($f['type'], $f['path']);
		if (!$ok) $gannet->onError();
	}
}
