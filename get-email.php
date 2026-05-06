<?php

require_once( "config.php" );
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/parser.php';
header( 'Content-Type: application/json; charset=utf-8' );

try {
    /* try to connect */
    $inbox = imap_open( MAILBOX, MAILUSERNAME, MAILPASSWORD );
    if( $inbox === false ) {
        http_response_code( 500 );
        echo json_encode( [
            'success' => false,
            'error'   => 'Cannot connect to mail server: ' . imap_last_error()
        ], JSON_UNESCAPED_SLASHES );
        exit;
    }

    /* grab emails */
    $emails = imap_search( $inbox, 'ALL' );
    $db     = Database::getInstance();
    $imported = 0;
    $skipped  = 0;
    $errors   = 0;
    $messages = [];
    $deletedEmails = 0;

    /* if emails are returned, cycle through each... */
    if( $emails ) {
        rsort( $emails );

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
                $body = match( $encoding ) {
                    3 => base64_decode( $body ),
                    4 => quoted_printable_decode( $body ),
                    default => $body
                };

                // Save to temp file
                $tmpPath = sys_get_temp_dir() . '/' . uniqid( 'dmarc_', true ) . '.xml.gz';
                file_put_contents( $tmpPath, $body );

                try {
                    $parsed   = DmarcParser::parseFile( $tmpPath );
                    $reportId = $db->insertReport( $parsed['report'] );
                    if( $reportId === 0 ) {
                        $messages[] = [
                            'type'     => 'info',
                            'filename' => $att['filename'],
                            'message'  => 'Duplicate report, skipped.'
                        ];
                        $skipped++;
                    } else {
                        foreach( $parsed['records'] as $rec ) {
                            $rec[':report_db_id'] = $reportId;
                            $db->insertRecord( $rec );
                        }
                        $messages[] = [
                            'type'     => 'success',
                            'filename' => $att['filename'],
                            'message'  => 'Imported report.',
                            'records'  => count( $parsed['records'] )
                        ];
                        $imported++;
                    }
                } catch ( Throwable $e ) {
                    $messages[] = [
                        'type'     => 'error',
                        'filename' => $att['filename'],
                        'message'  => 'Error parsing report: ' . $e->getMessage()
                    ];
                    $errors++;
                    $email_has_error = true;
                }
                @unlink( $tmpPath );
            }

            // Only delete the email if no errors occurred during import
            if( !$email_has_error ) {
                imap_delete( $inbox, $email_number );
                $deletedEmails++;
            }
        }
        imap_expunge( $inbox );
    }

    http_response_code( $errors > 0 ? 207 : 200 );
    echo json_encode( [
        'success' => $errors === 0,
        'summary' => [
            'emails_found'    => is_array( $emails ) ? count( $emails ) : 0,
            'emails_deleted'  => $deletedEmails,
            'imported'        => $imported,
            'skipped'         => $skipped,
            'errors'          => $errors
        ],
        'messages' => $messages
    ], JSON_UNESCAPED_SLASHES );

    /* close the connection */
    imap_close( $inbox );
} catch ( Throwable $e ) {
    http_response_code( 500 );
    echo json_encode( [
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES );
}
?>