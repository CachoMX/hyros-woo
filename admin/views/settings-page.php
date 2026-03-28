<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_woocommerce')) {
    wp_die(__('Insufficient permissions.', 'hyros-woo'));
}

$api_key        = Hyros_Settings::get_api_key();
$masked_key     = Hyros_Settings::mask_api_key($api_key);
$key_defined    = defined('HYROS_API_KEY') && !empty(HYROS_API_KEY);
$recent_logs    = Hyros_Logger::get_recent(50);
$nonce          = wp_create_nonce('hyros_woo_nonce');
$selected_id    = get_option('hyros_woo_selected_script_id', '');
?>
<div class="wrap">
    <h1>
        <?php esc_html_e('Hyros Integration', 'hyros-woo'); ?>
        <span id="hyros-connection-badge" class="hyros-connection-badge hyros-badge-unknown">&#9679; <?php esc_html_e('Unknown', 'hyros-woo'); ?></span>
    </h1>

    <div id="hyros-notice" class="hyros-notice" style="display:none;"></div>

    <?php if ($key_defined): ?>
        <div class="notice notice-info inline"><p>
            <?php esc_html_e('API key is set via the HYROS_API_KEY constant in wp-config.php and cannot be edited here.', 'hyros-woo'); ?>
        </p></div>
    <?php endif; ?>

    <form id="hyros-settings-form" method="post">
        <table class="form-table" role="presentation">
            <tbody>

                <!-- API Key -->
                <tr>
                    <th scope="row">
                        <label for="hyros-api-key"><?php esc_html_e('API Key', 'hyros-woo'); ?></label>
                    </th>
                    <td>
                        <?php if ($key_defined): ?>
                            <input type="password" id="hyros-api-key" name="api_key"
                                   value="<?php echo esc_attr($masked_key); ?>"
                                   class="regular-text" disabled readonly />
                            <p class="description"><?php esc_html_e('Defined in wp-config.php.', 'hyros-woo'); ?></p>
                        <?php else: ?>
                            <input type="password" id="hyros-api-key" name="api_key"
                                   value="<?php echo esc_attr($masked_key); ?>"
                                   class="regular-text"
                                   autocomplete="new-password"
                                   placeholder="<?php esc_attr_e('Enter your Hyros API key', 'hyros-woo'); ?>" />
                        <?php endif; ?>
                        <button type="button" id="hyros-validate-btn" class="button button-secondary">
                            <?php esc_html_e('Validate &amp; Load Scripts', 'hyros-woo'); ?>
                        </button>
                        <span id="hyros-validate-status"></span>
                    </td>
                </tr>

                <!-- Script selector -->
                <tr id="hyros-script-row" style="<?php echo empty($selected_id) ? 'display:none;' : ''; ?>">
                    <th scope="row">
                        <label for="hyros-script-select"><?php esc_html_e('Tracking Script', 'hyros-woo'); ?></label>
                    </th>
                    <td>
                        <select id="hyros-script-select" name="script_id" class="regular-text">
                            <option value=""><?php esc_html_e('— Select a script —', 'hyros-woo'); ?></option>
                            <?php if (!empty($selected_id)): ?>
                                <option value="<?php echo esc_attr($selected_id); ?>" selected>
                                    <?php echo esc_html(get_option('hyros_woo_selected_script_id', $selected_id)); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select the script Hyros provided for your account.', 'hyros-woo'); ?></p>
                    </td>
                </tr>

            </tbody>
        </table>

        <?php if (!$key_defined): ?>
            <p class="submit">
                <button type="button" id="hyros-save-btn" class="button button-primary">
                    <?php esc_html_e('Save Settings', 'hyros-woo'); ?>
                </button>
            </p>
        <?php endif; ?>
    </form>

    <!-- Recent Activity Log -->
    <h2><?php esc_html_e('Recent Activity Log', 'hyros-woo'); ?></h2>
    <?php if (empty($recent_logs)): ?>
        <p><?php esc_html_e('No activity recorded yet.', 'hyros-woo'); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped hyros-log-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Time', 'hyros-woo'); ?></th>
                    <th><?php esc_html_e('Order', 'hyros-woo'); ?></th>
                    <th><?php esc_html_e('Event', 'hyros-woo'); ?></th>
                    <th><?php esc_html_e('Detail', 'hyros-woo'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_logs as $entry): ?>
                    <?php $is_failed = strpos($entry['event'], 'failed') !== false; ?>
                    <tr class="<?php echo $is_failed ? 'hyros-row-failed' : ''; ?>">
                        <td><?php echo esc_html($entry['time']); ?></td>
                        <td>
                            <?php if (!empty($entry['order_id'])): ?>
                                <a href="<?php echo esc_url(get_edit_post_link((int) $entry['order_id'])); ?>">
                                    #<?php echo esc_html($entry['order_id']); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($entry['event']); ?></td>
                        <td><?php echo esc_html($entry['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div><!-- .wrap -->

<script type="text/javascript">
(function($) {
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce   = '<?php echo esc_js($nonce); ?>';

    function showNotice(msg, type) {
        var $notice = $('#hyros-notice');
        $notice.removeClass('hyros-notice-success hyros-notice-error')
               .addClass(type === 'success' ? 'hyros-notice-success' : 'hyros-notice-error')
               .text(msg)
               .show();
    }

    function updateBadge(connected) {
        var $badge = $('#hyros-connection-badge');
        if (connected) {
            $badge.removeClass('hyros-badge-unknown hyros-badge-error')
                  .addClass('hyros-badge-ok')
                  .html('&#9679; Connected');
        } else {
            $badge.removeClass('hyros-badge-unknown hyros-badge-ok')
                  .addClass('hyros-badge-error')
                  .html('&#9679; Not connected');
        }
    }

    function fetchScripts(apiKey) {
        $.post(ajaxUrl, {
            action:  'hyros_fetch_scripts',
            nonce:   nonce,
            api_key: apiKey
        }, function(response) {
            if (!response.success) {
                showNotice(response.data.message, 'error');
                return;
            }
            var $select = $('#hyros-script-select');
            $select.find('option:not(:first)').remove();
            $.each(response.data.scripts, function(i, script) {
                $select.append($('<option>', { value: script.id, text: script.name }));
            });
            // Restore previously selected value.
            $select.val('<?php echo esc_js($selected_id); ?>');
            $('#hyros-script-row').show();
        }).fail(function() {
            showNotice('<?php echo esc_js(__('Failed to fetch scripts.', 'hyros-woo')); ?>', 'error');
        });
    }

    // Validate & Load Scripts
    $('#hyros-validate-btn').on('click', function() {
        var $btn    = $(this);
        var $status = $('#hyros-validate-status');
        var apiKey  = $('#hyros-api-key').val();

        if (!apiKey) {
            $status.removeClass('hyros-ok').addClass('hyros-error')
                   .text('<?php echo esc_js(__('Please enter an API key.', 'hyros-woo')); ?>');
            return;
        }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Validating...', 'hyros-woo')); ?>');
        $status.text('');

        $.post(ajaxUrl, {
            action:  'hyros_validate_key',
            nonce:   nonce,
            api_key: apiKey
        }, function(response) {
            $btn.prop('disabled', false)
                .text('<?php echo esc_js(__('Validate & Load Scripts', 'hyros-woo')); ?>');

            if (response.success) {
                $status.removeClass('hyros-error').addClass('hyros-ok')
                       .text(response.data.message);
                updateBadge(true);
                fetchScripts(apiKey);
            } else {
                $status.removeClass('hyros-ok').addClass('hyros-error')
                       .text(response.data.message);
                updateBadge(false);
            }
        }).fail(function() {
            $btn.prop('disabled', false)
                .text('<?php echo esc_js(__('Validate & Load Scripts', 'hyros-woo')); ?>');
            $status.removeClass('hyros-ok').addClass('hyros-error')
                   .text('<?php echo esc_js(__('Request failed.', 'hyros-woo')); ?>');
        });
    });

    // Save Settings
    $('#hyros-save-btn').on('click', function() {
        var $btn          = $(this);
        var apiKey        = $('#hyros-api-key').val();
        var scriptId      = $('#hyros-script-select').val();

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'hyros-woo')); ?>');

        $.post(ajaxUrl, {
            action:         'hyros_save_settings',
            nonce:          nonce,
            api_key:        apiKey,
            script_id:      scriptId,
            script_content: ''  // Content saved server-side from script_id lookup.
        }, function(response) {
            $btn.prop('disabled', false)
                .text('<?php echo esc_js(__('Save Settings', 'hyros-woo')); ?>');

            if (response.success) {
                showNotice(response.data.message, 'success');
            } else {
                showNotice(response.data.message, 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false)
                .text('<?php echo esc_js(__('Save Settings', 'hyros-woo')); ?>');
            showNotice('<?php echo esc_js(__('Save failed.', 'hyros-woo')); ?>', 'error');
        });
    });

    // Auto-check connection on load if key exists.
    <?php if (!empty($masked_key)): ?>
    updateBadge(false); // Will be updated if validation passes.
    <?php endif; ?>

})(jQuery);
</script>
