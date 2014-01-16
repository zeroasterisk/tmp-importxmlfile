<?php
$dbCnf=array(
	'host' => 'localhost',
	'user' =>'config',
	'pass' =>'config',
	'db' => 'config',
);
include('importxmlfile.config.php');
$conn=mysql_connect($dbCnf['host'], $dbCnf['user'], $dbCnf['pass']);
mysql_select_db($dbCnf['db'], $conn);

$autodealer = simplexml_load_file("Cardealer/DCS_Autoz4Sell.xml");

foreach ($autodealer->auto as $auto) {
$query="select sid from classifieds_listing_field_tree where caption='".$auto->Model."'";
$exe=mysql_query($query);
list($maodelid)=mysql_fetch_array($exe);

$query="select sid from classifieds_listing_field_list where value='".$auto->Exterior_x0020_Color."' and field_sid='107'";
$exe=mysql_query($query);
list($excid)=mysql_fetch_array($exe);

$query="select sid from classifieds_listing_field_list where value='".$auto->Transmission_x0020_Type."' and field_sid='111'";
$exe=mysql_query($query);
list($transid)=mysql_fetch_array($exe);

$query="select sid from classifieds_listing_field_list where value='".$auto->Body_x0020_Type."' and field_sid='160'";
$exe=mysql_query($query);
list($bodysid)=mysql_fetch_array($exe);

$query="select sid from classifieds_listing_field_list where value='".$auto->Engine."' and field_sid='110'";
$exe=mysql_query($query);
list($ensid)=mysql_fetch_array($exe);

$inquery="insert into classifieds_listings (user_sid,Address,City,State,ZipCode,Vin,Year,MakeModel,Transmission,ExteriorColor,Price,Mileage,feature_youtube_video_id,BodyStyle,Engine,SellerComments,category_sid,moderation_status,active) values('220','".$auto->Address1."','".$auto->City."','".$auto->State."','".$auto->Zip."','".$auto->VIN."','".$auto->Year."','".$maodelid."','".$transid."','".$excid."','".$auto->Retail."','".$auto->Mileage."','".$auto->Video_x0020_URL."','$bodysid','$ensid','".$auto->Comments_x0020_And_x0020_Default_x0020_Sellers_x0020_Notes."','4','APPROVED','1')";
$re=mysql_query($inquery);
$lsid=mysql_insert_id();
$images=explode(',',$auto->Images);

$i=1;
$caption=$auto->Year.' '.$auto->Make.' '.$auto->Model;
if(sizeof($images)>0)
{
foreach($images as $k=>$v)
{
$ch = curl_init($v);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_BINARYTRANSFER,1);
$rawdata=curl_exec ($ch);
curl_close ($ch);
$query2="insert into classifieds_listings_pictures (listing_sid,storage_method,`order`,caption) values('$lsid','file_system','$i','$caption')";
$exe2=mysql_query($query2);
$plsid=mysql_insert_id();
$rename='picture_'.$plsid.'.jpg';
$fp = fopen("files/pictures/$rename",'w');
fwrite($fp, $rawdata);
$desired_width = 100;
$file_tmp="files/pictures/".$rename;
$source_image = imagecreatefromjpeg($file_tmp);
	$width = imagesx($source_image);
	$height = imagesy($source_image);

	/* find the "desired height" of this thumbnail, relative to the desired width  */
	$desired_height = floor($height * ($desired_width / $width));

	/* create a new, "virtual" image */
	$virtual_image = imagecreatetruecolor($desired_width, $desired_height);

	/* copy source image at a resized size */
	imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0, $desired_width, $desired_height, $width, $height);
	$rename2='thumb_'.$plsid.'.jpg';
  $dest="files/pictures/".$rename2;
	/* create the physical thumbnail image to its destination */
	imagejpeg($virtual_image, $dest);

//$fp2 = fopen("files/$rename2",'w');
//fwrite($fp2, $rawdata);
fclose($fp);
$query56="update classifieds_listings_pictures SET picture_saved_name='$rename' where sid='$plsid'";
mysql_query($query56);
$i++;
}}
$query567="update classifieds_listings SET pictures='".($i-1)."' where sid='$lsid'";
mysql_query($query567);

}
rename("Cardealer/DCS_Autoz4Sell.xml", "Cardealer/DCS_Autoz4Sell_2.xml");
?>
