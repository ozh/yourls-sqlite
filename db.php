<?php

yourls_db_sqlite_connect();


/**
 * Connect to SQLite DB
 */
function yourls_db_sqlite_connect() {
    global $ydb;

    // Use core PDO library
    require_once( YOURLS_INC . '/ezSQL/ez_sql_core.php' );
    require_once( YOURLS_INC . '/ezSQL/ez_sql_core_yourls.php' );
    require_once( YOURLS_INC . '/ezSQL/ez_sql_pdo.php' );
    // Overwrite core PDO YOURLS library to allow connection to a SQLite DB instead of a MySQL server
    require_once( YOURLS_USERDIR . '/ez_sql_pdo_sqlite_yourls.php' );

    $dbname = YOURLS_USERDIR . '/' . YOURLS_DB_NAME . '.sq3';
    $ydb = new ezSQL_pdo_sqlite_YOURLS( YOURLS_DB_USER, YOURLS_DB_PASS, $dbname, YOURLS_DB_HOST );
    $ydb->DB_driver = 'sqlite3';

    yourls_debug_log( "DB driver: sqlite3" );
    
    // Custom tables to be created upon install
    yourls_add_filter( 'shunt_yourls_create_sql_tables', 'yourls_create_sqlite_tables' );
    
    return $ydb;
}


/**
 * Assume SQLite server is always alive
 */
function yourls_is_db_alive() {
    return true;
}


/**
 * Die with a DB error message
 *
 * @TODO in version 1.8 : use a new localized string, specific to the problem (ie: "DB is dead")
 *
 * @since 1.7.1
 */
function yourls_db_dead() {
    // Use any /user/db_error.php file
    if( file_exists( YOURLS_USERDIR . '/db_error.php' ) ) {
        include_once( YOURLS_USERDIR . '/db_error.php' );
        die();
    }

    yourls_die( yourls__( 'Incorrect DB config, or could not connect to DB' ), yourls__( 'Fatal error' ), 503 );
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
        $ydb->query( $table_query );
    }    

    // Get list of created tables
    $create_success = $ydb->get_results( 'SELECT name FROM sqlite_master WHERE type = "table"' );
    
    $created_tables = [];
    foreach( (array)$create_success as $table ) {
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
