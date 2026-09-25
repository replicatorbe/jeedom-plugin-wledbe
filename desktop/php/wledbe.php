<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('wledbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_wledbeDiscover">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Rechercher des WLED}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_wledbeAddIp">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter par adresse IP}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_wledbeScenes">
				<i class="fas fa-theater-masks"></i>
				<br>
				<span>{{Scènes}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<!-- Bibliothèque de scènes : remplie par le JS, cachée jusqu'au clic sur
		     « Scènes ». Rien ici n'est un eqLogicAttr : elle s'enregistre à part. -->
		<div id="div_wledbeScenes" style="display:none;">
			<legend><i class="fas fa-theater-masks"></i> {{Scènes}}</legend>
			<div class="alert alert-info" style="margin:5px;">
				{{Une scène joue un effet pendant une durée donnée, avec une priorité, puis rend l'éclairage d'avant. Elle est commune à tous vos WLED ; chacun reçoit une commande « Scène … ». Dans un scénario, la commande « Lancer une scène » accepte le nom en titre et des options en message :}}
				<code>durée=30</code> <code>durée=5m</code> <code>délai=10</code> <code>heure=22:30</code> <code>priorité=95</code> <code>fin=éteindre</code>.
				{{Une scène plus prioritaire recouvre les autres ; une scène sous garde est réimposée si quelqu'un la défait. Une commande manuelle (allumer, couleur…) pendant une scène l'abandonne. « Essayer » joue la scène telle qu'elle est à l'écran, sans l'enregistrer.}}
			</div>
			<div class="form-inline" style="margin:5px 5px 10px 5px;">
				<a class="btn btn-success btn-sm" id="bt_wledbeScenesSave"><i class="fas fa-check-circle"></i> {{Enregistrer les scènes}}</a>
				<a class="btn btn-default btn-sm" id="bt_wledbeSceneAdd"><i class="fas fa-plus-circle"></i> {{Nouvelle scène}}</a>
				<a class="btn btn-default btn-sm" id="bt_wledbeScenesReset"><i class="fas fa-undo"></i> {{Scènes d'origine}}</a>
				<span style="margin-left:20px;">{{Essayer sur}}</span>
				<select class="form-control input-sm" id="sel_wledbeTestDevice"></select>
				<span>{{pendant}}</span>
				<input type="number" class="form-control input-sm" id="in_wledbeTestDuration" value="10" min="1" max="600" style="width:70px;"> s
				<a class="btn btn-default btn-sm" id="bt_wledbeTestStop" title="{{Arrête toutes les scènes de cet appareil, y compris une vraie alarme en cours, et rend l'éclairage d'avant.}}"><i class="fas fa-stop"></i> {{Arrêter les scènes de cet appareil}}</a>
			</div>
			<div id="div_wledbeSceneList"></div>
			<datalist id="dl_wledbeEffects"></datalist>
			<datalist id="dl_wledbePalettes"></datalist>
		</div>

		<legend><i class="fas fa-lightbulb"></i> {{Mes WLED}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun WLED pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Rechercher des WLED » : le plugin écoute les annonces mDNS et interroge toutes les adresses de votre réseau local. Vous pouvez aussi saisir une adresse IP à la main.}}</li>';
			echo '<li>{{Créez les appareils trouvés. Les commandes, les listes d\'effets et de palettes sont remplies aussitôt.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Tout se passe sur votre réseau local, sans cloud ni MQTT.}}</span>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			$matrix = $eqLogic->getConfiguration('layout', 'strip') === 'matrix';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="' . ($matrix ? 'fas fa-th' : 'fas fa-grip-lines') . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-info">' . ($matrix ? '{{Matrice}}' : '{{Bande}}') . '</span> ';
			echo '<span class="label label-default">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip', '')) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Bande du salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-plug"></i> {{Appareil}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.150">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Si elle change, la découverte la retrouve grâce à l'adresse MAC.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Vérifier les ordres}}</label>
								<div class="col-sm-9">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="verify" checked>
									<span class="help-block" style="margin:4px 0 0 0;">{{Après chaque ordre, le plugin relit l'état appliqué par WLED et relance l'ordre s'il s'est perdu. Un échec définitif est signalé dans le centre de messages et par la commande « Vérification ».}}</span>
								</div>
							</div>

							<legend><i class="fas fa-info-circle"></i> {{Identité}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom dans WLED}}</label>
								<div class="col-sm-9">
									<span id="span_wledbeDeviceName"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Type}}</label>
								<div class="col-sm-9">
									<span id="span_wledbeLayout" class="label label-info"></span>
									<span id="span_wledbeLeds" style="margin-left:6px;"></span>
									<span id="span_wledbeRgbw" class="label label-default" style="margin-left:6px;display:none;">RGBW</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse MAC}}</label>
								<div class="col-sm-9">
									<span id="span_wledbeMac"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version}}</label>
								<div class="col-sm-9">
									<span id="span_wledbeVersion"></span>
									<span id="span_wledbeArch" class="label label-default" style="margin-left:6px;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<a class="btn btn-default btn-sm" id="bt_wledbeRefresh"><i class="fas fa-sync"></i> {{Relever maintenant}}</a>
									<a class="btn btn-default btn-sm" id="bt_wledbeLists"><i class="fas fa-list"></i> {{Relire effets, palettes et presets}}</a>
									<a class="btn btn-default btn-sm" id="bt_wledbeOpen" target="_blank"><i class="fas fa-external-link-alt"></i> {{Interface WLED}}</a>
									<br>
									<span id="span_wledbeStatus" style="display:inline-block;margin-top:6px;"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info" id="div_wledbeState">{{Chargement…}}</div>
					<legend><i class="fas fa-theater-masks"></i> {{Scènes sur cet appareil}}</legend>
					<div id="div_wledbeStack"></div>
					<legend><i class="fas fa-code"></i> {{Dernière réponse de l'appareil}}</legend>
					<span class="help-block">{{Le JSON de /json/si tel que WLED l'a renvoyé. C'est la pièce à joindre en cas de valeur douteuse.}}</span>
					<pre id="pre_wledbeRaw" style="max-height:520px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'wledbe', 'js', 'wledbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
