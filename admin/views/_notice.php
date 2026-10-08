<?php
/**
 * Shared admin feedback notice (nd_msg / nd_err from redirects).
 * Whitelist-only: an unknown message renders nothing.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;

$messages = array(
	'settings-saved'    => array( 'success', __( 'Settings saved.', 'newsdesk-ai' ) ),
	'settings-invalid'  => array( 'error', __( 'Some values were invalid; please check them again.', 'newsdesk-ai' ) ),
	'digest-sent'       => array( 'success', __( 'Daily digest sent.', 'newsdesk-ai' ) ),
	'digest-skipped'    => array( 'warning', __( 'The digest was not sent (it is disabled, or it already went out today).', 'newsdesk-ai' ) ),
	'job-created'       => array( 'success', __( 'The job was created and queued.', 'newsdesk-ai' ) ),
	'provider-ok'       => array( 'success', __( 'Connected to provider: ', 'newsdesk-ai' ) . ( isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '' ) ), // phpcs:ignore
	'run-now-done'      => array( 'success', __( 'The immediate run has finished. Check “Last run” for the job status and the logs for detail.', 'newsdesk-ai' ) ),
	'job-exists'        => array( 'info', __( 'A similar job is already queued or running.', 'newsdesk-ai' ) ),
	'retry-failed'      => array( 'error', __( 'Could not load the job.', 'newsdesk-ai' ) ),
	'source-created'    => array( 'success', __( 'Source added.', 'newsdesk-ai' ) ),
	'source-saved'      => array( 'success', __( 'Source updated.', 'newsdesk-ai' ) ),
	'source-deleted'    => array( 'success', __( 'Source deleted.', 'newsdesk-ai' ) ),
	'source-toggled'    => array( 'success', __( 'Source status changed.', 'newsdesk-ai' ) ),
	'delete-failed'     => array( 'error', __( 'Could not delete.', 'newsdesk-ai' ) ),
	'draft-approved'    => array( 'success', __( 'Draft approved — publishing is still manual (§73).', 'newsdesk-ai' ) ),
	'draft-rejected'    => array( 'success', __( 'Draft rejected.', 'newsdesk-ai' ) ),
	'source-tested'     => array(
		'success',
		sprintf(
			/* translators: 1: number of items fetched, 2: elapsed milliseconds */
			__( 'Connected — %1$d items fetched in %2$d ms.', 'newsdesk-ai' ),
			isset( $_GET['count'] ) ? (int) $_GET['count'] : 0, // phpcs:ignore
			isset( $_GET['ms'] ) ? (int) $_GET['ms'] : 0 // phpcs:ignore
		)
		. ( isset( $_GET['sample'] ) && '' !== $_GET['sample'] // phpcs:ignore
			? ' ' . __( 'Example:', 'newsdesk-ai' ) . ' «' . sanitize_text_field( wp_unslash( $_GET['sample'] ) ) . '»' // phpcs:ignore
			: '' )
	),
	'logs-cleared'      => array(
		'success',
		sprintf(
			/* translators: %d: number of deleted log rows */
			__( '%d log rows cleared.', 'newsdesk-ai' ),
			isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : 0 // phpcs:ignore
		)
	),
);

$msgKey = isset( $_GET['nd_msg'] ) ? sanitize_key( wp_unslash( $_GET['nd_msg'] ) ) : ''; // phpcs:ignore
if ( '' !== $msgKey && isset( $messages[ $msgKey ] ) ) {
	$type = $messages[ $msgKey ][0];
	$text = $messages[ $msgKey ][1];
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>'; // phpcs:ignore
	unset( $type, $text );
}
if ( isset( $_GET['nd_err'] ) ) { // phpcs:ignore
	$err = trim( (string) wp_unslash( $_GET['nd_err'] ) ); // phpcs:ignore
	if ( '' !== $err ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>'; // phpcs:ignore
	}
}
unset( $messages, $msgKey, $err );
