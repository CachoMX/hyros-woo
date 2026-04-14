<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.', 'hyros-woo'));
}

$api_key         = Hyros_Settings::get_api_key();
$masked_key      = Hyros_Settings::mask_api_key($api_key);
$key_defined     = defined('HYROS_API_KEY') && !empty(HYROS_API_KEY);
$recent_logs     = Hyros_Logger::get_recent(50);
$current_script  = get_option('hyros_woo_selected_script', '');
$selected_domain = get_option('hyros_woo_selected_domain', '');
$account_cache   = get_transient('hyros_woo_account_info');
$has_account     = is_array($account_cache) && !empty($account_cache);

// Pre-batch order objects for the log table to avoid N+1 queries.
$log_order_ids = array_filter(array_unique(array_column($recent_logs, 'order_id')));
$log_orders    = [];
if (!empty($log_order_ids)) {
    foreach (wc_get_orders(['post__in' => $log_order_ids, 'limit' => -1, 'return' => 'objects']) as $o) {
        $log_orders[$o->get_id()] = $o;
    }
}

$logo_url = HYROS_WOO_PLUGIN_URL . 'admin/images/hyros-logo.svg';
?>
<div class="wrap hyros-wrap">

    <!-- Page Header -->
    <div class="hyros-page-header">
        <h1>
            <img src="<?php echo esc_url($logo_url); ?>" alt="Hyros" class="hyros-logo" />
            <?php esc_html_e('Hyros Integration', 'hyros-woo'); ?>
        </h1>
        <span id="hyros-connection-badge" class="hyros-connection-badge hyros-badge-unknown">
            <span class="hyros-dot"></span> <?php esc_html_e('Unknown', 'hyros-woo'); ?>
        </span>
    </div>

    <div id="hyros-notice" class="hyros-notice" role="alert" aria-live="assertive" style="display:none;"></div>

    <?php if ($key_defined): ?>
        <div class="notice notice-info inline"><p>
            <?php esc_html_e('API key is set via the HYROS_API_KEY constant in wp-config.php and cannot be edited here.', 'hyros-woo'); ?>
        </p></div>
    <?php endif; ?>

    <!-- Row 1: Account Info + Settings -->
    <div class="hyros-grid">

        <!-- Account Info Card -->
        <div class="hyros-card" id="hyros-account-card" style="<?php echo $has_account ? '' : 'display:none;'; ?>">
            <div class="hyros-card__header">
                <h2><?php esc_html_e('Hyros Account', 'hyros-woo'); ?></h2>
            </div>
            <div class="hyros-card__body">
                <div class="hyros-account-info">
                    <div class="hyros-account-avatar" id="hyros-avatar">
                        <?php
                        if ($has_account) {
                            echo esc_html(strtoupper(
                                substr($account_cache['firstName'] ?? '', 0, 1) .
                                substr($account_cache['lastName'] ?? '', 0, 1)
                            ));
                        } else {
                            echo '--';
                        }
                        ?>
                    </div>
                    <div class="hyros-account-text">
                        <strong id="hyros-account-name">
                            <?php echo $has_account ? esc_html(trim(($account_cache['firstName'] ?? '') . ' ' . ($account_cache['lastName'] ?? ''))) : '—'; ?>
                        </strong>
                        <span id="hyros-account-email">
                            <?php echo $has_account ? esc_html($account_cache['email'] ?? '') : '—'; ?>
                        </span>
                        <span id="hyros-account-company">
                            <?php echo $has_account ? esc_html($account_cache['companyName'] ?? '') : ''; ?>
                        </span>
                    </div>
                </div>

                <hr style="margin:12px 0;border-color:#f0f0f1;" />

                <div class="hyros-account-detail">
                    <dl>
                        <dt><?php esc_html_e('Timezone', 'hyros-woo'); ?></dt>
                        <dd id="hyros-tz"><?php echo $has_account ? esc_html($account_cache['timezone'] ?? '—') : '—'; ?></dd>

                        <dt><?php esc_html_e('Currency', 'hyros-woo'); ?></dt>
                        <dd id="hyros-currency">
                            <?php if ($has_account && !empty($account_cache['currency'])): ?>
                                <span class="hyros-tag hyros-tag--blue"><?php echo esc_html($account_cache['currency']); ?></span>
                            <?php else: ?>
                                <span>—</span>
                            <?php endif; ?>
                        </dd>

                        <dt><?php esc_html_e('Attribution Window', 'hyros-woo'); ?></dt>
                        <dd id="hyros-attribution">
                            <?php echo $has_account ? esc_html(($account_cache['attribution'] ?? '') . ' days') : '—'; ?>
                        </dd>

                        <dt><?php esc_html_e('Attribution Mode', 'hyros-woo'); ?></dt>
                        <dd id="hyros-pending-mode">
                            <?php echo $has_account ? esc_html($account_cache['pendingMode'] ?? '—') : '—'; ?>
                        </dd>

                        <dt><?php esc_html_e('Ignore Organic Sources', 'hyros-woo'); ?></dt>
                        <dd id="hyros-ignore-organic">
                            <?php
                            if ($has_account) {
                                $organic = $account_cache['ignoreOrganic'] ?? '';
                                if ($organic === 'true') {
                                    echo '<span class="hyros-tag hyros-tag--warning">' . esc_html__('Yes', 'hyros-woo') . '</span>';
                                } else {
                                    echo '<span class="hyros-tag hyros-tag--green">' . esc_html__('No', 'hyros-woo') . '</span>';
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </dd>

                        <dt><?php esc_html_e('Sale Grouping', 'hyros-woo'); ?></dt>
                        <dd id="hyros-sale-grouping">
                            <?php
                            if ($has_account) {
                                $grouping = $account_cache['saleGrouping'] ?? '';
                                $window   = $account_cache['groupingWindow'] ?? '';
                                if ($grouping === 'true') {
                                    $label = $window ? sprintf(__('%s min', 'hyros-woo'), esc_html($window)) : __('On', 'hyros-woo');
                                    echo '<span class="hyros-tag hyros-tag--blue">' . esc_html($label) . '</span>';
                                } else {
                                    echo '<span class="hyros-tag">' . esc_html__('Off', 'hyros-woo') . '</span>';
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </dd>

                        <dt><?php esc_html_e('Recurring Products', 'hyros-woo'); ?></dt>
                        <dd id="hyros-recurring">
                            <?php
                            if ($has_account) {
                                $rec = $account_cache['recurringEnabled'] ?? '';
                                if ($rec === 'true') {
                                    echo '<span class="hyros-tag hyros-tag--green">' . esc_html__('Enabled', 'hyros-woo') . '</span>';
                                } else {
                                    echo '<span class="hyros-tag">' . esc_html__('Disabled', 'hyros-woo') . '</span>';
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </dd>

                        <dt><?php esc_html_e('Track EU Customers', 'hyros-woo'); ?></dt>
                        <dd id="hyros-eu">
                            <?php
                            if ($has_account) {
                                $eu = $account_cache['trackEu'] ?? '';
                                if ($eu === 'true') {
                                    echo '<span class="hyros-tag hyros-tag--green">' . esc_html__('Yes', 'hyros-woo') . '</span>';
                                } else {
                                    echo '<span class="hyros-tag">' . esc_html__('No', 'hyros-woo') . '</span>';
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Settings Card -->
        <div class="hyros-card">
            <div class="hyros-card__header">
                <h2><?php esc_html_e('Settings', 'hyros-woo'); ?></h2>
            </div>
            <div class="hyros-card__body">
                <form id="hyros-settings-form" method="post">

                    <!-- API Key -->
                    <div class="hyros-form-row">
                        <label for="hyros-api-key"><?php esc_html_e('API Key', 'hyros-woo'); ?></label>
                        <div class="hyros-input-group">
                            <?php if ($key_defined): ?>
                                <input type="password" id="hyros-api-key" name="api_key"
                                       value="<?php echo esc_attr($masked_key); ?>"
                                       class="regular-text" disabled readonly />
                            <?php else: ?>
                                <input type="password" id="hyros-api-key" name="api_key"
                                       value="<?php echo esc_attr($masked_key); ?>"
                                       class="regular-text"
                                       autocomplete="new-password"
                                       placeholder="<?php esc_attr_e('Enter your Hyros API key', 'hyros-woo'); ?>" />
                            <?php endif; ?>
                            <button type="button" id="hyros-validate-btn" class="button button-secondary">
                                <?php esc_html_e('Validate Key', 'hyros-woo'); ?>
                            </button>
                            <span id="hyros-validate-status" role="status" aria-live="polite" aria-atomic="true"></span>
                        </div>
                        <?php if ($key_defined): ?>
                            <p class="description"><?php esc_html_e('Defined in wp-config.php.', 'hyros-woo'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Domain Selector -->
                    <div class="hyros-form-row" id="hyros-domain-row" style="display:none;">
                        <label for="hyros-domain-select"><?php esc_html_e('Domain', 'hyros-woo'); ?></label>
                        <div class="hyros-input-group">
                            <select id="hyros-domain-select" name="domain">
                                <option value=""><?php esc_html_e('— Select domain —', 'hyros-woo'); ?></option>
                                <?php if (!empty($selected_domain)): ?>
                                    <option value="<?php echo esc_attr($selected_domain); ?>" selected>
                                        <?php echo esc_html($selected_domain); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <span id="hyros-domain-status" class="hyros-domain-status" role="status" aria-live="polite" aria-atomic="true"></span>
                        </div>
                    </div>

                    <!-- Tracking Script -->
                    <div class="hyros-form-row">
                        <label for="hyros-script-content"><?php esc_html_e('Tracking Script', 'hyros-woo'); ?></label>
                        <textarea id="hyros-script-content" name="script_content"
                                  class="large-text code" rows="7"
                                  placeholder="<?php esc_attr_e('Your Hyros tracking script will appear here automatically after validation.', 'hyros-woo'); ?>"><?php echo esc_textarea($current_script); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Auto-loaded when you validate your API key and select a domain. Only Hyros-origin scripts are accepted.', 'hyros-woo'); ?>
                        </p>
                    </div>

                    <!-- Tracking Options -->
                    <div class="hyros-form-row">
                        <label><?php esc_html_e('Tracking Options', 'hyros-woo'); ?></label>
                        <div class="hyros-toggles">

                            <label class="hyros-toggle-row">
                                <input type="checkbox" name="track_sales" value="1"
                                    <?php checked(get_option('hyros_woo_track_sales', 'yes'), 'yes'); ?> />
                                <span class="hyros-toggle-label">
                                    <?php esc_html_e('Track Sales', 'hyros-woo'); ?>
                                    <span class="hyros-toggle-hint"><?php esc_html_e('Send orders and refunds to Hyros. Disable if using the official Hyros WooCommerce integration.', 'hyros-woo'); ?></span>
                                </span>
                            </label>

                            <label class="hyros-toggle-row">
                                <input type="checkbox" name="track_subscriptions" value="1"
                                    <?php checked(get_option('hyros_woo_track_subscriptions', 'yes'), 'yes'); ?> />
                                <span class="hyros-toggle-label">
                                    <?php esc_html_e('Track Subscription Renewals', 'hyros-woo'); ?>
                                    <span class="hyros-toggle-hint"><?php esc_html_e('Send WooCommerce Subscriptions renewal payments to Hyros.', 'hyros-woo'); ?></span>
                                </span>
                            </label>

                            <label class="hyros-toggle-row">
                                <input type="checkbox" name="track_add_to_cart" value="1"
                                    <?php checked(get_option('hyros_woo_track_add_to_cart', 'yes'), 'yes'); ?> />
                                <span class="hyros-toggle-label">
                                    <?php esc_html_e('Track Add to Cart', 'hyros-woo'); ?>
                                    <span class="hyros-toggle-hint"><?php esc_html_e('Send cart events and clicks to Hyros when a product is added to cart.', 'hyros-woo'); ?></span>
                                </span>
                            </label>

                            <label class="hyros-toggle-row">
                                <input type="checkbox" name="send_cogs" value="1"
                                    <?php checked(get_option('hyros_woo_send_cogs', 'no'), 'yes'); ?> />
                                <span class="hyros-toggle-label">
                                    <?php esc_html_e('Send Cost of Goods (COGS)', 'hyros-woo'); ?>
                                    <span class="hyros-toggle-hint"><?php esc_html_e('Include COGS per item using the _wc_cog_cost product meta (set by WooCommerce Cost of Goods or similar plugins).', 'hyros-woo'); ?></span>
                                </span>
                            </label>

                            <label class="hyros-toggle-row">
                                <input type="checkbox" name="inject_script" value="1"
                                    <?php checked(get_option('hyros_woo_inject_script', 'yes'), 'yes'); ?> />
                                <span class="hyros-toggle-label">
                                    <?php esc_html_e('Inject Tracking Script', 'hyros-woo'); ?>
                                    <span class="hyros-toggle-hint"><?php esc_html_e('Turn off if your theme or tag manager already injects the Hyros script.', 'hyros-woo'); ?></span>
                                </span>
                            </label>

                            <label class="hyros-toggle-row">
                                <input type="checkbox" name="require_consent" value="1"
                                    <?php checked(get_option('hyros_woo_require_consent', 'no'), 'yes'); ?> />
                                <span class="hyros-toggle-label">
                                    <?php esc_html_e('Require Marketing Consent', 'hyros-woo'); ?>
                                    <span class="hyros-toggle-hint"><?php esc_html_e('Only inject/send tracking when marketing consent is granted (wp_has_consent("marketing") or cookie fallback).', 'hyros-woo'); ?></span>
                                </span>
                            </label>

                        </div>
                    </div>

                </form>
            </div>
            <div class="hyros-card__footer">
                <button type="button" id="hyros-save-btn" class="button button-primary">
                    <?php esc_html_e('Save Settings', 'hyros-woo'); ?>
                </button>
            </div>
        </div>

    </div><!-- .hyros-grid -->

    <!-- Row 2: Activity Log -->
    <div class="hyros-grid hyros-grid--full">
        <div class="hyros-card">
            <div class="hyros-card__header">
                <h2><?php esc_html_e('Activity Log', 'hyros-woo'); ?></h2>
                <span class="hyros-log-count">
                    <?php echo esc_html(sprintf(__('%d most recent', 'hyros-woo'), count($recent_logs))); ?>
                </span>
            </div>
            <div class="hyros-card__body" style="padding:0;">

                <?php if (empty($recent_logs)): ?>
                    <p style="padding:16px;color:#50575e;"><?php esc_html_e('No activity recorded yet.', 'hyros-woo'); ?></p>
                <?php else: ?>
                    <table class="hyros-log-table">
                        <thead>
                            <tr>
                                <th style="width:130px;"><?php esc_html_e('Time', 'hyros-woo'); ?></th>
                                <th style="width:200px;"><?php esc_html_e('Event', 'hyros-woo'); ?></th>
                                <th style="width:170px;"><?php esc_html_e('Lead', 'hyros-woo'); ?></th>
                                <th style="width:65px;"><?php esc_html_e('Order', 'hyros-woo'); ?></th>
                                <th><?php esc_html_e('Items', 'hyros-woo'); ?></th>
                                <th style="width:90px;"><?php esc_html_e('Total', 'hyros-woo'); ?></th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_logs as $entry):
                            $event_code = $entry['event'] ?? '';
                            $row_class  = Hyros_Logger::row_class($event_code);
                            $parsed     = Hyros_Logger::parse_detail_for_display($entry);
                            $email      = $entry['email'] ?? '';
                            $order_id   = (int) ($entry['order_id'] ?? 0);
                            $log_order  = $order_id > 0 ? ($log_orders[$order_id] ?? null) : null;
                            $edit_url   = $log_order ? $log_order->get_edit_order_url() : '';

                            // Build detail dl content.
                            $detail_items = array_filter([
                                __('Phone', 'hyros-woo')      => $parsed['phone'],
                                __('IP', 'hyros-woo')         => $parsed['ip'],
                                __('Cart ID', 'hyros-woo')    => $parsed['cart_id'],
                                __('Hyros ID', 'hyros-woo')   => $parsed['hyros_id'],
                                __('Raw Detail', 'hyros-woo') => $parsed['detail'],
                            ]);
                            $has_details = !empty($detail_items);
                            $detail_row_id = 'hyros-log-detail-' . md5((string) ($entry['time'] ?? '') . '|' . (string) $order_id . '|' . (string) $event_code);
                        ?>
                            <tr class="<?php echo esc_attr($row_class); ?>">
                                <td style="font-size:12px;color:#50575e;">
                                    <?php echo esc_html($entry['time']); ?>
                                </td>
                                <td>
                                    <span class="hyros-log-event"><?php echo esc_html($parsed['label']); ?></span>
                                </td>
                                <td style="font-size:12px;">
                                    <?php echo esc_html($email); ?>
                                </td>
                                <td>
                                    <?php if ($edit_url): ?>
                                        <a href="<?php echo esc_url($edit_url); ?>">#<?php echo esc_html($order_id); ?></a>
                                    <?php elseif ($order_id > 0): ?>
                                        #<?php echo esc_html($order_id); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:#50575e;">
                                    <?php echo esc_html($parsed['items_summary']); ?>
                                </td>
                                <td style="font-size:12px;">
                                    <?php if (!empty($parsed['total'])): ?>
                                        <strong><?php echo esc_html($parsed['total']); ?></strong>
                                        <?php if (!empty($parsed['currency'])): ?>
                                            <span style="color:#50575e;"><?php echo esc_html($parsed['currency']); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($has_details): ?>
                                        <button type="button" class="hyros-log-detail-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr($detail_row_id); ?>">
                                            <?php esc_html_e('View', 'hyros-woo'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($has_details): ?>
                            <tr id="<?php echo esc_attr($detail_row_id); ?>" class="hyros-log-detail-row <?php echo esc_attr($row_class); ?>" style="display:none;" aria-hidden="true">
                                <td colspan="7">
                                    <dl>
                                        <?php foreach ($detail_items as $dt => $dd): ?>
                                            <dt><?php echo esc_html($dt); ?></dt>
                                            <dd><?php echo esc_html($dd); ?></dd>
                                        <?php endforeach; ?>
                                    </dl>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div><!-- .hyros-wrap -->
