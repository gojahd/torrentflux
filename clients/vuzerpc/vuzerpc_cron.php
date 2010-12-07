#!/usr/bin/php
<?php
/*
VUZE xmwebui (0.2.8) RPC interface for PHP (CRON)
		       by Epsylon3 on gmail.com, Nov 2010

Require PHP 5 for public/protected members

Temporary cron script used to update .stat files
before full integration in fluxcli.php

*/

# sample cron.d update vuze rpc stat files every minutes
# */1 * * * *     www-data cd /var/git/torrentflux/clients/vuzerpc ;./vuzerpc_cron.php update

chdir('../../html');

// check for home
if (!is_file('inc/main.core.php'))
	exit("Error: this script can't find main.core.php, please run it in current directory or change chdir in sources.\n");

//get $cfg
require("inc/main.core.php");
global $cfg;

//set username for logs
$cfg["user"] = 'cron';

require("inc/classes/VuzeRPC.php");

//print_r($cfg);

//commented to keep default (use db cfg)
//$cfg['vuze_rpc_host']='127.0.0.1';
//$cfg['vuze_rpc_port']='19091';
//$cfg['vuze_rpc_user']='vuze';
//$cfg['vuze_rpc_password']='mypassword';

$client = 'vuzerpc';

function updateStatFiles($bShowMissing=false) {
	global $cfg, $db, $client;

	//convertTime
	require_once("inc/functions/functions.core.php");

	$vuze = VuzeRPC::getInstance($cfg);

	// do special-pre-start-checks
	if (!VuzeRPC::isRunning()) {
		return;
	}

	$tfs = $vuze->torrent_get_tf();
	//file_put_contents($cfg["path"].'.vuzerpc/'."updateStatFiles.log",serialize($tfs));

	if (empty($tfs))
		return;

	$hashes = array("''");
	foreach ($tfs as $hash => $t) {
		$hashes[] = "'".strtolower($hash)."'";
	}

	$sql = "SELECT hash, transfer, sharekill FROM tf_transfers WHERE type='torrent' AND client IN('vuzerpc','azureus') AND hash IN (".implode(',',$hashes).")";
	$recordset = $db->Execute($sql);
	$hashes=array();
	$sharekills=array();
	while (list($hash, $transfer, $sharekill) = $recordset->FetchRow()) {
		$hash = strtoupper($hash);
		$hashes[$hash] = $transfer;
		$sharekills[$hash] = $sharekill;
	}

	//SHAREKILLS
	$nbUpdate=0;
	foreach ($tfs as $hash => $t) {
		if (!isset($sharekills[$hash]))
			continue;
		if (($t['status']==8 || $t['status']==9) && $t['sharing'] > $sharekills[$hash]) {
			
			$transfer = $hashes[$hash];
			
			$nbUpdate++;
			
			if (!$vuze->torrent_stop_tf($hash)) {
				AuditAction($cfg["constants"]["debug"], $client.": stop error $transfer.");
			} else {
				// log
				AuditAction($cfg["constants"]["stop_transfer"], $client.": sharekill stopped $transfer");
				// flag the transfer as stopped (in db)
				stopTransferSettings($transfer);
			}
		}
	}
	echo " stopped $nbUpdate torrents.\n";
	
	$nbUpdate=0;
	$missing=array();
	foreach ($tfs as $hash => $t) {
		if (!isset($hashes[$hash])) {
			if ($bShowMissing) $missing[$t['rpcid']] = $t['name'];
			continue;
		}

		$nbUpdate++;
		
		$transfer = $hashes[$hash];
		
		//file_put_contents($cfg["path"].'.vuzerpc/'."updateStatFiles4.log",serialize($t));
		$sf = new StatFile($transfer);
		$sf->running = $t['running'];

		if ($sf->running) {

			$sharebase = (int) $sharekills[$hash];
			$sharekill = (int) round(floatval($t['seedRatioLimit']) * 100);
	
			if ($sharebase > 0 && $sharekill != (int) $sf->sharekill) {
				AuditAction($cfg["constants"]["debug"], $client.": changed .stat sharekill to $sharekill $transfer.");
				$sf->sharekill = $sharebase;
			}

			$max_share = max($sharebase, $sharekill);

			if ($t['eta'] > 0) {
				$sf->time_left = convertTime($t['eta']);
			}

			$sf->percent_done = $t['percentDone'];

			if ($t['status'] != 9 && $t['status'] != 5) {
				$sf->peers = $t['peers'];
				$sf->seeds = $t['seeds'];
			}

			if ($t['seeds'] >= 0)
				$sf->seeds = $t['seeds'];

			if ($t['peers'] >= 0)
				$sf->peers = $t['peers'];

			if ((float)$t['speedDown'] >= 0.0)
				$sf->down_speed = formatBytesTokBMBGBTB($t['speedDown'])."/s";
			if ((float)$t['speedUp'] >= 0.0)
				$sf->up_speed = formatBytesTokBMBGBTB($t['speedUp'])."/s";

			if ($t['status'] == 8) {
				$sf->percent_done = 100 + $t['sharing'];
				$sf->down_speed = "&nbsp;";
				if (trim($sf->up_speed) == '')
					$sf->up_speed = "&nbsp;";
			}
			if ($t['status'] == 9) {
				$sf->percent_done = 100 + $t['sharing'];
				$sf->up_speed = "&nbsp;";
				$sf->down_speed = "&nbsp;";
			}

		} else {
			//Stopped or finished...
			
			$sf->down_speed = "";
			$sf->up_speed = "";
			$sf->peers = "";
			$sf->time_left = "0";
			if ($t['eta'] < -1) {
				$sf->time_left = "Finished in ".convertTime(abs($t['eta']));
			} elseif ($sf->percent_done >= 100 && strpos($sf->time_left, 'Finished') === false) {
				$sf->time_left = "Finished!";
			}
			//if ($sf->percent_done < 100 && $sf->percent_done > 0)
			//	$sf->percent_done = 0 - $sf->percent_done;
		}
		
		$sf->downtotal = $t['downTotal'];
		$sf->uptotal = $t['upTotal'];
		
		if (!$sf->size)
			$sf->size = $t['size'];
		
		if ($sf->seeds = -1);
			$sf->seeds = '';
		$sf->write();
	}
	$nb = count($tfs);
	echo " updated $nbUpdate/$nb stat files.\n";

	if (isset($max_share) && $max_share != $sharekill) {
		//set vuze global sharekill to max sharekill value
		$vuze->session_set('seedRatioLimit', $max_share / 100);
		AuditAction($cfg["constants"]["debug"], $client.": changed vuze global sharekill to $max_share.");
	}

	if ($bShowMissing) return $missing;
//	echo $vuze->lastError."\n";
}
//--------------------------------------------------------------------

global $argv;

// prevent invocation from web
if (empty($argv[0])) die("command line only");
if (isset($_REQUEST['argv'])) die("command line only");

// list vuze torrents (via rpc)
$cmd = isset($argv[1]) ? $argv[1] : '';
switch ($cmd) {
	case 'list':
		$v = VuzeRPC::getInstance();
		$torrents = $v->torrent_get_tf();
		//$filter = array('running' => 1);
		//$torrents = $v->torrent_filter_tf($filter);
		echo print_r($torrents,true);
	break;

	// list vuze seeding torrents (via rpc)
	case 'seed':
		$v = VuzeRPC::getInstance();
		$torrents = $v->torrent_get_tf();
		$filter = array('running' => 1, 'status' => 8);
		$torrents = $v->torrent_filter_tf($filter);
		echo print_r($torrents,true);
	break;


	// list vuze downloading torrents (via rpc)
	case 'down':
		$v = VuzeRPC::getInstance();
		$torrents = $v->torrent_get_tf();
		$filter = array('running' => 1, 'status' => 4);
		$torrents = $v->torrent_filter_tf($filter);
		echo print_r($torrents,true);
	break;

	// get vuze session settings (via rpc)
	case 'session':
		$v = VuzeRPC::getInstance();
		$session = $v->session_get();
		print_r($session);
	break;

	// torrent missing in torrentflux
	case 'missing':
		echo "Not in TorrentFlux:\n ";
		$missing = updateStatFiles($bShowMissing=true);
		print_r($missing);
	break;

	case 'delete':
		$v = VuzeRPC::getInstance();
		$session = $v->session_get();
		print_r($session);
	break;


	case 'update':
	default:
		echo $client.": updateStatFiles()\n";
		updateStatFiles();
}
?>
