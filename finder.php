<?php
if(isset($_GET['debugg'])){
error_reporting(E_ALL);
ini_set('display_errors', 'on');
}
set_time_limit(120);

$start = microtime(true);
header('Content-type: application/json; charset=UTF-8');

// Ceck input data:
if (preg_match("/(2[0-3]|[01][0-9]):[0-5][0-9]/", $_GET['departureTime']) == FALSE){
	die('{"error":"Error in departure time shhould be: HH:MM , but is: '.$_GET['departureTime'].' "}');
	} 
if (preg_match("/201[0-9]-(0[0-9]|[1][0-2])-[0-3][0-9]/", $_GET['departureDate']) == FALSE){
	die('{"error":"Error in departureDate shhould be: YYYY-MM-DD , but is: '.$_GET['departureDate'].' "}');
	} 
if (preg_match("/(2[0-3]|[01][0-9]):[0-5][0-9]/", $_GET['arrivalTime']) == FALSE){
	die('{"error":"Error in arrival time shhould be: HH:MM , but is: '.$_GET['arrivalTime'].' "}');
	} 
if (preg_match("/201[0-9]-(0[0-9]|[1][0-2])-[0-3][0-9]/", $_GET['arrivalDate']) == FALSE){
	die('{"error":"Error in arrivalDate shhould be: YYYY-MM-DD , but is: '.$_GET['arrivalDate'].' "}');
	}
if(strtotime($_GET["arrivalDate"].'T'.$_GET['arrivalTime']) < strtotime($_GET["departureDate"].'T'.$_GET['departureTime'])){
	die('{"error":"First deparature is after last arrival"}');
	}
	
if(isset($_GET['avgnr'])==false){
  $_GET['avgnr'] = 0;
}
else{
 $_GET['avgnr'] = intval($_GET['avgnr']);
}

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

// Make search url:
$url = 'https://api.trafiklab.se/samtrafiken/resrobot/Search.json'.
  '?apiVersion=2.1'.
  '&coordSys=WGS84'.
  '&fromId='.$_GET['from'].
  '&toId='.$_GET['to'].
  '&date='.$_GET['departureDate'].
  '&time='.urlencode($_GET['departureTime']).
  '&searchType=F'.
  '&arrival=false'.
  '&key='.file_get_contents('../resrobot.key');
$md5url = md5($url);

/*
&searchType
F Standard search. ResRobot will find the fastest trip using all possible transport modes.
T Train and local public transport. Express buses are not included in the search.
B Express bus and local public transport. Regional trains and speed trains are not included in the search.
*/

/*
'&mode1=true'. //Speed train. X2000 and Arlanda Express
'&mode2=true'. //Train (except speed trains)
'&mode3=true'. //Bus (except express buses)
'&mode4=ture'. //Boat
'&mode5=ture'); //Express bus
*/

$run = true;
if(file_exists('findercache/'.$md5url)){
	$nu = filemtime('findercache/'.$md5url);
	if(microtime(true)-$nu<10000){
		$resultobject = json_decode(file_get_contents('findercache/'.$md5url));
	 	$run = false;
	}
}

if($run){
  // Run search:
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $resultstring = curl_exec($ch);
  curl_close($ch);
  
    //remove specialChars and make object:
	$resultstring = str_replace('"#','"',$resultstring);
	$resultstring = str_replace('"@','"',$resultstring);
	$resultobject = json_decode(utf8_encode($resultstring));
  	file_put_contents('findercache/'.$md5url, json_encode($resultobject));
}

if(isset($_GET['debugg'])){
  print 'Tidtabellsdata: ';
  print_r($resultobject);
}

// Generate out object:
$outobject = new stdClass;
$outobject->totaltimeaftercache = microtime(true) - $start;
$outobject->timetableresult = new stdClass;
$outobject->timetableresult->ttitem = array();

// Generator object $visa 3 results;
$outobject->totaltimeafterreqtotr = microtime(true) - $start;
$i = 0;

// Loop max 3 hits:
foreach($resultobject->timetableresult->ttitem as &$trip){
	if(is_array($trip->segment) == false){
  	  $trip->segment = array($trip->segment);
  	}
  	
  	$sista = end($trip->segment);
	if(
	strtotime($trip->segment[0]->departure->datetime) >= strtotime($_GET["departureDate"].'T'.$_GET['departureTime'])
	&&
	strtotime($sista->arrival->datetime) <= strtotime($_GET["arrivalDate"].'T'.$_GET['arrivalTime'])
	){
	
	 if($i == $_GET['avgnr']){
	    $outobject->timetableresult->ttitem[0] = $trip;
	 }
  		$i++;
  	}
}

//print_r($resultobject);

if($i==0){
	die('{"error":"No trips found in time interval"}');
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
			//$segment->segmentid->carrier->name = $agencylist[$segment->segmentid->carrier->id];
			if($segment->segmentid->mot->type == "G"){
				$segment->price = "0";
			}
			else
			{
				$segment->search = new stdClass;
				$segment->search->url = ''.
							'from='.$segment->departure->location->id.
							'&to='.$segment->arrival->location->id.
							'&date='.preg_replace('/\+/','&departureTime=',urlencode($segment->departure->datetime)).
							'&arrivalDate='.preg_replace('/\+/','&arrivalTime=',urlencode($segment->arrival->datetime));
			}
		}
	}
}

$outobject->totaltimeafterurls = microtime(true) - $start;

//Sellers; print $urlstring;
$sellers["NSB"][0] 		= "http://api.yathra.se:8800/nsb/?";
$sellers["VT"][0] 		= "http://api.yathra.se:8800/vt/?";
$sellers["AEX"][0] 		= "http://api.yathra.se:8800/at/?";
$sellers["TIB"][0] 		= "http://api.yathra.se:8800/tib/?";
$sellers["OT"][0] 		= "http://api.yathra.se:8800/ot/?";
$sellers["SKTR"][0] 		= "http://api.yathra.se:8800/sktr/?";
$sellers["NETTBUSS"][0]		= "http://api.yathra.se:8800/nettbuss/?";
$sellers["SJ"][0] 		= "http://api.yathra.se:8800/sj/?";
$sellers["HLT"][0] 		= "http://api.yathra.se:8800/hlt/?";
$sellers["SWEBUS"][0] 		= "http://api.yathra.se:8800/swebus/?";
$sellers["BT"][0]		= "http://api.yathra.se:8800/bt/?";
$sellers["Snt"][0] 		= "http://api.yathra.se:8800/snalltaget/?";
$sellers["JLT"][0] 		= "http://api.yathra.se:8800/jlt/?";
$sellers["DTR"][0] 		= "http://api.yathra.se:8800/dtr/?";
$sellers["LTK"][0] 		= "http://api.yathra.se:8800/ltk/?";
$sellers["BTR"][0] 		= "http://api.yathra.se:8800/btr/?";
$sellers["XTR"][0] 		= "http://api.yathra.se:8800/xtr/?";
$sellers["KLT"][0] 		= "http://api.yathra.se:8800/klt/?";
$sellers["MAS"][0] 		= "http://api.yathra.se:8800/mas/?";
$sellers["SL"][0] 		= "http://api1.yathra.se/prisAPI/sl.php?";
/*$sellers["VT"][1] 	= "http://api1.yathra.se/prisAPI/vt.php?";
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

//create the multiple cURL handle
$curlmultihande = curl_multi_init();
$running=null;



$server = 0;
//do all the CURL requests;
foreach($outobject->timetableresult->ttitem as &$trip){
	foreach($sellers as $name => $seller){
	
		if(isset($trip->search->url)){
			if(isset($trip->search->responce)==False){
				$trip->search->responce = array();
				}
			$trip->search->responce[$name]->chandle = curl_init($seller[$server].$trip->search->url);
			curl_setopt($trip->search->responce[$name]->chandle,CURLOPT_RETURNTRANSFER,TRUE);
			curl_setopt($trip->search->responce[$name]->chandle, CURLOPT_CONNECTTIMEOUT ,20); 
			curl_setopt($trip->search->responce[$name]->chandle, CURLOPT_TIMEOUT, 20);
			curl_multi_add_handle($curlmultihande,$trip->search->responce[$name]->chandle);
			curl_multi_exec($curlmultihande, $running);
			}
		}

	if(count($trip->segment)>1){
		foreach($trip->segment as &$segment){
			if($segment->segmentid->mot->type != "G"){
				foreach($sellers as $name => $seller){
					if(isset($segment->search->url)){
						if(isset($segment->search->responce)==False){
							$segment->search->responce = array();
						}
						$segment->search->responce[$name]->chandle = curl_init($seller[$server].$segment->search->url);
						curl_setopt($segment->search->responce[$name]->chandle,CURLOPT_RETURNTRANSFER,TRUE);
						curl_setopt($segment->search->responce[$name]->chandle, CURLOPT_CONNECTTIMEOUT ,20); 
						curl_setopt($segment->search->responce[$name]->chandle, CURLOPT_TIMEOUT, 20);
						curl_multi_add_handle($curlmultihande,$segment->search->responce[$name]->chandle);
						curl_multi_exec($curlmultihande, $running);
					}
				}	
			}
		}
	}
	
	do {
	curl_multi_exec($curlmultihande, $running);
	} while($running > 0);	
	$server++;
	if ($server > 2){
		$server = 0;
	}
	$server = 0;
}

$outobject->totaltimedoneask = microtime(true) - $start;

//Get responce all the CURL requests;
foreach($outobject->timetableresult->ttitem as &$trip){
	foreach($trip->search->responce as &$responce){
		if(isset($responce->chandle)){
			$responce->pricedata = json_decode(curl_multi_getcontent($responce->chandle));
			$responce->pricedatatime = strval(curl_getinfo($responce->chandle, CURLINFO_TOTAL_TIME));
			$responce->chandle = '';
		}
	}

	foreach($trip->segment as &$segment){
		if(isset($segment->search->responce)){
			foreach($segment->search->responce as &$responce){
				if(isset($responce->chandle)){
					$responce->pricedata = json_decode(curl_multi_getcontent($responce->chandle));
					$responce->pricedatatime = strval(curl_getinfo($responce->chandle, CURLINFO_TOTAL_TIME));
					$responce->chandle = '';
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
		
		// IF the section has a 0 price (Walking sets price to 0;)
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
		$segment->lowestpriceseller = new stdClass;
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
