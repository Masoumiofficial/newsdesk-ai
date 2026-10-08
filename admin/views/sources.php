<?php
/**
 * Sources view — data: sources, total, page, perPage, filters, form, editing, msg, err, categories, types.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */

$msgs = array(
	'source-created' => __( 'Source created successfully.', 'newsdesk-ai' ),
	'source-saved'   => __( 'Source updated.', 'newsdesk-ai' ),
	'source-deleted' => __( 'The source and its news items were deleted.', 'newsdesk-ai' ),
	'delete-failed'  => __( 'Delete failed.', 'newsdesk-ai' ),
	'source-toggled' => __( 'Source status changed.', 'newsdesk-ai' ),
);
if ( ! empty( $view['msg'] ) && isset( $msgs[ $view['msg'] ] ) ) {
	\NewsDesk\AI\Admin\AdminView::notice( 'success', $msgs[ $view['msg'] ] );
}
if ( ! empty( $view['err'] ) ) {
	\NewsDesk\AI\Admin\AdminView::notice( 'error', $view['err'] );
}

$form = $view['form'];
$action = admin_url( 'admin-post.php' );
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'News sources', 'newsdesk-ai' ), __( 'Feeds and news sites with trust scores', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nd-filter">
		<input type="hidden" name="page" value="nd-sources" />
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Search by name…', 'newsdesk-ai' ); ?>" value="<?php echo esc_attr( $view['filters']['search'] ); ?>" />
		<select name="type">
			<option value=""><?php esc_html_e( 'All types', 'newsdesk-ai' ); ?></option>
			<?php foreach ( $view['types'] as $t ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $view['filters']['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'newsdesk-ai' ); ?></option>
			<?php foreach ( array( 'active', 'paused', 'error', 'disabled' ) as $st ) : ?>
				<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $view['filters']['status'], $st ); ?>><?php echo esc_html( $st ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button"><?php esc_html_e( 'Filter', 'newsdesk-ai' ); ?></button>
	</form>

	<div class="nd-table-wrap"><table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Name', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Type', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Language', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Trust', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Last success', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'newsdesk-ai' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $view['sources'] ) ) : ?>
			<tr><td colspan="7"><?php \NewsDesk\AI\Admin\AdminView::empty( __( 'No sources registered', 'newsdesk-ai' ), __( 'Use the form below to add your first feed or news site.', 'newsdesk-ai' ), '📡' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $view['sources'] as $src ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $src->name ); ?></strong><br />
						<a href="<?php echo esc_url( $src->feedUrl ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $src->feedUrl ); ?></a>
					</td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::badge( 'brand', (string) $src->type ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( $src->language ); ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $src->trustScore ); // phpcs:ignore ?><?php echo $src->trustOverride ? ' 🔒' : ''; ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::badge( (string) $src->status ); // phpcs:ignore ?></td>
					<td><?php echo $src->lastSuccessAt ? esc_html( $src->lastSuccessAt->format( 'Y-m-d H:i' ) ) : '—'; ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-sources', 'edit' => $src->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsdesk-ai' ); ?></a>
						<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline-block">
							<input type="hidden" name="action" value="newsdesk_newsroom_source_toggle" />
							<input type="hidden" name="source_id" value="<?php echo (int) $src->id; ?>" />
							<input type="hidden" name="active" value="<?php echo $src->active ? '0' : '1'; ?>" />
							<?php wp_nonce_field( 'newsdesk_newsroom_source_toggle' ); ?>
							<button class="button button-small" type="submit"><?php echo $src->active ? esc_html__( 'Paused', 'newsdesk-ai' ) : esc_html__( 'Active', 'newsdesk-ai' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline-block">
							<input type="hidden" name="action" value="newsdesk_newsroom_source_test" />
							<input type="hidden" name="source_id" value="<?php echo (int) $src->id; ?>" />
							<?php wp_nonce_field( 'newsdesk_newsroom_source_test' ); ?>
							<button class="button button-small" type="submit"><?php esc_html_e( 'Test connection', 'newsdesk-ai' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this source and all of its news items?', 'newsdesk-ai' ) ); ?>');">
							<input type="hidden" name="action" value="newsdesk_newsroom_source_delete" />
							<input type="hidden" name="source_id" value="<?php echo (int) $src->id; ?>" />
							<?php wp_nonce_field( 'newsdesk_newsroom_source_delete' ); ?>
							<button class="button button-small button-link-delete" type="submit"><?php esc_html_e( 'Delete', 'newsdesk-ai' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table></div>

	<?php
	$pages = (int) ceil( $view['total'] / max( 1, $view['perPage'] ) );
	if ( $pages > 1 ) {
		echo '<p>';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg( array( 'page' => 'nd-sources', 'paged' => $i ), admin_url( 'admin.php' ) );
			printf(
				'<a class="button button-small %s" href="%s">%d</a> ',
				$i === $view['page'] ? 'button-primary' : '',
				esc_url( $url ),
				(int) $i
			);
		}
		echo '</p>';
	}
	?>

	<hr />
	<h2><?php echo $view['editing'] ? esc_html__( 'Edit source', 'newsdesk-ai' ) : esc_html__( 'Add source', 'newsdesk-ai' ); ?></h2>
	<div class="nd-panel"><form method="post" action="<?php echo esc_url( $action ); ?>" class="nd-form">
		<input type="hidden" name="action" value="newsdesk_newsroom_source_save" />
		<?php if ( $view['editing'] ) : ?>
			<input type="hidden" name="source_id" value="<?php echo (int) $view['editing']; ?>" />
		<?php endif; ?>
		<?php wp_nonce_field( 'newsdesk_newsroom_source_save' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_name"><?php esc_html_e( 'Source name *', 'newsdesk-ai' ); ?></label></th>
				<td><input required type="text" id="nd_name" name="name" class="regular-text" value="<?php echo esc_attr( $form->name ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_type"><?php esc_html_e( 'Type', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_type" name="type">
						<?php foreach ( $view['types'] as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $form->type, $t ); ?>><?php echo esc_html( $t ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'rss/atom: a standard feed. web: extraction from an HTML listing page — automatic via JSON-LD/OpenGraph, with CSS selector overrides available.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_feed"><?php esc_html_e( 'Feed URL', 'newsdesk-ai' ); ?></label></th>
				<td><input type="url" id="nd_feed" name="feed_url" class="regular-text" value="<?php echo esc_attr( $form->feedUrl ); ?>" placeholder="https://example.com/feed.xml" />
					<p class="description"><?php esc_html_e( 'For web sources: the URL of a listing page (the home page or a section).', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<?php
			$ws = is_array( $form->settings ) ? $form->settings : array();
			$webFields = array(
				'list_item'       => array( __( 'List item selector', 'newsdesk-ai' ), 'article.post, li.news-item', false ),
				'list_link'       => array( __( 'Article link selector', 'newsdesk-ai' ), 'h2 a, a.title', false ),
				'url_pattern'     => array( __( 'Article URL pattern (regex)', 'newsdesk-ai' ), '/news/\d+', false ),
				'article_title'   => array( __( 'Title selector (article page)', 'newsdesk-ai' ), 'h1.title', false ),
				'article_content' => array( __( 'Body selector (article page)', 'newsdesk-ai' ), 'div.article-body', false ),
				'article_date'    => array( __( 'Date selector (article page)', 'newsdesk-ai' ), 'time[datetime]', false ),
				'article_author'  => array( __( 'Author selector (article page)', 'newsdesk-ai' ), 'span.author', false ),
				'max_articles'    => array( __( 'Maximum items per fetch', 'newsdesk-ai' ), '15', true ),
			);
			?>
			<tr class="nd-web-only" <?php echo 'web' === $form->type ? '' : 'style="display:none"'; ?>>
				<th><?php esc_html_e( 'Web extraction settings', 'newsdesk-ai' ); ?></th>
				<td>
					<p class="description" style="margin-bottom:8px"><?php esc_html_e( 'All optional. Blank = automatic detection (JSON-LD → OpenGraph → <article>/<h1>). Fill these in only when automatic detection gets it wrong. Supported selectors: tag, .class, #id, [attr=val], descendant spaces and >.', 'newsdesk-ai' ); ?></p>
					<?php foreach ( $webFields as $wk => $wf ) : ?>
						<p>
							<label style="display:inline-block;min-width:220px"><?php echo esc_html( $wf[0] ); ?></label>
							<input type="<?php echo $wf[2] ? 'number' : 'text'; ?>" <?php echo $wf[2] ? 'min="1" max="40"' : 'dir="ltr"'; ?> name="settings[<?php echo esc_attr( $wk ); ?>]" class="regular-text" value="<?php echo esc_attr( $ws[ $wk ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $wf[1] ); ?>" />
						</p>
					<?php endforeach; ?>
					<p>
						<label><input type="checkbox" name="settings[allow_offsite]" value="1" <?php checked( ! empty( $ws['allow_offsite'] ) ); ?> /> <?php esc_html_e( 'Follow links outside the source domain', 'newsdesk-ai' ); ?></label>
					</p>
				</td>
			</tr>
			<script>
			(function(){var t=document.getElementById('nd_type');if(!t)return;function u(){var w=t.value==='web';document.querySelectorAll('.nd-web-only').forEach(function(r){r.style.display=w?'':'none';});}t.addEventListener('change',u);u();})();
			</script>
			<tr>
				<th><label for="nd_url"><?php esc_html_e( 'Source website', 'newsdesk-ai' ); ?></label></th>
				<td><input type="url" id="nd_url" name="url" class="regular-text" value="<?php echo esc_attr( $form->url ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_lang"><?php esc_html_e( 'Language', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_lang" name="language" class="regular-text" value="<?php echo esc_attr( $form->language ); ?>" placeholder="en_US" /></td>
			</tr>
			<tr>
				<th><label for="nd_cat"><?php esc_html_e( 'Editorial category', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_cat" name="category">
						<?php foreach ( $view['categories'] as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $form->category, $cat ); ?>><?php echo esc_html( $cat ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="nd_priority"><?php esc_html_e( 'Priority (1–100)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" max="100" id="nd_priority" name="priority" value="<?php echo (int) $form->priority; ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_source_type"><?php esc_html_e( 'Source type', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_source_type" name="source_type">
						<?php foreach ( \NewsDesk\AI\Domain\Entity\Source::SOURCE_TYPES as $nd_st ) : ?>
							<option value="<?php echo esc_attr( $nd_st ); ?>" <?php selected( $form->sourceType, $nd_st ); ?>><?php echo esc_html( $nd_st ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'What kind of publisher this is. SECURITY sources feed CVE extraction.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_tier"><?php esc_html_e( 'Trust tier', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_tier" name="tier">
						<?php
						$nd_tier_labels = array(
							1 => __( 'Tier 1 — official / authoritative', 'newsdesk-ai' ),
							2 => __( 'Tier 2 — established publication', 'newsdesk-ai' ),
							3 => __( 'Tier 3 — general media', 'newsdesk-ai' ),
							4 => __( 'Tier 4 — unverified / community', 'newsdesk-ai' ),
						);
						foreach ( \NewsDesk\AI\Domain\Entity\Source::TIERS as $nd_t ) :
							?>
							<option value="<?php echo (int) $nd_t; ?>" <?php selected( (int) $form->tier, (int) $nd_t ); ?>>
								<?php echo esc_html( $nd_tier_labels[ $nd_t ] ?? (string) $nd_t ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						printf(
							/* translators: %s: comma-separated list of tier defaults, e.g. "95, 80, 60, 35". */
							esc_html__( 'Sets the default base trust when you leave the score blank (%s).', 'newsdesk-ai' ),
							esc_html( implode( ', ', \NewsDesk\AI\Domain\Entity\Source::TIER_TRUST ) )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_trust"><?php esc_html_e( 'Base trust (0–100)', 'newsdesk-ai' ); ?></label></th>
				<td>
					<input type="number" min="0" max="100" step="0.5" id="nd_trust" name="base_trust_score" value="<?php echo esc_attr( $form->baseTrustScore ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank to inherit the tier default.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_interval"><?php esc_html_e( 'Fetch interval (minutes)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="15" max="1440" id="nd_interval" name="fetch_interval_min" value="<?php echo (int) $form->fetchIntervalMin; ?>" /></td>
			</tr>
		</table>
		<p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'newsdesk-ai' ); ?></button>
			<?php if ( $view['editing'] ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nd-sources' ) ); ?>"><?php esc_html_e( 'Cancel', 'newsdesk-ai' ); ?></a><?php endif; ?>
		</p>
	</form></div>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
