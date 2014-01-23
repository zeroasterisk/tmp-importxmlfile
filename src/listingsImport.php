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

        foreach ($this->listingDump['auto'] as $listing) {
            $this->listingMap[] = $this->mapListing($listing);
        }

    }

    /**
     * Create array of key => value pairs that map to database column => values
     * @param  array $listing
     * @return array
     *
     * By building the values with functions, it makes it easier in the future to add feeds
     * from different vendors that may have different keys for data
     *
     */
    private function mapListing($listing) {
        $returnListing = array
        (
            //'sid' => '',
            'category_sid' => 4, // @see table classifieds_categories
            'user_sid' => 223, // @see table users_users @todo may need to be dynamic in future
            'active' => 1,
            'moderation_status' => 'APPROVED',
            'keywords' => $this->getListingInfo($listing, 'keywords'),
            //'views' => '',
            'pictures' => $this->getListingInfo($listing, 'picturesCount'),
            'activation_date' => date("Y-m-d H:i:s"),
            'expiration_date' => '',
            'first_activation_date' => '', // @todo this could cause mess with update; review
            //'last_user_ip' => '', //defaults to NULL
            //'feature_featured' => '',
            //'featured_last_showed' => '',
            //'feature_highlighted' => '',
            //'feature_slideshow' => '',
            //'feature_sponsored' => '',
            //'feature_youtube' => '',
            'feature_youtube_video_id' => $this->getListingInfo($listing, 'youtubeVideoID'),
            //'facebook_repost_status' => '',
            //'twitter_repost_status' => '',
            'Year' => $this->getListingInfo($listing, 'year'),
            'Mileage' => $this->getListingInfo($listing, 'mileage'),
            'Condition' => $this->getListingInfo($listing, 'condition'),
            'ExteriorColor' => $this->getListingInfo($listing, 'exteriorColor'),
            'InteriorColor' => $this->getListingInfo($listing, 'interiorColor'),
            'Doors' => $this->getListingInfo($listing, 'doors'),
            'Engine' => $this->getListingInfo($listing, 'engine'),
            'Transmission' => $this->getListingInfo($listing, 'transmission'),
            'Vin' => $this->getListingInfo($listing, 'vin'),
            'ZipCode' => $this->getListingInfo($listing, 'zipCode'),
            'Price' => $this->getListingInfo($listing, 'price'),            
            'MakeModel' => $this->getListingInfo($listing, 'makeModel'),
            'BodyStyle' => $this->getListingInfo($listing, 'bodyStyle'),            
            'SellerComments' => $this->getListingInfo($listing, 'sellerComments'),
            'Address' => $this->getListingInfo($listing, 'address'),
            'City' => $this->getListingInfo($listing, 'city'),
            'State' => $this->getListingInfo($listing, 'state'),
            'Sold' => $this->getListingInfo($listing, 'sold'),
            'ListingRating' => $this->getListingInfo($listing, 'listingRating'),
        );

        // add on the options        
        //$returnListing += $this->getListingOptions($listing);
    }

    private function getListingInfo($listing) {
        // this should be overridden by extending class
    }

    // @todo - is this necessary/convenient/better?
    private function getListingOptions($listing) {
        $return = array(
            // options
            'AirConditioning' => '',
            'AlloyWheels' => '',
            'AmFmRadio' => '',
            'AmFmStereoTape' => '',
            'FuelType' => '',
            'DriveType' => '',
            'DriverAirBag' => '',
            'PassengerAirBag' => '',
            'SideAirBag' => '',
            'AntiLockBrakes' => '',
            'PowerSteering' => '',
            'CruiseControl' => '',
            'Video' => '',
            'LeatherSeats' => '',
            'PowerSeats' => '',
            'ChildSeat' => '',
            'TiltWheel' => '',
            'PowerWindows' => '',
            'RearWindowDefroster' => '',
            'PowerDoorLocks' => '',
            'TintedGlass' => '',
            'CompactDiscPlayer' => '',
            'PowerMirrors' => '',
            'CompactDiscChanger' => '',
            'SunroofMoonroof' => '',
            'AutomaticHeadlights' => '',
            'DaytimeRunningLights' => '',
            'ElectronicBrakeAssistance' => '',
            'FogLights' => '',
            'KeylessEntry' => '',
            'RemoteIgnition' => '',
            'SteeringWheelMountedControls' => '',
            'Navigation' => '',
        );
    }

    /**
     * @todo  this isn't necessary except for debugging
     */
    public function showDataDump()
    {
        var_dump($this->listingDump);
    }
    

}

class dealerCarSearchListingsImport extends listingsImport {

    public function __construct() 
    {
        parent::__construct();
        // @todo meh, not sure anything else will need to be done here
    }
    private function getListingInfo($listing) 
    {
        
    }

}


$test = new dealerCarSearchListingsImport;
$test->importFile('../DCS_Autoz4Sell.xml');
$test->showDataDump();