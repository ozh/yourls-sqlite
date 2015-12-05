<?php
/**
 * Tweak the ezSQL PDO driver for SQLite
 */
class ezSQL_pdo_sqlite_YOURLS extends ezSQL_pdo {

    /**
    * Constructor - Overwrite original to use sqlite3
    * 
    * @since 1.7
    */
    function __construct( $dbuser='', $dbpassword='', $dbname='', $dbhost='localhost', $encoding='' ) {
        $this->show_errors = defined( 'YOURLS_DEBUG' ) && YOURLS_DEBUG; // comply to YOURLS debug mode
        $this->dbuser = $dbuser;
        $this->dbpassword = $dbpassword;
        $this->dbname = $dbname;
        $this->dbhost = $dbhost; // this is unused for SQLite
        $this->encoding = $encoding; // Not sure if that is used

        $dsn = "sqlite:$dbname" ;
        $this->dsn = $dsn;
        
        // Turn on track errors 
        ini_set('track_errors',1);
        
        $this->connect( $dsn, $dbuser, $dbpassword );
    }

    /**
     * Return fake MySQL server version
     */
    function mysql_version() {
        return 9001; // Over nine thousand so we qualify as above minimum requirement
    }
    
    /**
     * Perform mySQL query - just like the original but we log the query first
     */
    function query( $query ) {
    
        // Keep history of all queries
        $this->debug_log[] = $query;
        
        // Original function
        return parent::query( $query );
    }

    /**
     * Tweak original catch_error()
     *
     * The PDO SQLite driver is dumb : when a query generates an error, the error message isn't reset after a successful query
     *
     * Example:
     *    $db = new PDO('sqlite:test.sqlite3');
     *    $db->exec( "bogus sql" ); // error query
     *    var_dump( $db->errorInfo() ); // -> array( 'HY000', 1, 'near "bogus": syntax error' ) -- ok
     *    $db->exec( "select 1" ); // valid query
     *    var_dump( $db->errorInfo() ); // -> array( '00000', 1, 'near "bogus": syntax error' ) -- dafuq?!
     *
     * Note that with PDO + MySQL, the result of previous code is as expected: second array becomes array( '00000', null, null )
     *
     * This tweak makes sure we return false (as in, "caught error = nope") if error code is '00000', regardless of error message
     */
    function catch_error() {
        $err_array = $this->dbh->errorInfo();
        if( $err_array[0] == '00000' )
            return false;
        
        return parent::catch_error();
    }
    
    /**
     * Properly escape for SQLite
     *
     * MySQL wants 'I\'m nice', but SQLite wants 'I''m nice'.
     * Note that PDO::quote() puts quote *around* strings, which we don't want, hence the trim
     */
    function escape( $input ) {
        return trim( $this->dbh->quote( stripslashes( $input ) ), "'" );
    }

    /**
    * Disconnect - unused, just defined for consistency with other classes
    */
    function disconnect() { }
    
}
