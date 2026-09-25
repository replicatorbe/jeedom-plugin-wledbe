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
  wledbeRenderStack(_data)
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

/* ================================================================= SCÈNES */

/* Valeur d'attribut HTML : l'échappement de wledbeEscape ne couvre pas les
   guillemets, qui fermeraient l'attribut. */
function wledbeAttr(_text) {
  return String((_text === null || _text === undefined) ? '' : _text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

var wledbeScenesData = []
var wledbeScenesLoaded = false

function wledbeScenesToggle() {
  var div = wledbeEl('div_wledbeScenes')
  if (div === null) { return }
  if (div.style.display !== 'none') {
    div.style.display = 'none'
    return
  }
  div.style.display = ''
  wledbeScenesLoad()
}

function wledbeScenesLoad() {
  wledbeAjax('scenes', {}, function (result) {
    var r = result.result
    wledbeScenesData = r.scenes
    var fill = function (_id, _items) {
      var dl = wledbeEl(_id)
      if (dl === null) { return }
      dl.innerHTML = ''
      _items.forEach(function (_name) {
        var opt = document.createElement('option')
        opt.value = _name
        dl.appendChild(opt)
      })
    }
    fill('dl_wledbeEffects', r.effects)
    fill('dl_wledbePalettes', r.palettes)
    var sel = wledbeEl('sel_wledbeTestDevice')
    if (sel !== null) {
      sel.innerHTML = ''
      r.devices.forEach(function (_d) {
        var opt = document.createElement('option')
        opt.value = _d.id
        opt.textContent = _d.name + (_d.matrix ? ' ({{matrice}})' : '')
        sel.appendChild(opt)
      })
    }
    wledbeScenesLoaded = true
    wledbeScenesRender()
  })
}

function wledbeRecipeHtml(_kind, _recipe) {
  var r = _recipe || {}
  var colors = r.colors || ['#ffffff', '#000000', '#000000']
  var f = function (_field, _label, _input) {
    return '<div class="form-group"><label class="col-sm-4 control-label">' + _label + '</label><div class="col-sm-8">' + _input + '</div></div>'
  }
  var attr = ' data-recipe="' + _kind + '"'
  var html = ''
  if (_kind === 'matrix') {
    html += f('enabled', '{{Recette propre}}', '<label class="checkbox-inline"><input type="checkbox"' + attr + ' data-field="enabled"' + (r.enabled ? ' checked' : '') + '>{{sinon la recette bande est jouée}}</label>')
  }
  html += f('effect', '{{Effet}}', '<input class="form-control input-sm"' + attr + ' data-field="effect" list="dl_wledbeEffects" value="' + wledbeAttr(r.effect) + '">')
  var cols = ''
  for (var i = 0; i < 3; i++) {
    cols += '<input type="color"' + attr + ' data-field="colors" data-slot="' + i + '" value="' + wledbeAttr(colors[i] || '#000000') + '" style="width:48px;height:28px;margin-right:4px;" title="{{Couleur}} ' + (i + 1) + '">'
  }
  html += f('colors', '{{Couleurs}}', cols)
  html += f('palette', '{{Palette}}', '<input class="form-control input-sm"' + attr + ' data-field="palette" list="dl_wledbePalettes" placeholder="{{celle en cours}}" value="' + wledbeAttr(r.palette) + '">')
  html += f('brightness', '{{Luminosité}} %', '<input type="number" min="1" max="100" class="form-control input-sm"' + attr + ' data-field="brightness" value="' + wledbeAttr(r.brightness) + '">')
  html += f('speed', '{{Vitesse}}', '<input type="number" min="0" max="255" class="form-control input-sm"' + attr + ' data-field="speed" value="' + wledbeAttr(r.speed) + '">')
  html += f('intensity', '{{Intensité}}', '<input type="number" min="0" max="255" class="form-control input-sm"' + attr + ' data-field="intensity" value="' + wledbeAttr(r.intensity) + '">')
  if (_kind === 'matrix') {
    html += f('text', '{{Texte défilant}}', '<input class="form-control input-sm"' + attr + ' data-field="text" maxlength="64" placeholder="{{avec l\'effet Scrolling Text}}" value="' + wledbeAttr(r.text) + '">')
  }
  html += f('json', '{{JSON avancé}}', '<input class="form-control input-sm"' + attr + ' data-field="json" placeholder=\'{"seg":{"c1":200}}\' value="' + wledbeAttr(r.json) + '">')
  return html
}

function wledbeScenesRender() {
  var list = wledbeEl('div_wledbeSceneList')
  if (list === null) { return }
  var html = ''
  wledbeScenesData.forEach(function (_s, _i) {
    html += '<div class="panel panel-default wledbeScene" data-index="' + _i + '" data-id="' + wledbeAttr(_s.id) + '" style="margin:5px;">'
    html += '<div class="panel-heading form-inline">'
    html += '<input class="form-control input-sm" data-field="name" value="' + wledbeAttr(_s.name) + '" style="width:220px;font-weight:bold;"> '
    html += '<label style="margin-left:10px;">{{Priorité}}</label> <input type="number" min="0" max="100" class="form-control input-sm" data-field="priority" value="' + wledbeAttr(_s.priority) + '" style="width:70px;"> '
    html += '<label style="margin-left:10px;">{{Durée}}</label> <input type="number" min="0" max="86400" class="form-control input-sm" data-field="duration" value="' + wledbeAttr(_s.duration) + '" style="width:80px;"> s '
    html += '<label style="margin-left:10px;">{{Ensuite}}</label> <select class="form-control input-sm" data-field="end">'
    ;[['restore', '{{rendre l\'éclairage}}'], ['off', '{{éteindre}}'], ['keep', '{{laisser la scène}}']].forEach(function (_o) {
      html += '<option value="' + _o[0] + '"' + (_s.end === _o[0] ? ' selected' : '') + '>' + _o[1] + '</option>'
    })
    html += '</select> '
    html += '<label class="checkbox-inline" style="margin-left:10px;"><input type="checkbox" data-field="guard"' + (_s.guard ? ' checked' : '') + '>{{sous garde}}</label>'
    html += '<span class="pull-right">'
    html += '<a class="btn btn-primary btn-xs wledbeSceneTest"><i class="fas fa-play"></i> {{Essayer}}</a> '
    html += '<a class="btn btn-default btn-xs wledbeSceneToggle"><i class="fas fa-sliders-h"></i> {{Recettes}}</a> '
    html += '<a class="btn btn-danger btn-xs wledbeSceneRemove"><i class="fas fa-trash"></i></a>'
    html += '</span>'
    html += '<div class="help-block" style="margin:4px 0 0 0;"><small>{{Durée 0 : sans fin, jusqu\'à « Arrêter ». Identifiant :}} ' + wledbeEscape(_s.id) + '</small></div>'
    html += '</div>'
    html += '<div class="panel-body wledbeSceneBody" style="display:none;">'
    html += '<div class="col-md-6"><form class="form-horizontal"><legend><i class="fas fa-grip-lines"></i> {{Bande}}</legend>' + wledbeRecipeHtml('strip', _s.strip) + '</form></div>'
    html += '<div class="col-md-6"><form class="form-horizontal"><legend><i class="fas fa-th"></i> {{Matrice}}</legend>' + wledbeRecipeHtml('matrix', _s.matrix) + '</form></div>'
    html += '</div></div>'
  })
  list.innerHTML = html
}

/* Relit la bibliothèque dans le DOM : c'est lui qui porte les saisies. */
function wledbeScenesCollect() {
  var scenes = []
  document.querySelectorAll('#div_wledbeSceneList .wledbeScene').forEach(function (_card) {
    var scene = { id: _card.getAttribute('data-id'), strip: { colors: [] }, matrix: { colors: [] } }
    _card.querySelectorAll('[data-field]').forEach(function (_el) {
      var field = _el.getAttribute('data-field')
      var recipe = _el.getAttribute('data-recipe')
      var value = (_el.type === 'checkbox') ? (_el.checked ? 1 : 0) : _el.value
      var target = recipe ? scene[recipe] : scene
      if (field === 'colors') {
        target.colors[parseInt(_el.getAttribute('data-slot'), 10)] = value
      } else {
        target[field] = value
      }
    })
    scenes.push(scene)
  })
  return scenes
}

function wledbeScenesSave(_then) {
  wledbeAjax('saveScenes', { scenes: JSON.stringify(wledbeScenesCollect()) }, function (result) {
    wledbeScenesData = result.result
    wledbeScenesRender()
    if (typeof _then === 'function') {
      _then()
    } else {
      jeedomUtils.showAlert({ message: '{{Scènes enregistrées. Les commandes « Scène … » des équipements sont à jour.}}', level: 'success' })
    }
  })
}

function wledbeSceneAdd() {
  wledbeScenesData = wledbeScenesCollect()
  wledbeScenesData.push({
    id: '', name: '{{Nouvelle scène}} ' + (wledbeScenesData.length + 1), priority: 50, duration: 30, end: 'restore', guard: 0,
    strip: { effect: 'Solid', colors: ['#ffffff', '#000000', '#000000'], palette: '', brightness: 100, speed: 128, intensity: 128, json: '' },
    matrix: { enabled: 0, effect: 'Scrolling Text', colors: ['#ffffff', '#000000', '#000000'], palette: '', brightness: 100, speed: 128, intensity: 128, text: '', json: '' }
  })
  wledbeScenesRender()
  var cards = document.querySelectorAll('#div_wledbeSceneList .wledbeScene')
  var last = cards[cards.length - 1]
  if (last) {
    last.querySelector('.wledbeSceneBody').style.display = ''
    last.querySelector('[data-field="name"]').focus()
  }
}

function wledbeScenesReset() {
  bootbox.confirm('{{Remplacer toutes les scènes par celles livrées avec le plugin ? Les commandes des scènes supprimées disparaîtront des équipements.}}', function (_ok) {
    if (!_ok) { return }
    wledbeAjax('resetScenes', {}, function (result) {
      wledbeScenesData = result.result
      wledbeScenesRender()
    })
  })
}

/* Essayer enregistre d'abord : on essaie ce qu'on voit à l'écran. */
function wledbeSceneTest(_card) {
  var eq = wledbeEl('sel_wledbeTestDevice').value
  if (!eq) {
    jeedomUtils.showAlert({ message: '{{Aucun WLED pour l\'essai.}}', level: 'warning' })
    return
  }
  var index = parseInt(_card.getAttribute('data-index'), 10)
  wledbeScenesSave(function () {
    var scene = wledbeScenesData[index]
    wledbeAjax('testScene', { id: eq, scene: scene.id, duration: wledbeEl('in_wledbeTestDuration').value }, function () {
      jeedomUtils.showAlert({ message: '{{Scène lancée :}} ' + scene.name, level: 'success' })
    })
  })
}

function wledbeTestStop() {
  var eq = wledbeEl('sel_wledbeTestDevice').value
  if (!eq) { return }
  wledbeAjax('stopScenes', { id: eq }, function () {
    jeedomUtils.showAlert({ message: '{{Scènes arrêtées, éclairage rendu.}}', level: 'success' })
  })
}

function wledbeSceneClick(_target) {
  var card = _target.closest('.wledbeScene')
  if (card === null) { return false }
  if (_target.closest('.wledbeSceneToggle') !== null) {
    var body = card.querySelector('.wledbeSceneBody')
    body.style.display = (body.style.display === 'none') ? '' : 'none'
    return true
  }
  if (_target.closest('.wledbeSceneRemove') !== null) {
    wledbeScenesData = wledbeScenesCollect()
    wledbeScenesData.splice(parseInt(card.getAttribute('data-index'), 10), 1)
    wledbeScenesRender()
    jeedomUtils.showAlert({ message: '{{Scène retirée. Enregistrez pour confirmer.}}', level: 'warning' })
    return true
  }
  if (_target.closest('.wledbeSceneTest') !== null) {
    wledbeSceneTest(card)
    return true
  }
  return false
}

function wledbeRenderStack(_data) {
  var div = wledbeEl('div_wledbeStack')
  if (div === null) { return }
  var stack = (isset(_data) && _data.stack) ? _data.stack : []
  if (stack.length === 0) {
    div.innerHTML = '<p class="text-muted">{{Aucune scène en cours.}}</p>'
    return
  }
  var html = '<table class="table table-condensed"><thead><tr><th>{{Scène}}</th><th>{{Priorité}}</th><th>{{État}}</th><th>{{Fin}}</th></tr></thead><tbody>'
  stack.forEach(function (_e) {
    var shown = (_e.key === _data.applied)
    var state = !_e.active ? '{{programmée à}} ' + new Date(_e.start_at * 1000).toLocaleTimeString()
      : (shown ? '<span class="label label-success">{{affichée}}</span>' : '<span class="label label-default">{{recouverte}}</span>')
    html += '<tr><td>' + wledbeEscape(_e.name) + (_e.guard ? ' <i class="fas fa-shield-alt" title="{{sous garde}}"></i>' : '') + '</td>'
    html += '<td>' + wledbeEscape(_e.priority) + '</td><td>' + state + '</td>'
    html += '<td>' + (_e.until > 0 ? new Date(_e.until * 1000).toLocaleTimeString() : (_e.active ? '{{sans fin}}' : '')) + '</td></tr>'
  })
  div.innerHTML = html + '</tbody></table>'
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
      bt_wledbeLists: wledbeLists,
      bt_wledbeScenes: wledbeScenesToggle,
      bt_wledbeScenesSave: wledbeScenesSave,
      bt_wledbeSceneAdd: wledbeSceneAdd,
      bt_wledbeScenesReset: wledbeScenesReset,
      bt_wledbeTestStop: wledbeTestStop
    }
    if (wledbeSceneClick(target)) {
      _event.preventDefault()
      return
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
