/* global hyrosAdmin, jQuery */
(function ($) {
    'use strict';

    var cfg        = hyrosAdmin;
    var i18n       = cfg.i18n;
    var debug      = cfg.debug === '1';
    var savedDomain = cfg.savedDomain || '';

    function log() {
        if (debug && window.console) {
            console.log.apply(console, ['[HyrosWoo]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    function showNotice(msg, type) {
        var $n = $('#hyros-notice');
        $n.removeClass('hyros-notice-success hyros-notice-error')
          .addClass(type === 'success' ? 'hyros-notice-success' : 'hyros-notice-error')
          .text(msg).show();
        log('Notice (' + type + '):', msg);
    }

    function updateBadge(connected) {
        var $b = $('#hyros-connection-badge');
        $b.removeClass('hyros-badge-unknown hyros-badge-ok hyros-badge-error');
        if (connected) {
            $b.addClass('hyros-badge-ok').html('<span class="hyros-dot"></span>' + i18n.connected);
        } else {
            $b.addClass('hyros-badge-error').html('<span class="hyros-dot"></span>' + i18n.notConnected);
        }
    }

    function setDomainStatus(message, type) {
        var $status = $('#hyros-domain-status');
        $status.removeClass('hyros-ok hyros-error').text(message || '');
        if (type === 'ok') {
            $status.addClass('hyros-ok');
        } else if (type === 'error') {
            $status.addClass('hyros-error');
        }
    }

    /* ------------------------------------------------------------------
       Account Info Card
       ------------------------------------------------------------------ */

    function populateAccountCard(account) {
        if (!account || !account.email) { return; }

        var firstName = account.firstName || '';
        var lastName  = account.lastName  || '';
        var initials  = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase() || '??';

        $('#hyros-avatar').text(initials);
        $('#hyros-account-name').text((firstName + ' ' + lastName).trim() || account.email);
        $('#hyros-account-email').text(account.email);
        $('#hyros-account-company').text(account.companyName || '');
        $('#hyros-tz').text(account.timezone || '—');
        $('#hyros-currency').text(account.currency || '—');
        $('#hyros-attribution').text(account.attribution ? account.attribution + ' days' : '—');
        $('#hyros-pending-mode').text(account.pendingMode || '—');

        var organicVal = account.ignoreOrganic;
        $('#hyros-ignore-organic').html(
            (organicVal === 'true' || organicVal === true)
                ? '<span class="hyros-tag hyros-tag--warning">Yes</span>'
                : '<span class="hyros-tag hyros-tag--green">No</span>'
        );

        var groupingVal = account.saleGrouping;
        var windowVal   = account.groupingWindow;
        var $grouping   = $('#hyros-sale-grouping').empty();
        if (groupingVal === 'true' || groupingVal === true) {
            var minutes = parseInt(windowVal, 10);
            var groupLabel = (!isNaN(minutes) && minutes > 0) ? String(minutes) + ' min' : 'On';
            $('<span>', { 'class': 'hyros-tag hyros-tag--blue', text: groupLabel }).appendTo($grouping);
        } else {
            $('<span>', { 'class': 'hyros-tag', text: 'Off' }).appendTo($grouping);
        }

        var recVal = account.recurringEnabled;
        $('#hyros-recurring').html(
            (recVal === 'true' || recVal === true)
                ? '<span class="hyros-tag hyros-tag--green">Enabled</span>'
                : '<span class="hyros-tag">Disabled</span>'
        );

        var euVal = account.trackEu;
        var $eu   = $('#hyros-eu');
        if (euVal === 'true' || euVal === true) {
            $eu.html('<span class="hyros-tag hyros-tag--green">Yes</span>');
        } else {
            $eu.html('<span class="hyros-tag">No</span>');
        }

        $('#hyros-account-card').slideDown(200);
        log('Account card populated for', account.email);
    }

    /* ------------------------------------------------------------------
       Domain Dropdown
       ------------------------------------------------------------------ */

    function populateDomains(domains) {
        var $select = $('#hyros-domain-select');
        $select.find('option:not(:first)').remove();
        $.each(domains, function (i, domain) {
            // Use jQuery methods — never string concat — to prevent DOM-XSS.
            var $opt = $('<option>', { value: domain, text: domain });
            if (domain === savedDomain) { $opt.prop('selected', true); }
            $select.append($opt);
        });
        $('#hyros-domain-row').show();
        log('Domains loaded:', domains);
    }

    function loadScriptForDomain(domain) {
        setDomainStatus(i18n.loading, '');

        $.post(cfg.ajaxUrl, {
            action: 'hyros_get_domain_script',
            nonce:  cfg.nonce,
            domain: domain
        }, function (response) {
            log('Domain script response:', response);
            if (response.success && response.data.script) {
                $('#hyros-script-content').val(response.data.script);
                setDomainStatus(i18n.scriptLoaded, 'ok');
            } else {
                setDomainStatus(i18n.couldNotLoad, 'error');
            }
        }).fail(function () {
            setDomainStatus(i18n.requestFailed, 'error');
        });
    }

    $('#hyros-domain-select').on('change', function () {
        var domain = $(this).val();
        if (!domain) { return; }
        savedDomain = domain;
        loadScriptForDomain(domain);
    });

    /* ------------------------------------------------------------------
       Validate + Success handler
       ------------------------------------------------------------------ */

    function handleValidateSuccess(data) {
        updateBadge(true);

        if (data.account) {
            populateAccountCard(data.account);
        }

        if (data.domains && data.domains.length > 0) {
            populateDomains(data.domains);
            // Auto-load the saved domain script if textarea is empty.
            if (savedDomain && !$('#hyros-script-content').val()) {
                loadScriptForDomain(savedDomain);
            }
        } else if (data.script) {
            $('#hyros-script-content').val(data.script);
            log('Tracking script loaded, length:', data.script.length);
        }
    }

    /* ------------------------------------------------------------------
       Validate Key button
       ------------------------------------------------------------------ */

    $('#hyros-validate-btn').on('click', function () {
        var $btn    = $(this);
        var $status = $('#hyros-validate-status');
        var apiKey  = $('#hyros-api-key').val();

        if (!apiKey) {
            $status.removeClass('hyros-ok').addClass('hyros-error').text(i18n.enterApiKey);
            return;
        }

        $btn.prop('disabled', true).text(i18n.validating);
        $status.text('');

        var payload = { action: 'hyros_validate_key', nonce: cfg.nonce, api_key: apiKey };
        log('Validate request');

        $.post(cfg.ajaxUrl, payload, function (response) {
            log('Validate response:', response);
            $btn.prop('disabled', false).text(i18n.validateKey);

            if (response.success) {
                $status.removeClass('hyros-error').addClass('hyros-ok').text(response.data.message);
                handleValidateSuccess(response.data);
            } else {
                $status.removeClass('hyros-ok').addClass('hyros-error').text(response.data.message);
                updateBadge(false);
            }
        }).fail(function (xhr, status, error) {
            log('Validate failed:', status, error);
            $btn.prop('disabled', false).text(i18n.validateKey);
            $status.removeClass('hyros-ok').addClass('hyros-error').text(i18n.requestFailed);
        });
    });

    /* ------------------------------------------------------------------
       Save Settings button
       ------------------------------------------------------------------ */

    $('#hyros-save-btn').on('click', function () {
        var $btn = $(this);
        var scriptContent = $('#hyros-script-content').val();

        // Structural pre-check only. The server validates the src host against
        // *.hyros.com plus the account's custom tracking domains from /domains.
        if (scriptContent && !/<script[\s\S]*script\.src[\s\S]*<\/script>/i.test(scriptContent)) {
            showNotice(i18n.invalidScript, 'error');
            $('#hyros-script-content').attr('aria-invalid', 'true').focus();
            return;
        }
        $('#hyros-script-content').attr('aria-invalid', 'false');

        $btn.prop('disabled', true).text(i18n.saving);

        $.post(cfg.ajaxUrl, {
            action:               'hyros_save_settings',
            nonce:                cfg.nonce,
            api_key:              $('#hyros-api-key').val(),
            script_content:       scriptContent,
            track_sales:          $('input[name="track_sales"]').is(':checked')         ? '1' : '',
            track_subscriptions:  $('input[name="track_subscriptions"]').is(':checked') ? '1' : '',
            track_add_to_cart:    $('input[name="track_add_to_cart"]').is(':checked')   ? '1' : '',
            send_cogs:            $('input[name="send_cogs"]').is(':checked')           ? '1' : '',
            inject_script:        $('input[name="inject_script"]').is(':checked')       ? '1' : '',
            require_consent:      $('input[name="require_consent"]').is(':checked')     ? '1' : ''
        }, function (response) {
            log('Save response:', response);
            $btn.prop('disabled', false).text(i18n.saveSettings);
            if (response.success) {
                showNotice(response.data.message, response.data.warning ? 'error' : 'success');
            } else {
                showNotice(response.data.message, 'error');
            }
        }).fail(function (xhr, status, error) {
            log('Save failed:', status, error);
            $btn.prop('disabled', false).text(i18n.saveSettings);
            showNotice(i18n.saveFailed, 'error');
        });
    });

    /* ------------------------------------------------------------------
       Activity Log — expandable detail rows
       ------------------------------------------------------------------ */

    $(document).on('click', '.hyros-log-detail-toggle', function () {
        var $btn      = $(this);
        var $row      = $btn.closest('tr').next('.hyros-log-detail-row');
        var isVisible = $row.is(':visible');
        $row.toggle(!isVisible);
        $row.attr('aria-hidden', isVisible ? 'true' : 'false');
        $btn.attr('aria-expanded', isVisible ? 'false' : 'true');
        $btn.text(isVisible ? i18n.view : i18n.hide);
    });

    /* ------------------------------------------------------------------
       Auto-check on page load when a key is already stored
       ------------------------------------------------------------------ */

    if (cfg.hasKey === '1') {
        var $badge = $('#hyros-connection-badge');
        $badge.removeClass('hyros-badge-unknown hyros-badge-ok hyros-badge-error')
              .addClass('hyros-badge-unknown')
              .html('<span class="hyros-dot"></span>' + i18n.checking);

        $.post(cfg.ajaxUrl, {
            action:  'hyros_validate_key',
            nonce:   cfg.nonce,
            api_key: cfg.maskedKey
        }, function (response) {
            log('Auto-check response:', response);
            if (response.success) {
                handleValidateSuccess(response.data);
            } else {
                updateBadge(false);
            }
        }).fail(function () {
            updateBadge(false);
        });
    }

})(jQuery);
