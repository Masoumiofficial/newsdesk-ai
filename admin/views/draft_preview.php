<?php
/**
 * Single-version review screen (v1.2) — data: version, story, claims,
 * used_claim_ids, unknown_claim_ids, versions.
 *
 * Read-only render of content_json (never the WP post) so the editor judges
 * exactly what the machine produced. Approve/reject re-use the Phase 6 actions.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */

/** @var \NewsDesk\AI\Domain\Entity\ContentVersion $v */
$v = $view['version'];
/** @var \NewsDesk\AI\Domain\Entity\Story $st */
$st      = $view['story'];
$claims  = $view['claims'];
$a       = is_array( $v->content ) ? $v->content : array();
$isFa    = 'fa' === $st->langCode();
$dir     = $isFa ? 'rtl' : 'ltr';
$pending = \NewsDesk\AI\Domain\Entity\ContentVersion::STATUS_NEEDS_REVIEW === $v->status;
$backUrl = add_query_arg( array( 'page' => 'nd-drafts' ), admin_url( 'admin.php' ) );
$postUrl = $v->draftPostId > 0 ? get_edit_post_link( $v->draftPostId ) : '';

$statusBadge = static function ( string $s ): string {
	$map = array(
		'VERIFIED'           => '#00a32a',
		'PARTIALLY_VERIFIED' => '#dba617',
		'UNVERIFIED'         => '#8c8f94',
		'CONTRADICTED'       => '#d63638',
	);
	$c = $map[ $s ] ?? '#8c8f94';
	return '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;color:#fff;background:' . esc_attr( $c ) . '">' . esc_html( $s ) . '</span>';
};
/**
 * Claim risk is assessed on every claim and was, until now, never shown. An
 * editor approving a draft could not see that a CRITICAL security claim was
 * only PARTIALLY_VERIFIED -- which is precisely the decision this screen exists
 * to support.
 */
$riskBadge = static function ( string $risk, string $action ): string {
	if ( '' === $risk ) {
		return '<span class="description">—</span>';
	}
	$map = array(
		'CRITICAL' => '#d63638',
		'HIGH'     => '#cc5500',
		'MEDIUM'   => '#dba617',
		'LOW'      => '#8c8f94',
	);
	$c   = $map[ strtoupper( $risk ) ] ?? '#8c8f94';
	$out = '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;color:#fff;background:' . esc_attr( $c ) . '">' . esc_html( strtoupper( $risk ) ) . '</span>';
	if ( '' !== $action ) {
		$out .= '<br /><code style="font-size:10px">' . esc_html( strtoupper( $action ) ) . '</code>';
	}
	return $out;
};

$claimChips = static function ( array $ids ) use ( $claims, $statusBadge ): string {
	if ( ! $ids ) {
		return '<span class="description">' . esc_html__( 'No evidence citation', 'newsdesk-ai' ) . '</span>';
	}
	$out = array();
	foreach ( $ids as $id ) {
		$id = (string) $id;
		if ( isset( $claims[ $id ] ) ) {
			$out[] = '<a href="#claim-' . esc_attr( $id ) . '" title="' . esc_attr( $claims[ $id ]->claimText ) . '" style="text-decoration:none">' . $statusBadge( (string) $claims[ $id ]->verificationStatus ) . '</a>';
		} else {
			$out[] = '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;color:#fff;background:#d63638">' . esc_html__( 'Unknown', 'newsdesk-ai' ) . ' ' . esc_html( mb_substr( $id, 0, 8 ) ) . '</span>';
		}
	}
	return implode( ' ', $out );
};
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Preview version', 'newsdesk-ai' ), $st->canonicalTitle . ' · v' . (int) $v->versionNo ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>
	<p><a href="<?php echo esc_url( $backUrl ); ?>">&larr; <?php esc_html_e( 'Back to the review queue', 'newsdesk-ai' ); ?></a></p>

	<?php if ( ! empty( $view['unknown_claim_ids'] ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php
			/* translators: %d: number of claim ids */
			echo esc_html( sprintf( _n( '%d citations in the body point to evidence that does not exist in the database.', '%d ارجاع در متن به شواهدی اشاره می‌کنند که در پایگاه داده وجود ندارند.', count( $view['unknown_claim_ids'] ), 'newsdesk-ai' ), count( $view['unknown_claim_ids'] ) ) );
			?>
		</p></div>
	<?php endif; ?>

	<div class="nd-preview-layout">

		<!-- ============ Article ============ -->
		<div class="nd-preview-article">
			<div class="nd-card" style="padding:22px 28px;max-width:860px;border-inline-start-width:3px">
				<article dir="<?php echo esc_attr( $dir ); ?>" style="line-height:1.9;font-size:15px">
					<h2 style="margin-top:0;font-size:24px;line-height:1.4"><?php echo esc_html( (string) ( $a['title'] ?? '' ) ); ?></h2>
					<p class="description" style="margin:-6px 0 14px"><?php esc_html_e( 'Meta description:', 'newsdesk-ai' ); ?> <?php echo esc_html( (string) ( $a['meta_description'] ?? '' ) ); ?></p>
					<p style="font-weight:600"><?php echo esc_html( (string) ( $a['lead'] ?? '' ) ); ?></p>

					<?php foreach ( (array) ( $a['sections'] ?? array() ) as $i => $sec ) : ?>
						<h3 style="font-size:18px;margin:26px 0 6px">
							<?php echo esc_html( (string) ( $sec['heading'] ?? '' ) ); ?>
							<?php if ( ! empty( $sec['type'] ) ) : ?><code style="font-size:11px;margin-inline-start:6px"><?php echo esc_html( (string) $sec['type'] ); ?></code><?php endif; ?>
						</h3>
						<div style="margin-bottom:6px"><?php echo $claimChips( (array) ( $sec['claim_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
						<?php foreach ( (array) ( $sec['paragraphs'] ?? array() ) as $p ) : ?>
							<p><?php echo esc_html( (string) $p ); ?></p>
						<?php endforeach; ?>
					<?php endforeach; ?>

					<?php $faq = (array) ( $a['faq'] ?? array() ); ?>
					<?php if ( $faq ) : ?>
						<h3 style="font-size:18px;margin:26px 0 6px"><?php echo esc_html( $isFa ? 'پرسش‌های پرتکرار' : 'FAQ' ); ?></h3>
						<?php foreach ( $faq as $f ) : ?>
							<p><strong><?php echo esc_html( (string) ( $f['question'] ?? '' ) ); ?></strong>
								<span style="margin-inline-start:6px"><?php echo $claimChips( (array) ( $f['claim_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><br />
								<?php echo esc_html( (string) ( $f['answer'] ?? '' ) ); ?></p>
						<?php endforeach; ?>
					<?php endif; ?>

					<?php $notes = (array) ( $a['citation_notes'] ?? array() ); ?>
					<?php if ( $notes ) : ?>
						<h3 style="font-size:15px;margin:26px 0 6px"><?php esc_html_e( 'Citation notes', 'newsdesk-ai' ); ?></h3>
						<ul style="list-style:disc;padding-inline-start:20px"><?php foreach ( $notes as $n ) : ?><li><?php echo esc_html( (string) $n ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
				</article>
			</div>
		</div>

		<!-- ============ Sidebar ============ -->
		<div class="nd-preview-side">

			<div class="nd-card" style="margin-bottom:14px">
				<h3 style="margin-top:0"><?php esc_html_e( 'Decision', 'newsdesk-ai' ); ?></h3>
				<p>
					<?php esc_html_e( 'Status:', 'newsdesk-ai' ); ?> <strong><?php echo esc_html( $v->status ); ?></strong><br />
					<?php esc_html_e( 'Quality score:', 'newsdesk-ai' ); ?> <strong><?php echo esc_html( number_format_i18n( (float) $v->qualityScore, 1 ) ); ?></strong> / 100
					<?php if ( '' !== $v->errorCode ) : ?><br /><code><?php echo esc_html( $v->errorCode ); ?></code><?php endif; ?>
				</p>
				<?php if ( $pending ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px">
						<input type="hidden" name="action" value="<?php echo esc_attr( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_APPROVE ); ?>" />
						<input type="hidden" name="version_id" value="<?php echo (int) $v->versionId; ?>" />
						<?php wp_nonce_field( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_APPROVE ); ?>
						<?php submit_button( __( 'Approve — ready for an editor to publish', 'newsdesk-ai' ), 'primary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_REJECT ); ?>" />
						<input type="hidden" name="version_id" value="<?php echo (int) $v->versionId; ?>" />
						<?php wp_nonce_field( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_REJECT ); ?>
						<input type="text" name="reason" class="regular-text" style="width:100%;margin-bottom:6px" maxlength="255" placeholder="<?php esc_attr_e( 'Reason for rejection (optional)', 'newsdesk-ai' ); ?>" />
						<?php submit_button( __( 'Reject', 'newsdesk-ai' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'This version is not awaiting review; it is view-only.', 'newsdesk-ai' ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $postUrl ) : ?>
					<p style="margin-bottom:0"><a href="<?php echo esc_url( $postUrl ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the draft in the WordPress editor ↗', 'newsdesk-ai' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="nd-card" style="margin-bottom:14px">
				<h3 style="margin-top:0"><?php esc_html_e( 'Generation details', 'newsdesk-ai' ); ?></h3>
				<table class="widefat" style="border:0">
					<?php if ( ! empty( $a['news_type'] ) ) : ?>
					<tr><td><?php esc_html_e( 'News type', 'newsdesk-ai' ); ?></td><td><?php echo \NewsDesk\AI\Admin\AdminView::badge( 'brand', (string) $a['news_type'] ); // phpcs:ignore ?></td></tr>
					<?php endif; ?>
					<?php if ( ! empty( $a['focus_keyword'] ) ) : ?>
					<tr><td><?php esc_html_e( 'Focus keyword', 'newsdesk-ai' ); ?></td><td><strong><?php echo esc_html( (string) $a['focus_keyword'] ); ?></strong>
						<?php if ( ! empty( $a['secondary_keywords'] ) ) : ?><div class="description"><?php echo esc_html( implode( ' · ', (array) $a['secondary_keywords'] ) ); ?></div><?php endif; ?>
						<?php if ( ! empty( $a['search_intent'] ) ) : ?><div class="description">Intent: <?php echo esc_html( (string) $a['search_intent'] ); ?></div><?php endif; ?>
					</td></tr>
					<?php endif; ?>
					<tr><td><?php esc_html_e( 'Provider / model', 'newsdesk-ai' ); ?></td><td dir="ltr"><?php echo esc_html( $v->provider . ' / ' . $v->model ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Prompt version', 'newsdesk-ai' ); ?></td><td dir="ltr"><?php echo esc_html( $v->promptVersion ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Job', 'newsdesk-ai' ); ?></td><td>#<?php echo (int) $v->jobId; ?></td></tr>
					<tr><td><?php esc_html_e( 'Create', 'newsdesk-ai' ); ?></td><td><?php echo esc_html( $v->createdAt ? $v->createdAt->format( 'Y-m-d H:i' ) : '—' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Content hash', 'newsdesk-ai' ); ?></td><td dir="ltr"><code><?php echo esc_html( mb_substr( $v->contentHash, 0, 12 ) ); ?></code></td></tr>
					<tr><td><?php esc_html_e( 'Story', 'newsdesk-ai' ); ?></td><td>
						<?php esc_html_e( 'Evidence:', 'newsdesk-ai' ); ?> <?php echo (int) $st->evidenceCount; ?> ·
						<?php esc_html_e( 'Approved:', 'newsdesk-ai' ); ?> <?php echo (int) $st->verifiedClaimCount; ?> ·
						<?php esc_html_e( 'Contradiction:', 'newsdesk-ai' ); ?> <?php echo (int) $st->contradictionCount; ?>
					</td></tr>
				</table>
			</div>

			<?php if ( count( $view['versions'] ) > 1 ) : ?>
			<div class="nd-card" style="margin-bottom:14px">
				<h3 style="margin-top:0"><?php esc_html_e( 'Other versions', 'newsdesk-ai' ); ?></h3>
				<ul style="margin:0">
					<?php foreach ( $view['versions'] as $o ) : ?>
						<li>
							<?php if ( $o->versionId === $v->versionId ) : ?>
								<strong>v<?php echo (int) $o->versionNo; ?></strong>
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-drafts', 'preview' => (int) $o->versionId ), admin_url( 'admin.php' ) ) ); ?>">v<?php echo (int) $o->versionNo; ?></a>
							<?php endif; ?>
							— <?php echo esc_html( $o->status ); ?> · <?php echo esc_html( number_format_i18n( (float) $o->qualityScore, 1 ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- ============ Security intelligence ============ -->
	<?php if ( ! empty( $st->isSecurity ) ) : ?>
		<?php
		$nd_sev_colour = array(
			'CRITICAL' => '#d63638',
			'HIGH'     => '#cc5500',
			'MEDIUM'   => '#dba617',
			'LOW'      => '#8c8f94',
		);
		$nd_sev = strtoupper( (string) $st->severity );
		$nd_cve = is_array( $st->cveIds ) ? $st->cveIds : array();
		$nd_aff = is_array( $st->affectedVersions ) ? $st->affectedVersions : array();
		$nd_fix = is_array( $st->fixedVersions ) ? $st->fixedVersions : array();
		?>
		<h2 style="margin-top:28px"><?php esc_html_e( 'Security intelligence', 'newsdesk-ai' ); ?>
			<span class="description" style="font-weight:400;font-size:13px">
				<?php esc_html_e( 'Extracted by pattern matching, never by a model.', 'newsdesk-ai' ); ?>
			</span>
		</h2>
		<table class="widefat striped">
			<tbody>
			<?php if ( '' !== $nd_sev ) : ?>
				<tr>
					<th style="width:180px"><?php esc_html_e( 'Severity', 'newsdesk-ai' ); ?></th>
					<td>
						<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr( $nd_sev_colour[ $nd_sev ] ?? '#8c8f94' ); ?>"><?php echo esc_html( $nd_sev ); ?></span>
						<?php if ( (float) $st->cvssScore > 0 ) : ?>
							<code style="margin-inline-start:8px">CVSS <?php echo esc_html( number_format_i18n( (float) $st->cvssScore, 1 ) ); ?></code>
						<?php endif; ?>
						<?php if ( ! empty( $st->exploited ) ) : ?>
							<strong style="color:#d63638;margin-inline-start:8px"><?php esc_html_e( 'Exploited in the wild', 'newsdesk-ai' ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( $nd_cve ) : ?>
				<tr>
					<th><?php esc_html_e( 'CVE', 'newsdesk-ai' ); ?></th>
					<td dir="ltr">
						<?php foreach ( $nd_cve as $nd_id ) : ?>
							<a href="<?php echo esc_url( 'https://nvd.nist.gov/vuln/detail/' . rawurlencode( (string) $nd_id ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( (string) $nd_id ); ?></code></a>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( $nd_aff ) : ?>
				<tr>
					<th><?php esc_html_e( 'Affected versions', 'newsdesk-ai' ); ?></th>
					<td dir="ltr"><code><?php echo esc_html( implode( ', ', array_map( 'strval', $nd_aff ) ) ); ?></code></td>
				</tr>
			<?php endif; ?>
			<?php if ( $nd_fix ) : ?>
				<tr>
					<th><?php esc_html_e( 'Fixed in', 'newsdesk-ai' ); ?></th>
					<td dir="ltr"><code><?php echo esc_html( implode( ', ', array_map( 'strval', $nd_fix ) ) ); ?></code></td>
				</tr>
			<?php endif; ?>
			<?php if ( '' !== (string) $st->vendorAdvisory ) : ?>
				<tr>
					<th><?php esc_html_e( 'Vendor advisory', 'newsdesk-ai' ); ?></th>
					<td><a href="<?php echo esc_url( (string) $st->vendorAdvisory ); ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?php echo esc_html( (string) $st->vendorAdvisory ); ?> ↗</a></td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<!-- ============ Evidence map ============ -->
	<h2 style="margin-top:28px"><?php esc_html_e( 'Evidence map', 'newsdesk-ai' ); ?>
		<span class="description" style="font-weight:400;font-size:13px">
			<?php
			/* translators: 1: number of claims used in text, 2: total claims for story */
			echo esc_html( sprintf( __( '%1$d of %2$d claims are used in the body', 'newsdesk-ai' ), count( array_intersect( $view['used_claim_ids'], array_keys( $claims ) ) ), count( $claims ) ) );
			?>
		</span>
	</h2>
	<?php if ( ! $claims ) : ?>
		<p class="description"><?php esc_html_e( 'No evidence has been recorded for this story.', 'newsdesk-ai' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr>
				<th style="width:110px"><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
				<th style="width:110px"><?php esc_html_e( 'Risk / action', 'newsdesk-ai' ); ?></th>
				<th><?php esc_html_e( 'Claim', 'newsdesk-ai' ); ?></th>
				<th><?php esc_html_e( 'Evidence snippet', 'newsdesk-ai' ); ?></th>
				<th style="width:80px"><?php esc_html_e( 'Confidence', 'newsdesk-ai' ); ?></th>
				<th style="width:70px"><?php esc_html_e( 'In body', 'newsdesk-ai' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $claims as $id => $c ) : ?>
				<tr id="claim-<?php echo esc_attr( (string) $id ); ?>" <?php echo in_array( (string) $id, $view['used_claim_ids'], true ) ? '' : 'style="opacity:.55"'; ?>>
					<td><?php echo $statusBadge( (string) $c->verificationStatus ); // phpcs:ignore WordPress.Security.EscapeOutput ?><br /><code style="font-size:10px"><?php echo esc_html( $c->claimType ); ?></code></td>
					<td><?php echo $riskBadge( (string) $c->risk, (string) $c->action ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( $c->claimText ); ?></td>
					<td>
						<em><?php echo esc_html( mb_substr( (string) $c->supportSnippet, 0, 220 ) ); ?><?php echo mb_strlen( (string) $c->supportSnippet ) > 220 ? '…' : ''; ?></em>
						<?php if ( '' !== $c->sourceUrl ) : ?><br /><a href="<?php echo esc_url( $c->sourceUrl ); ?>" target="_blank" rel="noopener noreferrer" dir="ltr" style="font-size:11px"><?php echo esc_html( wp_parse_url( $c->sourceUrl, PHP_URL_HOST ) ); ?> ↗</a><?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (float) $c->confidence * 100, 0 ) ); ?>%</td>
					<td><?php echo in_array( (string) $id, $view['used_claim_ids'], true ) ? '✅' : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
