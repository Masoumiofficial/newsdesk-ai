<?php
/**
 * Settings view — data: s (NewsroomSettings).
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
$s = $view['s'];
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Settings', 'newsdesk-ai' ), __( 'Scheduling, editorial selection, AI, content quality, images and notifications', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="newsdesk_newsroom_settings_save" />
		<?php wp_nonce_field( 'newsdesk_newsroom_settings_save' ); ?>
	<div class="nd-settings">
		<aside class="nd-settings-nav" id="nd-settings-nav">
			<a href="#nd-sec-sched"><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'Schedule', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-edit"><span class="dashicons dashicons-filter"></span><?php esc_html_e( 'Editorial selection', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-feed"><span class="dashicons dashicons-rss"></span><?php esc_html_e( 'Fetch feed', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-sys"><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'System', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-ai"><span class="dashicons dashicons-superhero"></span><?php esc_html_e( 'AI', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-content"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Content generation and quality', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-img"><span class="dashicons dashicons-format-image"></span><?php esc_html_e( 'News images', 'newsdesk-ai' ); ?></a>
			<a href="#nd-sec-notif"><span class="dashicons dashicons-bell"></span><?php esc_html_e( 'Notifications and daily digest', 'newsdesk-ai' ); ?></a>
		</aside>
		<div class="nd-settings-main">

		<section class="nd-section" id="nd-sec-sched">
		<h2><?php esc_html_e( 'Scheduling (§42)', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Slot times', 'newsdesk-ai' ); ?></label></th>
				<td>
					<?php foreach ( $s->scheduleTimes() as $slot ) : ?>
						<input type="text" name="schedule_times[]" value="<?php echo esc_attr( $slot ); ?>" pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" class="small-text" />
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Format HH:MM — scheduling follows the site time zone.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_ewh"><?php esc_html_e( 'Stage 1 window (hours)', 'newsdesk-ai' ); ?></label></th>
				<td>
					<input type="number" min="1" max="72" id="nd_ewh" name="editorial_window_hours" value="<?php echo (int) $s->editorialWindowHours(); ?>" />
					<p class="description"><?php esc_html_e( 'Only news from this window is considered first. Recommended: 24 hours.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_fwd"><?php esc_html_e( 'Stage 2 window (days)', 'newsdesk-ai' ); ?></label></th>
				<td>
					<input type="number" min="1" max="30" id="nd_fwd" name="fallback_window_days" value="<?php echo (int) $s->fallbackWindowDays(); ?>" />
					<p class="description"><?php esc_html_e( 'If nothing is found in the stage 1 window, the search widens to this range. If there is still nothing, the outcome is NO NEWS and no content is created.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
		</table>

		</section>
		<section class="nd-section" id="nd-sec-edit">
		<h2><?php esc_html_e( 'Editorial selection (phase 2 — SEO/AEO/GEO first)', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_qs"><?php esc_html_e( 'Quick-start mode', 'newsdesk-ai' ); ?></label></th>
				<td>
					<label><input type="checkbox" id="nd_qs" name="quick_start" value="1" <?php checked( $s->quickStartEnabled() ); ?> /> <?php esc_html_e( 'Active', 'newsdesk-ai' ); ?></label>
					<p class="description"><?php esc_html_e( 'To see your first drafts: lowers the selection threshold to 35, trust to 20 and the content quality gate to 70 (your saved values are left untouched). Publishing still requires human approval. Turn it off once things are running.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_mspw"><?php esc_html_e( 'Maximum stories per window', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="1" max="20" id="nd_mspw" name="max_stories_per_window" value="<?php echo (int) $s->maxStoriesPerWindow(); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_qg"><?php esc_html_e( 'Quality threshold (composite, 0–100)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" max="100" id="nd_qg" name="selection_quality_gate" value="<?php echo (int) $s->selectionQualityGate(); ?>" />
					<p class="description"><?php esc_html_e( 'Below this threshold → the valid outcome NO_PUBLISHABLE_STORY_FOUND (§14).', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_mt"><?php esc_html_e( 'Minimum source trust (0–100)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" max="100" id="nd_mt" name="selection_min_trust" value="<?php echo (int) $s->selectionMinTrust(); ?>" />
					<p class="description"><?php esc_html_e( 'Low trust cannot be selected even with excellent SEO.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
		</table>

		</section>
		<section class="nd-section" id="nd-sec-feed">
		<h2><?php esc_html_e( 'Fetch feed', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_to"><?php esc_html_e( 'Request timeout (seconds)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="5" max="120" id="nd_to" name="fetch_timeout" value="<?php echo (int) $s->fetchTimeout(); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_mr"><?php esc_html_e( 'Maximum response size (KB)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="64" max="10240" id="nd_mr" name="max_response_kb" value="<?php echo (int) $s->maxResponseKb(); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_mi"><?php esc_html_e( 'Maximum items per source', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="1" max="200" id="nd_mi" name="max_items_per_source" value="<?php echo (int) $s->maxItemsPerSource(); ?>" /></td>
			</tr>
		</table>

		</section>
		<section class="nd-section" id="nd-sec-sys">
		<h2><?php esc_html_e( 'System', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_ll"><?php esc_html_e( 'Log level', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_ll" name="log_level">
						<?php foreach ( array_keys( \NewsDesk\AI\Logging\Logger::LEVELS ) as $lvl ) : ?>
							<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $s->logLevel(), $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="nd_ss"><?php esc_html_e( 'Key storage', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_ss" name="secret_storage">
						<option value="wordpress" <?php selected( $s->secretStorageType(), 'wordpress' ); ?>><?php esc_html_e( 'WordPress (default)', 'newsdesk-ai' ); ?></option>
						<option value="environment" <?php selected( $s->secretStorageType(), 'environment' ); ?>><?php esc_html_e( 'Environment variable (NEWSDESK_*)', 'newsdesk-ai' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="nd_author"><?php esc_html_e( 'Default draft author (ID)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" id="nd_author" name="default_author_id" value="<?php echo (int) $s->defaultAuthorId(); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_cat2"><?php esc_html_e( 'Default draft category (ID)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" id="nd_cat2" name="default_category_id" value="<?php echo (int) $s->defaultCategoryId(); ?>" /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Auto-publish', 'newsdesk-ai' ); ?></label></th>
				<td>
					<input type="checkbox" disabled /> <strong><?php esc_html_e( 'Disabled — always off', 'newsdesk-ai' ); ?></strong>
					<p class="description"><?php esc_html_e( 'This rule cannot be changed (§73). Every output is a draft and nothing else.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Deletion mode on uninstall', 'newsdesk-ai' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="uninstall_mode" value="delete_all" <?php checked( $s->uninstallMode(), 'delete_all' ); ?> />
						<?php esc_html_e( 'Delete all data on uninstall (default: keep)', 'newsdesk-ai' ); ?>
					</label>
				</td>
			</tr>
		</table>

		</section>
		<section class="nd-section" id="nd-sec-ai">
		<h2><?php esc_html_e( 'AI (phase 3 — §18, §22, §23)', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_aio"><?php esc_html_e( 'Provider order (fallback priority)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_aio" name="ai_provider_order" value="<?php echo esc_attr( implode( ',', $s->aiProviderOrder() ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'gapgpt,openai,gemini — fallback applies only to retryable errors (§21).', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'API keys (stored only in SecretStorage — §23)', 'newsdesk-ai' ); ?></label></th>
				<td>
					<?php foreach ( array( 'gapgpt' => 'GapGPT', 'openai' => 'OpenAI', 'gemini' => 'Gemini' ) as $pid => $pname ) : ?>
						<p>
							<label><?php echo esc_html( $pname ); ?>: <input type="password" name="<?php echo esc_attr( $pid ); ?>_api_key" value="" autocomplete="new-password" class="regular-text" placeholder="sk-…" /></label>
							<?php if ( ! empty( $view['secret_present'][ $pid ] ) ) : ?>
								<em><?php esc_html_e( '(Set ✅ — enter a new value to change it)', 'newsdesk-ai' ); ?></em>
								<label style="margin-right:8px"><input type="checkbox" name="clear_secret[<?php echo esc_attr( $pid ); ?>_api_key]" value="1" /> <?php esc_html_e( 'Delete key', 'newsdesk-ai' ); ?></label>
							<?php else : ?>
								<em><?php esc_html_e( '(not set)', 'newsdesk-ai' ); ?></em>
							<?php endif; ?>
						</p>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'The current value is never shown. Blank = unchanged. To clear it, tick “Delete key”.', 'newsdesk-ai' ); ?></p>
					<?php if ( empty( $view['encryption_available'] ) ) : ?>
						<p class="description" style="color:#b32d2e"><?php esc_html_e( 'Warning: neither sodium nor openssl is available on this server; keys will be stored unencrypted.', 'newsdesk-ai' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Keys are stored encrypted in the database, using a key derived from AUTH_KEY.', 'newsdesk-ai' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="nd_mgap"><?php esc_html_e( 'GapGPT model', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_mgap" name="ai_model_gapgpt" value="<?php echo esc_attr( $s->aiModelGapGpt() ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="nd_moai"><?php esc_html_e( 'OpenAI model', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_moai" name="ai_model_openai" value="<?php echo esc_attr( $s->aiModelOpenAi() ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="nd_mgem"><?php esc_html_e( 'Gemini model', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_mgem" name="ai_model_gemini" value="<?php echo esc_attr( $s->aiModelGemini() ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="nd_agap"><?php esc_html_e( 'GapGPT via alternate CDN', 'newsdesk-ai' ); ?></label></th>
				<td><input type="checkbox" id="nd_agap" name="ai_gapgpt_alt_cdn" value="1" <?php checked( $s->aiGapGptAltCdn() ); ?> />
					<span class="description"><?php esc_html_e( 'Use the alternate CDN https://api.gapapi.com/v1 instead of https://api.gapgpt.app/v1 (per the supplier\'s own documentation)', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_atemp"><?php esc_html_e( 'Temperature', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" step="0.1" min="0" max="2" id="nd_atemp" name="ai_temperature" value="<?php echo esc_attr( $s->aiTemperature() ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_atok"><?php esc_html_e( 'Maximum output tokens', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="128" max="8192" id="nd_atok" name="ai_max_tokens" value="<?php echo (int) $s->aiMaxTokens(); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_atmo"><?php esc_html_e( 'Request timeout (seconds)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="10" max="300" id="nd_atmo" name="ai_timeout" value="<?php echo (int) $s->aiTimeout(); ?>" /></td>
			</tr>
			<tr>
				<th><label for="nd_abud"><?php esc_html_e( 'Token budget per job (0 = unlimited)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" id="nd_abud" name="ai_budget_per_job" value="<?php echo (int) $s->aiBudgetPerJob(); ?>" />
					<p class="description"><?php esc_html_e( 'Checked before every network call (BUDGET_EXCEEDED — §22).', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_afc"><?php esc_html_e( 'AI fact-check', 'newsdesk-ai' ); ?></label></th>
				<td><input type="checkbox" id="nd_afc" name="ai_fact_check_enabled" value="1" <?php checked( $s->aiFactCheckEnabled() ); ?> />
					<span class="description"><?php esc_html_e( 'The model\'s verdict on each claim is recorded; the rules always make the final decision (§19).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_acur"><?php esc_html_e( 'Cost estimate currency', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" maxlength="3" id="nd_acur" name="ai_currency" value="<?php echo esc_attr( $s->aiCurrency() ); ?>" class="small-text" /></td>
			</tr>
		</table>

		</section>
		<section class="nd-section" id="nd-sec-content">
		<h2><?php esc_html_e( 'Phase 4 — content generation and quality (§36)', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_clang"><?php esc_html_e( 'Language of generated articles', 'newsdesk-ai' ); ?></label></th>
				<td>
					<select id="nd_clang" name="content_language">
						<option value="fa" <?php selected( $s->contentLanguage(), 'fa' ); ?>><?php esc_html_e( 'Persian — English sources are rewritten natively, not translated', 'newsdesk-ai' ); ?></option>
						<option value="en" <?php selected( $s->contentLanguage(), 'en' ); ?>><?php esc_html_e( 'English', 'newsdesk-ai' ); ?></option>
						<option value="ar" <?php selected( $s->contentLanguage(), 'ar' ); ?>><?php esc_html_e( 'العربية', 'newsdesk-ai' ); ?></option>
						<option value="source" <?php selected( $s->contentLanguage(), 'source' ); ?>><?php esc_html_e( 'Same language as each story\'s source', 'newsdesk-ai' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The article is written natively in this language, not translated word for word. Product names, brands and version numbers keep their original form. If WPML or Polylang is active, the post language is set on the draft automatically so you can attach other translations to it later.', 'newsdesk-ai' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="nd_cqg"><?php esc_html_e( 'Content quality gate (0–100)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="0" max="100" id="nd_cqg" name="content_quality_gate" value="<?php echo (int) $s->contentQualityGate(); ?>" class="small-text" />
					<span class="description"><?php esc_html_e( 'Below this score the version is revised; once revisions run out → NEEDS_REVIEW (§36).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_cmr"><?php esc_html_e( 'Maximum AI revisions', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="1" max="5" id="nd_cmr" name="content_max_revisions" value="<?php echo (int) $s->contentMaxRevisions(); ?>" class="small-text" />
					<span class="description"><?php esc_html_e( 'Gate feedback is sent back to the model; beyond this many attempts, no draft is created (§31).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_cil"><?php esc_html_e( 'Linking (internal / external)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="checkbox" id="nd_cil" name="content_include_links" value="1" <?php checked( $s->contentIncludeLinks() ); ?> />
					<span class="description"><?php esc_html_e( 'Layers 15–16: internal link suggestions plus external citations from verified sources.', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_cge"><?php esc_html_e( 'Content generation enabled', 'newsdesk-ai' ); ?></label></th>
				<td><input type="checkbox" id="nd_cge" name="content_generate_enabled" value="1" <?php checked( $s->contentGenerateEnabled() ); ?> />
					<span class="description"><?php esc_html_e( 'Off = research, evidence and fact-checking only (no-article mode).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
		</table>

		</section>
		<section class="nd-section" id="nd-sec-img">
		<h2><?php esc_html_e( 'Phase 5 — news images (§66)', 'newsdesk-ai' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_ige"><?php esc_html_e( 'Image generation enabled', 'newsdesk-ai' ); ?></label></th>
				<td><input type="checkbox" id="nd_ige" name="image_generate_enabled" value="1" <?php checked( $s->imageGenerateEnabled() ); ?> />
					<span class="description"><?php esc_html_e( 'Off = no images; an image service failure never fails the article or the job (§66).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_im"><?php esc_html_e( 'Image model', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_im" name="image_model" value="<?php echo esc_attr( $s->imageModel() ); ?>" class="regular-text" />
					<span class="description"><?php esc_html_e( 'The default comes from GapGPT\'s own documentation; the full model list is available at /v1/models.', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_is"><?php esc_html_e( 'Image size', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_is" name="image_size" value="<?php echo esc_attr( $s->imageSize() ); ?>" class="small-text" />
					<span class="description"><?php esc_html_e( 'Format: width×height (for example 1024x1024).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
			<tr>
				<th><label for="nd_imb"><?php esc_html_e( 'Download size cap (bytes)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="number" min="262144" max="20971520" id="nd_imb" name="image_max_bytes" value="<?php echo (int) $s->imageMaxBytes(); ?>" class="small-text" />
					<span class="description"><?php esc_html_e( 'Response size limit when downloading images (§52).', 'newsdesk-ai' ); ?></span>
				</td>
			</tr>
		</table>


		</section>
		<section class="nd-section" id="nd-sec-notif">
		<h2><?php esc_html_e( 'Notifications and daily digest (phase 8)', 'newsdesk-ai' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Publishing is always manual (§73). Notifications only inform you and never block a job. Tokens and secret URLs live in SecretStorage (§23) and never reach HTML or the logs.', 'newsdesk-ai' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="nd_notif_enabled"><?php esc_html_e( 'Enable notifications', 'newsdesk-ai' ); ?></label></th>
				<td><input type="checkbox" id="nd_notif_enabled" name="notification_enabled" value="1" <?php checked( $s->notificationEnabled() ); ?> /></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Channels', 'newsdesk-ai' ); ?></label></th>
				<td>
					<?php foreach ( array( 'webhook' => 'وب‌هوک', 'email' => 'ایمیل', 'telegram' => 'تلگرام', 'slack' => 'اسلک' ) as $chId => $chLabel ) : ?>
						<label style="display:inline-block;margin-right:14px">
							<input type="checkbox" name="notification_channels[]" value="<?php echo esc_attr( $chId ); ?>" <?php checked( in_array( $chId, $s->notificationChannels(), true ) ); ?> />
							<?php echo esc_html( $chLabel ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Events', 'newsdesk-ai' ); ?></label></th>
				<td>
					<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="notification_events[]" value="content_ready" <?php checked( in_array( 'content_ready', $s->notificationEvents(), true ) ); ?> /> <?php esc_html_e( 'Content ready for review', 'newsdesk-ai' ); ?></label>
					<label style="display:inline-block"><input type="checkbox" name="notification_events[]" value="digest" <?php checked( in_array( 'digest', $s->notificationEvents(), true ) ); ?> /> <?php esc_html_e( 'Daily digest', 'newsdesk-ai' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="nd_wh"><?php esc_html_e( 'Webhook URL', 'newsdesk-ai' ); ?></label></th>
				<td><input type="url" id="nd_wh" name="notification_webhook_url" class="regular-text" placeholder="<?php echo ! empty( $view['secret_present']['notification_webhook_url'] ) ? esc_attr__( 'Set — enter a new value to change it', 'newsdesk-ai' ) : esc_attr__( 'https://…', 'newsdesk-ai' ); ?>" /><?php if ( ! empty( $view['secret_present']['notification_webhook_url'] ) ) : ?> <label style="margin-right:8px"><input type="checkbox" name="clear_secret[notification_webhook_url]" value="1" /> <?php esc_html_e( 'Delete the stored value', 'newsdesk-ai' ); ?></label><?php endif; ?></td>
			</tr>
			<tr>
				<th><label for="nd_whsec"><?php esc_html_e( 'Webhook signing secret (HMAC)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="password" id="nd_whsec" name="notification_webhook_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $view['secret_present']['notification_webhook_secret'] ) ? esc_attr__( 'Set — enter a new value to change it', 'newsdesk-ai' ) : ''; ?>" /><?php if ( ! empty( $view['secret_present']['notification_webhook_secret'] ) ) : ?> <label style="margin-right:8px"><input type="checkbox" name="clear_secret[notification_webhook_secret]" value="1" /> <?php esc_html_e( 'Delete the stored value', 'newsdesk-ai' ); ?></label><?php endif; ?></td>
			</tr>
			<tr>
				<th><label for="nd_recip"><?php esc_html_e( 'Email recipients (comma separated)', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_recip" name="notification_email_recipients" value="<?php echo esc_attr( implode( ', ', $s->notificationEmailRecipients() ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="nd_tg"><?php esc_html_e( 'Telegram bot token', 'newsdesk-ai' ); ?></label></th>
				<td><input type="password" id="nd_tg" name="telegram_bot_token" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $view['secret_present']['telegram_bot_token'] ) ? esc_attr__( 'Set — enter a new value to change it', 'newsdesk-ai' ) : ''; ?>" /><?php if ( ! empty( $view['secret_present']['telegram_bot_token'] ) ) : ?> <label style="margin-right:8px"><input type="checkbox" name="clear_secret[telegram_bot_token]" value="1" /> <?php esc_html_e( 'Delete the stored value', 'newsdesk-ai' ); ?></label><?php endif; ?></td>
			</tr>
			<tr>
				<th><label for="nd_tgid"><?php esc_html_e( 'Telegram chat ID', 'newsdesk-ai' ); ?></label></th>
				<td><input type="text" id="nd_tgid" name="telegram_chat_id" value="<?php echo esc_attr( $s->telegramChatId() ); ?>" class="regular-text" placeholder="123456789 یا @channel" /></td>
			</tr>
			<tr>
				<th><label for="nd_sl"><?php esc_html_e( 'Slack webhook URL', 'newsdesk-ai' ); ?></label></th>
				<td><input type="url" id="nd_sl" name="slack_webhook_url" class="regular-text" placeholder="<?php echo ! empty( $view['secret_present']['slack_webhook_url'] ) ? esc_attr__( 'Set — enter a new value to change it', 'newsdesk-ai' ) : esc_attr__( 'https://hooks.slack.com/services/…', 'newsdesk-ai' ); ?>" /><?php if ( ! empty( $view['secret_present']['slack_webhook_url'] ) ) : ?> <label style="margin-right:8px"><input type="checkbox" name="clear_secret[slack_webhook_url]" value="1" /> <?php esc_html_e( 'Delete the stored value', 'newsdesk-ai' ); ?></label><?php endif; ?></td>
			</tr>
			<tr>
				<th><label for="nd_dig"><?php esc_html_e( 'Daily digest', 'newsdesk-ai' ); ?></label></th>
				<td>
					<label style="display:inline-block;margin-right:14px"><input type="checkbox" id="nd_dig" name="digest_enabled" value="1" <?php checked( $s->digestEnabled() ); ?> /> <?php esc_html_e( 'Active', 'newsdesk-ai' ); ?></label>
					<input type="time" name="digest_time" value="<?php echo esc_attr( $s->digestTime() ); ?>" />
				</td>
			</tr>
		</table>

		</section>
		<div class="nd-savebar">
			<p class="description"><?php esc_html_e( 'Changes only take effect when you press Save. API keys are never displayed on this page.', 'newsdesk-ai' ); ?></p>
			<?php submit_button( __( 'Save settings', 'newsdesk-ai' ), 'primary', 'submit', false ); ?>
		</div>
		</div><!-- /.nd-settings-main -->
	</div><!-- /.nd-settings -->
	</form>
	<script>
	(function(){var links=document.querySelectorAll('#nd-settings-nav a'),secs=[];links.forEach(function(a){var el=document.querySelector(a.getAttribute('href'));if(el)secs.push([el,a]);});
	function act(){var y=window.scrollY+120,cur=secs[0];secs.forEach(function(p){if(p[0].offsetTop<=y)cur=p;});links.forEach(function(a){a.classList.remove('is-active');});if(cur)cur[1].classList.add('is-active');}
	window.addEventListener('scroll',act,{passive:true});act();})();
	</script>

	<form id="nd-digest-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nd-actions" style="margin-top:10px">
		<input type="hidden" name="action" value="newsdesk_newsroom_digest_run" />
		<?php wp_nonce_field( 'newsdesk_newsroom_digest_run' ); ?>
		<?php submit_button( __( 'Send the daily digest now', 'newsdesk-ai' ), 'secondary' ); ?>
	</form>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
