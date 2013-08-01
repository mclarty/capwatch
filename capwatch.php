<?php
/**
 *
 *	Plugin Name: CAPWATCH Import
 *	Description: Custom plugin for Texas Wing to import the CAPWATCH database to WordPress for user authentication.
 *	Version: 1.1
 *  Revised: 2013-08-01
 *	Author: Nick McLarty
 *	Author URI: http://www.inick.net/
 *	
 */

add_action( 'admin_menu', 'register_capwatch_menu' );

function register_capwatch_menu() {
	add_menu_page( 'CAPWATCH Database Dashboard', 'CAPWATCH', 'manage_options', 'capwatch', 'show_capwatch_menu', NULL, 50 );
}

function show_capwatch_menu() {
	global $wpdb;

	if ( $_FILES ) {
		handle_capwatch_upload();
	}

	if ( $_POST['exclude_orgs'] ) {
		update_option( 'capwatch_exclude_orgs', $_POST['exclude_orgs'] );
		echo '<div class="updated">Excluded organizations updated.</div>';
	}

	$count = dbCountTables();
	$lastUpdated = new DateTime( date( 'r', get_option( 'capwatch_lastUpdated' ) ) );
	$timeNow = new DateTime( 'now' );
	$timeSince = $lastUpdated->diff( $timeNow );
	$timeSinceStr = $timeSince->format( '%mm %dd %hh %im %ss' );

	$excludedOrgs = get_option( 'capwatch_exclude_orgs' );

	$qry = "SELECT ORGID, Wing, Unit, Name FROM wp_capwatch_org WHERE ORGID IN ({$excludedOrgs}) ORDER BY ORGID";
	$rs = $wpdb->get_results( $qry );

	?>
	<div id="wpbody">
		<div id="wpbody-content">
			<div class="wrap">

				<h2>CAPWATCH Database Dashboard</h2>

				<div class="metabox-holder postbox">
					<h3 class="hdnle">Texas Wing Database Statistics</h3>
					<div class="inside">
						<h4>Last Updated: <?php echo $lastUpdated->format( 'r' ) . ' (' . $timeSinceStr . ')'; ?></h4>
						Commanders Table: <?php echo $count['wp_capwatch_commanders']; ?> records<br />
						Members Table: <?php echo $count['wp_capwatch_member']; ?> records<br />
						Member Contacts Table: <?php echo $count['wp_capwatch_member_contact']; ?> records<br />
						Organizations Table: <?php echo $count['wp_capwatch_org']; ?> records<br />
						Org Addresses Table: <?php echo $count['wp_capwatch_org_address']; ?> records<br />
						Org Contacts Table: <?php echo $count['wp_capwatch_org_contact']; ?> records<br />
					</div>
				</div>

				<form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post" enctype="multipart/form-data">
					<input type="file" name="db_file" accept="application/zip" />
					<input type="submit" value="Upload Database" />
				</form>

				<br />

				<div class="metabox-holder postbox">
					<h3 class="hdnle">Organizations Excluded from Authentication</h3>
					<div class="inside">
						<?php

						foreach( $rs as $org ) {
							echo "ORGID {$org->ORGID}: {$org->Wing}-{$org->Unit} {$org->Name}<br />";
						}

						?>

						<br />
						
						<form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post">
							<label for="exclude_orgs">OrgID's to exclude from authentication (comma delimited)</label>
							<input type="text" name="exclude_orgs" id="exclude_orgs" value="<?php echo get_option( 'capwatch_exclude_orgs' ); ?>" />
							<input type="submit" value="Update" />
						</form>

					</div>

			</div>
		</div>
	</div>
	<?php 
}

function capwatchError( $msg ) {
	$error = new WP_Error( 'broke', __( '<div class="error">' . $msg . '</div>' ) );
	echo $error->get_error_message();

	return TRUE;
}

function deleteDir( $dirPath ) {

    if ( !is_dir( $dirPath ) ) {
        throw new InvalidArgumentException( '$dirPath must be a directory' );
    }
    if ( substr( $dirPath, strlen( $dirPath ) - 1, 1 ) != '/' ) {
        $dirPath .= '/';
    }
    $files = glob( $dirPath . '*', GLOB_MARK );
    foreach ( $files as $file ) {
        if ( is_dir( $file ) ) {
            self::deleteDir( $file );
        } else {
            unlink( $file );
        }
    }
    rmdir( $dirPath );

}

function dbCountTables() {
	global $wpdb;

	$tables = array( 'wp_capwatch_commanders', 'wp_capwatch_equipment', 'wp_capwatch_member', 'wp_capwatch_org', 
						'wp_capwatch_org_address', 'wp_capwatch_org_contact', 'wp_capwatch_member_contact' );

	foreach( $tables as $table ) {
		$qry = "SELECT COUNT(*) AS COUNT FROM {$table}";
		$rs = $wpdb->get_results( $qry );
		$count[$table] = $rs[0]->COUNT;
	}

	return $count;
}

function dbLoadTable( $fileName, $tableName ) {
	global $wpdb;

	$qry = "TRUNCATE TABLE {$tableName}";
	$rs = $wpdb->query( $qry );
	if ( $rs == FALSE ) {
		$error = capwatchError( '<strong>MySQL Error:</strong> Query <em>' . $qry . '</em> failed.' );
		$wpdb->print_error();
		return FALSE;
	}

	$qry = "SHOW COLUMNS FROM {$tableName}";
	$rs = $wpdb->get_results( $qry );
	
	foreach( $rs as $key => $column ) {
		$columns[$key] = $column->Field;
	}

	$fileData = file( $fileName );
	$i = 0;

	foreach( $fileData as $row ) {
		if ( $i ) {
			$cols = str_getcsv( $row );
			foreach( $columns as $key => $colName ) {
				$array[$colName] = str_replace( '"', NULL, $cols[$key] );
			}
			$wpdb->insert( $tableName, $array );
		}
		$i++;
	}

}

function readCSV($csvFile){
	$file_handle = fopen($csvFile, 'r');
	while (!feof($file_handle) ) {
		$line_of_text[] = fgetcsv($file_handle, 1024);
	}
	fclose($file_handle);
	return $line_of_text;
}


function handle_capwatch_upload() {

	if ( $errorIndex = $_FILES['db_file']['error'] ) {
		$error = capwatchError( '<strong>Error on Upload:<strong> ' . $errorIndex );
	} else {
		$tmp_name = $_FILES['db_file']['tmp_name'];
		$name = $_FILES['db_file']['name'];
		$upload_dir = wp_upload_dir();
		$upload_dir['capwatch'] = $upload_dir['basedir'] . '/capwatch';
		if ( file_exists( $upload_dir['capwatch'] ) ) {
			deleteDir( $upload_dir['capwatch'] );
		}
		$mkdir = mkdir( $upload_dir['capwatch'] );
	}

	if ( $mkdir ) {
		$moveFile = move_uploaded_file( $tmp_name, $upload_dir['capwatch'] . '/' . $name );
	} elseif ( !$error ) {
		$error = capwatchError( '<strong>Error creating CAPWATCH temporary directory</strong>' );
	}

	if ( $moveFile ) {
		$zip = new ZipArchive;
		$rs = $zip->open( $upload_dir['capwatch'] . '/' . $name );
		if ( $rs ) {
			$zip->extractTo( $upload_dir['capwatch'] );
			$zip->close();
			$unzipped = TRUE;
		} elseif ( !$error ) {
			$error = capwatchError( '<strong>Error during unzip of CAPWATCH archive</strong>' );
		}
	} elseif ( !$error ) {
		$error = capwatchError( '<strong>Error moving uploaded file to CAPWATCH temporary directory</strong>' );
	}

	if ( $unzipped ) {
		dbLoadTable( $upload_dir['capwatch'] . '/Commanders.txt', 'wp_capwatch_commanders' );
		dbLoadTable( $upload_dir['capwatch'] . '/equipment.txt', 'wp_capwatch_equipment' );
		dbLoadTable( $upload_dir['capwatch'] . '/Member.txt', 'wp_capwatch_member' );
		dbLoadTable( $upload_dir['capwatch'] . '/Organization.txt', 'wp_capwatch_org' );
		dbLoadTable( $upload_dir['capwatch'] . '/OrgAddresses.txt', 'wp_capwatch_org_address' );
		dbLoadTable( $upload_dir['capwatch'] . '/OrgContact.txt', 'wp_capwatch_org_contact' );
		dbLoadTable( $upload_dir['capwatch'] . '/MbrContact.txt', 'wp_capwatch_member_contact' );
		deleteDir( $upload_dir['capwatch'] );
	}

	update_option( 'capwatch_lastUpdated', time() );

	echo '<div class="updated">CAPWATCH database updated.</div>';

}