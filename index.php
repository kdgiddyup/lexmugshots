<?php
require_once("simple_html_dom.php");
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
$data = array();
$start= -$_GET["start"];
$end = -$_GET["end"];
$sources = array(
	0 => array(
        'agency'	=> 'Lexington County Sheriff\'s Department',
        'main'      => 'http://jail.lexingtonsheriff.net/jailinmates.aspx',
        'list'		=> 'http://jail.lexingtonsheriff.net/jqHandler.ashx?op=s',
        'detail'    => 'http://jail.lexingtonsheriff.net/InmateDetail.aspx',
        'mug'       => 'http://jail.lexingtonsheriff.net/Mug.aspx',
        'cookie'    => './tmp/lexmugs.txt'
	)
);
$startTarget = strtotime("$start days 0:0:0", strtotime('now'));
$endTarget = strtotime("$end days 0:0:0", strtotime('now'));

/* debug */
$test = array();

/*  App  */

function rrmdir($src) {
    $dir = opendir($src);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if ( is_dir($full) ) {
                rrmdir($full);
            }
            else {
                unlink($full);
            }
        }
    }
    closedir($dir);
    rmdir($src);
}
$i = 0;
foreach ( $sources as $source )
{
    /* curl init */
    /* debug */
    // logging headers
    $curlLog = fopen('./logs/curlLog.txt','w');

    
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
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => $curlLog, 
        //CURLOPT_ENCODING => "",
        )
        );
    $list = curl_exec($chList);
    
    /* for debug */
    //$headerSent = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    
    curl_close($chList);



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
       
    if (!$list) {
        $list = curl_error($ch);
    }
    else {
        $list = json_decode($list);
    }
    
    $data[$i]['agency'] = $source['agency'];
    $data[$i]['url'] = $source['main'];
    $data[$i]['success'] = true;
    
	$data[$i]['data'] = array();
	$j = 0;
	foreach ( $list->rows as $inmate )
	{
        $booked = strtotime($inmate->disp_arrest_date, strtotime('now'));
	 	//$timestamp = strtotime( $booked->setTime(0,0,0)->format('Y-m-d H:i:s') );

		// Only process inmates for the date target window.
		if ( $booked >= $startTarget && $booked <= $endTarget)
		{
            // attempt to get mug

            /* for debug - write headers for mug request */
            $mugLog = fopen('./logs/mugLog.txt','w');
            $detailLog = fopen('./logs/detailLog.txt','w');
            
            // update inmate number in hidden form element and process $post array to string
            $postHome['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $inmate->my_num;
            
            $temp_string = array();
            foreach ( $postHome as $key => $value )
                    $temp_string[] = $key . "=" . urlencode($value);
            // Bring in array elements into string
            $postHomeString = implode('&', $temp_string);
            

            // POST to get inmate detail; 
            // event validator, event state and event generator are passed in the $post_string
            $chdet = curl_init($source['main']);
                curl_setopt_array( $chdet, array(
                    CURLOPT_REFERER => $source['main'],
                    //CURLOPT_POST => true,
                    //CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'],
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
                    CURLOPT_VERBOSE => true,
                    CURLOPT_STDERR => $detailLog
                ));
                
                
            $detail = curl_exec($chdet);
            $redirectUrl = curl_getinfo($chdet)['url'];
            
            // debug - export headers to inmate object for examination in console
            // $inmate->curlInfo = curl_getInfo($chdet);
            curl_close($chdet);

            // let's take a look at the returned HTML
           
            $detailDom = new DOMDocument();
            $detailDom->loadHTML($detail);
           
            // scrape some arrest details not available in the main list
            $detailDom2 = new simple_html_dom();
            $detailDom2->load($detail);
            $inmate->relDate = trim($detailDom2->find("#mainContent_CenterColumnContent_lblReleaseDate",0)->plaintext);
            $inmate->courtNext = trim($detailDom2->find("#mainContent_CenterColumnContent_lblNextCourtDate",0)->plaintext);
            $inmate->totalBond = trim($detailDom2->find("#mainContent_CenterColumnContent_lblTotalBoundAmount",0)->plaintext);
            
            $item = new stdClass();
            // traverse rows
            foreach ($detailDom2->find("#mainContent_CenterColumnContent_dgMainResults tr") as $detailRow){
                $item->charge = trim($detailRow->find('td',0)->plaintext);
                $item->status = trim($detailRow->find('td',1)->plaintext);
                $item->docket = trim($detailRow->find('td',2)->plaintext);
                $item->bond = trim($detailRow->find('td',3)->plaintext);
                $inmate->charges[] = $item;
            }
            

            // clean up memory
            $detailDom2->clear();
            unset($detailDom2);
                
             // store detail event state, validation and generator strings from this document
            $postDetail = array();

            $inputs = $detailDom->getElementsByTagName('input');
            foreach ( $inputs as $input )
                $postDetail[$input->getAttribute('name')] = $inputValue = $input->getAttribute('value');    

            // update inmate number in hidden form element and process $post array to string
            $postDetail['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $inmate->my_num;        
            $temp_string = array();
            foreach ( $postDetail as $key => $value )
                    $temp_string[] = $key . "=" . urlencode($value);
            // Bring in array elements into string
            $postDetailString = implode('&', $temp_string);
            
            /* debug: output some curl strings for this inmate */
            $inmate->mugQuery = $postDetail;
            $inmate->detailDom = $detail;
            $inmate->redirect = $redirectUrl;
            
            // make call to mug endpoint with new redirect URL set as referer
            $chmug = curl_init( $source['mug']);
            curl_setopt_array( $chmug, array(
                CURLOPT_REFERER => $source['main'],
                CURLOPT_RETURNTRANSFER => true,
                //CURLOPT_POST => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_COOKIEJAR => $source['cookie'],
                CURLOPT_COOKIEFILE => $source['cookie'],
                CURLOPT_FOLLOWLOCATION => TRUE,     // Follow redirects
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_POSTFIELDS => $postDetailString,
                CURLOPT_HTTPHEADER => array('Expect: '),
                
                /* for debug - more info */
                //CURLINFO_HEADER_OUT => true,
                CURLOPT_HEADER => false,
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => $mugLog
            ));

            $raw_mug = curl_exec($chmug);
            // $inmate->mugInfo = curl_getinfo($chmug);
            curl_close($chmug);

            

            if (!$raw_mug) {
                $inmate->image = "http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg";
            }
            else {
                // make the file instead of sending it
                // $f = fopen('./mugs/mug'.$inmate->my_num.'.jpg', 'wb');
                // fwrite($f, $raw_mug);
                // fclose($f);

                // process image string
                $mug = imagecreatefromstring($raw_mug);
                if ($mug !== false) {
                    // start buffering and catch image output
                    ob_start();
                    imagejpeg($mug);
                    $contents =  ob_get_contents();
                    $img_data = "data:image/jpg;base64,".base64_encode($contents);
                    ob_end_clean();
                    imagedestroy($mug);
                } 
                else {
                    $img_data = "http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg";
                    } 
                $inmate->image = $img_data;
                }
            
                
         
    //         /* debug */
             //$test[] = $inmate;
             $data[$i]['data'][] = $inmate;

	// 		$name_last = (string) $inmate->nl;
	// 		$name_first = (string) $inmate->nf;
	// 		$name_middle = (string) $inmate->nm;

    //         $data[$i]['data'][$j]['last'] = $name_last;
    //         $data[$i]['data'][$j]['first'] = $name_first;
    //         $data[$i]['data'][$j]['middle'] = $name_middle;


	// 		// Address.
	// 		$data[$i]['data'][$j]['city'] = (string) $inmate->csz;

	// 		// Race
	// 		$data[$i]['data'][$j]['race'] = (string) explode(" / ",$inmate->racegen)[0];
            
    //         // Gender
	// 		$data[$i]['data'][$j]['sex'] = (string) explode(" / ",$inmate->racegen)[1];

    //         // Date of birth. Needs a little wrangling because of two-digit pre-epoch years;
    //         // strtotime() is not reliable since it maps values between 0-69 to 2000-2069 
    //         // and values between 70-100 to 1970-2000.
    //         // Helped here by fact that we also get their age so we can just subtract 
    //         // age from current year to yield birth year
    //         $dob = explode("/", $inmate->dob);
    //         $inmate->dob = $dob[0]."/".$dob[1]."/".(date("Y") - $inmate->age);
    //         $data[$i]['data'][$j]['dob'] = (string) date("M j, Y", strtotime($inmate->dob));
            
    //         // Age.
    //         $data[$i]['data'][$j]['age'] = (string) $inmate->age;

	// 		// Height.
	// 		$data[$i]['data'][$j]['height'] = (string) $inmate->ht;

	// 		// Weight.
	// 		$data[$i]['data'][$j]['weight'] = (string) $inmate->wt;

	// 		// Mugshot: make sure it's an actual image file.
	// 		$url_mug = (string) $inmate->image1['src'];
	// 		if ( preg_match('/\.(jpeg|jpg|png|gif)$/i', $url_mug) )
	// 		{
	// 			$data[$i]['data'][$j]['photo'] = $url_mug;
	// 		}

    //         // arrest info
    //         // is there any?
    //         if ( array_key_exists("ar", $inmate)) {
    //             $data[$i]['data'][$j]['arrestinfo'][]['present'] = (boolean) true;
    //             $data[$i]['data'][$j]['arrestinfo'][] = $inmate->ar;
            
    //         }
	// 		// Booking number.
	// 		$data[$i]['data'][$j]['booknum'] = (string) $inmate->bn;
            
    //         // Booking date and time.
	// 		$data[$i]['data'][$j]['booktime'] = (string) date("g:i a, M j, Y",strtotime($inmate->bd));

    //         // Release date
	// 		$data[$i]['data'][$j]['reldate'] = ($inmate->dtout == "Confined") ? (string) "Confined" : (string) date("g:i a",strtotime($inmate->tmout)).", ".date("M j, Y",strtotime($inmate->dtout));
            
    //         // Inmate number.
	// 		$data[$i]['data'][$j]['inmatenum'] = (string) $inmate->nn;

        //     $j++;
		}
	}
	$i++;
}

// Return data in JSON format.
header('Content-Type: application/json');
echo json_encode( $data[0] );
/* debug */
//echo json_encode( $test );
//echo json_encode( $raw_data );
?>