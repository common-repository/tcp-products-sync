jQuery(document).ready(function($) {

  var activation_key;

  $('#activation_key_label').delegate('#change_activation_key', 'click', function(e) {
    e.preventDefault();
    activation_key = $('#activation_key').text();
    $('#activation_key_label').html(
      '<input class="regular-text" type="text" name="activation_key"> ' +
      '<input type="submit" class="button button-primary" value="' + wcjsonsync_lang.activate + '"> ' + 
      '<input type="button" class="button" value="' + wcjsonsync_lang.cancel + '" id="cancel_change_key">'
    );
  });

  $('#activation_key_label').delegate('#cancel_change_key', 'click', function(e) {
    e.preventDefault();
    $('#activation_key_label').html(
      '<p id="activation_key">' + activation_key + '</p>' +
      '<p><a href="#" id="change_activation_key">' + wcjsonsync_lang.change_activation_key + '</a></p>'
    );
  });

  // sync status
  $('#sync_form').submit(function() {
    $('#sync_button').prop('value', wcjsonsync_lang.syncing + '...').prop('disabled', true);
  });

  // sync plugins
  $('#tcp_plugins_sync_form').submit(function() {
    $('#plugins_sync_button').prop('value', wcjsonsync_lang.syncing + '...').prop('disabled', true);
  });

});