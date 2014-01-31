<?php

ini_set('xdebug.var_display_max_depth', 10 );
ini_set('xdebug.var_display_max_children', 10000 );



/**
 * How to extend: create a new class extending listingsImport and override the getListing*() methods
 */

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
     * The make model list( from classifieds_listing_field_tree
     */
    private $makeAndModelList;

    /**
     * All options that can be stored for a vehicle
     * @var array
     */
    //private $allListingOptions = array();
    /**
     * Fire it up
     */
    public function __construct()
    {
        require_once 'conn.php';
        $db = new dbConnection;
        $this->conn = $db->connection;
        $this->fieldList = $this->getFieldList();
        $this->makeAndModelList = $this->getMakeAndModelList();
        //$this->allListingOptions = $this->getAllListingOptions();
        
    }

    public function __destruct()
    {
        // hmmm, does this release the connection?
        $this->conn = null;
    }

    /**
     * Get list of all fields and store in array for search
     * @see  classifieds_listings_field_list
     * @return array of key=>value == sid=>value
     */
    private function getFieldList()
    {
        $_fieldList = array();
        $query = $this->conn->prepare("SELECT sid, field_sid, value FROM classifieds_listing_field_list");
        $query->execute();

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $thisKey = $row['sid'] . ':' . $row['field_sid'];
            $_fieldList[$thisKey] = $row['value'];
        }
        
        return $_fieldList;
    }

    private function getMakeAndModelList()
    {
        $_list = array();
        $query = $this->conn->prepare("SELECT sid, caption FROM classifieds_listing_field_tree");
        $query->execute();

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $thisKey = $row['sid'];
            $_list[$thisKey] = $row['caption'];
        }
        
        return $_list;
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
        
        $this->listingMap = $this->mapListingsForDealers($this->listingDump); 

        $this->listingsCreateUpdateDelete();


    }

    private function listingsCreateUpdateDelete()
    {
        $nightlyFeed = $this->listingMap;

        foreach ($nightlyFeed as $dealerInventory)
        {
            $dealerSID = $dealerInventory[0]['user_sid'];
            $currentDealerListings = $this->getCurrentDealerListings($dealerSID);
            $currentVins = array_keys($currentDealerListings);

            // if the VIN and sid (user id) isn't in db, create the listing
            $create = array();
            // if the VIN and sid is in db, update the listing (maybe update if different)
            $update = array();
            // if the VIN and sid is in db, but not in listingMap, delete from DB
            $delete = array(); 
            foreach ($dealerInventory as $inventoryItem) {
                //var_dump($listing);
                $searchResult = array_search($inventoryItem['Vin'], $currentVins);
                if ($searchResult)
                {
                    $update[] = $inventoryItem;                
                }
                else 
                {
                    $create[] = $inventoryItem;
                }
                
                unset($currentVins[$searchResult]);
            }
            // everything in $currentVins is in db under this user, but not in feed, so we delete
            $delete = $currentVins;
            
            $this->createListings($create);
            $this->updateListings($update);
            $this->deleteListings($delete);
        }        

    }

    /**
     * [createListings description]
     * @param  array $create
     * @return [type]
     */
    private function createListings($create)
    {
        // all empty arrays need to be null or empty string        
        for ($i = 0; $i < count($create); $i++)
        {
            foreach ($create[$i] as $k => $field)
            {
                if (is_array($field) && empty($field))
                {
                    $create[$i][$k] = null;
                }
                // @todo, do we need to serialize in case?
            }
            $images = $create[$i]['Images'];
            unset($create[$i]['Images']);
            // the insert
            $columns = array_keys($create[$i]);
            // for ($c = 0; $c < count($columns); $c++)
            // {
            //     //$columns[$i] = $this->conn->quote($columns[$i]);
            // }
            $columnList = "`" . join("`,`", $columns) . "`";
            $params = array_map(function($col) { return ":$col"; }, $columns);
            $paramList = "'" . join("','", array_map(function($col) { return ":$col"; }, $columns))  . "'";

            $paramValues = array_combine($params, array_values($create[$i]));
            //print $columnList . '<br>';
            //print $paramList . '<br>';
            $sql = "INSERT INTO `classifieds_listings` ($columnList) VALUES ($paramList)";
            $stmt = $this->conn->prepare($sql);
            //var_dump($paramValues);
            foreach ($paramValues as $k => $v)
            {
                //print "binding ${v} to ${k}<br>";
                $stmt->bindParam("{$k}", $v);
            }
            print_r($stmt);
            // @todo move to logging 
            if ($stmt === false) { die(var_dump($this->conn->errorInfo(), true)); }

            $insert = $stmt->execute();
            print_r($insert);
            if ($insert === false) { die(var_dump($this->conn->errorInfo(), true));}       


        }
    }

    private function updateListings($update)
    {
        var_dump($update);
    }

    private function deleteListings($delete)
    {

    }

    private function getCurrentDealerListings($userId = null)
    {
        $stmt = "SELECT sid, user_sid, Vin FROM classifieds_listings";
        if (!is_null($userId))
        {
            $stmt .= " WHERE user_sid = :user_sid";
        }
        $query = $this->conn->prepare($stmt);
        if (!is_null($userId))
        {
            $query->bindParam(':user_sid', $userId, PDO::PARAM_INT);
        }      

        $query->execute();

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['Vin']]['sid'] = $row['sid'];
            $results[$row['Vin']]['user_sid'] = $row['user_sid'];
        }

        return $results;
        // $getFields = array(
        //     'sid',
        //     'user_sid',
        //     'keywords',
        //     'views',
        //     'pictures',
        //     'feature_youtube_video_id',
        //     'AirConditioning',
        //     'AlloyWheels',
        //     'AmFmRadio',
        //     'AmFmStereoTape',
        //     'ZipCode',
        //     'Price',
        //     'Year',
        //     'Mileage',
        //     'Condition',
        //     'Vin',
        //     'ExteriorColor',
        //     'InteriorColor',
        //     'Doors',
        //     'Engine',
        //     'Transmission',
        //     'FuelType',
        //     'DriveType',
        //     'DriverAirBag',
        //     'PassengerAirBag',
        //     'SideAirBag',
        //     'AntiLockBrakes',
        //     'PowerSteering',
        //     'CruiseControl',
        //     'Video',
        //     'MakeModel',
        //     'BodyStyle',
        //     'LeatherSeats',
        //     'PowerSeats',
        //     'ChildSeat',
        //     'TiltWheel',
        //     'PowerWindows',
        //     'RearWindowDefroster',
        //     'PowerDoorLocks',
        //     'TintedGlass',
        //     'CompactDiscPlayer',
        //     'PowerMirrors',
        //     'CompactDiscChanger',
        //     'SunroofMoonroof',
        //     'SellerComments',
        //     'Address',
        //     'City',
        //     'State',
        //     'Sold',
        //     'ListingRating',
        //     'AutomaticHeadlights',
        //     'DaytimeRunningLights',
        //     'ElectronicBrakeAssistance',
        //     'FogLights',
        //     'KeylessEntry',
        //     'RemoteIgnition',
        //     'SteeringWheelMountedControls',
        //     'Navigation'
        // );
        
    }

    protected function mapListingsForDealers($dealerCars)
    {
        $return = array();
        foreach ($dealerCars as $dealer)
        {   
            $return[] = $this->mapListings($dealer);
        }

        return $return;
    }

    protected function mapListings($listings)
    {
        $return = array();
        foreach ($listings as $advertiser) 
        {
            $return[] = $this->mapListing($advertiser);
        }

        return $return;
    }

    /**
     * Search all fields from classifieds_listing_field_list
     * @param  [type] $searchFor
     * @return [type]
     */
    private function searchFieldList($searchFor, $field_sid = null, $list = null)
    {   
        if (is_array($searchFor))
        {
            $searchFor = current($searchFor);
        }
        if ($searchFor === '')
        {
            return null;
        }

        if (is_null($list)) {
            $list = $this->fieldList;
        }
        $searchFor = preg_quote($searchFor);
        $return = preg_grep('/' . $searchFor . '/i', $list);

        // if nothing is found
        // for now, return null
        // @todo logging

        if (!$return) 
        {
            
            return null;
        }
        // if more than one row is returned, the field_sid is the tie breaker
        if (count($return) > 1)
        {
            
            $keys = array_keys($return);
            $return = array_search($field_sid, $keys);
            $return = explode(':', $keys[$return]);
            return $return[0];
        }
        else
        {
            
            $return = array_keys($return);
            return current($return);
        }

        return null;
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
            'user_sid' => $this->getListingUserID($listing), // @see table users_users @todo may need to be dynamic in future
            'active' => 1,
            'moderation_status' => 'APPROVED',
            'activation_date' => date("Y-m-d H:i:s"),
            'expiration_date' => '',
            'first_activation_date' => '', // @todo this could cause mess with update; review  


            'feature_youtube_video_id' => $this->getListingYoutubeVideoID($listing),
            'keywords' => $this->getListingKeywords($listing),
            'pictures' => $this->getListingPicturesCount($listing),
            'Year' => $this->getListingYear($listing),
            'Mileage' => $this->getListingMileage($listing),
            'Condition' => $this->getListingCondition($listing),
            'ExteriorColor' => $this->getListingExteriorColor($listing),
            'InteriorColor' => $this->getListingInteriorColor($listing),
            'Doors' => $this->getListingDoors($listing),
            'Engine' => $this->getListingEngine($listing),
            'Transmission' => $this->getListingTransmission($listing),
            'Vin' => $this->getListingVin($listing),
            'ZipCode' => $this->getListingZipCode($listing),
            'Price' => $this->getListingPrice($listing),            
            'MakeModel' => $this->getListingMakeModel($listing),
            'BodyStyle' => $this->getListingBodyStyle($listing),            
            'SellerComments' => $this->getListingSellerComments($listing),
            'Address' => $this->getListingAddress($listing),
            'City' => $this->getListingCity($listing),
            'State' => $this->getListingState($listing),
            'Sold' => $this->getListingSold($listing),
            'ListingRating' => $this->getListingListingRating($listing),
            'FuelType' => $this->getListingFuelType($listing),
            'DriveType' => $this->getListingDriveType($listing),
            'Images' => $this->getListingImages($listing)
        );

        // add on the options        
        $returnListing += $this->getListingOptions($listing);

        return $returnListing;
    }

    /* Anytime a new dealer is added, they need to be mapped to their
       respective user id within the CMS
       */
    private function mapDealerNumberToUserSID($dealerNumber)
    {
        $dealerMap = array(
            '5555' => 220
        );

        return $dealerMap[$dealerNumber];
    }

    /** 
     * the getListing*() methods that should be 
     * overridden for different data sources.
     * The original methods are based on xml data
     * from dealercarsearch.com
     */

    /** will need to be overridden in extending class **/
    protected function mapListingOptions() {
        $return = array(
            // options
            'AirConditioning' => 'Air Conditioning',
            'AlloyWheels' => 'Alloy Wheel',
            'AmFmRadio' => 'AM/FM',
            'AmFmStereoTape' => '', // not sure - I've requested complete list from provider
            'DriverAirBag' => 'Driver Airbag',
            'PassengerAirBag' => 'Passenger Airbag',
            'SideAirBag' => 'Side Airbags',
            'AntiLockBrakes' => 'Anti-Lock Brakes',
            'PowerSteering' => 'Power Steering',
            'CruiseControl' => 'Cruise Control', // not sure
            'Video' => '', // not sure
            'LeatherSeats' => 'Leather Seats',
            'PowerSeats' => 'Power Seats',
            'ChildSeat' => '', // not sure
            'TiltWheel' => 'Tilt Wheel',
            'PowerWindows' => 'Power Windows/Locks: Standard',
            'RearWindowDefroster' => 'Rear Defroster',
            'PowerDoorLocks' => 'Power Locks',
            'TintedGlass' => 'Tinted Windows',
            'CompactDiscPlayer' => 'CD',
            'PowerMirrors' => 'Power Mirrors',
            'CompactDiscChanger' => 'CD Changer', // not sure
            'SunroofMoonroof' => 'Sun Roof',
            'AutomaticHeadlights' => 'Automatic Headlights',
            'DaytimeRunningLights' => 'Daytime Running Lights',
            'ElectronicBrakeAssistance' => 'Electronic Brake Assistance',
            'FogLights' => 'Fog Lights',
            'KeylessEntry' => 'Keyless Entry',
            'RemoteIgnition' => '', // not sure
            'SteeringWheelMountedControls' => 'Steering Wheel Mounted Controls',
            'Navigation' => 'Navigation'
        );

        return $return;
    }
    
    /**
     * getListingOptions may be complicated with future feeds
     * dealercarsearch provides it as one string of comma separated values;
     * others provide it as a key => (boolean) for each one
     * e.g. 'AirConditiong' => 1, '4x4' => 0
     *
     * Update: matter of fact, this would be a pretty complicated
     * algo to get perfect...will need some human interaction
     */
    protected function getListingOptions($listing)
    {
     
        if ($listing['Options'] === '' || is_array($listing['Options'])) {
            return array();
        }
        $return = array();
        $optionsMap = $this->mapListingOptions();
        $options = $listing['Options'];
        $options = explode(',', $options);
        foreach ($optionsMap as $dbField => $option) {
            $return[$dbField] = 0;
            if (array_search($option, $options))
            {
                $return[$dbField] = 1;
            }
        }

        return $return;
        
    }
    protected function getListingImages($listing) 
    {
        $return = array();
        if (!empty($listing['Images']))
        {
            $return = explode(',', $listing['Images']);
        }

        return $return;
    }
    protected function getListingUserID($listing)
    {
        return $this->mapDealerNumberToUserSID($listing['Dealer_x0020_ID']);
    }
    protected function getListingDriveType($listing)
    {
        // @todo
    }
    protected function getListingFuelType($listing)
    {
        // @todo
    }
    protected function getListingListingRating($listing)
    {
        // no data in dealercarsearch feed
        return null;
    }
    protected function getListingSold($listing)
    {
        // no data in dealercarsearch feed
        return null;
    }
    protected function getListingState($listing)
    {
        return $listing['State'];
    }
    protected function getListingCity($listing)
    {
        return $listing['City'];
    }
    protected function getListingAddress($listing)
    {
        $return = empty($listing['Address2']) ? $listing['Address1'] : $listing['Address1'] . ' ' . $listing['Address2'];
        return $return;
    }
    protected function getListingSellerComments($listing)
    {
        return $listing['Comments_x0020_And_x0020_Default_x0020_Sellers_x0020_Notes'];
    }
    protected function getListingBodyStyle($listing)
    {
        $return = $this->searchFieldList($listing['Body_x0020_Type'], 160);
        return $this->getSID($return);
    }
    
    protected function getListingMakeModel($listing)
    {
        $return = $this->searchFieldList($listing['Model'], null, $this->makeAndModelList);
        return $return;
    }
    protected function getListingPrice($listing)
    {
        return $listing['Retail'];
    }
    protected function getListingZipCode($listing)
    {
        return $listing['Zip'];
    }
    protected function getListingVin($listing)
    {
        return $listing['VIN'];
    }
    protected function getListingTransmission($listing)
    {
        $return = $this->searchFieldList($listing['Transmission_x0020_Type'], 111);
        return $this->getSID($return);
    }
    protected function getListingDoors($listing)
    {
        // @todo don't see any doors field in dealercarsearch feed
        // $return = $this->searchFieldList($listing['Cylinders'] . ' Cylinder');
        // $this->_debug($return, $listing['Cylinders'] . ' Cylinder');
        // return $this->getSID($return);
        return null;
    }
    protected function getListingEngine($listing)
    {
        $return = $this->searchFieldList($listing['Cylinders'] . ' Cylinder', 110);
        return $this->getSID($return);
    }
    protected function getListingInteriorColor($listing)
    {
        $return = $this->searchFieldList($listing['Interior_x0020_Color'], 108);
        return $this->getSID($return);
    }
    protected function getListingExteriorColor($listing)
    {
        $return = $this->searchFieldList($listing['Exterior_x0020_Color'], 107);
        return $this->getSID($return);
    }
    protected function getListingCondition($listing)
    {
        $return = $this->searchFieldList($listing['New_x0020__x002F__x0020_Used'], 202);
        return $this->getSID($return);
    }
    protected function getListingMileage($listing)
    {
        return $listing['Mileage'];
    }
    protected function getListingYear($listing)
    {
        return $listing['Year'];
    }

    protected function getListingYoutubeVideoID($listing)
    {
        $videoURL = $listing['Video_x0020_URL'];
        $video = array();
        if (!empty($videoURL))
        {
            parse_str(parse_url($videoURL, PHP_URL_QUERY), $video);
            return $video['v'];
        }
        return '';
        
    }
    protected function getListingKeywords($listing)
    {
        return $listing['Address1'] . ' ' . 
               $listing['City'] . ' ' . 
               $listing['Year'] . ' ' .
               $listing['Make'] . ' ' .
               $listing['Model'] . ' ' .
               $listing['Trim'];
    }
    protected function getListingPicturesCount($listing)
    {
        if (is_array($listing['Images'])) 
        {
            // if a field is empty it is sent as an array
            $listing['Images'] = '';
        }
        $images = explode(',', $listing['Images']);
        return count($images);
    }
    /**
     * Retuns the SID part of a value
     * @param  string $val 123:233
     * @return int 123
     */
    private function getSID($val)
    {
        $ex = explode(':', $val);
        return (int)$ex[0];
    }
    private function _debug($value, $item)
    {
        print 'Value returned: ' . $value . "\n";
        print 'Searched for: ' . $item . "\n";
        die();
    }
    // protected function getListingInfo($listing, $field) {
    //     // this should be overridden by extending class
        
    // }

    
    // @todo - is this necessary/convenient/better?
    // may deprecate
    private function getAllListingOptions() {
        $return = array(
            // options
            'AirConditioning',
            'AlloyWheels',
            'AmFmRadio',
            'AmFmStereoTape',
            'DriverAirBag',
            'PassengerAirBag',
            'SideAirBag',
            'AntiLockBrakes',
            'PowerSteering',
            'CruiseControl',
            'Video',
            'LeatherSeats',
            'PowerSeats',
            'ChildSeat',
            'TiltWheel',
            'PowerWindows',
            'RearWindowDefroster',
            'PowerDoorLocks',
            'TintedGlass',
            'CompactDiscPlayer',
            'PowerMirrors',
            'CompactDiscChanger',
            'SunroofMoonroof',
            'AutomaticHeadlights',
            'DaytimeRunningLights',
            'ElectronicBrakeAssistance',
            'FogLights',
            'KeylessEntry',
            'RemoteIgnition',
            'SteeringWheelMountedControls',
            'Navigation'
        );

        return $return;
    }

    /**
     * @todo  this isn't necessary except for debugging
     */
    public function showDataDump()
    {
        var_dump($this->listingDump);
    }
    

}

/**
 * This is the default -- in this version no overriding is necessary
 */
class dealerCarSearchListingsImport extends listingsImport {

    public function __construct() 
    {
        parent::__construct();
        // @todo meh, not sure anything else will need to be done here
    }

}


$test = new dealerCarSearchListingsImport;
$test->importFile('../DCS_Autoz4Sell.xml');
//$test->showDataDump();