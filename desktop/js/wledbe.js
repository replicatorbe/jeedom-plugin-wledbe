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

/* Ce qui vient d'un appareil ou du serveur est du texte, jamais du
   balisage. */
function wledbeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

/* Valeur d'attribut HTML : l'échappement de wledbeEscape ne couvre pas les
   guillemets, qui fermeraient l'attribut. */
function wledbeAttr(_text) {
  return String((_text === null || _text === undefined) ? '' : _text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

/* Les fenêtres du coeur : jeeDialog depuis Jeedom 4.4, bootbox sinon (il
   n'est chargé qu'avec jQuery). */
function wledbeConfirm(_title, _message, _callback) {
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.confirm({ title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } })
  } else {
    bootbox.confirm({ title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } })
  }
}

function wledbePrompt(_title, _value, _placeholder, _callback) {
  var options = { title: _title, value: _value, placeholder: _placeholder, callback: function (_v) { if (_v !== null) { _callback(_v) } } }
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.prompt(options)
  } else {
    bootbox.prompt(options)
  }
}

function wledbeAlert(_title, _message, _callback) {
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.alert({ title: _title, message: _message, callback: _callback })
  } else {
    bootbox.alert({ title: _title, message: _message, callback: _callback })
  }
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
        /* Un message d'erreur peut citer un nom de scène ou une valeur relue
           sur un appareil : échappé, car l'alerte l'affiche en HTML. */
        jeedomUtils.showAlert({ message: wledbeEscape(result.result), level: 'danger' })
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

function wledbeText(_id, _text) {
  var el = wledbeEl(_id)
  if (el !== null) { el.textContent = (_text === null || _text === undefined) ? '' : String(_text) }
}

/* ============================================================== DÉCOUVERTE */

function wledbeDiscover() {
  var last = ''
  try { last = window.localStorage.getItem('wledbe:subnet') || '' } catch (e) { last = '' }

  wledbePrompt('{{Sous-réseau à parcourir, en /24. Laisser vide pour celui de Jeedom.}}', last, '192.168.0.0/24', function (_subnet) {
    var subnet = String(_subnet).trim()
    try { window.localStorage.setItem('wledbe:subnet', subnet) } catch (e) { /* mode privé */ }
    /* La recherche dure une dizaine de secondes : un voile d'attente plutôt
       qu'un message qui disparaîtrait avant la fin. */
    domUtils.showLoading()
    wledbeAjax('discover', { subnet: subnet }, function (result) {
      domUtils.hideLoading()
      wledbeShowFound(result.result)
    }, function (error) {
      domUtils.hideLoading()
      jeedomUtils.showAlert({ message: wledbeEscape((error && error.result) ? error.result : '{{Échec de la recherche}}'), level: 'danger' })
    })
  })
}

function wledbeAddIp() {
  wledbePrompt('{{Adresse IP du WLED}}', '', '192.168.0.150', function (_ip) {
    var ip = String(_ip).trim()
    if (ip === '') { return }
    wledbeAjax('probe', { ip: ip }, function (result) {
      wledbeShowFound(result.result)
    })
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
    html += '<p class="text-muted"><small>{{Le mDNS n\'est pas disponible sur cette machine (avahi absent ou arrêté) : seul le balayage HTTP a été utilisé.}}</small></p>'
  }
  for (var i = 0; i < devices.length; i++) {
    var d = devices[i]
    html += '<div class="checkbox"><label>'
    html += '<input type="checkbox" class="wledbeFound" data-index="' + i + '"' + (d.known && d.known_ip === d.ip ? '' : ' checked') + '> '
    html += '<b>' + wledbeEscape(d.name || d.mac) + '</b>'
    html += ' — ' + wledbeEscape(d.ip)
    html += ' <span class="label label-info">' + (d.layout === 'matrix' ? '{{matrice}} ' + wledbeEscape(d.matrix_w) + '×' + wledbeEscape(d.matrix_h) : '{{bande}}') + '</span>'
    html += ' <small>' + wledbeEscape(d.leds) + ' {{LED}} · WLED ' + wledbeEscape(d.version) + ' · ' + wledbeEscape(d.source) + '</small>'
    if (d.known) {
      html += ' <span class="label label-default">{{déjà créé :}} ' + wledbeEscape(d.known) + '</span>'
      if (d.known_ip !== d.ip) { html += ' <span class="label label-warning">{{nouvelle adresse}}</span>' }
    }
    html += '</label></div>'
  }
  wledbeConfirm('{{WLED trouvés}}', html, function () {
    var chosen = []
    document.querySelectorAll('.wledbeFound').forEach(function (_box) {
      if (_box.checked) { chosen.push({ ip: devices[parseInt(_box.getAttribute('data-index'), 10)].ip }) }
    })
    if (chosen.length === 0) { return }
    /* Chaque appareil est interrogé, créé et relevé : quelques secondes. */
    domUtils.showLoading()
    wledbeAjax('create', { devices: JSON.stringify(chosen) }, function (result) {
      domUtils.hideLoading()
      var r = result.result
      if (r.errors && r.errors.length > 0) {
        var list = r.errors.map(function (_e) { return '<li>' + wledbeEscape(_e) + '</li>' }).join('')
        wledbeAlert('{{Création incomplète}}', '<p>' + r.created + ' {{appareil(s) créé(s). Échecs :}}</p><ul>' + list + '</ul>', function () { wledbeReload() })
        return
      }
      wledbeReload()
    }, function (error) {
      domUtils.hideLoading()
      jeedomUtils.showAlert({ message: wledbeEscape((error && error.result) ? error.result : '{{Échec de la création}}'), level: 'danger' })
    })
  })
}

/* ================================================================= GROUPES */

function wledbeAddGroup() {
  wledbePrompt('{{Nom du nouveau groupe}}', '', '{{Toute la maison}}', function (_name) {
    var name = String(_name).trim()
    if (name === '') { return }
    wledbeAjax('createGroup', { name: name }, function (result) {
      wledbeReload(result.result.id)
    })
  })
}

/* Les cases des membres et le champ caché qui part à l'enregistrement. */
function wledbeMembersToInput() {
  var ids = []
  document.querySelectorAll('.wledbeMember').forEach(function (_box) {
    if (_box.checked) { ids.push(_box.getAttribute('data-id')) }
  })
  var input = wledbeEl('in_wledbeMembers')
  if (input !== null) { input.value = ids.join(',') }
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
}

/* Coche les membres enregistrés et remplit le champ caché : le coeur vide
   tous les champs avant de les remplir, et ne sait pas mettre une liste dans
   un champ texte. Sans cette écriture, enregistrer le groupe sans toucher aux
   cases enverrait une liste vide et effacerait ses membres. */
function wledbeMembersFromConf(_members) {
  var ids = {}
  var list = Array.isArray(_members) ? _members : String(_members || '').split(',')
  var kept = []
  list.forEach(function (_id) {
    var id = String(_id).trim()
    if (id !== '') {
      ids[id] = true
      kept.push(id)
    }
  })
  document.querySelectorAll('.wledbeMember').forEach(function (_box) {
    _box.checked = ids[_box.getAttribute('data-id')] === true
  })
  var input = wledbeEl('in_wledbeMembers')
  if (input !== null) { input.value = kept.join(',') }
}

function wledbeRenderMembers(_data) {
  var div = wledbeEl('div_wledbeMembers')
  if (div === null) { return }
  if (!isset(_data) || !_data.group) {
    div.innerHTML = ''
    return
  }
  var state = wledbeEl('div_wledbeState')
  if (state !== null) {
    state.className = 'alert alert-info'
    state.textContent = _data.members.length + ' {{membre(s) actif(s)}}'
  }
  var all = (_data.members || []).concat(_data.inactive || [])
  if (all.length === 0) {
    div.innerHTML = '<p class="text-muted">{{Aucun membre : cochez des WLED dans l\'onglet Équipement, puis enregistrez.}}</p>'
    return
  }
  var html = '<table class="table table-condensed"><thead><tr><th>{{Membre}}</th><th>{{En ligne}}</th><th>{{Allumé}}</th></tr></thead><tbody>'
  _data.members.forEach(function (_m) {
    html += '<tr><td>' + wledbeEscape(_m.name) + (_m.matrix ? ' <span class="label label-info">{{matrice}}</span>' : '') + '</td>'
    html += '<td>' + (_m.online ? '<span class="label label-success">{{oui}}</span>' : '<span class="label label-danger">{{non}}</span>') + '</td>'
    html += '<td>' + (_m.on ? '{{oui}}' : '{{non}}') + '</td></tr>'
  })
  ;(_data.inactive || []).forEach(function (_m) {
    html += '<tr class="text-muted"><td>' + wledbeEscape(_m.name) + '</td><td colspan="2"><span class="label label-default">' + wledbeEscape(_m.reason) + '</span> {{ignoré par le groupe}}</td></tr>'
  })
  div.innerHTML = html + '</tbody></table>'
}

/* ============================================================== DIAGNOSTIC */

function wledbeRender(_data) {
  if (isset(_data) && _data.group) {
    wledbeRenderMembers(_data)
    return
  }
  wledbeRenderMembers(null)
  var state = wledbeEl('div_wledbeState')
  if (state !== null && isset(_data)) {
    var lists = (_data.effects !== undefined)
      ? ' · ' + _data.effects + ' {{effets}}, ' + _data.palettes + ' {{palettes}}, ' + _data.presets + ' {{presets}}'
      : ''
    if (_data.loading) {
      state.className = 'alert alert-info'
      state.textContent = '{{Chargement…}}'
    } else if (_data.fetchedAt === '') {
      state.className = 'alert alert-warning'
      state.textContent = '{{Aucun relevé pour le moment.}}'
    } else if (_data.online) {
      state.className = 'alert alert-success'
      state.textContent = '{{Dernier relevé :}} ' + _data.fetchedAt + lists
        + ' · ' + (_data.live ? '{{connexion directe : état instantané}}' : '{{pas de connexion directe : état relu chaque minute}}')
    } else {
      state.className = 'alert alert-warning'
      state.textContent = '{{Injoignable :}} ' + _data.failures + ' {{échec(s) consécutif(s)}}'
        + (_data.problem ? ' — ' + _data.problem : '') + ' · {{dernier relevé réussi :}} ' + (_data.fetchedAt || '{{jamais}}')
    }
  }
  wledbeRenderStack(_data)
  var raw = wledbeEl('pre_wledbeRaw')
  if (raw !== null) {
    var empty = !isset(_data) || !_data.raw || (Array.isArray(_data.raw) && _data.raw.length === 0)
    raw.textContent = empty ? '' : JSON.stringify(_data.raw, null, 2)
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

var wledbeScenesData = []
var wledbeScenesLoaded = false
var wledbeScenesDirty = false

/* Scènes modifiées et pas enregistrées : le coeur demande confirmation avant
   de quitter la page, comme pour un équipement. */
function wledbeSetDirty(_dirty) {
  wledbeScenesDirty = _dirty
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = _dirty }
}

/* Chargée une fois : replier puis rouvrir le panneau ne doit pas effacer
   les saisies en cours. */
function wledbeScenesToggle() {
  var div = wledbeEl('div_wledbeScenes')
  if (div === null) { return }
  if (div.style.display !== 'none') {
    div.style.display = 'none'
    return
  }
  div.style.display = ''
  if (!wledbeScenesLoaded) { wledbeScenesLoad() }
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
      if (r.devices.length === 0) {
        var none = document.createElement('option')
        none.value = ''
        none.textContent = '{{aucun WLED actif}}'
        sel.appendChild(none)
      }
    }
    wledbeScenesLoaded = true
    wledbeSetDirty(false)
    wledbeScenesRender()
  })
}

function wledbeRecipeHtml(_kind, _recipe) {
  var r = _recipe || {}
  var colors = r.colors || ['#ffffff', '#000000', '#000000']
  var f = function (_label, _input) {
    return '<div class="form-group"><label class="col-sm-4 control-label">' + _label + '</label><div class="col-sm-8">' + _input + '</div></div>'
  }
  var attr = ' data-recipe="' + _kind + '"'
  var html = ''
  if (_kind === 'matrix') {
    html += f('{{Recette propre}}', '<label class="checkbox-inline"><input type="checkbox"' + attr + ' data-field="enabled"' + (r.enabled ? ' checked' : '') + '>{{sinon la recette bande est jouée}}</label>')
  }
  html += f('{{Effet}}', '<input class="form-control input-sm"' + attr + ' data-field="effect" list="dl_wledbeEffects" value="' + wledbeAttr(r.effect) + '">')
  var cols = ''
  for (var i = 0; i < 3; i++) {
    cols += '<input type="color"' + attr + ' data-field="colors" data-slot="' + i + '" value="' + wledbeAttr(colors[i] || '#000000') + '" style="width:48px;height:28px;margin-right:4px;" title="{{Couleur}} ' + (i + 1) + '">'
  }
  html += f('{{Couleurs}}', cols)
  html += f('{{Palette}}', '<input class="form-control input-sm"' + attr + ' data-field="palette" list="dl_wledbePalettes" placeholder="{{celle en cours}}" value="' + wledbeAttr(r.palette) + '">')
  html += f('{{Luminosité}} %', '<input type="number" min="1" max="100" class="form-control input-sm"' + attr + ' data-field="brightness" value="' + wledbeAttr(r.brightness) + '">')
  html += f('{{Vitesse}}', '<input type="number" min="0" max="255" class="form-control input-sm"' + attr + ' data-field="speed" value="' + wledbeAttr(r.speed) + '">')
  html += f('{{Intensité}}', '<input type="number" min="0" max="255" class="form-control input-sm"' + attr + ' data-field="intensity" value="' + wledbeAttr(r.intensity) + '">')
  if (_kind === 'matrix') {
    html += f('{{Texte défilant}}', '<input class="form-control input-sm"' + attr + ' data-field="text" maxlength="32" placeholder="{{avec l\'effet Scrolling Text}}" value="' + wledbeAttr(r.text) + '">'
      + '<span class="help-block" style="margin:2px 0 0 0;"><small>{{32 caractères au plus, moins avec des accents : c\'est la limite de WLED.}}</small></span>')
  }
  html += f('{{JSON avancé}}', '<input class="form-control input-sm"' + attr + ' data-field="json" placeholder=\'{"seg":{"c1":200}}\' value="' + wledbeAttr(r.json) + '">')
  return html
}

/* Cartes ouvertes, par identifiant de scène (ou rang pour une scène pas
   encore enregistrée) : un nouveau rendu ne doit pas replier la recette qu'on
   est en train de régler. */
function wledbeOpenCards() {
  var open = {}
  document.querySelectorAll('#div_wledbeSceneList .wledbeScene').forEach(function (_card) {
    if (_card.querySelector('.wledbeSceneBody').style.display !== 'none') {
      open[_card.getAttribute('data-id') || ('#' + _card.getAttribute('data-index'))] = true
    }
  })
  return open
}

function wledbeScenesRender(_open) {
  var list = wledbeEl('div_wledbeSceneList')
  if (list === null) { return }
  var open = _open || wledbeOpenCards()
  if (wledbeScenesData.length === 0) {
    list.innerHTML = '<div class="alert alert-warning" style="margin:5px;">{{Aucune scène. « Nouvelle scène » en crée une, « Scènes d\'origine » remet celles livrées avec le plugin.}}</div>'
    return
  }
  var html = ''
  wledbeScenesData.forEach(function (_s, _i) {
    var isOpen = open[_s.id || ('#' + _i)] === true
    html += '<div class="panel panel-default wledbeScene" data-index="' + _i + '" data-id="' + wledbeAttr(_s.id) + '" style="margin:5px;">'
    html += '<div class="panel-heading form-inline">'
    html += '<input class="form-control input-sm" data-field="name" maxlength="48" value="' + wledbeAttr(_s.name) + '" style="width:220px;font-weight:bold;"> '
    html += '<label style="margin-left:10px;">{{Priorité}}</label> <input type="number" min="0" max="100" class="form-control input-sm" data-field="priority" value="' + wledbeAttr(_s.priority) + '" style="width:70px;"> '
    html += '<label style="margin-left:10px;">{{Durée}}</label> <input type="number" min="0" max="86400" class="form-control input-sm" data-field="duration" value="' + wledbeAttr(_s.duration) + '" style="width:80px;"> s '
    html += '<label style="margin-left:10px;">{{Ensuite}}</label> <select class="form-control input-sm" data-field="end">'
    ;[['restore', '{{rendre l\'éclairage}}'], ['off', '{{éteindre}}'], ['keep', '{{laisser la scène}}']].forEach(function (_o) {
      html += '<option value="' + _o[0] + '"' + (_s.end === _o[0] ? ' selected' : '') + '>' + _o[1] + '</option>'
    })
    html += '</select> '
    html += '<label class="checkbox-inline" style="margin-left:10px;"><input type="checkbox" data-field="guard"' + (_s.guard ? ' checked' : '') + '>{{sous garde}}</label>'
    html += '<span class="pull-right">'
    html += '<a class="btn btn-primary btn-xs wledbeSceneTest" title="{{Joue la scène telle qu\'elle est à l\'écran, sans l\'enregistrer}}"><i class="fas fa-play"></i> {{Essayer}}</a> '
    html += '<a class="btn btn-default btn-xs wledbeSceneToggle"><i class="fas fa-sliders-h"></i> {{Recettes}}</a> '
    html += '<a class="btn btn-danger btn-xs wledbeSceneRemove" title="{{Retirer la scène}}"><i class="fas fa-trash"></i></a>'
    html += '</span>'
    html += '<div class="help-block" style="margin:4px 0 0 0;"><small>{{Durée 0 : sans fin, jusqu\'à « Arrêter la scène en cours » ou « Arrêter toutes les scènes ». Identifiant :}} '
      + (_s.id ? wledbeEscape(_s.id) : '{{attribué à l\'enregistrement}}') + '</small></div>'
    html += '</div>'
    html += '<div class="panel-body wledbeSceneBody" style="' + (isOpen ? '' : 'display:none;') + '">'
    html += '<div class="col-md-6"><form class="form-horizontal"><legend><i class="fas fa-grip-lines"></i> {{Bande}}</legend>' + wledbeRecipeHtml('strip', _s.strip) + '</form></div>'
    html += '<div class="col-md-6"><form class="form-horizontal"><legend><i class="fas fa-th"></i> {{Matrice}}</legend>' + wledbeRecipeHtml('matrix', _s.matrix) + '</form></div>'
    html += '</div></div>'
  })
  list.innerHTML = html
}

/* Relit la bibliothèque dans le DOM : c'est lui qui porte les saisies. Un
   champ numérique vidé n'est pas envoyé : le serveur garde alors la valeur
   par défaut, au lieu de lire zéro. */
function wledbeCollectCard(_card) {
  var scene = { id: _card.getAttribute('data-id'), strip: { colors: [] }, matrix: { colors: [] } }
  _card.querySelectorAll('[data-field]').forEach(function (_el) {
    var field = _el.getAttribute('data-field')
    var recipe = _el.getAttribute('data-recipe')
    var target = recipe ? scene[recipe] : scene
    if (_el.type === 'number' && String(_el.value).trim() === '') { return }
    var value = (_el.type === 'checkbox') ? (_el.checked ? 1 : 0) : _el.value
    if (field === 'colors') {
      target.colors[parseInt(_el.getAttribute('data-slot'), 10)] = value
    } else {
      target[field] = value
    }
  })
  return scene
}

function wledbeScenesCollect() {
  var scenes = []
  document.querySelectorAll('#div_wledbeSceneList .wledbeScene').forEach(function (_card) {
    scenes.push(wledbeCollectCard(_card))
  })
  return scenes
}

function wledbeScenesSave() {
  var open = wledbeOpenCards()
  wledbeAjax('saveScenes', { scenes: JSON.stringify(wledbeScenesCollect()) }, function (result) {
    wledbeScenesData = result.result
    wledbeSetDirty(false)
    wledbeScenesRender(open)
    jeedomUtils.showAlert({ message: '{{Scènes enregistrées. Les commandes « Scène … » des équipements sont à jour.}}', level: 'success' })
  })
}

function wledbeSceneAdd() {
  var open = wledbeOpenCards()
  wledbeScenesData = wledbeScenesCollect()
  /* Le premier nom libre : deux scènes ne peuvent pas porter le même. */
  var taken = {}
  wledbeScenesData.forEach(function (_s) { taken[String(_s.name).toLowerCase()] = true })
  var n = 1
  while (taken[('{{Nouvelle scène}} ' + n).toLowerCase()]) { n++ }
  wledbeScenesData.push({
    id: '', name: '{{Nouvelle scène}} ' + n, priority: 50, duration: 30, end: 'restore', guard: 0,
    strip: { effect: 'Solid', colors: ['#ffffff', '#000000', '#000000'], palette: '', brightness: 100, speed: 128, intensity: 128, json: '' },
    matrix: { enabled: 0, effect: 'Scrolling Text', colors: ['#ffffff', '#000000', '#000000'], palette: '', brightness: 100, speed: 128, intensity: 128, text: '', json: '' }
  })
  open['#' + (wledbeScenesData.length - 1)] = true
  wledbeSetDirty(true)
  wledbeScenesRender(open)
  var cards = document.querySelectorAll('#div_wledbeSceneList .wledbeScene')
  var last = cards[cards.length - 1]
  if (last) { last.querySelector('[data-field="name"]').focus() }
}

function wledbeScenesReset() {
  wledbeConfirm('{{Scènes d\'origine}}', '{{Remplacer toutes les scènes par celles livrées avec le plugin ? Les modifications non enregistrées sont perdues, et les commandes des scènes supprimées disparaîtront des équipements.}}', function () {
    wledbeAjax('resetScenes', {}, function (result) {
      wledbeScenesData = result.result
      wledbeSetDirty(false)
      wledbeScenesRender({})
    })
  })
}

/* Essayer joue la scène telle qu'elle est à l'écran, sans rien
   enregistrer : ni les autres modifications en cours, ni une suppression
   pas encore confirmée. */
function wledbeSceneTest(_card) {
  var eq = wledbeEl('sel_wledbeTestDevice').value
  if (!eq) {
    jeedomUtils.showAlert({ message: '{{Aucun WLED actif pour l\'essai.}}', level: 'warning' })
    return
  }
  var scene = wledbeCollectCard(_card)
  wledbeAjax('testScene', { id: eq, scene: JSON.stringify(scene), duration: wledbeEl('in_wledbeTestDuration').value }, function () {
    jeedomUtils.showAlert({ message: '{{Scène lancée :}} ' + wledbeEscape(scene.name), level: 'success' })
  })
}

function wledbeTestStop() {
  var eq = wledbeEl('sel_wledbeTestDevice').value
  if (!eq) {
    jeedomUtils.showAlert({ message: '{{Aucun WLED actif.}}', level: 'warning' })
    return
  }
  wledbeAjax('stopScenes', { id: eq }, function (result) {
    jeedomUtils.showAlert({ message: result.result ? '{{Scènes arrêtées, éclairage rendu.}}' : '{{Aucune scène en cours sur cet appareil.}}', level: 'success' })
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
    var open = wledbeOpenCards()
    wledbeScenesData = wledbeScenesCollect()
    wledbeScenesData.splice(parseInt(card.getAttribute('data-index'), 10), 1)
    wledbeSetDirty(true)
    wledbeScenesRender(open)
    jeedomUtils.showAlert({ message: '{{Scène retirée. Enregistrez pour confirmer ; ses commandes disparaîtront alors des équipements.}}', level: 'warning' })
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
  var html = ''
  if (isset(_data) && _data.pending) {
    html += '<div class="alert alert-warning">{{Restauration de l\'éclairage en attente : l\'appareil ne répondait pas à la fin de la dernière scène. Nouvel essai toutes les 30 secondes.}}</div>'
  }
  if (stack.length === 0) {
    div.innerHTML = html + '<p class="text-muted">{{Aucune scène en cours.}}</p>'
    return
  }
  html += '<table class="table table-condensed"><thead><tr><th>{{Scène}}</th><th>{{Priorité}}</th><th>{{État}}</th><th>{{Fin}}</th></tr></thead><tbody>'
  stack.forEach(function (_e) {
    var shown = (_e.key === _data.applied)
    var state = !_e.active ? '{{programmée à}} ' + new Date(_e.start_at * 1000).toLocaleTimeString()
      : (shown ? '<span class="label label-success">{{affichée}}</span>' : '<span class="label label-default">{{en attente d\'affichage}}</span>')
    html += '<tr><td>' + wledbeEscape(_e.name) + (_e.guard ? ' <i class="fas fa-shield-alt" title="{{sous garde}}"></i>' : '') + (_e.test ? ' <span class="label label-info">{{essai}}</span>' : '') + '</td>'
    html += '<td>' + wledbeEscape(_e.priority) + '</td><td>' + state + '</td>'
    html += '<td>' + (_e.until > 0 ? new Date(_e.until * 1000).toLocaleTimeString() : (_e.active ? '{{sans fin}}' : '')) + '</td></tr>'
  })
  div.innerHTML = html + '</tbody></table>'
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  wledbeStatus('', '')
  wledbeRender({ loading: true, raw: null })

  /* Les textes fournis par l'appareil (nom, version…) sont posés en texte :
     un WLED renommé en balisage ne doit rien exécuter dans la page. */
  var conf = isset(_eqLogic.configuration) ? _eqLogic.configuration : {}
  var group = conf.kind === 'group'
  document.querySelectorAll('.wledbeGroupOnly').forEach(function (_el) { _el.style.display = group ? '' : 'none' })
  document.querySelectorAll('.wledbeDeviceOnly').forEach(function (_el) { _el.style.display = group ? 'none' : '' })
  wledbeMembersFromConf(conf.members)
  wledbeText('span_wledbeDeviceName', conf.device_name)
  wledbeText('span_wledbeLeds', conf.leds ? conf.leds + ' {{LED}}' : '')
  wledbeText('span_wledbeMac', conf.mac)
  wledbeText('span_wledbeVersion', conf.version)
  wledbeText('span_wledbeArch', conf.arch)
  wledbeText('span_wledbeLayout', (conf.layout === 'matrix')
    ? '{{Matrice}} ' + (conf.matrix_w || '?') + '×' + (conf.matrix_h || '?')
    : '{{Bande}}')
  var rgbw = wledbeEl('span_wledbeRgbw')
  if (rgbw !== null) { rgbw.style.display = (conf.rgbw == 1) ? '' : 'none' }
  var open = wledbeEl('bt_wledbeOpen')
  if (open !== null) {
    open.style.display = conf.ip ? '' : 'none'
    open.setAttribute('href', conf.ip ? 'http://' + encodeURI(conf.ip) + '/' : '#')
  }

  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    var id = String(_eqLogic.id)
    wledbeAjax('data', { id: id }, function (result) {
      /* Réponse tardive d'un équipement ouvert avant celui-ci : ignorée. */
      if (String(result.result.id) !== String(wledbeCurrentId())) { return }
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
/* Les gestionnaires sont redéfinis à chaque chargement de la page : une mise
   à jour du plugin qui ajoute un bouton est prise en compte sans recharger
   l'onglet. Les écouteurs, eux, ne sont posés qu'une fois sur le document et
   appellent ces gestionnaires au moment de l'événement. */
window.wledbeHandlers = {
  click: function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_wledbeDiscover: wledbeDiscover,
      bt_wledbeAddIp: wledbeAddIp,
      bt_wledbeRefresh: wledbeRefresh,
      bt_wledbeGroupRefresh: wledbeRefresh,
      bt_wledbeLists: wledbeLists,
      bt_wledbeScenes: wledbeScenesToggle,
      bt_wledbeAddGroup: wledbeAddGroup,
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
  },
  change: function (_event) {
    if (_event.target && _event.target.classList && _event.target.classList.contains('wledbeMember')) {
      wledbeMembersToInput()
    }
  },
  /* Toute saisie dans l'éditeur de scènes le marque comme modifié. */
  input: function (_event) {
    if (_event.target && typeof _event.target.closest === 'function' && _event.target.closest('#div_wledbeSceneList') !== null) {
      wledbeSetDirty(true)
    }
  }
}

/* Un onglet ouvert avant la version 0.3 a déjà l'ancien écouteur (drapeau
   wledbeListening) : on ne pose pas le nouveau par-dessus, qui ferait
   exécuter deux fois chaque bouton. Un rechargement de l'onglet suffit. */
if (!window.wledbeListening && !window.wledbeListeningV2) {
  window.wledbeListeningV2 = true
  ;['click', 'change', 'input'].forEach(function (_type) {
    document.addEventListener(_type, function (_event) {
      if (window.wledbeHandlers && typeof window.wledbeHandlers[_type] === 'function') {
        window.wledbeHandlers[_type](_event)
      }
    })
  })
}
