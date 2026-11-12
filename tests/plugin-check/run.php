<?php
// Runs the official Plugin Check (wp plugin check) inside Playground and prints CSV findings.
$_SERVER['argv'] = array( 'wp', '--path=/wordpress', 'plugin', 'check', 'hookvigil-for-woocommerce', '--format=csv', '--fields=file,line,type,code,message' );
$argv            = $_SERVER['argv'];
$_SERVER['argc'] = count( $argv );
include '/tmp/wp-cli.phar';
