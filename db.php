<?php
/*
SQLite driver for YOURLS.
Version: 1.1
This driver requires YOURLS 1.7.3 -- not before -- not after!
Author: Ozh
*/

yourls_db_sqlite_connect();

/**
 * Connect to SQLite DB
 */
function yourls_db_sqlite_connect() {
    global $ydb;

	if (!defined( 'YOURLS_DB_NAME' ))
        yourls_die ( yourls__( 'Incorrect DB config (set YOURLS_DB_NAME)' ), yourls__( 'Fatal error' ), 503 );

    $dbname = YOURLS_USERDIR . '/' . YOURLS_DB_NAME . '.sq3';

    /**
     * Data Source Name (dsn) used to connect the DB
     *
     * DSN with PDO is something like:
     * 'mysql:host=123.4.5.6;dbname=test_db;port=3306'
     * 'sqlite:/opt/databases/mydb.sq3'
     * 'pgsql:host=192.168.13.37;port=5432;dbname=omgwtf'
     */
    $dsn = sprintf( 'sqlite:%s', $dbname );

    /**
     * PDO driver options and attributes

     * The PDO constructor is something like:
     *   new PDO( string $dsn, string $username, string $password [, array $options ] )
     * The driver options are passed to the PDO constructor, eg array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
     * The attribute options are then set in a foreach($attr as $k=>$v){$db->setAttribute($k, $v)} loop
     */
    $attributes     = yourls_apply_filter( 'db_connect_attributes',    array() ); // attributes as key-value pairs

    $ydb = new \YOURLS\Database\YDB( $dsn, "", "", array(), $attributes );
    $ydb->init();

    // Past this point, we're connected
    yourls_debug_log(sprintf('Opened database %s ', $dbname, $dbhost));

    // Custom tables to be created upon install
    yourls_add_filter( 'shunt_yourls_create_sql_tables', 'yourls_create_sqlite_tables' );

    // Custom stat query to replace MySQL DATE_FORMAT with SQLite strftime
    yourls_add_filter( 'stat_query_dates', 'yourls_sqlite_stat_query_dates' );
    
    // Custom stat query to get last 24 hours hits
    yourls_add_filter( 'stat_query_last24h', create_function( '', 'return "SELECT 1;";') ); // just bypass original query
    yourls_add_filter( 'pre_yourls_info_last_24h', 'yourls_sqlite_last_24h_hits' );         // use this one instead

    // Return version for compat
    yourls_add_filter( 'shunt_get_database_version', create_function( '', 'return "5.0";') );

    // Shunt get_all_options to prevent error from SHOW TABLES query
    yourls_add_filter( 'shunt_all_options', 'yourls_sqlite_get_all_options' );
    
    yourls_debug_mode(YOURLS_DEBUG);

	return $ydb;
}

function yourls_sqlite_get_all_options() {
    // Options uses a SHOW TABLES query to check if YOURLS is installed.
    // However, sqlite doesn't support that, and it hard dies there.
    // This does the equivalent in sqlite.
    global $ydb;
    $table = YOURLS_DB_TABLE_OPTIONS;
    $sql = "SELECT option_name, option_value FROM $table WHERE 1=1";

    try {
        $options = (array) $ydb->fetchPairs($sql);
    } catch ( PDOException $e ) {
        try {
            $check = $ydb->fetchAffected(
                sprintf(
                    'SELECT name FROM sqlite_master WHERE type = "table" AND ' .
                    'name LIKE "%s"', $table));
            // Table doesn't exist. Set installed to false and short circuit.
            if ($check == 0) {
                $ydb->set_installed(false);
                return true;
            }
        // Error at this point means the database isn't readable
        } catch ( PDOException $e ) {
            $ydb->dead_or_error($e);
        }
    }

    // Unlikely scenario, but who knows: table exists, but is empty
    if (empty($options)) {
        return false;
    }

    foreach ($options as $name => $value) {
        $ydb->set_option($name, yourls_maybe_unserialize($value));
    }

    $ydb->set_installed(true);
    return true;
}

/**
 * Custom SQLite query to get last 24 hours hits on a URL
 *
 * Just a little difference: the original MySQL query generates an array of [hour AM/PM] => [hits] but SQLite doesn't
 * understand AM & PM.
 * Originally we make an array with :
 * $h = date('H A', $now - ($i * 60 * 60) );
 * Due to this limitation, the array is crafted with:
 * $h = date('H', $now - ($i * 60 * 60) );
 *
 * Yep, all that for that simple 'H' instead of 'H A'
 *
 * @return array Last 24 hour hits
 */
function yourls_sqlite_last_24h_hits() {
    $table = YOURLS_DB_TABLE_LOG;
    $last_24h = array();
    
    global $ydb;
    global $keyword, $aggregate; // as defined in yourls-loader.php
    
	// Define keyword query range : either a single keyword or a list of keywords
	if( $aggregate ) {
		$keyword_list = yourls_get_longurl_keywords( $longurl );
		$keyword_range = "IN ( '" . join( "', '", $keyword_list ) . "' )"; // IN ( 'blah', 'bleh', 'bloh' )
	} else {
		$keyword_range = sprintf( "= '%s'", yourls_escape( $keyword ) );
	}
    
	$query = "SELECT
		strftime('%H', `click_time`) AS `time`,
		COUNT(*) AS `count`
	FROM `yourls_log`
	WHERE `shorturl` $keyword_range AND `click_time` > datetime('now', '-1 day')
	GROUP BY `time`;";
	$rows = $ydb->get_results( $query );
    
    $_last_24h = array();
	foreach( (array)$rows as $row ) {
		if ( $row->time )
			$_last_24h[ "$row->time" ] = $row->count;
	}
    
    $now = intval( date('U') );
	for ($i = 23; $i >= 0; $i--) {
		$h = date('H', $now - ($i * 60 * 60) );
		// If the $last_24h doesn't have all the hours, insert missing hours with value 0
		$last_24h[ $h ] = array_key_exists( $h, $_last_24h ) ? $_last_24h[ $h ] : 0 ;
	}
    
    return( $last_24h );
}


/**
 * Format SQL queries for SQLite
 *
 * Replace all
 *    DATE_FORMAT(`field`, '%FORMAT')        with    strftime('%FORMAT', `field`)
 *    (CURRENT_TIMESTAMP - INTERVAL 1 DAY)   with    datetime('now', '-1 day')
 *    Date format string that SQLite doesn't understand (eg "%p")
 *
 * @param string $query Query
 * @return string Modified query
 */
function yourls_sqlite_stat_query_dates( $query ) {
    // from: DATE_FORMAT(`field`, '%FORMAT')
    // to:   strftime('%FORMAT', `field`)
    preg_match_all( '/DATE_FORMAT\(\s*([^,]+),\s*([^\)]+)\)/', $query, $matches );
    // $matches[0] is an array of all the "DATE_FORMAT(`THING`, '%STUFF')"
    // $matches[1] is an array of all the `THING`
    // $matches[2] is an array of all the '%STUFF'
    $replace = array();
    foreach( $matches[1] as $k => $v ) {
        $replace[] = sprintf( 'strftime(%s, %s)', $matches[2][$k], $matches[1][$k] );
    }
    $query = str_replace( $matches[0], $replace, $query );
    
    // from: `field` > (CURRENT_TIMESTAMP - INTERVAL 1 DAY)
    // to:   `field` > datetime('now', '-1 day');
    $query = str_replace( "(CURRENT_TIMESTAMP - INTERVAL 1 DAY)", "datetime('now', '-1 day')", $query );    
    
    // Remove %p from date formats, as SQLite doesn't get it
    $query = preg_replace( '/\s*%p\s*/', '', $query );    
    
    return $query;
}

/**
 * Create tables. Return array( 'success' => array of success strings, 'errors' => array of error strings )
 *
 */
function yourls_create_sqlite_tables() {
    global $ydb;
    
    $error_msg = array();
    $success_msg = array();

    // Create Table Query
    $create_tables = array();
    
        
    $create_tables[YOURLS_DB_TABLE_OPTIONS] = 
        'CREATE TABLE IF NOT EXISTS `'.YOURLS_DB_TABLE_OPTIONS.'` ('.
        '`option_id` bigint(20) NULL,'.
        '`option_name` varchar(64) NOT NULL default "",'.
        '`option_value` longtext NOT NULL,'.
        'PRIMARY KEY  (`option_id`,`option_name`)'.
        ');';

    $create_tables[YOURLS_DB_TABLE_URL] =
        'CREATE TABLE IF NOT EXISTS `'.YOURLS_DB_TABLE_URL.'` ('.
        '`keyword` varchar(200) NOT NULL,'.
        '`url` text BINARY NOT NULL,'.
        '`title` text,'.
        '`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'.
        '`ip` VARCHAR(41) NOT NULL,'.
        '`clicks` INT(11) NOT NULL,'.
        ' PRIMARY KEY  (`keyword`)'.
        ');';

    $create_tables[YOURLS_DB_TABLE_LOG] = 
        'CREATE TABLE IF NOT EXISTS `'.YOURLS_DB_TABLE_LOG.'` ('.
        '`click_id`  INTEGER PRIMARY KEY AUTOINCREMENT NULL,'.
        '`click_time` datetime NOT NULL,'.
        '`shorturl` varchar(200) NOT NULL,'.
        '`referrer` varchar(200) NOT NULL,'.
        '`user_agent` varchar(255) NOT NULL,'.
        '`ip_address` varchar(41) NOT NULL,'.
        '`country_code` char(2) NOT NULL'.
        '); ';

    $create_table_count = 0;
    
    // Create tables
    foreach ( $create_tables as $table_name => $table_query ) {
        $ydb->perform( $table_query );
    }    

    // Get list of created tables
    $create_success = $ydb->fetchObjects( 'SELECT name FROM sqlite_master WHERE type = "table" AND name NOT LIKE "sqlite_%"' );
    
    $created_tables = [];
    $i = 0;
    foreach( $create_success as $table ) {
        $created_tables[] = $table->name;
    }
    
    // Compare list of created tables with needed tables
    foreach( $create_tables as $table_name => $table_query  ) {
        if( in_array( $table_name, $created_tables ) ) {
            $create_table_count++;
            $success_msg[] = yourls_s( "Table '%s' created.", $table_name ); 
        } else {
            $error_msg[] = yourls_s( "Error creating table '%s'.", $table_name );
        }
    }
    
    // Initializes the option table
    if( !yourls_initialize_options() )
        $error_msg[] = yourls__( 'Could not initialize options' );
    
    // Insert sample links
    if( !yourls_insert_sample_links() )
        $error_msg[] = yourls__( 'Could not insert sample short URLs' );
    
    // Check results of operations
    if ( sizeof( $create_tables ) == $create_table_count ) {
        $success_msg[] = yourls__( 'YOURLS tables successfully created.' );
    } else {
        $error_msg[] = yourls__( 'Error creating YOURLS tables.' ); 
    }

    return array( 'success' => $success_msg, 'error' => $error_msg );
}
