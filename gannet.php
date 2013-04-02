<?php

error_reporting(E_ALL | E_STRICT);

function Gannet_log($s) {
	echo '[Migration] ' . $s . "\n";
}

function Gannet_logError($s) {
	Gannet_log('[ERR] ' . $s); 
}

function Gannet_logWarn($s) {
	Gannet_log('[WARN] ' . $s); 
}

class Gannet_Config {

	private $data_ = array();

	public function __construct($data) {
		// Set some default values:
		if (!array_key_exists('migration_table_name', $data)) $data['migration_table_name'] = 'dbmigrations';
		if (!array_key_exists('stop_on_error', $data)) $data['stop_on_error'] = true;
		$this->data_ = $data;
	}
	
	public function toPdoConnectionString() {
		$s = '';
		foreach ($this->data_['connection'] as $k => $v) {
			if ($v === false) continue;
			if ($k == 'username') continue;
			if ($k == 'password') continue;
			if ($k == 'database') $k = 'dbname';
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

	public function versionFiles($scriptFolder) {
		$temp = array();
		foreach (glob($scriptFolder . "*.*") as $filePath) {
			$p = pathinfo($filePath);
			$v = $p['filename'];
			$ext = $p['extension'];
			if (isset($temp[$v])) {
				Gannet_logWarn('Skipping duplicate version file: ' . $filePath);
				continue;
			}
			$temp[$v] = array('path' => $filePath, 'type' => $ext);
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
		$this->db_ = new PDO(
			$this->config_->toPdoConnectionString(),
			$this->config_->get('connection.username'),
			$this->config_->get('connection.password'),
			array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
		);
	}
	
	public function dbCheckMigrationTable() {
		$tableName = $this->config_->get('migration_table_name');
		Gannet_log('Checking if "' . $tableName . '" table exists...');
		try {
			$this->db_->query('SELECT 1 FROM ' . $tableName . ' LIMIT 1');
		} catch (Exception $e) {
			Gannet_logError('Table ' . $tableName . ' does not exist or is not readable: ' . $e->getMessage());
			$this->onError();
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
		if (!(error_reporting() & $errno)) return;
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
	if (strpos($configFilePath, '.') === 0) $configFilePath = dirname(__FILE__) . '/' . $configFilePath;
} else {
	$configFilePath = dirname(__FILE__) . '/config/config.toml';	
}

$configFilePath = realpath($configFilePath);

/**
 * Initialize configuration
 */

require_once "Toml.php";
if (!file_exists($configFilePath)) throw new Exception('Config file does not exist: ' . $configFilePath);
Gannet_log("Using config at " . $configFilePath);
$config = new Gannet_Config(Toml::parseFile($configFilePath));

/**
 * Check script folder
 */
 
$scriptFolder = dirname(__FILE__) . '/scripts/';
if ($config->get('script_path')) {
	$scriptFolder = $config->get('script_path');
	if (strpos($scriptFolder, '.') === 0) $scriptFolder = dirname(__FILE__) . '/' . $scriptFolder;
}

if (!strlen($scriptFolder)) throw new Exception('Script folder path is an empty string.');
$scriptFolder = realpath($scriptFolder);
if ($scriptFolder[strlen($scriptFolder) - 1] != '/') $scriptFolder .= '/';
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
$currentVersion = $gannet->dbCurrentVersion();

/**
 * Upgrade the database
 */

while (true) {
	$nextVersion = $gannet->dbNextVersion($currentVersion, $versions);

	Gannet_log('Current version: ' . $currentVersion);
	Gannet_log('Next version: ' . ($nextVersion ? $nextVersion['version'] : 'none'));
	if (!$nextVersion) break;
	
	Gannet_log('Running: ' . $nextVersion['path']);
	$ok = $gannet->runCommand($nextVersion['type'], $nextVersion['path']);
	if (!$ok) $gannet->onError();
	
	$currentVersion = $nextVersion['version'];
	$gannet->dbSaveVersionInfo($currentVersion);
}