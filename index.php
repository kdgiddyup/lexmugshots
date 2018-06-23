<?php
require_once("simple_html_dom.php");
header("Access-Control-Allow-Origin: *");

// allow script to run for at least 5 minutes, for larger record requests
set_time_limit(0);

/**
 * Search and display recent confinement data
 * for Lexington County Detention Center
 *
 * LICENSE: None.
 *
 * @author      Kelly Davis <kellydavis1974 at gmail dot com> 
 * @version     Release 1.0
 * @since       File available since Release 1.0
 */


/*  Declarations  */
date_default_timezone_set('America/New_York');
$data = array();
$file = dirname(__FILE__).'/cache/cache.txt';
$start= -$_GET["start"];
$end = -$_GET["end"];
$sources = array(
	0 => array(
        'agency'	=> 'Lexington County Sheriff\'s Department',
        'main'      => 'http://jail.lexingtonsheriff.net/jailinmates.aspx',
        'list'		=> 'http://jail.lexingtonsheriff.net/jqHandler.ashx?op=s',
        'detail'    => 'http://jail.lexingtonsheriff.net/InmateDetail.aspx',
        'mug'       => 'http://jail.lexingtonsheriff.net/Mug.aspx',
        'cookie'    => dirname(__FILE__).'/tmp/lexmugs.txt'
	)
);
if (!file_exists('tmp'))
    mkdir('tmp');
if (!file_exists('cache'))
    mkdir('cache');


// deploy: change -2 to -90
$startTarget = strtotime("-90 days 0:0:0", strtotime('now'));
$endTarget = strtotime("0 days 0:0:0", strtotime('now'));
$userStart = strtotime("$start days 0:0:0", strtotime('now'));
$userEnd = strtotime("$end days 0:0:0", strtotime('now'));

/* debug */
//$test = array();


/*
Framework for caching set-up:

    
if (!file_exists($file) || time()-filemtime($file) > 2 * 3600) {
    // execute existing code set to 90 days
    file_put_contents($file,$json);
}

$data = json_decode(file_get_contents($file),true);
foreach( $data as $k=>$v){
    if ($v['disp_arrest_date'] >= $startTarget && $v['disp_arrest_date'] < $end_target) {

    }
}
*/


/*  App  */

// does the cache file exist, or is it stale 
// (let's say, more than 2 hours old?
if (!file_exists($file) || time()-filemtime($file) > 2*3600 ){
$i = 0;
foreach ( $sources as $source )
{
    /* curl init */
    /* debug */
    // logging headers
    //$curlLog = fopen('./logs/curlLog.txt','w');
    
    /* remove any existing cookie */  
    {
        if ( file_exists($source['cookie']) ) {
            unlink($source['cookie']);
        }
            
    } 

    /* GET homepage to retrieve session and form state values */
    $ch = curl_init($source['main']);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_REFERER => $source['main'],
        CURLOPT_COOKIEJAR => $source['cookie'],
        CURLOPT_COOKIEFILE => $source['cookie'],
    ));

    $home = curl_exec($ch);
    curl_close($ch);

    if (!$home)
        $data[$i]->scrapeError = curl_error($ch);

    // POST to data handler to retrieve initial list of detainees
    $chList = curl_init($source['list']);
    curl_setopt_array( $chList, array(
        CURLOPT_REFERER => $source['main'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_COOKIEJAR => $source['cookie'],
        CURLOPT_COOKIEFILE => $source['cookie'],
        CURLOPT_POSTFIELDS => 't=ii&_search=false&page=1&rows=10000&sidx=date_arr&sord=desc&nd=1525363643699',

        /* debug options  */
        // CURLOPT_VERBOSE => true,
        // CURLOPT_STDERR => $curlLog, 
        // CURLOPT_ENCODING => "",
        )
        );
    $list = curl_exec($chList);
    if (!$list) 
        $data[$i]->listError = curl_error($chList);
    curl_close($chList); 
    $list = json_decode($list);

    /* for debug */
    //$headerSent = curl_getinfo($ch, CURLINFO_HEADER_OUT);

    // suppress error output for html loading
    libxml_use_internal_errors(true);
    
    // $postHome will hold our post variables for curl calls
    $inputsHome = array();
    $postHome = array();
    $dom = new DOMDocument();
    $dom->loadHTML($home);
    $inputs = $dom->getElementsByTagName('input');
    foreach ( $inputs as $input ) 
        $postHome[$input->getAttribute('name')] = $input->getAttribute('value');   
    
    $data[$i]['agency'] = $source['agency'];
    $data[$i]['url'] = $source['main'];
    $data[$i]['success'] = true;
    
    /* debug */
    // $data[$i]['cookie'] = $source['cookie'];
    
	$data[$i]['data'] = array();
	$j = 0;
	foreach ( $list->rows as $inmate )
	{
        $booked = strtotime($inmate->disp_arrest_date, strtotime('now'));
	 	//$timestamp = strtotime( $booked->setTime(0,0,0)->format('Y-m-d H:i:s') );

		// Only process inmates for the date target window.
        if ($booked >= $startTarget && $booked <= $endTarget) {
            // attempt to get mug

            /* for debug - write headers for mug request */
            // $mugLog = fopen('./logs/mugLog.txt','w');
            // $detailLog = fopen('./logs/detailLog.txt','w');
            
            // update inmate number in hidden form element and process $post array to string
            $postHome['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $inmate->my_num;
            
            $temp_string = array();
            foreach ($postHome as $key => $value) {
                $temp_string[] = $key . "=" . urlencode($value);
            }
            // Bring in array elements into string
            $postHomeString = implode('&', $temp_string);
            

            // POST to get inmate detail;
            // event validator, event state and event generator are passed in the $post_string
            $chDet = curl_init($source['main']);
            curl_setopt_array($chDet, array(
                    CURLOPT_REFERER => $source['main'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPGET => true,
                    CURLOPT_COOKIEJAR => $source['cookie'],
                    CURLOPT_COOKIEFILE => $source['cookie'],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => true,     // Follow redirects
                    CURLOPT_MAXREDIRS => 4,
                    CURLOPT_POSTFIELDS => $postHomeString,
                    CURLOPT_HTTPHEADER => array('Expect:  '),
                    //CURLOPT_HEADER => true,
                    /* for debug - more info */
                    //CURLINFO_HEADER_OUT => true,
                    // CURLOPT_VERBOSE => true,
                    // CURLOPT_STDERR => $detailLog
                ));
                
                
            $detail = curl_exec($chDet);
            if (!$detail) {
                $inmate->detailError = curl_error($chDet);
            }

            $redirectUrl = curl_getinfo($chDet)['url'];
            
            // debug - export headers to inmate object for examination in console
            // $inmate->curlInfo = curl_getInfo($chdet);
            curl_close($chDet);
            
            // let's take a look at the returned HTML
            // but only if it's not an empty string
            if ( !empty($detail)) {
                $detailDom = new DOMDocument();
                $detailDom->loadHTML($detail);
            
                // scrape some arrest details not available in the main list
                $detailDom2 = new simple_html_dom();
                $detailDom2->load($detail);
                
                $relDate = trim($detailDom2->find("#mainContent_CenterColumnContent_lblReleaseDate", 0)->plaintext);
                $inmate->relDate = $relDate ? $relDate : "Not listed";

                $courtNext = trim($detailDom2->find("#mainContent_CenterColumnContent_lblNextCourtDate", 0)->plaintext);
                $inmate->courtNext = $courtNext ? $courtNext : "Not listed";
                
                $totalBond = trim($detailDom2->find("#mainContent_CenterColumnContent_lblTotalBoundAmount", 0)->plaintext);
                $inmate->totalBond = $totalBond ? $totalBond : "Not listed";
                
                $r=0; // row index
                foreach ($detailDom2->find("#mainContent_CenterColumnContent_dgMainResults tr") as $rows) {
                    if ($r === 0) { // header row
                        $headers = $rows->find("td");
                    } else {  // data rows
                        $item = new stdClass();
                        $c=0; // column index to match headers
                        foreach ($rows->find("td") as $datum) {
                            $label = trim($headers[$c]->plaintext);
                            $item->$label = trim($datum->plaintext);
                            $c++; // increment column
                        }
                        $inmate->charges[] = $item;
                    }
                    $r++; // increment row index
                };
                            
                // clean up memory
                $detailDom2->clear();
                unset($detailDom2);
                    
                // store detail event state, validation and generator strings from this document
                $postDetail = array();

                $inputs = $detailDom->getElementsByTagName('input');
                foreach ($inputs as $input) {
                    $postDetail[$input->getAttribute('name')] = $inputValue = $input->getAttribute('value');
                }

                // update inmate number in hidden form element and process $post array to string
                $postDetail['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $inmate->my_num;
                $temp_string = array();
                foreach ($postDetail as $key => $value) {
                    $temp_string[] = $key . "=" . urlencode($value);
                }
                // Bring in array elements into string
                $postDetailString = implode('&', $temp_string);
                
                // clean up
                unset($detailDom);

                /* debug: output some curl strings for this inmate */
                // $inmate->mugQuery = $postDetail;
                // $inmate->detailDom = $detail;
                // $inmate->redirect = $redirectUrl;
                
                // make call to mug endpoint with new redirect URL set as referer
                $chMug = curl_init($source['mug']);
                curl_setopt_array($chMug, array(
                    CURLOPT_REFERER => $redirectUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPGET => true,
                    CURLOPT_COOKIEJAR => $source['cookie'],
                    CURLOPT_COOKIEFILE => $source['cookie'],
                    CURLOPT_FOLLOWLOCATION => true,     // Follow redirects
                    CURLOPT_MAXREDIRS => 4,
                    CURLOPT_POSTFIELDS => $postDetailString,
                    CURLOPT_HTTPHEADER => array('Expect: '),
                    
                    /* for debug - more info */
                    //CURLINFO_HEADER_OUT => true,
                    // CURLOPT_HEADER => false,
                    // CURLOPT_VERBOSE => true,
                    // CURLOPT_STDERR => $mugLog
                ));

                $raw_mug = curl_exec($chMug);
                if (!$raw_mug) {
                    $inmate->mugError = curl_error($chMug);
                    $inmate->image = "http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg";
                }
                else {
                    // make the file instead of sending it
                    // $f = fopen('./mugs/mug'.$inmate->my_num.'.jpg', 'wb');
                    // fwrite($f, $raw_mug);
                    // fclose($f);

                    // process image string
                    $mug = imagecreatefromstring($raw_mug);
                    unset($raw_mug);

                    if (!$mug) {
                        $img_data = "http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg";
                    } 
                    else {
                        // start buffering and catch image output
                        ob_start();
                        imagejpeg($mug);
                        $contents =  ob_get_contents();
                        $img_data = "data:image/jpg;base64,".base64_encode($contents);
                        ob_end_clean();
                        imagedestroy($mug);
                        } 
                    $inmate->image = $img_data;
                    unset($img_data);
                    }         
                    curl_close($chMug);
                    unset($chMug);
                    
                    /* remove superfluous to reduce processing
                    load on client side */
                    $inmate->dob = explode(" ", $inmate->dob)[0];
                /* debug */
                // $inmate->cookie = $source['cookie'];
                //$test[] = $inmate;
                
                $data[$i]['data'][] = $inmate;
            }
		}
	}
	$i++;
};
// cache this 90 days' worth of data
$data_to_cache = json_encode($data[0],false);
file_put_contents($file,$data_to_cache);

// send back only data requested
$inmateData = $data[0]['data'];
$data[0]['data']= Array();

foreach ($inmateData as $inmate) {
    if (strtotime($inmate->{'disp_arrest_date'}) >= $userStart && strtotime($inmate->{'disp_arrest_date'}) < $userEnd) {
        $data[0]['data'][] = $inmate;
    };
};
header('Content-Type: application/json');
echo json_encode( $data[0] );
} // end cache does not exist condition
// the cached file exists and is young
else {
    // get the cached data
    $data = json_decode(file_get_contents($file));
    // flag this as sourced from cache
    $data->cached = true;
    $data->start = date('n/j/Y',$userStart);
    $data->end = date('n/j/Y',$userEnd);
    // extract only the data needed by the user
    $inmateData = $data->data;
    $data->data = Array();
    foreach( $inmateData as $inmate){
        if ( strtotime($inmate->{'disp_arrest_date'}) >= $userStart && strtotime($inmate->{'disp_arrest_date'}) <= $userEnd) {
            $data->data[] = $inmate;
            };
    };
    // Return data in JSON format.
    header('Content-Type: application/json');
    echo json_encode( $data );    
}
/* debug */
//echo json_encode( $test );