<?php

//ini_set('xdebug.var_display_max_depth', 10 );
//ini_set('xdebug.var_display_max_children', 10000 );



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

        // move the file
        $time = date('Y_M_d', time());
        $oldPath = pathinfo($filePath);
        $newPath = $oldPath['dirname'] . $oldPath['filename'] . '_' . $time . '.' . $oldPath['extension'];
        rename($filePath, $newPath);


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
                
                if ($searchResult !== false)
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
            $this->deleteListings($delete, $dealerSID);
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
            $paramList = join(",", array_map(function($col) { return ":$col"; }, $columns));

            $paramValues = array_combine($params, array_values($create[$i]));
            //print $columnList . '<br>';
            //print $paramList . '<br>';
            $sql = "INSERT INTO `classifieds_listings` ($columnList) VALUES ($paramList)";
            $stmt = $this->conn->prepare($sql);
            //var_dump($paramValues);
            foreach ($paramValues as $k => $v)
            {
                 //print "binding ${v} to ${k}<br>";
                $stmt->bindValue($k, $v);
            }
            // @todo move to logging 
            if ($stmt === false) { die(var_dump($this->conn->errorInfo(), true)); }
            
            $insert = $stmt->execute();
            $lastSID = $this->conn->lastInsertId('sid');
            if ($insert === false) { die(var_dump($this->conn->errorInfo(), true));}       
            print "Created listing id {$lastSID}. Number {$i} of " . count($create) . "<br>";
            // handle images
            $imgCaption = $create[$i]['keywords'];
            $imageHandler = $this->getAndStoreListingImages($images, $lastSID, $imgCaption);

        }
    }

    private function updateListings($update)
    {

        for ($i = 0; $i < count($update); $i++)
        {
            foreach ($update[$i] as $k => $field)
            {
                if (is_array($field) && empty($field))
                {
                    $update[$i][$k] = null;
                }
                // @todo, do we need to serialize in case?
            }

            $vin = $update[$i]['Vin'];
            $user_sid = $update[$i]['user_sid'];
            unset($update[$i]['Vin'], $update[$i]['user_sid'], $update[$i]['Images']);
            $updateStmt = array();
            $paramArr = array();
            foreach ($update[$i] as $column => $value)
            {
                $updateStmt[]= "`{$column}` = :{$column}";
                $paramArr[":{$column}"] = $value; 
            }
            $updateStmt = implode(', ', $updateStmt);
            $sql = "UPDATE `classifieds_listings` SET {$updateStmt} WHERE Vin = :vin AND user_sid = :user_sid";
            $stmt = $this->conn->prepare($sql);
            foreach ($paramArr as $bindTo => $bindValue)
            {
                $stmt->bindValue($bindTo, $bindValue);
            }
            
            $stmt->bindParam(':vin', $vin);
            $stmt->bindParam(':user_sid', $user_sid);

            // @todo move to logging 
            if ($stmt === false) { die(var_dump($this->conn->errorInfo(), true)); }
            
            $runUpdate = $stmt->execute();

            if ($runUpdate === false) { die(var_dump($this->conn->errorInfo(), true));}       
        }
    }

    private function deleteListings($delete, $user_id)
    {
        $toDelete = array();
        $deleteTbls = array('sid' => 'classifieds_listings', 'listing_sid' => 'classifieds_listings_pictures');
        foreach ($delete as $d)
        {
            // get the record first
            
            $qry = "SELECT sid FROM `classifieds_listings` WHERE Vin = :vin AND user_sid = :user_id";
            $sel = $this->conn->prepare($qry);
            $sel->bindValue(':vin', $d);
            $sel->bindValue(':user_id', $user_id);
            $results = $sel->execute();
            $sid = $sel->fetch();
            
            $toDelete[] = $sid['sid'];

            // $qry = "DELETE FROM `classifieds_listings` WHERE Vin = :vin AND user_sid = :user_sid";
            // $stmt = $this->conn->prepare($qry);
            
            // // @todo move to logging 
            // if ($stmt === false) { die(var_dump($this->conn->errorInfo(), true)); }
            // $runDelete = $stmt->execute();
            // if ($runDelete === false) { die(var_dump($this->conn->errorInfo(), true));}
        }

        foreach ($toDelete as $del)
        {
            foreach ($deleteTbls as $field => $tbl) {
                $qry = "DELETE FROM `{$tbl}` WHERE `{$field}` = {$del}";
                $stmt = $this->conn->prepare($qry);
                $stmt->execute();
            }
        }
    }

    private function cleanUpEmptyValues($value)
    {

    }
    private function getAndStoreListingImages($images, $lastSID, $imageCaption)
    {
        // where do images go?
        $imgFolder = './files/pictures/';
        //$imgFolder = './';
        $tmpName = 'tmpImage';
        for($i = 0; $i < count($images); $i++)
        {
            $tmpImg = $images[$i];
            $tmpName .= $i;
            $tmpExt = substr($tmpImg, strrpos($tmpImg, '.'));
            
            $copy = copy($images[$i], $imgFolder . $tmpName . $tmpExt);
            
            $sql = "INSERT INTO `classifieds_listings_pictures` (`listing_sid`,`storage_method`,`order`,`caption`)";
            $sql .= " VALUES (:list_sid, :storage_method, :order, :caption)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':list_sid', $lastSID);
            $stmt->bindValue(':storage_method', 'file_system');
            $stmt->bindParam(':order', $i);
            $stmt->bindParam(':caption', $imageCaption);

            $insert = $stmt->execute();
            $lastId = $this->conn->lastInsertId('sid');

            // rename the file
            $permPic = 'picture_' . $lastId . $tmpExt;
            $thumb = 'thumb_' . $lastId . $tmpExt;
            
            // create the thumb            
            $thumbcopy = imagecreatefromjpeg($imgFolder . $permPic);
            
            
            $newThumb = $this->thumbnailBox($thumbcopy, 100, 100, $thumb, $imgFolder);
            
            
            $sql = "UPDATE `classifieds_listings_pictures` SET `picture_saved_name` = :permPic, `thumb_saved_name` = :thumb";
            $sql .= " WHERE sid = :sid";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':permPic', $permPic);
            $stmt->bindValue(':thumb', $thumb);
            $stmt->bindParam(':sid', $lastId);
            $update = $stmt->execute();

        }
    }

    /**
     * From http://stackoverflow.com/questions/747101/resize-crop-pad-a-picture-to-a-fixed-size
     */
    private function thumbnailBox($img, $box_w, $box_h, $dest, $folder) {
        //create the image, of the required size
        $new = imagecreatetruecolor($box_w, $box_h);
        if($new === false) {
            //creation failed -- probably not enough memory
            die( print __LINE__ . 'new = false');
            return null;
        }


        //Fill the image with a light grey color
        //(this will be visible in the padding around the image,
        //if the aspect ratios of the image and the thumbnail do not match)
        //Replace this with any color you want, or comment it out for black.
        //I used grey for testing =)
        $fill = imagecolorallocate($new, 200, 200, 205);
        imagefill($new, 0, 0, $fill);

        //compute resize ratio
        $hratio = $box_h / imagesy($img);
        $wratio = $box_w / imagesx($img);
        $ratio = min($hratio, $wratio);

        //if the source is smaller than the thumbnail size, 
        //don't resize -- add a margin instead
        //(that is, dont magnify images)
        if($ratio > 1.0)
            $ratio = 1.0;

        //compute sizes
        $sy = floor(imagesy($img) * $ratio);
        $sx = floor(imagesx($img) * $ratio);

        //compute margins
        //Using these margins centers the image in the thumbnail.
        //If you always want the image to the top left, 
        //set both of these to 0
        $m_y = floor(($box_h - $sy) / 2);
        $m_x = floor(($box_w - $sx) / 2);

        //Copy the image data, and resample
        //
        //If you want a fast and ugly thumbnail,
        //replace imagecopyresampled with imagecopyresized
        if(!imagecopyresampled($new, $img,
            $m_x, $m_y, //dest x, y (margins)
            0, 0, //src x, y (0,0 means top left)
            $sx, $sy,//dest w, h (resample to this size (computed above)
            imagesx($img), imagesy($img)) //src w, h (the full size of the original)
        ) {
            //copy failed
            imagedestroy($new);
            return null;
        }
            
        //copy successful
        return $new;
    }

    private function getCurrentDealerListings($userId = null)
    {
        $results = array();
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
$test->importFile('./Cardealer/DCS_Autoz4Sell.xml');
//$test->showDataDump();