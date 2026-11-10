/* CRM de leads: abrir o detalhe, trocar status, salvar observações e atribuir corretor sem recarregar a página. */
(function () {
  'use strict';
  var base = document.body.dataset.base || '';
  var meta = document.querySelector('meta[name="csrf"]');
  var token = meta ? meta.content : '';
  var toast = document.getElementById('toast');
  var relogio;

  function aviso(msg, erro) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.toggle('erro', !!erro);
    toast.hidden = false;
    clearTimeout(relogio);
    relogio = setTimeout(function () { toast.hidden = true; }, erro ? 5000 : 2400);
  }
  if (toast && !toast.hidden) relogio = setTimeout(function () { toast.hidden = true; }, 5000);

  function enviar(acao, dados) {
    return fetch(base + '/?r=' + acao, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'X-CSRF': token },
      body: new URLSearchParams(dados)
    }).then(function (r) {
      if (r.status === 401) { location.href = base + '/?r=login'; throw new Error('sessão'); }
      return r.json().then(function (j) {
        if (!j.ok) throw new Error(j.msg || 'Não foi possível salvar.');
        return j;
      });
    });
  }
  function conta(chave, delta) {
    var el = document.querySelector('[data-n="' + chave + '"]');
    if (el) el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
  }
  function abrir(li, abre) {
    var b = li.querySelector('.abrir'), det = li.querySelector('.det');
    if (abre === undefined) abre = det.hidden;
    det.hidden = !abre;
    b.setAttribute('aria-expanded', String(abre));
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('.abrir');
    if (b) { abrir(b.closest('.lead')); return; }
    var s = e.target.closest('[data-salvar-obs]');
    if (s) {
      var li = s.closest('.lead');
      s.disabled = true;
      enviar('lead_obs', { id: li.dataset.id, valor: li.querySelector('[data-obs]').value })
        .then(function (j) { aviso(j.msg); })
        .catch(function (err) { aviso(err.message, true); })
        .then(function () { s.disabled = false; });
    }
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.matches('[data-envia]')) { el.form.submit(); return; }
    if (!el.matches('[data-acao]')) return;
    var li = el.closest('.lead'), acao = el.dataset.acao;
    var anterior = el.dataset.anterior !== undefined ? el.dataset.anterior : (el.querySelector('option[selected]') || {}).value || '';
    enviar(acao, { id: li.dataset.id, valor: el.value }).then(function (j) {
      el.dataset.anterior = el.value;
      if (acao === 'lead_status') {
        el.closest('.st').dataset.s = j.status;
        var m = li.querySelector('[data-motivo]');
        if (m) m.hidden = j.status !== 'perdido';
        if (j.status === 'perdido') abrir(li, true);
        if (j.antes !== j.status) { conta(j.antes, -1); conta(j.status, 1); }
      }
      if (acao === 'lead_atribuir') {
        var sub = li.querySelector('[data-cor]');
        if (sub) sub.textContent = j.corretor || 'A distribuir';
        if (!anterior && el.value) conta('distribuir', -1);
        if (anterior && !el.value) conta('distribuir', 1);
      }
      aviso(j.msg);
    }).catch(function (err) {
      el.value = anterior;
      aviso(err.message, true);
    });
  });

  document.addEventListener('input', function (e) {
    if (!e.target.matches('[data-obs]')) return;
    var c = e.target.closest('.obs').querySelector('[data-conta]');
    if (c) c.textContent = e.target.value.length + ' de ' + e.target.maxLength + ' caracteres';
  });

  /* Imóvel de referência: mostra na hora qual imóvel o link ou o código colado representa. */
  var campoIm = document.querySelector('[data-imoveis]');
  if (campoIm) {
    var dica = document.getElementById('n-im-dica');
    var padrao = dica.textContent;
    var mapa = {};
    try { mapa = JSON.parse(campoIm.dataset.imoveis) || {}; } catch (err) {}
    var conferir = function () {
      var v = campoIm.value.trim(), m, cod = '', eLink = false;
      if ((m = v.match(/marceloosawaimoveis\.com\.br\/(\d{5,9})/i))) { cod = m[1]; eLink = true; }
      else if ((m = v.match(/^(?:c[oó]d(?:igo)?\.?\s*)?(\d{5,9})$/i))) cod = m[1];
      dica.className = 'dica';
      if (!v) dica.textContent = padrao;
      else if (cod && mapa[cod]) { dica.textContent = 'Imóvel reconhecido: ' + mapa[cod] + ' (cód. ' + cod + ')'; dica.classList.add('ok'); }
      else if (cod && eLink) dica.textContent = 'Link do site aceito (cód. ' + cod + '). O imóvel ainda não está na página inicial.';
      else if (cod) { dica.textContent = 'Não achei o código ' + cod + ' entre os imóveis do site. Confira o número ou cole o link.'; dica.classList.add('erro'); }
      else dica.textContent = 'Será salvo como texto livre, sem link para o site.';
    };
    campoIm.addEventListener('input', conferir);
    conferir();
  }

  /* Exclusões pedem um segundo toque no mesmo botão. */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.classList.contains('confirma') || f.dataset.ok) return;
    e.preventDefault();
    var b = f.querySelector('[data-confirma]');
    var texto = b.textContent;
    b.textContent = b.dataset.confirma;
    f.dataset.ok = '1';
    setTimeout(function () { b.textContent = texto; delete f.dataset.ok; }, 4000);
  });
})();
