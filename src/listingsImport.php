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
     * The fields as in db table classifieds_listings_field_list
     * @var array
     */
    private $fieldList;

    /**
     * Fire it up
     */
    public function __construct()
    {
        require_once 'conn.php';
        $this->conn = new dbConnection;
        //$_fieldList = $this->conn->query("SELECT sid, value FROM ") // @todo left off here
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
            /** values we don't need to mess with **/
            //'sid' => '',
            //'last_user_ip' => '',
            //'views' => '',
            //'feature_featured' => '',
            //'featured_last_showed' => '',
            //'feature_highlighted' => '',
            //'feature_slideshow' => '',
            //'feature_sponsored' => '',
            //'feature_youtube' => '',
            //'facebook_repost_status' => '',
            //'twitter_repost_status' => '',

            'category_sid' => 4, // @see table classifieds_categories
            'user_sid' => 223, // @see table users_users @todo may need to be dynamic in future
            'active' => 1,
            'moderation_status' => 'APPROVED',
            'activation_date' => date("Y-m-d H:i:s"),
            'expiration_date' => '',
            'first_activation_date' => '', // @todo this could cause mess with update; review  


            'feature_youtube_video_id' => $this->getListingInfo($listing, 'youtubeVideoID'),
            'keywords' => $this->getListingInfo($listing, 'keywords'),
            'pictures' => $this->getListingInfo($listing, 'picturesCount'),
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

    protected function getListingInfo($listing, $field) {
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

    /**
     * @todo - maybe this is better broken into individual functions?
     *  e.g. getListingKeywords in the parent class, then extend just the specific ones
     *       that don't match up.
     *       Caveat to this is that we would be assuming fields like Vin would always be 'Vin'
     * @param  [type] $listing
     * @param  [type] $field
     * @return [type]
     */
    protected function getListingInfo($listing, $field) 
    {   
        $return = '';
        $l = $listing;
        switch ($field)
        {
            case 'mileage':
                $return = $l['Mileage'];
            break;
            case 'condition':
                $return = $l[''];
            break;
            case 'exteriorColor':
                $return = $l[''];
            break;
            case 'interiorColor':
                $return = $l[''];
            break;
            case 'doors':
                $return = $l[''];
            break;
            case 'engine':
                $return = $l[''];
            break;
            case 'transmission':
                $return = $l[''];
            break;
            case 'vin':
                $return = $l[''];
            break;
            case 'zipCode':
                $return = $l[''];
            break;
            case 'price':
                $return = $l[''];
            break;            
            case 'makeModel':
                $return = $l[''];
            break;
            case 'bodyStyle':
                $return = $l[''];
            break;            
            case 'sellerComments':
                $return = $l[''];
            break;
            case 'address':
                $return = $l[''];
            break;
            case 'city':
                $return = $l[''];
            break;
            case 'state':
                $return = $l[''];
            break;
            case 'sold':
                $return = $l[''];
            break;
            case 'listingRating':
                $return = $l[''];
            break;
            case 'year': 
                $return = $l['Year'];
            break;
            case 'picturesCount':
                $images = explode(',', $l['Images']);
                $return = count($images);
            break;
            case 'youtubeVideoID':
                $videoURL = $l['Video_x0020_URL'];
                parse_str(parse_url($videoURL, PHP_URL_QUERY), $video);

                $return = $video['v'];
            break;
            case 'keywords':
                $return = $l['Address1'] . ' ' . 
                            $l['City'] . ' ' . 
                            $l['Year'] . ' ' .
                            $l['Make'] . ' ' .
                            $l['Model'] . ' ' .
                            $l['Trim'];
            break;


        }

        return $return;
    }

}


$test = new dealerCarSearchListingsImport;
$test->importFile('../DCS_Autoz4Sell.xml');
$test->showDataDump();