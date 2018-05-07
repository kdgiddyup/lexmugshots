<?php defined('SYSPATH') or die('No direct script access.');



/**
 * Muskegon Scrape
 *
 * This scrape is using JS and a popup to try and validate that you're a human being. To get around this,
 * we are going to hit the ajax urls directly
 *
 *  https://mcd911.net/p2c/jqHandler.ashx?op=s
 *
 * @package   Bandit
 * @author    Clay McIlrath
 * @see       http://www.mcd911.net/p2c/jailinmates.aspx
 */
class Model_State_Michigan_Muskegon extends Model_Bandit
{
    protected $name     = 'muskegon';   // name of scrape goes here
    protected $county   = 'muskegon';    // if it is a single county, put it here, otherwise remove this property
    protected $state    = 'michigan';  //  state goes here
    private $cookie     = '/tmp/muskegon_cookie.txt';
    protected $urls = [
        'main'      => 'https://mcd911.net/p2c/jailinmates.aspx',
        'referrer'  => 'https://mcd911.net/p2c/jailinmates.aspx',
        'list'      => 'https://mcd911.net/p2c/jqHandler.ashx?op=s',
        'detail'    => 'https://mcd911.net/p2c/jailinmates.aspx',
        'mug'       => 'https://mcd911.net/p2c/Mug.aspx'
    ];
    protected $errors = FALSE;
    protected $_offender = NULL;
    /**
     * Construct
     *
     * For now sets a timelimit, deletes cookie if they exist and creates mscrape model in DB.
     */
    public function __construct()
    {
        if ( file_exists($this->cookie) )
            unlink($this->cookie);   // Unlink only works for files, use rmdir for Directories.
    }
    /**
     * Scrape
     *
     * main scrape function makes the curl calls and sends details to the extraction function
     *
     * @return true - on completed scrape
     * @return false - on failed scrape
     */
    public function scrape()
    {
        $post = $this->get_post_data();
        $list = $this->load_url([
            'target'    => $this->urls['list'],
            'referrer'  => $this->urls['main'],
            'method'    => 'POST',
            'cookie'    => $this->cookie,
            'data'      => [
                't'         => 'ii',
                '_search'   => 'false',
                'nd'        => '1369948241492',
                'rows'      => '10000',
                'page'      => '1',
                'sidx'      => 'date_arr',
                'sord'      => 'desc'
            ]
        ]);
        $rows = json_decode($list['result'])->rows;
        $list_count = (int) json_decode($list['result'])->records;
        echo "<h1>$list_count</h1>";
        for ( $i = 0; $i < $list_count; $i++ )
        {
            $row = $rows[$i];
            $doc = Brass::factory('Brass_Offender', [
                'booking_id' => $this->name.'_'.$row->book_id
            ])->load();
            $this->_offender = [
                'booking_id'    => $this->name.'_'.$row->book_id,
                'scrape_time'   => time(),
                'updated'       => time(),
                'firstname'     => $row->firstname,
                'lastname'      => $row->lastname,
                'booking_date'  => strtotime($row->date_arr),           // 5/17/2013 12:00:00 AM'
                'charges'       => explode(' / ', $row->disp_charge),   // 'OBSTRUCTING JUSTICE / FAILURE TO APPEAR'
                'age'           => (int) $row->age,
                'gender'        => strtoupper($row->sex),
                'race'          => strtoupper($row->race),
                'dob'           => strtotime($row->dob),
                'state'         => $this->state,
                'county'        => $this->county,
                'scrape'        => $this->name
            ];
            // Record already exists
            if ( $doc->loaded() )
                continue;
            try
            {
                $doc->values($this->_offender);
                if ( $doc->check() )
                {
                    // Set this to the iterator just like the site does to retrieve the detail view
                    $post['ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex'] = $row->my_num;
                    if ( $this->get_mug($post) )
                        $doc->create();
                    sleep(rand(5,100));
                }
            }
            catch ( Brass_Validation_Exception $e )
            {
                foreach ( $e->array->errors('brass') as $k => $v )
                {
                    $this->errors[] = [
                        'key' => $k,
                        'issue' => $v,
                        'offender' => $row->disp_name.': '.$this->name.'_'.$this->state.'_'.$row->book_id
                    ];
                }
            }
            if ( $this->errors )
                var_dump($this->errors);
        }
        if ( isset($errors) )
        {
            $data['scrape'] = ucfirst($this->county).' county, '.ucfirst($this->state);
            $data['errors'] = new ArrayIterator( $errors );
            Model_Annex_Email::factory()->send('mail.exception.generic', 'system', $data);
        }
    }
    /**
     * Get Post Data
     *
     * Returns post data containing validations, sessions, and other bits that are needed to make
     * other pages load properly
     *
     * @return  array   $post   Post Data
     */
    public function get_post_data()
    {
        $home = $this->load_url([
            'target'    => $this->urls['main'],
            'referrer'  => $this->urls['main'],
            'method'    => 'GET',
            'cookie'    => $this->cookie
        ]);
        if ( $home['error'] )
            throw new Peruse_Exception('could not load the home page', 'severe');
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if ( ! $dom->loadHTML($home['result']) )
            throw new Peruse_Exception('could not parse html as DOMDocument', 'severe');
        $forms = $dom->getElementsByTagName('form');
        foreach ( $forms as $form )
        {
            $inputs = $form->getElementsByTagName('input');
            foreach ( $inputs as $input )
            {
                if ( $input->getAttribute('name') == 'ctl00$MasterPage$mainContent$CenterColumnContent$hfRecordIndex')
                    $post[$input->getAttribute('name')] = 0;
                else
                    $post[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }
        return $post;
    }
    public function get_mug($post)
    {
        // We need to get the url we've been redirected too, plus update the cookie which it uses to validate
        // and spit out the right image. Not much more we can do to simplify here
        $referrer = $this->load_url([
            'target'    => $this->urls['main'],
            'referrer'  => $this->urls['main'],
            'method'    => 'POST',
            'cookie'    => $this->cookie,
            'data'      => $post
        ])['status']['url'];
        $raw = $this->load_url([
            'target'    => $this->urls['mug'],
            'referrer'  => $referrer,
            'cookie'    => $this->cookie
        ])['result'];
        $mug = $this->mug_info($this->_offender);
        $mug_raw = $mug['raw'].$mug['name'];
        $mug_prod = $mug['prod'].$mug['name'];
        // If it's the default / anon photo, we want to skip
        if ( file_get_contents(DOCROOT.'default_images/muskegon.png') == $raw )
            return FALSE;
        // Write the raw file if it doesnt exist
        if ( ! file_exists($mug_raw) )
        {
            if ( ! is_dir($mug['raw']) )
                $this->create_path($mug['raw']);
            // make the file
            $f = fopen($mug_raw, 'wb');
            fwrite($f, $raw);
            fclose($f);
        }
        if ( ! is_dir($mug['prod']) )
            $this->create_path($mug['prod']);
        var_dump($this->_offender);
        var_dump($raw);
        echo '<img src="data:image/png;base64, '.base64_encode($raw).'" alt="no image" />';
        try
        {
            if ( file_exists($mug_raw) AND ! file_exists($mug_prod) )
            {
                return $this->mug_stamp(
                    $mug_raw,
                    $mug_prod,
                    $this->_offender['firstname'].' '.$this->_offender['lastname'],
                    $this->_offender['charges'][0],
                    @$this->_offender['charges'][1]
                );
            }
            else
            {
                return TRUE;
            }
        }
        catch ( Exception $e )
        {
            var_dump($e);
        }
    }
}