/*
 * @copyright 2014-2015 Sentora Project (http://www.sentora.org/) 
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * dns.js
 *
 * @package ZPanel DNS Manager
 * @version 1.0.0
 * @author Jason Davis - <jason.davis.fl@gmail.com>
 * @copyright (c) 2013 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 */

/*
NEW DNS Module JavaScript by Jason Davis
7/6/2013
 */


// SEC: escapa datos del usuario antes de meterlos en el HTML del diálogo (evita DOM XSS,
// p.ej. si el usuario teclea <img onerror=...> en el host name).
function dnsEscHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

var SentoraDNS = {

    unsavedChanges: false,

    init: function() {

        //this.cache.dnsTitleId = $("#dnsTitle");

        // Cache some Selectors for increased performance
        SentoraDNS.cache.dnsTitleId = $(".dnsTitle");

        SentoraDNS.events.init();

    },

    cache: {},

    events: {

        promptBeforeClose: function(e) {
            var e = e || window.event;
            if (!SentoraDNS.unsavedChanges) return;

            if (e) {
                e.returnValue = 'There are unsaved changes.  Are you sure you wish to leave without saving these changes?';
            }
            return 'There are unsaved changes.  Are you sure you wish to leave without saving these changes?';
        },

        init: function() {

            var mXWarning,
            nSWarning;

            // If user trys to leave the page with UN-SAVED changes, we will Alert them
            $(window).on('beforeunload', SentoraDNS.events.promptBeforeClose);


            //$("#typeMX div.hostName input").on('keypress',function() {
            $(document).on('keypress', '#typeMX div.hostName > input', function() {
                Sentora.utils.log('MX hostname change');
                var hostnameSelector = $(this);
                mXWarning = 'The host name portion of an MX record is typically left blank.<BR/>' +
                    'Only enter host name if you want the email address to be similar to <strong>username@hostname.example.com</strong>, ' +
                    'where hostname is what you are entering and example.com is the current domain name.';
                Sentora.dialog.confirm({
                    title: 'WARNING',
                    message: mXWarning,
                    width: 300,
                    cancelCallback: function(hostname) {
                        hostnameSelector.val('');
                    },
                    cancelButton: {
                        text: 'Cancel',
                        show: true,
                        class: 'btn-secondary'
                    },
                    okButton: {
                        text: 'Confirm',
                        show: true,
                        class: 'btn-primary'
                    },
                });

                $(document).off('keypress', '#typeMX div.hostName > input');
                //$("div.dnsRecordMX div.hostName input").die();
            });


            // Show Dialog when Delete button hit



            // Show Dialog if Hostname Matches the Domain Name
            $(document).on('change','div.hostName > input',function() {
                Sentora.utils.log('hostname change fired');
                var hostnameSelector = $(this);
                var hostName = $(this).val();
                var domainName = $("#domainName").val();
                var pattern = new RegExp(domainName + "$","g");

                if ( hostName.match(pattern) != null ) {
                    var msg = '<strong>Warnig:</strong> A host name record has been entered with the domain name.<BR/><BR/>' +
                         'The result will be the following:<BR/><strong>' + dnsEscHtml($(this).val()) + '.' + dnsEscHtml($("#domainName").val()) + '</strong><BR/><BR/>' +
                         'If this is not what you intended, <strong>Click Cancel</strong> to remove the domain name from the host name field and enter in only the host value.';
                    Sentora.dialog.confirm({
                        title: 'WARNING',
                        message: msg,
                        width: 300,
                        cancelCallback: function (hostname) {
                            hostnameSelector.val('');
                        },
                        cancelButton: {text: 'Cancel', show: true, class: 'btn-secondary'},
                        okButton: {text: 'Confirm', show: true, class: 'btn-primary'},
                    });
                }
            });


            // Activate SAVE and UNDO Buttons when Record Row EDITED
            $(document).on("keydown", "#dnsRecords input", function() {
                Sentora.utils.log(SentoraDNS.cache.dnsTitleId);
                Sentora.utils.log($("#dnsTitle"));
                SentoraDNS.cache.dnsTitleId.find(".save, .undo").removeClass("disabled");
                $(".tab-pane > .add").find(".save").removeClass("disabled");
            });
            $(document).on("change", "#dnsRecords select", function() {
                SentoraDNS.cache.dnsTitleId.find(".save, .undo").removeClass("disabled");
                $(".tab-pane > .add").find(".save").removeClass("disabled");
                SentoraDNS.unsavedChanges = true;
            });

            // Activate SAVE and UNDO Buttons when Record Row DELETED
            // $("#dnsRecords span.delete").on("click",function() {
            //     $("#dnsTitle a.save, #dnsTitle a.undo").removeClass("disabled");
            // });

            // Add new Record Row
            $("#dnsRecords div.add > .add-row").click(function(e) {
                Sentora.utils.log('add record button clicked');
                SentoraDNS.records.addRow($(this));
                e.preventDefault();
            });

            // Mark Record as "Deleted" and change the view of it's Row to reflect a Deleted item
            $(document).on("click", ".delete", function(e) {
                SentoraDNS.records.deleteRow($(this));
                e.preventDefault();
            });

            // Show Undo button when editing an EXISTING ROW
            $(document).on("keydown", "div.dnsRecord input[type='text']", function() {
                $(this).parents("div.dnsRecord").find("button.undo").fadeIn('slow');
                SentoraDNS.unsavedChanges = true;
            });

            // Undo editing of an EXISTING ROW
            $("button.undo").on("click", function() {
                SentoraDNS.records.undoRow($(this));
            });

            //Save Changes
            //$("#dnsTitle").find(".save").removeClass("disabled");
            $(document).on("click", ".dnsTitle a.save", function() {
                if ($(this).hasClass("disabled")) return false;
                Sentora.loader.showLoader();
                $("#dnsRecordsForm").submit();
                return false;
            });

            $(".tab-pane > .add").find(".save").click(function() {
                if ($(this).hasClass("disabled")) return false;
                Sentora.loader.showLoader();
                $("#dnsRecordsForm").submit();
                return false;
            });

            //Undo ALL Record Type Changes
            $(document).on("click", ".dnsTitle a.undo", function() {
                if ($(this).hasClass("disabled")) return false;
                $("button.undo").click();
                $(".tab-pane .new").remove();
                $(".dnsTitle a.save, .dnsTitle a.undo").addClass("disabled");
                $(".tab-pane > .add").find(".save").addClass("disabled");
                SentoraDNS.unsavedChanges = false;
                return false;
            });

            $("#dnsRecordsForm").submit(function() {
                SentoraDNS.unsavedChanges = false;
                // Combine component fields into hidden target before submission
                SentoraDNS.records.combineTargetFields($(this));
                //Remove any entries that have no value for any relevant fields
                $("div.dnsRecord").each(function() {
                    var hasValue = false;
                    $("input", $(this)).not("input[name*='ttl'], input[name*='type'], input[type='hidden']").filter(function() {
                        var val = $(this).val();
                        return val != "" || val > 0;
                    }).each(function() {
                        hasValue = true;
                    });
                    if (!hasValue) {
                        $(this).remove();
                    }
                });
                return true;
            });

        },

    },

    records: {

        // Add new record row
        addRow: function(record) {
            // Get correct DNS Record Template Div and Clone a copy of it
            var newRecord = record.parents("div.add").nextAll(".newRecord").clone();

            // Update New Record Counter Input field
            var counterElement = $("#dnsRecords input[name='newRecords']");
            var newId = parseInt(counterElement.attr("value"));
            newId++;
            counterElement.attr("value", newId);

            // Remove Labels from New records
            if (record.parents("div.add").siblings().length > 2) {
                Sentora.utils.log('div .add sibblings...');
                Sentora.utils.log(record.parents("div.add").siblings());
                //newRecord.find("label").remove();
            }

            // Set new record name (inputs and selects)
            $("input, select", newRecord).each(function() {
                var fieldName = $(this).attr("name").replace("proto_", "");
                $(this).attr("name", fieldName + "[new_" + newId + "]");
            });

            // Set CSS Class to mark record as NEW
            newRecord.addClass("dnsRecord new").removeClass("newRecord");
            newRecord.insertBefore(record.parents("div.add")).fadeIn();
            record.parents("div.records").scrollTop(record.parents("div.records").scrollTop() + 1000);
            $(".dnsTitle a.undo, .dnsTitle a.save").removeClass("disabled");
            $(".tab-pane > .add").find(".save").removeClass("disabled");
            SentoraDNS.unsavedChanges = true;

        },


        save: function() {

        },

        undoRow: function(row) {

            // Remove .tabError from all Tabs that have child Divs with .dnsRecordError
            $(".records div.dnsRecordError").parents("div.records").each(function(index) {
                var id = this.id;
                $("a[href='#" + id + "']").removeClass("tabError");
            });

            // Remove Disabled class from text inputs
            //row.siblings().children('.input-small').addClass("disabled");

            row.parents("div.dnsRecord").find("input[type='text']").each(function() {
                var myName = $(this).attr("name");
                myName = "original_" + myName;

                $(this).val($("input[name='" + myName + "']").val());
                $(this).parents("div.dnsRecord").removeClass("dnsRecordError");
                $(this).parents("div.dnsRecord").find("div.errorMessage").remove();

                $(this).parents("div.dnsRecord").removeClass("deleted").find("input.delete").val("false");
            });
            row.fadeOut('fast');
        },


        deleteRow: function(row) {

            row.parents("div.dnsRecord").addClass("deleted").find("input.delete").val("true");
            row.parents("div.dnsRecord").find("button.undo").fadeIn('slow');
            // Add Disabled class to Deleted inputs
            //row.siblings().children('.input-small').addClass("disabled");
            $(".dnsTitle a.save, .dnsTitle a.undo").removeClass("disabled");
            $(".tab-pane > .add").find(".save").removeClass("disabled");
            SentoraDNS.unsavedChanges = true;

        },


        delete: function() {
            var target = document.getElementById('zloader_content');
        },

        combineTargetFields: function($form) {
            // CAA: <flags> <tag> "<value>"
            $form.find('[name^="caa_flags["]').each(function() {
                var id = $(this).attr('name').replace('caa_flags[', '').replace(']', '');
                var flags = $(this).val().trim() || '0';
                var tag   = $form.find('[name="caa_tag['   + id + ']"]').val() || 'issue';
                var value = $form.find('[name="caa_value[' + id + ']"]').val().trim().replace(/"/g, '');
                $form.find('[name="target[' + id + ']"]').val(flags + ' ' + tag + ' "' + value + '"');
            });
            // NAPTR: <order> <pref> "<flags>" "<service>" "<regexp>" <replacement>
            $form.find('[name^="naptr_order["]').each(function() {
                var id      = $(this).attr('name').replace('naptr_order[', '').replace(']', '');
                var order   = $(this).val().trim() || '100';
                var pref    = $form.find('[name="naptr_pref['        + id + ']"]').val().trim() || '10';
                var flags   = $form.find('[name="naptr_flags['       + id + ']"]').val().trim().replace(/"/g, '');
                var service = $form.find('[name="naptr_service['     + id + ']"]').val().trim().replace(/"/g, '');
                var regexp  = $form.find('[name="naptr_regexp['      + id + ']"]').val().trim().replace(/"/g, '');
                var repl    = $form.find('[name="naptr_replacement[' + id + ']"]').val().trim() || '.';
                $form.find('[name="target[' + id + ']"]').val(
                    order + ' ' + pref + ' "' + flags + '" "' + service + '" "' + regexp + '" ' + repl
                );
            });
            // SSHFP: <algo> <fptype> <fingerprint>
            $form.find('[name^="sshfp_algo["]').each(function() {
                var id     = $(this).attr('name').replace('sshfp_algo[', '').replace(']', '');
                var algo   = $(this).val() || '4';
                var fptype = $form.find('[name="sshfp_fptype[' + id + ']"]').val() || '2';
                var fp     = $form.find('[name="sshfp_fp['     + id + ']"]').val().trim();
                $form.find('[name="target[' + id + ']"]').val(algo + ' ' + fptype + ' ' + fp);
            });
            // TLSA: <usage> <selector> <matching> <certdata>
            $form.find('[name^="tlsa_usage["]').each(function() {
                var id       = $(this).attr('name').replace('tlsa_usage[', '').replace(']', '');
                var usage    = $(this).val() || '3';
                var selector = $form.find('[name="tlsa_selector[' + id + ']"]').val() || '1';
                var matching = $form.find('[name="tlsa_matching[' + id + ']"]').val() || '1';
                var cert     = $form.find('[name="tlsa_cert['     + id + ']"]').val().trim();
                $form.find('[name="target[' + id + ']"]').val(usage + ' ' + selector + ' ' + matching + ' ' + cert);
            });
            // URI: <priority> <weight> "<uri>"
            $form.find('[name^="uri_priority["]').each(function() {
                var id  = $(this).attr('name').replace('uri_priority[', '').replace(']', '');
                var pri = $(this).val().trim() || '10';
                var wt  = $form.find('[name="uri_weight[' + id + ']"]').val().trim() || '1';
                var uri = $form.find('[name="uri_uri['    + id + ']"]').val().trim().replace(/"/g, '');
                $form.find('[name="target[' + id + ']"]').val(pri + ' ' + wt + ' "' + uri + '"');
            });
        },
    }

};

$(function() {
    SentoraDNS.init();
});