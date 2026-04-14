<?php

require_once( "config.php" );
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/parser.php';
require_once __DIR__ . '/includes/layout.php';

/* try to connect */

$inbox = imap_open( MAILBOX, MAILUSERNAME, MAILPASSWORD ) or die( 'Cannot connect to mail server: ' . imap_last_error() );


/* grab emails */
$emails = imap_search( $inbox, 'ALL' );

/* if emails are returned, cycle through each... */

if( $emails ) {
    renderHead( 'Import from Mailbox' );
    $db     = Database::getInstance();
    $output = '';
    rsort( $emails );
    $imported = 0;
    $skipped  = 0;
    $errors   = 0;

    foreach( $emails as $email_number ) {
        $structure   = imap_fetchstructure( $inbox, $email_number );
        $attachments = [];

        // Find attachments
        if( isset( $structure->parts ) && count( $structure->parts ) ) {
            for( $i = 0; $i < count( $structure->parts ); $i++ ) {
                $part          = $structure->parts[$i];
                $is_attachment = false;
                $filename      = '';

                if( isset( $part->dparameters ) ) {
                    foreach( $part->dparameters as $object ) {
                        if( strtolower( $object->attribute ) == 'filename' ) {
                            $is_attachment = true;
                            $filename      = $object->value;
                        }
                    }
                }
                if( $is_attachment && preg_match( '/\.xml\.gz$/i', $filename ) ) {
                    $attachments[] = [
                        'index'    => $i + 1, // IMAP parts are 1-based
                        'filename' => $filename
                    ];
                }
            }
        }

        $email_has_error = false;
        foreach( $attachments as $att ) {
            $body = imap_fetchbody( $inbox, $email_number, $att['index'] );
            // Decode if needed
            $encoding = $structure->parts[$att['index'] - 1]->encoding;
            switch( $encoding ) {
                case 3: // BASE64
                    $body = base64_decode( $body );
                    break;
                case 4: // QUOTED-PRINTABLE
                    $body = quoted_printable_decode( $body );
                    break;
            }

            // Save to temp file
            $tmpPath = sys_get_temp_dir() . '/' . uniqid( 'dmarc_', true ) . '.xml.gz';
            file_put_contents( $tmpPath, $body );

            try {
                $parsed   = DmarcParser::parseFile( $tmpPath );
                $reportId = $db->insertReport( $parsed['report'] );
                if( $reportId === 0 ) {
                    $output .= '<div class="alert alert-info">Duplicate report in ' . htmlspecialchars( $att['filename'] ) . ', skipped.</div>';
                    $skipped++;
                } else {
                    foreach( $parsed['records'] as $rec ) {
                        $rec[':report_db_id'] = $reportId;
                        $db->insertRecord( $rec );
                    }
                    $output .= '<div class="alert alert-success">Imported ' . htmlspecialchars( $att['filename'] ) . ' (' . count( $parsed['records'] ) . ' records)</div>';
                    $imported++;
                }
            } catch ( Throwable $e ) {
                $output .= '<div class="alert alert-error">Error parsing ' . htmlspecialchars( $att['filename'] ) . ': ' . htmlspecialchars( $e->getMessage() ) . '</div>';
                $errors++;
                $email_has_error = true;
            }
            @unlink( $tmpPath );
        }

        // Only delete the email if no errors occurred during import
        if( !$email_has_error ) {
            imap_delete( $inbox, $email_number );
        }
    }
    imap_expunge( $inbox );
    $output .= "<div class='alert alert-info'>Done. Imported: $imported, Skipped: $skipped, Errors: $errors</div>";
    echo $output;
} else {
    renderHead( 'Import from Mailbox' );
    echo '<div class="alert alert-info">No emails found.</div>';
}
renderFoot();

/* close the connection */
imap_close( $inbox );
?>