jQuery(document).ready(function ($) {
  
    $(window.location.hash).modal('show');
    $(".modal").on("hidden.bs.modal", function () { // any time a modal is hidden
      var urlReplace = window.location.toString().split('#', 1)[0];
      history.pushState(null, null, urlReplace); // push url without the hash as new history item
    });

    var bfuStopLoop = false;
    var bfuProcessingLoop = false;
    var bfuLoopErrors = 0;
    var bfuAjaxCall = false;

    $(window).bind('beforeunload', function () {
      if (bfuProcessingLoop) {
        return bfu_data.strings.leave_confirmation;
      }
    });

    var showError = function (error_message) {
      $('#bfu-error').text(error_message.substr(0, 200)).show();
      $("html, body").animate({scrollTop: 0}, 1000);
    }

    var fileScan = function (remaining_dirs) {
      if (bfuStopLoop) {
        bfuStopLoop = false;
        bfuProcessingLoop = false;
        return false;
      }
      bfuProcessingLoop = true;

      var data = {"remaining_dirs": remaining_dirs, js_nonce: bfu_data.uimptr_nonce,};
      $.post(ajaxurl + '?action=uimptr_bfu_file_scan', data, function (json) {
        if (json.success) {
          $('#bfu-scan-storage').text(json.data.file_size);
          $('#bfu-scan-files').text(json.data.file_count);
          $('#bfu-scan-progress').show();
          if (!json.data.is_done) {
            fileScan(json.data.remaining_dirs);
          } else {
            bfuProcessingLoop = false;
            if ( $('#subscribe-modal').length ) {
              $('.modal').modal('hide');
              $('#subscribe-modal').modal({
                backdrop: 'static',
                keyboard: false
              });
            }
          return true;
        }

      } else {
        showError(json.data);
        $('.modal').modal('hide');
      }
    }, 'json').fail(function () {
        showError(bfu_data.strings.ajax_error);
        $('.modal').modal('hide');
    });
  };

  $('#scan-modal').on('show.bs.modal', function () {
    $('#bfu-error').hide();
    bfuStopLoop = false;
    fileScan([]);
  }).on('hide.bs.modal', function () {
    bfuStopLoop = true;
    bfuProcessingLoop = false;
  });


  $('#subscribe-modal').on('shown.bs.modal', function () {
      $('#scan-modal').modal('hide');
  })

  $('.bfu-input-limit select').on('change', function () {
    var field = $(this).parents('.bfu-input-limit').children('input');
    if ($(this).val() === 'MB') {
      field.val(Math.round(field.val() * 1024));
    } else {
      field.val((field.val() / 1024).toFixed(1));
    }
  });

  $('#customSwitch_role').on('change', function () {
      bfu_is_roles(this);
  });

  $('#bfu-view-results').on('click', function () {
    $.post(ajaxurl, {
      action: 'uimptr_subscribe_dismiss',
      nonce: uimptr_ajax.nonce
    }, function( data ) {
      location.reload();
    });
  });

  var mc1Submitted = false;
  $('#mc-embedded-subscribe-form').on('submit reset', function (event) {
    if ("submit" === event.type) {
      mc1Submitted = true;
    } else if ( "reset" === event.type && mc1Submitted ) {;
      $('#bfu-subscribe-button').prop('disabled', true);
      $.post(ajaxurl, {
        action: 'uimptr_subscribe_dismiss',
        nonce: uimptr_ajax.nonce
      }, function( data ) {
        location.reload();
      });
    }
  });

  var sizelabel = function (tooltipItem, data) {
    var label = ' ' + data.labels[tooltipItem.index] || '';
    return label;
  };

  window.onload = function () {
    var pie1 = document.getElementById('bfu-local-pie');
    if (pie1) {

      var config_local = {
        type: 'pie',
        data: bfu_data.local_types,
        options: {
          responsive: true,
          legend: false,
          tooltips: {
            callbacks: {
              label: sizelabel
            },
            backgroundColor: '#F1F1F1',
            bodyFontColor: '#2A2A2A',
          },
          title: {
            display: true,
            position: 'bottom',
            fontSize: 18,
            fontStyle: 'normal',
            text: bfu_data.local_types.total
          }
        }
      };

      var ctx = pie1.getContext('2d');
      window.myPieLocal = new Chart(ctx, config_local);
    }
  }

  $('#scan-modal').on('click', '.btn-primary', function(e) {
    e.preventDefault();

    $.post(bfu_data.ajax_url, {
        action: 'uimptr_bfu_file_scan',
    }, function(response) {
        if (response.success) {
            $('#scan-results').html(response.data);
        } else {
            alert(response.data);
        }
    }).fail(function(jqXHR, textStatus, errorThrown) {
        alert('AJAX request failed: ' + textStatus + ', ' + errorThrown);
    });
  });

  $('#open-scan-modal-button').on('click', function() {
    $('#scan-modal').modal('show'); // This will open the modal
  });
});
