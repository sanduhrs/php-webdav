<?php

$req_dump = print_r($_REQUEST, TRUE);
$fp = file_put_contents('request.log', $req_dump, FILE_APPEND);

$get = print_r($_GET, TRUE);
$fp = file_put_contents('request.log', $get, FILE_APPEND);

$post = print_r($_POST, TRUE);
$fp = file_put_contents('request.log', $post, FILE_APPEND);

$req_body = file_get_contents('php://input');
$fp = file_put_contents('request.log', $req_body, FILE_APPEND);

/**
 * This server combines both CardDAV and CalDAV functionality into a single
 * server. It is assumed that the server runs at the root of a HTTP domain (be
 * that a domainname-based vhost or a specific TCP port.
 *
 * This example also assumes that you're using SQLite and the database has
 * already been setup (along with the database tables).
 *
 * You may choose to use MySQL instead, just change the PDO connection
 * statement.
 */

/**
 * UTC or GMT is easy to work with, and usually recommended for any
 * application.
 */
date_default_timezone_set('UTC');

/**
 * Make sure this setting is turned on and reflect the root url for your WebDAV
 * server.
 *
 * This can be for example the root / or a complete path to your server script.
 */
// $baseUri = '/';

/**
 * Database
 *
 * Feel free to switch this to MySQL, it will definitely be better for higher
 * concurrency.
 */
$pdo = new \PDO('sqlite:data/db.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Mapping PHP errors to exceptions.
 *
 * While this is not strictly needed, it makes a lot of sense to do so. If an
 * E_NOTICE or anything appears in your code, this allows SabreDAV to intercept
 * the issue and send a proper response back to the client (HTTP/1.1 500).
 */
function exception_error_handler($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

// Autoloader
require_once 'vendor/autoload.php';

/**
 * The backends. Yes we do really need all of them.
 *
 * This allows any developer to subclass just any of them and hook into their
 * own backend systems.
 */
$authBackend      = new \Sabre\DAV\Auth\Backend\PDO($pdo);
$principalBackend = new \Sabre\DAVACL\PrincipalBackend\PDO($pdo);
$carddavBackend   = new \Sabre\CardDAV\Backend\PDO($pdo);
$caldavBackend    = new \Sabre\CalDAV\Backend\PDO($pdo);

/**
 * The directory tree
 *
 * Basically this is an array which contains the 'top-level' directories in the
 * WebDAV server.
 */
$nodes = [
  // /principals
  new \Sabre\CalDAV\Principal\Collection($principalBackend),
  // /calendars
  new \Sabre\CalDAV\CalendarRoot($principalBackend, $caldavBackend),
  // /addressbook
  new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
  // /public
  new \Sabre\DAV\FS\Directory('files'),
];

$server = new Sabre\DAV\Server($nodes);
if (isset($baseUri)) {
  $server->setBaseUri($baseUri);
}

// Support for LOCK and UNLOCK
$lockBackend = new \Sabre\DAV\Locks\Backend\File('tmp/locksdb');
$lockPlugin = new \Sabre\DAV\Locks\Plugin($lockBackend);
$server->addPlugin($lockPlugin);

// Plugins
$server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
$server->addPlugin(new \Sabre\DAV\Sync\Plugin());
$server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
$server->addPlugin(new \Sabre\DAVACL\Plugin());

// CalDAV plugins
$server->addPlugin(new \Sabre\CalDAV\Plugin());
$server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
$server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
$server->addPlugin(new \Sabre\CalDAV\Schedule\Plugin());
$server->addPlugin(new \Sabre\CalDAV\Schedule\IMipPlugin('noreply@erdfisch.de'));
$server->addPlugin(new \Sabre\CalDAV\Subscriptions\Plugin());

// CardDAV plugins
$server->addPlugin(new \Sabre\CardDAV\Plugin());
$server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());

// Files
// Automatically guess (some) contenttypes, based on extesion
$server->addPlugin(new \Sabre\DAV\Browser\GuessContentType());
// Temporary file filter
$tempFF = new \Sabre\DAV\TemporaryFileFilterPlugin('tmp');
$server->addPlugin($tempFF);

/**
 * Ok. Perhaps not the smallest possible. The browser plugin is 100% optional,
 * but it really helps understanding the server.
 */
$server->addPlugin(
  new Sabre\DAV\Browser\Plugin()
);

$server->exec();

