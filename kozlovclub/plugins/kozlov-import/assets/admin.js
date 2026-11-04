(function ($) {
  var running = false, stop = false;
  function log(t) { var el = $('#ki-log'); el.text(el.text() + t + '\n'); el.scrollTop(el[0].scrollHeight); }
  function run(step, offset) {
    if (stop) { running = false; stop = false; $('.ki-steps button').prop('disabled', false); $('#ki-stop').prop('disabled', true); log('Остановлено.'); return; }
    $.post(KI.ajax, { action: 'ki_step', nonce: KI.nonce, step: step, offset: offset || 0, value: $('#ki-jz-number').val() || 0 }).done(function (r) {
      if (!r.success) { log('Ошибка: ' + (r.data || '?')); finish(step); return; }
      var d = r.data; log(d.log || ''); $('.ki-status[data-for="' + (step === 'media_retry' ? 'media' : (step === 'media_origin_retry' ? 'media_origin' : step)) + '"]').text(d.status || '');
      if (d.pending !== undefined) $('#ki-pending').text(d.pending);
      if (d.done) { finish(step); } else { run(step, d.offset); }
    }).fail(function (x) { log('Сбой запроса (' + x.status + '). Нажмите «Запустить» ещё раз, импорт продолжится с места остановки.'); finish(step); });
  }
  function finish(step) { running = false; $('.ki-steps button').prop('disabled', false); $('#ki-stop').prop('disabled', true); }
  $('.ki-steps button').on('click', function () {
    if (running) return; running = true; stop = false;
    $('.ki-steps button').prop('disabled', true); $('#ki-stop').prop('disabled', false);
    var step = $(this).data('step'); log('— ' + step + ' —'); run(step, 0);
  });
  $('#ki-stop').on('click', function () { stop = true; });
})(jQuery);
