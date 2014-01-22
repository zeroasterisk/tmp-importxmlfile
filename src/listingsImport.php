<?php

ini_set('xdebug.var_display_max_depth', 10 );

class listingsImport {
    
    /**
     * @var object hold pdo connection
     */
    private $conn;

    
    /**
     * The dump of all data from supplier
     * @var object as returned from simplexml
     */
    private $listingDump;


    /**
     * Fire it up
     */
    public function __construct()
    {
        require_once 'conn.php';
        $this->conn = new dbConnection;
    }

    /**
     * Inits the import
     * @param  string $filePath relative path to file
     * @return maybe a success/error message; maybe @todo
     */
    public function importFile($filePath)
    {
        $xml = simplexml_load_file($filePath);
        // make it into a nice array
        $this->listingDump = unserialize(serialize(json_decode(json_encode((array) $xml), 1)));        
    }

    /**
     * @todo  this isn't necessary except for debugging
     */
    public function showDataDump()
    {
        var_dump($this->listingDump);
    }
    

}


$test = new listingsImport;
$test->importFile('../DCS_Autoz4Sell.xml');
$test->showDataDump();