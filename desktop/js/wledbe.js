/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

function wledbeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'un appareil est du texte, jamais du balisage. */
function wledbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

function wledbeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/wledbe/core/ajax/wledbe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: result.result, level: 'danger' })
        return
      }
      _success(result)
    }
  })
}

function wledbeStatus(_text, _level) {
  var span = wledbeEl('span_wledbeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

function wledbeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

function wledbeReload(_id) {
  jeedomUtils.loadPage('index.php?v=d&m=wledbe&p=wledbe' + (_id ? '&id=' + _id : ''))
}

/* ============================================================== DÉCOUVERTE */

function wledbeDiscover() {
  var last = ''
  try { last = window.localStorage.getItem('wledbe:subnet') || '' } catch (e) { last = '' }

  bootbox.prompt({
    title: '{{Sous-réseau à parcourir (laisser vide pour celui de Jeedom)}}',
    value: last,
    placeholder: '192.168.0.0/24',
    callback: function (_subnet) {
      if (_subnet === null) { return }
      var subnet = String(_subnet).trim()
      try { window.localStorage.setItem('wledbe:subnet', subnet) } catch (e) { /* mode privé */ }
      jeedomUtils.showAlert({ message: '{{Recherche des WLED (mDNS et balayage du réseau)… une dizaine de secondes.}}', level: 'info' })
      wledbeAjax('discover', { subnet: subnet }, function (result) {
        jeedomUtils.hideAlert()
        wledbeShowFound(result.result)
      })
    }
  })
}

function wledbeAddIp() {
  bootbox.prompt({
    title: '{{Adresse IP du WLED}}',
    placeholder: '192.168.0.150',
    callback: function (_ip) {
      if (_ip === null) { return }
      var ip = String(_ip).trim()
      if (ip === '') { return }
      wledbeAjax('probe', { ip: ip }, function (result) {
        wledbeShowFound(result.result)
      })
    }
  })
}

function wledbeShowFound(_result) {
  var devices = (isset(_result) && isset(_result.devices)) ? _result.devices : []
  if (devices.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucun WLED n\'a répondu. Vérifiez qu\'il est allumé et sur le même réseau, ou saisissez le sous-réseau où il se trouve.}}', level: 'warning' })
    return
  }
  var html = '<p>{{Cochez les appareils à créer. Ceux qui existent déjà verront seulement leur adresse mise à jour.}}</p>'
  if (_result.mdns === false) {
    html += '<p class="text-muted"><small>{{avahi-browse est absent de la machine : seul le balayage HTTP a été utilisé.}}</small></p>'
  }
  for (var i = 0; i < devices.length; i++) {
    var d = devices[i]
    html += '<div class="checkbox"><label>'
    html += '<input type="checkbox" class="wledbeFound" data-index="' + i + '"' + (d.known && d.known_ip === d.ip ? '' : ' checked') + '> '
    html += '<b>' + wledbeEscape(d.name || d.mac) + '</b>'
    html += ' — ' + wledbeEscape(d.ip)
    html += ' <span class="label label-info">' + (d.layout === 'matrix' ? '{{matrice}} ' + d.matrix_w + '×' + d.matrix_h : '{{bande}}') + '</span>'
    html += ' <small>' + wledbeEscape(d.leds) + ' {{LED}} · WLED ' + wledbeEscape(d.version) + ' · ' + wledbeEscape(d.source) + '</small>'
    if (d.known) {
      html += ' <span class="label label-default">{{déjà créé :}} ' + wledbeEscape(d.known) + '</span>'
      if (d.known_ip !== d.ip) { html += ' <span class="label label-warning">{{nouvelle adresse}}</span>' }
    }
    html += '</label></div>'
  }
  bootbox.confirm({
    title: '{{WLED trouvés}}',
    message: html,
    callback: function (_ok) {
      if (!_ok) { return }
      var chosen = []
      document.querySelectorAll('.wledbeFound').forEach(function (_box) {
        if (_box.checked) { chosen.push({ ip: devices[parseInt(_box.getAttribute('data-index'), 10)].ip }) }
      })
      if (chosen.length === 0) { return }
      wledbeAjax('create', { devices: JSON.stringify(chosen) }, function () {
        jeedomUtils.showAlert({ message: '{{WLED enregistré(s).}}', level: 'success' })
        wledbeReload()
      })
    }
  })
}

/* ============================================================== DIAGNOSTIC */

function wledbeRender(_data) {
  var state = wledbeEl('div_wledbeState')
  if (state !== null && isset(_data)) {
    var lists = (_data.effects !== undefined)
      ? ' · ' + _data.effects + ' {{effets}}, ' + _data.palettes + ' {{palettes}}, ' + _data.presets + ' {{presets}}'
      : ''
    if (_data.fetchedAt === '') {
      state.className = 'alert alert-warning'
      state.textContent = '{{Aucun relevé pour le moment.}}'
    } else if (_data.online) {
      state.className = 'alert alert-success'
      state.textContent = '{{Dernier relevé :}} ' + _data.fetchedAt + lists
    } else {
      state.className = 'alert alert-warning'
      state.textContent = '{{Injoignable :}} ' + _data.failures + ' {{échec(s) consécutif(s)}}'
        + (_data.problem ? ' — ' + _data.problem : '') + ' · {{dernier relevé réussi :}} ' + (_data.fetchedAt || '{{jamais}}')
    }
  }
  var raw = wledbeEl('pre_wledbeRaw')
  if (raw !== null) {
    raw.textContent = (isset(_data) && _data.raw) ? JSON.stringify(_data.raw, null, 2) : ''
  }
}

function wledbeRefresh() {
  var id = wledbeCurrentId()
  if (id === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez l\'équipement avant de le relever.}}', level: 'warning' })
    return
  }
  wledbeStatus('{{Lecture en cours…}}', 'default')
  wledbeAjax('refresh', { id: id }, function (result) {
    wledbeStatus('{{Relevé effectué.}}', 'success')
    wledbeRender(result.result)
  }, function (error) {
    wledbeStatus((error && error.result) ? error.result : '{{Échec du relevé}}', 'danger')
  })
}

function wledbeLists() {
  var id = wledbeCurrentId()
  if (id === '') { return }
  wledbeStatus('{{Lecture des listes…}}', 'default')
  wledbeAjax('lists', { id: id }, function (result) {
    var c = result.result.counts
    wledbeStatus(c.effects + ' {{effets}}, ' + c.palettes + ' {{palettes}}, ' + c.presets + ' {{presets}}', 'success')
    wledbeRender(result.result)
  }, function (error) {
    wledbeStatus((error && error.result) ? error.result : '{{Échec de la lecture}}', 'danger')
  })
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  wledbeStatus('', '')
  wledbeRender({ fetchedAt: '', raw: null })

  var conf = isset(_eqLogic.configuration) ? _eqLogic.configuration : {}
  var layout = wledbeEl('span_wledbeLayout')
  if (layout !== null) {
    layout.textContent = (conf.layout === 'matrix')
      ? '{{Matrice}} ' + (conf.matrix_w || '?') + '×' + (conf.matrix_h || '?')
      : '{{Bande}}'
  }
  var rgbw = wledbeEl('span_wledbeRgbw')
  if (rgbw !== null) { rgbw.style.display = (conf.rgbw == 1) ? '' : 'none' }
  var open = wledbeEl('bt_wledbeOpen')
  if (open !== null) {
    open.style.display = conf.ip ? '' : 'none'
    open.setAttribute('href', conf.ip ? 'http://' + conf.ip + '/' : '#')
  }

  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    wledbeAjax('data', { id: _eqLogic.id }, function (result) {
      wledbeRender(result.result)
    })
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : DOMContentLoaded a déjà eu lieu, et ce script est
   réexécuté à chaque visite de la page. Un seul écouteur, posé une fois sur le
   document, qui résout les fonctions au moment du clic : elles sont
   redéfinies à chaque chargement. */
if (!window.wledbeListening) {
  window.wledbeListening = true
  document.addEventListener('click', function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_wledbeDiscover: wledbeDiscover,
      bt_wledbeAddIp: wledbeAddIp,
      bt_wledbeRefresh: wledbeRefresh,
      bt_wledbeLists: wledbeLists
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
  })
}
