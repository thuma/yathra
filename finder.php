<?php
if(isset($_GET['debugg'])){
error_reporting(E_ALL);
ini_set('display_errors', 'on');
}

$start = microtime(true);
header('Content-type: application/json; charset=UTF-8');

if (preg_match("/(2[0-3]|[01][0-9]):[0-5][0-9]/", $_GET['departureTime']) == FALSE){
	die('{"error":"Error in departure time shhould be: HH:MM , but is: '.$_GET['departureTime'].' "}');
	} 
if (preg_match("/201[0-9]-(0[0-9]|[1][0-2])-[0-3][0-9]/", $_GET['date']) == FALSE){
	die('{"error":"Error in date shhould be: YYYY-MM-DD , but is: '.$_GET['date'].' "}');
	} 
/*if (file_exists('prisAPI/stations/'.$_GET['from']) == FALSE)
	{
	die('{"error":"Error from station does not exist"}');
	} 
if (file_exists('prisAPI/stations/'.$_GET['to']) == FALSE)
	{
	die('{"error":"Error to station does not exist"}');
	}*/

// Check if in cache:
asort($_GET);
$cachefile = md5(json_encode($_GET));
@mkdir('findercache');
if(file_exists('findercache/'.$cachefile)){
	$nu = filemtime('findercache/'.$cachefile);
	if(microtime(true)-$nu<10000){
		die(file_get_contents('findercache/'.$cachefile));
	}
}

set_time_limit(120);
// Prepair default search parameters:
/*$_GET['from'] = "7400001";
$_GET['to'] = "7400110";
$_GET['date'] = "2013-04-18";
$_GET['departureTime'] = "21:10";
*/

$outobject;
$outobject->totaltimeaftercache = microtime(true) - $start;

// Init search:
$ch = curl_init('https://api.trafiklab.se/samtrafiken/resrobot/Search.json'.
'?apiVersion=2.1'.
'&coordSys=WGS84'.
'&fromId='.$_GET['from'].
'&toId='.$_GET['to'].
'&date='.$_GET['date'].
'&time='.urlencode($_GET['departureTime']).
'&searchType=F'./*
F 	Standard search. ResRobot will find the fastest trip using all possible transport modes.
T   Train and local public transport. Express buses are not included in the search.
B   Express bus and local public transport. Regional trains and speed trains are not included in the search.
*/
'&arrival=false'.
'&key=<Key>');
/*
'&mode1=true'. //Speed train. X2000 and Arlanda Express
'&mode2=true'. //Train (except speed trains)
'&mode3=true'. //Bus (except express buses)
'&mode4=ture'. //Boat
'&mode5=ture'); //Express bus
*/

// Run search:
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resultstring = curl_exec($ch);
curl_close($ch);

//remove specialChars and make object:
$resultstring = str_replace('"#','"',$resultstring);
$resultstring = str_replace('"@','"',$resultstring);
$resultobject = json_decode(utf8_encode($resultstring));

// Generator object $visa 4 results;
$outobject->totaltimeafterreqtotr = microtime(true) - $start;
$i = 0;
// Loop max 3 hits:
foreach($resultobject->timetableresult->ttitem as &$trip){
	if(is_array($trip->segment)){
	  $outobject->timetableresult->ttitem[$i] = $trip;	
  	}
  	else{
  	  $outobject->timetableresult->ttitem[$i]->segment = array($trip->segment);
  	}
	if(strtotime($outobject->timetableresult->ttitem[$i]->segment[0]->departure->datetime) >= strtotime($_GET["date"].'T'.$_GET['departureTime']) ){
  		$i++;
  	}
  	if($i>2){
  		break;
  	}
}


// Get alla agencys:
$agency = file_get_contents('agency.txt');
$agency = preg_split('/\n/',$agency);
$agencylist = array();

foreach($agency as $row)
	{
		$row = preg_split('/,/',$row);
		if(isset($row[0]) AND isset($row[1]))
			{
			$agencylist[trim($row[0])] = trim($row[1]);
			}
	}


// Generate search and total travel time:
foreach($outobject->timetableresult->ttitem as &$trip){
	$trip->search = new stdClass;
	$last = count($trip->segment)-1;
	// Generate travel time;
	$duration = strtotime($trip->segment[$last]->arrival->datetime) - strtotime($trip->segment[0]->departure->datetime);
	$trip->traveltimetotal = date("H:i",$duration-3600);
	$trip->segment[0]->segmentid->carrier->name = $agencylist[$trip->segment[0]->segmentid->carrier->id];
	
	$first = 0;
	if($trip->segment[0]->segmentid->mot->type == "G"){
		$first = 1;
	}
	
	if($trip->segment[$last]->segmentid->mot->type == "G"){
		$last = $last-1;
	}
	if($last >= $first){
	$trip->search->url = ''.
							'from='.$trip->segment[$first]->departure->location->id.
							'&to='.$trip->segment[$last]->arrival->location->id.
							'&date='.preg_replace('/\+/','&departureTime=',urlencode($trip->segment[$first]->departure->datetime)).
							'&arrivalDate='.preg_replace('/\+/','&arrivalTime=',urlencode($trip->segment[$last]->arrival->datetime));
	}

	//  If there is more than one trip.
	if($last > $first){
	// Generate price querys:
		foreach($trip->segment as &$segment){
			$segment->segmentid->carrier->name = $agencylist[$segment->segmentid->carrier->id];
			if($segment->segmentid->mot->type == "G"){
				$segment->price = "0";
			}
			else
			{
				$segment->search->url = ''.
							'from='.$segment->departure->location->id.
							'&to='.$segment->arrival->location->id.
							'&date='.preg_replace('/\+/','&departureTime=',urlencode($segment->departure->datetime)).
							'&arrivalDate='.preg_replace('/\+/','&arrivalTime=',urlencode($segment->arrival->datetime));
			}
		}
	}
}


//Sellers; print $urlstring;
$sellers["VT"][0] 		= "http://api1.yathra.se/prisAPI/vt.php?";
$sellers["BT"][0]		= "http://api1.yathra.se/prisAPI/bt.php?";
$sellers["NSB"][0] 		= "http://api1.yathra.se/prisAPI/nsb.php?";
$sellers["AEX"][0] 		= "http://api1.yathra.se/prisAPI/at.php?";
$sellers["OT"][0] 		= "http://api1.yathra.se/prisAPI/ot.php?";
$sellers["NETTBUSS"][0] = "http://api1.yathra.se/prisAPI/nettbuss.php?";
$sellers["SL"][0] 		= "http://api1.yathra.se/prisAPI/sl.php?";
$sellers["SJ"][0] 		= "http://pi.thure.org:8800/sj/?";
$sellers["HLT"][0] 		= "http://pi.thure.org:8800/hlt/?";
$sellers["SKTR"][0] 	= "http://api1.yathra.se/prisAPI/sktr.php?";
$sellers["SWEBUS"][0] 	= "http://api1.yathra.se/prisAPI/swebus.php?";
$sellers["TIB"][0] 		= "http://api1.yathra.se/prisAPI/tib.php?";
$sellers["Snt"][0] 		= "http://pi.thure.org:8800/snalltaget/?";
$sellers["JLT"][0] 		= "http://pi.thure.org:8800/jlt/?";
$sellers["DTR"][0] 		= "http://pi.thure.org:8800/dtr/?";
$sellers["LTK"][0] 		= "http://pi.thure.org:8800/ltk/?";
$sellers["BTR"][0] 		= "http://pi.thure.org:8800/btr/?";
$sellers["XTR"][0] 		= "http://pi.thure.org:8800/xtr/?";
$sellers["KLT"][0] 		= "http://pi.thure.org:8800/klt/?";
$sellers["MAS"][0] 		= "http://pi.thure.org:8800/mas/?";
/*$sellers["VT"][1] 		= "http://api1.yathra.se/prisAPI/vt.php?";
$sellers["BT"][1]		= "http://api1.yathra.se/prisAPI/bt.php?";
$sellers["NSB"][1] 		= "http://api1.yathra.se/prisAPI/nsb.php?";
$sellers["AEX"][1] 		= "http://api1.yathra.se/prisAPI/at.php?";
$sellers["OT"][1] 		= "http://api1.yathra.se/prisAPI/ot.php?";
$sellers["NETTBUSS"][1] = "http://api1.yathra.se/prisAPI/nettbuss.php?";
$sellers["SL"][1] 		= "http://api1.yathra.se/prisAPI/sl.php?";
$sellers["SJ"][1] 		= "http://[2001:16d8:ff00:125::2]:8800/sj/?";
$sellers["HLT"][1] 		= "http://[2001:16d8:ff00:125::2]:8800/hlt/?";
$sellers["SKTR"][1] 	= "http://api1.yathra.se/prisAPI/sktr.php?";
$sellers["SWEBUS"][1] 	= "http://api1.yathra.se/prisAPI/swebus.php?";
$sellers["TIB"][1] 		= "http://api1.yathra.se/prisAPI/tib.php?";
$sellers["Snt"][1] 		= "http://[2001:16d8:ff00:125::2]:8800/snalltaget/?";
$sellers["VT"][2] 		= "http://api1.yathra.se/prisAPI/vt.php?";
$sellers["BT"][2]		= "http://api1.yathra.se/prisAPI/bt.php?";
$sellers["NSB"][2] 		= "http://api1.yathra.se/prisAPI/nsb.php?";
$sellers["AEX"][2] 		= "http://api1.yathra.se/prisAPI/at.php?";
$sellers["OT"][2] 		= "http://api1.yathra.se/prisAPI/ot.php?";
$sellers["NETTBUSS"][2] = "http://api1.yathra.se/prisAPI/nettbuss.php?";
$sellers["SL"][2] 		= "http://api1.yathra.se/prisAPI/sl.php?";
$sellers["SJ"][2] 		= "http://[2001:16d8:ff00:2cb::2]:8800/sj/?";
$sellers["HLT"][2] 		= "http://[2001:16d8:ff00:2cb::2]:8800/hlt/?";
$sellers["SKTR"][2] 	= "http://api1.yathra.se/prisAPI/sktr.php?";
$sellers["SWEBUS"][2] 	= "http://api1.yathra.se/prisAPI/swebus.php?";
$sellers["TIB"][2] 		= "http://api1.yathra.se/prisAPI/tib.php?";
$sellers["Snt"][2] 		= "http://[2001:16d8:ff00:2cb::2]:8800/snalltaget/?";
*/

$outobject->totaltimebeforegenerate = microtime(true) - $start;

//create the multiple cURL handle
$curlmultihande = curl_multi_init();
$running=null;

$server = 0;
//do all the CURL requests;
foreach($outobject->timetableresult->ttitem as &$trip){
	foreach($sellers as $name => $seller){
	
		if(isset($trip->search->url)){
			$trip->search->responce[$name]->handle = curl_init($seller[$server].$trip->search->url);
			curl_setopt($trip->search->responce[$name]->handle,CURLOPT_RETURNTRANSFER,TRUE);
			curl_multi_add_handle($curlmultihande,$trip->search->responce[$name]->handle);
			}
		}

	if(count($trip->segment)>1){
		foreach($trip->segment as &$segment){
			if($segment->segmentid->mot->type != "G"){
				foreach($sellers as $name => $seller){
					if(isset($segment->search->url)){
						$segment->search->responce[$name]->handle = curl_init($seller[$server].$segment->search->url);
						curl_setopt($segment->search->responce[$name]->handle,CURLOPT_RETURNTRANSFER,TRUE);
						curl_multi_add_handle($curlmultihande,$segment->search->responce[$name]->handle);
					}
				}	
			}
		}
	}
	do {
    curl_multi_exec($curlmultihande,$running);
   	// Lets try not sleeping. sleep(1);
	} while($running > 0);

	$server++;
	if ($server > 2){
		$server = 0;
	}
	$server = 0;
}

$outobject->totaltimebeforeask = microtime(true) - $start;

//execute the handles this will take time!
do {
    curl_multi_exec($curlmultihande,$running);
   // Lets try not sleeping. sleep(1);
} while($running > 0);

$outobject->totaltimedoneask = microtime(true) - $start;

//Get responce all the CURL requests;
foreach($outobject->timetableresult->ttitem as &$trip){
	foreach($trip->search->responce as &$responce){
		if(isset($responce->handle)){
			$responce->pricedata = json_decode(curl_multi_getcontent($responce->handle));
			$responce->pricedatatime = strval(curl_getinfo($responce->handle, CURLINFO_TOTAL_TIME));
			curl_close($responce->handle);
			$responce->handle = "";
		}
	}

	foreach($trip->segment as &$segment){
		if(isset($segment->search->responce)){
			foreach($segment->search->responce as &$responce){
				if(isset($responce->handle)){
					$responce->pricedata = json_decode(curl_multi_getcontent($responce->handle));
					$responce->pricedatatime = strval(curl_getinfo($responce->handle, CURLINFO_TOTAL_TIME));
					curl_close($responce->handle);
					$responce->handle = "";
				}
			}
		}
	}
}

//close all the handles
curl_multi_close($curlmultihande);
$outobject->totaltimeafterreq = time() - $start;

//Calculate the lowest price for the section:
foreach($outobject->timetableresult->ttitem as &$trip){
$total = 0;
$totalseller = "";
	foreach($trip->segment as &$segment){
		$lowest = 13371337;
		$seller = "";
		$url = "";
		
		// IF the section has a 0 price (Walking set price to 0;)
		if(isset($segment->price)){
			if($segment->price == "0"){
			$lowest = 0;
			$seller = "Walk";
			}
		}
		if (isset($segment->search->responce)){
			foreach($segment->search->responce as &$responce){
				if(isset($responce->pricedata->price)){
					if(floatval($responce->pricedata->price)<$lowest AND floatval($responce->pricedata->price) > 0){
						$lowest = floatval($responce->pricedata->price);
						$seller = $responce->pricedata->sellername;
						$url = $responce->pricedata->url;
					}
				}
			}
		}
		$segment->lowestprice = strval($lowest);
		$segment->lowestpriceseller->name = $seller;
		$segment->lowestpriceseller->url = $url;
		$total = $total + $lowest;
		$totalseller = $totalseller.$seller." ";
	}

	$trip->price = strval($total);
	$trip->sellername = "Flera";
	$trip->url = "#";
	
	$lowest = 13371337;
	foreach($trip->search->responce as &$responce){
		if(isset($responce->pricedata->price)){
			if(floatval($responce->pricedata->price)<$lowest AND floatval($responce->pricedata->price) > 0){
				$lowest = floatval($responce->pricedata->price);
				$seller = $responce->pricedata->sellername;
				$url = $responce->pricedata->url;
			}
		}
	}
	if ($lowest < $total){
		$trip->price = strval($lowest);
		$trip->sellername = $seller;
		$trip->url = $url;
	}
}

$outobject->totaltime = microtime(true) - $start;

print json_encode($outobject);
// Stor to cache:
file_put_contents('findercache/'.$cachefile, json_encode($outobject));

print "\n";
?>
