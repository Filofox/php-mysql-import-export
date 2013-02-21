<?php

/**
 * Class for importing and exporting SQL dumps directly via PHP
 *
 * This class makes heavy use of others' work. Please see function definitions for licence details
 *
 * Due to the licence of some parts of the code used here, this library is issued under a GPLv2 license
 * Details of this license can be found at http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (c) 2012 Pat Fox
 *
 * @author		Pat Fox
 */
class MySQLImportExport{

	protected $host;
	protected $user;
	protected $password;
	protected $connection;

	protected $use_mysqli = false;

	protected $ignore = array();

	public function __construct( $host, $database, $user, $password )
	{
		ini_set('memory_limit', '5120M');
		set_time_limit ( 0 );

		$this->use_mysqli = class_exists( 'mysqli' );

		if( $this->use_mysqli ){
			$this->connection = new mysqli( $host, $user, $password, $database );

			/* check connection */
			if (mysqli_connect_errno()) {
				printf("Connect failed: %s\n", mysqli_connect_error());
				exit();
			}

		} else {
			$this->connection = mysql_connect( $host, $user, $password );
			mysql_select_db( $database, $this->connection );
		}

	}

	/**
	 * Mark a table not to be exported
	 *
	 * @param		string		$table
	 */
	public function IgnoreTable( $table ){
		$this->ignore[] = $table;
	}
	/**
	 * Mark a list of tables not to be exported
	 *
	 * @param		array		$tables
	 */
	public function IgnoreTables( $tables ){
		foreach( $tables as $table ){
			$this->IgnoreTable( $table );
		}
	}

	/**
	 * Create dump of database
	 *
	 * @param		MySQLImportExportStorage		$storage		[Optional] Object to pass exported data to
	 *
	 * @return		mixed											If a storage object is passed, this is returned.
	 * 																Otherwise, a string is returned
	 */
	public function Export( MySQLImportExportStorage $storage = null ){

		$return_string = false;
		if( $storage === null ){
			$return_string = true;
			$storage = new MySQLImportExportStorageString();
		}
		if( $this->use_mysqli ){
			$this->ExportMysqli( $storage );
		} else {
			$this->ExportMysql( $storage );
		}

		if( $return_string ){
			return (string)$storage;
		} else {
			return $storage;
		}

	}

	/**
	 * Create dump of database using the MySQLi class
	 *
	 * Heavily based on the code found at
	 * http://www.ephestione.it/backup-zip-mysql-database-php-email-attachment/
	 * ( Modified to use MySQLi )
	 */
	protected function ExportMysqli( MySQLImportExportStorage $storage ){
		$tables = array();
		$result = $this->connection->query("SHOW TABLES");

		while( $row = $result->fetch_row() )
		{
			$tables[] = $row[0];
		}

		foreach($tables as $table) {
			if( !in_array ( $table, $this->ignore ) ) {

				// To avoid table clash when restoring
				$storage->Add ( "DROP TABLE IF EXISTS $table;" );

				$result = $this->connection->query( "SHOW CREATE TABLE $table" );
				// Table definition
				$row2 = $result->fetch_row();
				$get_data = true;
				if( preg_match( '~^CREATE (.*)VIEW~Ui', $row2[1] ) ){
					$row2[1] = preg_replace( '~^CREATE (.*)VIEW~Ui', 'CREATE VIEW', $row2[1] );
					$get_data = false;
				}

				$storage->Add( "\n\n".$row2[1].";\n\n" );

				if( $get_data ){
					// Now get the data
					$result = $this->connection->query( "SELECT * FROM $table" );
					while($row = $result->fetch_row()) {
						$storage->Add(  "INSERT INTO $table VALUES(" );
						$fields = array();
						foreach( $row as $field ){
							$fields[] = "'" . $this->connection->real_escape_string( $field ) . "'";
						}
						$storage->Add( implode( ",", $fields ) . ");\n" );
					}
					$storage->Add ( "\n\n\n" );
				}
			}
		}
		$storage->Close();
	}

	/**
	 * Create dump of database with native mysql functions
	 *
	 * Heavily based on the code found at
	 * http://www.ephestione.it/backup-zip-mysql-database-php-email-attachment/
	 *
	 * @return		string
	 */
	protected function ExportMysql( MySQLImportExportStorage $storage ){
		$tables = array();
		$result = mysql_query("SHOW TABLES", $this->connection);

		while( $row = mysql_fetch_row($result) )
		{
			$tables[] = $row[0];
		}
		foreach($tables as $table) {
			if( !in_array ( $table, $this->ignore ) ) {

				// To avoid table clash when restoring
				$storage->Add( "DROP TABLE IF EXISTS $table;" );

				// Table definition
				$row2 = mysql_fetch_row
				(
					mysql_query(
						"SHOW CREATE TABLE $table",
						$this->connection
					)
				);
				$get_data = true;
				if( preg_match( '~^CREATE (.*)VIEW~Ui', $row2[1] ) ){
					$row2[1] = preg_replace( '~^CREATE (.*)VIEW~Ui', 'CREATE VIEW', $row2[1] );
					$get_data = false;
				}

				$storage->Add( "\n\n".$row2[1].";\n\n" );

				if( $get_data ){
					// Now get the data
					$result = mysql_query( "SELECT * FROM $table", $this->connection );
					while($row = mysql_fetch_row($result)) {
						$storage->Add( "INSERT INTO $table VALUES(" );
						$fields = array();
						foreach( $row as $field ){
							$fields[] = "'" . mysql_real_escape_string( $field ) . "'";
						}
						$storage->Add( implode( ",", $fields ) . ");\n" );
					}
					$storage->Add( "\n\n\n" );
				}
			}
		}
		$storage->Close();
	}

	/**
	 * Import one or more SQL statements
	 *
	 * @param		string		$sql
	 */
	public function Import( $storage ){

		if( gettype( $storage ) == 'string' ){
			$storage = new MySQLImportExportStorageString( $sql );
		}

		if( $this->use_mysqli ){
			$this->ImportMYSQLi( $storage );
		} else {
			$this->ImportMysql( $storage );
		}

	}

	/**
	 * Import one or more SQL statements using the MySQLi class
	 *
	 * @param		string		$sql
	 */
	protected function ImportMYSQLi( MySQLImportExportStorage $storage ){

		$sql = $storage->Get();

		/* execute multi query */
		if ($this->connection->multi_query($sql)) {
			do {
				/* store first result set */
				if ($result = $this->connection->store_result()) {
					while ($row = $result->fetch_row()) {
						printf("%s\n", $row[0]);
					}
					$result->free();
				}
				/* print divider */
				if ($this->connection->more_results()) {
				}
			} while ($this->connection->next_result());
		}

		/* close connection */
		//$mysqli->close();

	}

	/**
	 * Import one or more SQL statements using native mysql functions
	 *
	 * @param		string		$sql
	 */
	protected function ImportMysql( MySQLImportExportStorage $storage ){

		$sql = $storage->Get();

		$sql = $this->RemoveRemarks($sql);
		$sql = $this->RemoveComments($sql);

		foreach( $this->SplitSQL($sql, ';') as $sql ){
			mysql_query($sql) or die('error in query');
		}
	}

	// What follows is the original license for some of the code on which this file was based
	// The code itself has been edited slightly

	/***************************************************************************
	*                             sql_parse.php
	*                              -------------------
	*     begin                : Thu May 31, 2001
	*     copyright            : (C) 2001 The phpBB Group
	*     email                : support@phpbb.com
	*
	*     $Id: sql_parse.php,v 1.8 2002/03/18 23:53:12 psotfx Exp $
	*
	****************************************************************************/

	/***************************************************************************
	 *
	 *   This program is free software; you can redistribute it and/or modify
	 *   it under the terms of the GNU General Public License as published by
	 *   the Free Software Foundation; either version 2 of the License, or
	 *   (at your option) any later version.
	 *
	 ***************************************************************************/

	protected function RemoveComments(&$output)
	{
		$lines = explode("\n", $output);
		$output = "";

		// try to keep mem. use down
		$linecount = count($lines);

		$in_comment = false;
		for($i = 0; $i < $linecount; $i++)
		{
			if( preg_match("/^\/\*/", preg_quote($lines[$i])) )
			{
				$in_comment = true;
			}

			if( !$in_comment )
			{
				$output .= $lines[$i] . "\n";
			}

			if( preg_match("/\*\/$/", preg_quote($lines[$i])) )
			{
				$in_comment = false;
			}
		}

		unset($lines);
		return $output;
	}

	//
	// RemoveRemarks will strip the sql comment lines out of an uploaded sql file
	//
	function RemoveRemarks($sql)
	{
	   $lines = explode("\n", $sql);

	   // try to keep mem. use down
	   $sql = "";

	   $linecount = count($lines);
	   $output = "";

	   for ($i = 0; $i < $linecount; $i++)
	   {
		  if (($i != ($linecount - 1)) || (strlen($lines[$i]) > 0))
		  {
			 if (isset($lines[$i][0]) && $lines[$i][0] != "#")
			 {
				$output .= $lines[$i] . "\n";
			 }
			 else
			 {
				$output .= "\n";
			 }
			 // Trading a bit of speed for lower mem. use here.
			 $lines[$i] = "";
		  }
	   }

	   return $output;

	}

	//
	// SplitSQL will split an uploaded sql file into single sql statements.
	// Note: expects trim() to have already been run on $sql.
	//
	protected function SplitSQL($sql, $delimiter)
	{
	   // Split up our string into "possible" SQL statements.
	   $tokens = explode($delimiter, $sql);

	   // try to save mem.
	   $sql = "";
	   $output = array();

	   // we don't actually care about the matches preg gives us.
	   $matches = array();

	   // this is faster than calling count($oktens) every time thru the loop.
	   $token_count = count($tokens);
	   for ($i = 0; $i < $token_count; $i++)
	   {
		  // Don't wanna add an empty string as the last thing in the array.
		  if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0)))
		  {
			 // This is the total number of single quotes in the token.
			 $total_quotes = preg_match_all("/'/", $tokens[$i], $matches);
			 // Counts single quotes that are preceded by an odd number of backslashes,
			 // which means they're escaped quotes.
			 $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$i], $matches);

			 $unescaped_quotes = $total_quotes - $escaped_quotes;

			 // If the number of unescaped quotes is even, then the delimiter did NOT occur inside a string literal.
			 if (($unescaped_quotes % 2) == 0)
			 {
				// It's a complete sql statement.
				$output[] = $tokens[$i];
				// save memory.
				$tokens[$i] = "";
			 }
			 else
			 {
				// incomplete sql statement. keep adding tokens until we have a complete one.
				// $temp will hold what we have so far.
				$temp = $tokens[$i] . $delimiter;
				// save memory..
				$tokens[$i] = "";

				// Do we have a complete statement yet?
				$complete_stmt = false;

				for ($j = $i + 1; (!$complete_stmt && ($j < $token_count)); $j++)
				{
				   // This is the total number of single quotes in the token.
				   $total_quotes = preg_match_all("/'/", $tokens[$j], $matches);
				   // Counts single quotes that are preceded by an odd number of backslashes,
				   // which means they're escaped quotes.
				   $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$j], $matches);

				   $unescaped_quotes = $total_quotes - $escaped_quotes;

				   if (($unescaped_quotes % 2) == 1)
				   {
					  // odd number of unescaped quotes. In combination with the previous incomplete
					  // statement(s), we now have a complete statement. (2 odds always make an even)
					  $output[] = $temp . $tokens[$j];

					  // save memory.
					  $tokens[$j] = "";
					  $temp = "";

					  // exit the loop.
					  $complete_stmt = true;
					  // make sure the outer loop continues at the right point.
					  $i = $j;
				   }
				   else
				   {
					  // even number of unescaped quotes. We still don't have a complete statement.
					  // (1 odd and 1 even always make an odd)
					  $temp .= $tokens[$j] . $delimiter;
					  // save memory.
					  $tokens[$j] = "";
				   }

				} // for..
			 } // else
		  }
	   }

	   return $output;
	}
}
/**
 * Base class for passing import/export data
 */
abstract class MySQLImportExportStorage{
	public abstract function Add( $string );

	public function Close(){}
}
/**
 * Basic string class
 */
class MySQLImportExportStorageString extends MySQLImportExportStorage{

	protected $sql = '';

	public function __construct( $sql = false ){
		$this->sql = $sql;
	}
	public function Add( $string ){
		$this->sql .= $string;
	}
	public function __toString(){
		return $this->sql;
	}
}
/**
 * Class for working with files
 */
class MySQLImportExportStorageFile extends MySQLImportExportStorage{

	protected $filename = '';
	protected $handle = false;

	public function __construct( $filename ){
		$this->filename = $filename;
	}

	public function Add( $string ){
		if( $this->handle == false ){
			$this->handle = fopen( $this->filename, 'w' );
		}
		fputs( $this->handle, $string, strlen( $string ) );
	}
	public function Get(){
		return file_get_contents( $this->filename );
	}
	public function Close(){
		if( $this->handle !== false ){
			fclose( $this->handle );
		}
	}
}
