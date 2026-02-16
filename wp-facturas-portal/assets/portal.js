(function($){
  function toast(msg, ok){
    var $t = $('.wpfp-toast');
    if(!$t.length) return;
    $t.text(msg).removeClass('ok err').addClass(ok?'ok':'err').fadeIn(120);
    clearTimeout(window.__wpfp_to);
    window.__wpfp_to = setTimeout(function(){ $t.fadeOut(200); }, 1600);
  }

  function updateBadge($row, estado){
    var $b = $row.find('.wpfp-badge');
    $b.removeClass('wpfp-pendiente wpfp-asignado wpfp-duda wpfp-cargada')
      .addClass('wpfp-'+estado)
      .text(estado.charAt(0).toUpperCase()+estado.slice(1));
  }

  function saveRow($row){
    var id = $row.data('id');
    var obs = $row.find('.wpfp-obs').val();
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
        toast('Guardado', true);
      } else {
        toast((resp && resp.data && resp.data.message) ? resp.data.message : 'Error', false);
      }
    }).fail(function(xhr){
      var msg = 'Error';
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
      toast(msg, false);
    }).always(function(){
      $btn.prop('disabled', false).text('Guardar');
    });
  }

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
})(jQuery);
