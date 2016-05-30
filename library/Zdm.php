<?php
/**
 * Zend Framework dependency manager
 *
 * Manages dependencies of the Zend Framework in application code. Fetches 
 * Zend Framework classes from the Git repo as needed. Allows building of 
 * ZF dependent code without including the full ZF library.
 *
 * @category   Zdm
 * @copyright  Eran Galperin
 * @author     Eran Galperin
 * @license    http://www.opensource.org/licenses/mit-license.php  MIT license
 */
class Zdm {
	protected static $_instance = null;
	protected $_config = array(
		'libraryPath' => '/library', // If not passed will be set relative to Zdm class
		'prependStack' => true, // Prepend autoloader to autoload stack (otherwise append)
		'zfVersion' => '1.12.18', // Zend Framework version
		'repository' => 'https://raw.githubusercontent.com/zendframework/zf1/release-',
		'method' => self::CURL
	);

	protected $_dependencies = array(
		'Zend/Layout.php' => array('Zend/Filter/Word','Zend/Filter/StringToLower.php'),
		'Zend/View.php' => 'Zend/View',
		'Zend/Controller/Action.php' => 'Zend/Controller/Action/Helper'
	);

	protected $_errors = array();
	
	const CURL = 1;
	const FILE_GET_CONTENTS = 2;
	/**
	 * Initialize and register autoloader instance
	 * @param array $config
	 */
	public static function start($config = array()) {
		self::$_instance = new self($config);
	}

	/**
	 * Get autoloader instance
	 * @return Zdm
	 */
	public static function getInstance() {
		if(is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor
	 * @param array $config
	 */
	protected function  __construct($config = array()) {
		$this -> _config = array_merge($this -> _config,$config);
		if(!isset($config['libraryPath'])) {
			$this -> _config['libraryPath'] = dirname(__FILE__);
		}

		spl_autoload_register(get_class($this) . '::autoload',true,$this -> _config['prependStack']);
	}

	/**
	 * Autoload function
	 * @param string $class
	 */
	public static function autoload($class) {
		if(!class_exists($class) && stripos($class,'Zend') !== false) {
			$file = str_replace('_','/',$class) . '.php';
			$self = self::getInstance();
			$self -> load($file);
		}
	}

	/**
	 * Require file - fetch from repository if does not exist
	 * @param string $file
	 */
	public function load($file) {
		$local = $this -> _config['libraryPath'] . DIRECTORY_SEPARATOR . $file;
		if(!is_file($local)) {
			$this -> fetch($file);
		}
		require($local);
	}

	/**
	 * Fetch Zend Framework class from repository
	 * @param string $file ZF class file to fetch
	 */
	public function fetch($file) {
		$local = $this -> _config['libraryPath'] . DIRECTORY_SEPARATOR . $file;
		$target = $this -> _config['repository'] . $this -> _config['zfVersion'] . '/library/' . $file;
		$data = $this -> _remoteFetch($target);
		var_dump($data);
		if(!is_dir(dirname($local))) {
			mkdir(dirname($local),0755,true);
		}
		$offset = 0;
		while(($pos = strpos($data,"require_once",$offset)) !== false) {
			$data = substr($data,0,$pos) . "//" . substr($data,$pos);
			$offset = $pos + 10;
		}
		file_put_contents($local, $data);
		$this -> fetchDependencies($file);
	}

	/**
	 * Select method to fetch remote file by configuration
	 * @param string $url
	 * @return string
	 */
	protected function _remoteFetch($url) {
		if($this -> _config['method'] == self::CURL) {
			$curl = curl_init();
			$options = array(
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => false,
				CURLOPT_SSL_VERIFYPEER => false
			);
			curl_setopt_array($curl, $options);
			$contents = curl_exec($curl);
			curl_close($curl);
			return $contents; 
		} else {
			return file_get_contents($url);
		}
	}
	/**
	 * Fetch a directory of Zend Framework classes from repository
	 * @param string $dir
	 */
	public function fetchDir($dir) {
		$localDir = $this -> _config['libraryPath'] . DIRECTORY_SEPARATOR . $dir;
		if(!is_dir($localDir)) {
			mkdir($localDir,0755,true);
		}
		$target = $this -> _config['repository'] . $this -> _config['zfVersion'] . '/library/' . $dir;

		//Local directory fetch
		if(is_dir($target)) {
			foreach(glob($target . DIRECTORY_SEPARATOR . '*.*') as $file) {
				$base = $dir . DIRECTORY_SEPARATOR . basename($file);
				if(is_dir($file)) {
					$this -> fetchDir($base);
				} else {
					$this -> fetch($base);
				}
			}
		//Zend Framework repo fetch
		} else {
			$result = file_get_contents($target);
			$tree = new DOMDocument();
			$tree -> loadHTML($result);
			$xpath = new DOMXPath($tree);
			$result = $xpath -> query('//ul/li/a');
			foreach($result as $node) {
				$file = $node -> getAttribute('href');
				if(strpos($file,'.php') === false) {
					if($file != '../') {
						$this -> fetchDir($dir .'/' . $file);
					}
				} else {
					$this ->fetch($dir . '/' . $file);
				}
			}
		}
	}

	/**
	 * Fetch dependencies for file
	 * @param string $file
	 */
	public function fetchDependencies($file) {
		if(array_key_exists($file, $this -> _dependencies)) {
			$deps = (array)$this -> _dependencies[$file];
			foreach($deps as $dep) {
				if(stripos($dep,'.php') === false) {
					$this -> fetchDir($dep);
				} else {
					$this -> fetch($dep);
				}
			}
		}
	}
}