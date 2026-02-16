(function($){
  function getDirtyRows(){
    return $('.wpfp-row.is-dirty');
  }

  function updateDirtyStatus(){
    var count = getDirtyRows().length;
    var $status = $('.wpfp-save-status');
    if(!$status.length) return;
    if(count === 0){
      $status.text('Sin cambios pendientes.');
    } else if(count === 1){
      $status.text('1 factura con cambios sin guardar.');
    } else {
      $status.text(count + ' facturas con cambios sin guardar.');
    }
  }

  function markDirty($row, isDirty){
    $row.attr('data-dirty', isDirty ? '1' : '0').toggleClass('is-dirty', !!isDirty);
    updateDirtyStatus();
  }

  function setInitialObservation($row, value){
    $row.find('.wpfp-obs').attr('data-initial', value);
    markDirty($row, false);
  }

  function isRowDirty($row){
    var $obs = $row.find('.wpfp-obs');
    return ($obs.val() || '') !== ($obs.attr('data-initial') || '');
  }

  function toast(msg, ok){
    var $t = $('.wpfp-toast');
    if(!$t.length) return;
    $t.text(msg).removeClass('ok err').addClass(ok?'ok':'err').fadeIn(120);
    clearTimeout(window.__wpfp_to);
    if(ok){
      window.__wpfp_to = setTimeout(function(){ $t.fadeOut(200); }, 1600);
    }
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
        setInitialObservation($row, obs);
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

  $(document).on('input', '.wpfp-obs', function(){
    var $row = $(this).closest('tr');
    markDirty($row, isRowDirty($row));
  });

  $(document).on('keydown', '.wpfp-obs', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      saveRow($(this).closest('tr'));
    }
  });

  window.addEventListener('beforeunload', function(e){
    if(getDirtyRows().length > 0){
      e.preventDefault();
      e.returnValue = '';
    }
  });

  $(function(){
    updateDirtyStatus();
  });
})(jQuery);
