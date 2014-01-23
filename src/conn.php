<?php

/**
 * Database connection
 */

/**
 * @todo
 *   custom logging class (check github for out-of-box)?
 */

class dbConnection {
	
	private $user = 'autoz4sell';
	private $pass = 'autoz4sell';
	private $database = 'autoz4sell';

	public $connection;

	public function __construct() 
	{
		try {
			$this->connection = new PDO('mysql:host=localhost;dbname=' . $this->database, $this->user, $this->pass);
			$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch (PDOException $e) {
			die('ERROR: ' . $e->getMessage());
		}
	}

}