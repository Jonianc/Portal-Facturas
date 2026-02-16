(function($){
  function toast(msg, ok){
    var $t = $('.wpfp-toast');
    if(!$t.length) return;
    $t.text(msg).removeClass('ok err').addClass(ok?'ok':'err').fadeIn(120);
    clearTimeout(window.__wpfp_to);
    window.__wpfp_to = setTimeout(function(){ $t.fadeOut(220); }, 1800);
  }

  function updateBadge($row, estado){
    var $b = $row.find('.wpfp-badge');
    $b.removeClass('wpfp-pendiente wpfp-asignado')
      .addClass('wpfp-'+estado)
      .text(estado.charAt(0).toUpperCase()+estado.slice(1));
  }

  function setDirtyState($input, dirty){
    var $row = $input.closest('tr');
    var $btn = $row.find('.wpfp-save');
    $input.toggleClass('wpfp-dirty', !!dirty);
    $btn.toggleClass('wpfp-ready', !!dirty);
  }

  function saveRow($row){
    var id = $row.data('id');
    var $input = $row.find('.wpfp-obs');
    var obs = $input.val();
    var $btn = $row.find('.wpfp-save');
    $btn.prop('disabled', true).text('Guardando...');

    $.post(WPFPP.ajax_url, {
      action: 'wpfp_update_factura',
      nonce: WPFPP.nonce,
      id: id,
      observacion: obs
    }).done(function(resp){
      if(resp && resp.success){
        updateBadge($row, resp.data.estado);
        $input.data('initial', obs);
        setDirtyState($input, false);
        toast('Guardado correctamente', true);
      } else {
        toast((resp && resp.data && resp.data.message) ? resp.data.message : 'Error al guardar', false);
      }
    }).fail(function(xhr){
      var msg = 'Error al guardar';
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
      toast(msg, false);
    }).always(function(){
      $btn.prop('disabled', false).text('Guardar');
    });
  }

  function refreshDirtyState($input){
    var initial = $input.data('initial');
    if (typeof initial === 'undefined') {
      initial = $input.val();
      $input.data('initial', initial);
    }
    setDirtyState($input, $input.val() !== initial);
  }

  $('.wpfp-obs').each(function(){
    var $input = $(this);
    $input.data('initial', $input.val());
  });

  $(document).on('input', '.wpfp-obs', function(){
    refreshDirtyState($(this));
  });

  $(document).on('click', '.wpfp-save', function(){
    var $row = $(this).closest('tr');
    saveRow($row);
  });

  $(document).on('keydown', '.wpfp-obs', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      saveRow($(this).closest('tr'));
    }
  });

  $(document).on('change', '[data-wpfp-autosubmit="month"]', function(){
    var form = this.form;
    if(form) form.submit();
  });
})(jQuery);
